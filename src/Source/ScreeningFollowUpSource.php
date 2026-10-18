<?php

/**
 * Screening totals at or above their published cut-off with nothing recorded after
 * them (ScreeningRules), from the questionnaire responses OpenEMR's own runtime saves.
 * A response whose encounter form was deleted isn't read: OpenEMR keeps the response
 * row when the form is deleted.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Source;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Model\PatientRef;
use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\Screening\FollowUpRecord;
use OpenHealthcare\StaffWorklist\Screening\ResponseTotals;
use OpenHealthcare\StaffWorklist\Screening\ScreenFinding;
use OpenHealthcare\StaffWorklist\Screening\ScreeningInstrument;
use OpenHealthcare\StaffWorklist\Screening\ScreeningRules;
use OpenHealthcare\StaffWorklist\Screening\ScreenResponse;

final class ScreeningFollowUpSource implements WorkSourceInterface
{
    private readonly ScreeningRules $rules;

    public function __construct(
        private readonly \DateTimeImmutable $now,
    ) {
        $this->rules = new ScreeningRules($now);
    }

    public function id(): string
    {
        return ItemKey::SOURCE_SCREEN;
    }

    public function collect(?int $pid = null): array
    {
        $codes = array_keys(ScreeningInstrument::all());
        $binds = [$this->rules->windowStart()->format('Y-m-d H:i:s')];
        $mentions = [];
        foreach ($codes as $code) {
            $mentions[] = 'COALESCE(NULLIF(qr.questionnaire, \'\'), rep.questionnaire) LIKE ?';
            $binds[] = '%"' . $code . '"%';
        }
        $patientFilter = '';
        if ($pid !== null) {
            $patientFilter = 'AND qr.patient_id = ?';
            $binds[] = $pid;
        }

        // The Questionnaire is the response row's own copy, else the repository's.
        $sql = "SELECT qr.id, qr.patient_id, qr.create_time, ru.fname AS by_fname, ru.lname AS by_lname,
                COALESCE(NULLIF(qr.questionnaire, ''), rep.questionnaire) AS questionnaire_json,
                qr.questionnaire_response AS response_json,
                fe.facility_id AS site_id, fe.provider_id AS visit_provider, fe.sensitivity,
                pd.providerID AS patient_provider, pd.sex,
                " . PatientRef::SQL_COLUMNS . "
            FROM questionnaire_response AS qr
            JOIN patient_data AS pd ON pd.pid = qr.patient_id
            LEFT JOIN questionnaire_repository AS rep ON rep.id = qr.questionnaire_foreign_id
            LEFT JOIN form_encounter AS fe ON qr.encounter > 0 AND fe.encounter = qr.encounter AND fe.pid = qr.patient_id
            LEFT JOIN users AS ru ON qr.creator_user_id > 0 AND ru.id = qr.creator_user_id
            WHERE qr.create_time >= ?
              AND (" . implode(' OR ', $mentions) . ")
              AND NOT EXISTS (
                  SELECT 1 FROM form_questionnaire_assessments AS fqa
                  JOIN forms AS f ON f.form_id = fqa.id AND f.pid = fqa.pid AND f.formdir = 'questionnaire_assessments'
                  WHERE fqa.response_id = qr.response_id AND f.deleted = 1)
              $patientFilter";

        $responses = [];
        $rows = [];
        $sexByPid = [];
        foreach (QueryUtils::fetchRecords($sql, $binds) as $row) {
            $date = self::date($row['create_time'] ?? null);
            $totals = ResponseTotals::read((string)($row['questionnaire_json'] ?? ''), (string)($row['response_json'] ?? ''), $codes);
            $patient = PatientRef::fromRow((int)$row['patient_id'], $row, $this->now);
            if ($date === null || $totals === [] || $patient->deceased) {
                continue;
            }
            $id = (int)$row['id'];
            $responses[] = new ScreenResponse($id, $patient->pid, $date, $totals);
            $rows[$id] = $row;
            $sexByPid[$patient->pid] = is_string($row['sex'] ?? null) ? $row['sex'] : null;
        }
        if ($responses === []) {
            return [];
        }

        $items = [];
        foreach ($this->rules->findings($responses, $sexByPid, $this->records(array_keys($sexByPid))) as $finding) {
            $items[] = $this->toItem($finding, $rows[$finding->response->id]);
        }
        return $items;
    }

    /**
     * Problems still on the list, and codes billed at visits in the window, for these patients.
     *
     * @param list<int> $pids
     * @return list<FollowUpRecord>
     */
    private function records(array $pids): array
    {
        $in = implode(', ', array_fill(0, count($pids), '?'));
        $records = [];
        $problems = "SELECT l.pid, l.diagnosis, l.enddate FROM lists AS l
            WHERE l.type = 'medical_problem' AND l.activity = 1 AND COALESCE(l.diagnosis, '') <> ''
              AND COALESCE(l.verification, '') NOT IN ('entered-in-error', 'refuted')
              AND l.pid IN ($in)";
        foreach (QueryUtils::fetchRecords($problems, $pids) as $row) {
            // lists.diagnosis holds "TYPE:CODE" pairs separated by semicolons.
            foreach (explode(';', (string)$row['diagnosis']) as $pair) {
                [$type, $code] = array_pad(explode(':', $pair, 2), 2, '');
                if ($code !== '') {
                    $records[] = new FollowUpRecord((int)$row['pid'], $type, $code, self::date($row['enddate'] ?? null));
                }
            }
        }
        $billed = "SELECT b.pid, b.code_type, b.code, fe.date FROM billing AS b
            JOIN form_encounter AS fe ON fe.encounter = b.encounter AND fe.pid = b.pid
            WHERE b.activity = 1 AND b.code_type IN ('ICD10', 'CPT4', 'HCPCS') AND fe.date >= ?
              AND b.pid IN ($in)";
        foreach (QueryUtils::fetchRecords($billed, [$this->rules->windowStart()->format('Y-m-d H:i:s'), ...$pids]) as $row) {
            $visit = self::date($row['date'] ?? null);
            if ($visit !== null) {
                $records[] = new FollowUpRecord((int)$row['pid'], (string)$row['code_type'], (string)$row['code'], $visit);
            }
        }
        return $records;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toItem(ScreenFinding $finding, array $row): WorkItem
    {
        $instrument = $finding->instrument;
        $score = rtrim(rtrim(number_format($finding->score, 2, '.', ''), '0'), '.');
        $facts = [
            xl('Screened') . ' ' . oeFormatShortDate($finding->response->date->format('Y-m-d')),
            xl('Cut-off') . ' ' . $finding->cutoff . ': ' . xl($instrument->meaning),
        ];
        $by = trim(($row['by_fname'] ?? '') . ' ' . ($row['by_lname'] ?? ''));
        if ($by !== '') {
            $facts[0] .= ' ' . xl('by') . ' ' . $by;
        }
        if ($finding->sexUnknown) {
            $facts[] = xl('Sex not recorded as male or female, so the cut-off for women was used');
        }
        $facts[] = xl('Source') . ': ' . $instrument->source;
        $owner = ((int)($row['visit_provider'] ?? 0)) ?: ((int)($row['patient_provider'] ?? 0));

        return new WorkItem(
            key: new ItemKey(ItemKey::SOURCE_SCREEN, $finding->response->pid, (string)$finding->response->id),
            patient: PatientRef::fromRow($finding->response->pid, $row, $this->now),
            title: $instrument->name . ' ' . xl('total') . ' ' . $score . ', ' . xl('at or above its published cut-off'),
            detail: implode(' · ', $facts),
            priority: $finding->severe ? WorkItem::PRIORITY_HIGH : WorkItem::PRIORITY_NORMAL,
            since: $finding->response->date,
            ownerId: $owner ?: null,
            actionLabel: xl('Open chart'),
            actionUrl: '',
            actionMode: 'chart',
            dismissible: true,
            siteId: ((int)($row['site_id'] ?? 0)) ?: null,
            sensitivity: is_string($row['sensitivity'] ?? null) ? $row['sensitivity'] : null,
        );
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
