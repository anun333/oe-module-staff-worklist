<?php

/**
 * Positive social-needs screens from the last 180 days with nothing after them (SocialNeedsRules):
 * OpenEMR's SDOH history, and Hunger Vital Sign and six-item food security questionnaires such as Clinic
 * Forms adds to OpenEMR's questionnaires. Services count from the UDS module's Table 6A Line 40 to 42
 * value sets, when that module has fetched them; a referral on or after the screen counts for any need.
 * It only reads.
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
use OpenHealthcare\Common\QuestionnaireStatus;
use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Model\PatientRef;
use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\Screening\ResponseScoring;
use OpenHealthcare\StaffWorklist\Screening\ScreeningInstrument;
use OpenHealthcare\StaffWorklist\Social\SocialNeedsRules;
use OpenHealthcare\StaffWorklist\Social\SocialScreen;

final class SocialNeedsSource implements WorkSourceInterface
{
    private const WINDOW_DAYS = 180;

    /** The UDS 2026 value sets for Table 6A Lines 40 to 42 (the UDS module's data/table-6a.json). */
    private const LINE_VALUE_SETS = [
        '40' => ['2.16.840.1.113762.1.4.1247.15', '2.16.840.1.113762.1.4.1247.9', '2.16.840.1.113762.1.4.1247.6'],
        '41' => ['2.16.840.1.113762.1.4.1247.19', '2.16.840.1.113762.1.4.1247.43'],
        '42' => ['2.16.840.1.113762.1.4.1247.109'],
    ];

    /** OpenEMR billing code types by value set code system, as the UDS module matches them. */
    private const TYPES_BY_SYSTEM = ['cpt' => ['CPT4'], 'hcpcs' => ['HCPCS'], 'snomed' => ['SNOMED', 'SNOMED-CT', 'SNOMED-PR']];

    private const FSS_TOTAL = '95379-4';
    private const FSS_PANEL = '95361-2';

    public function __construct(
        private readonly \DateTimeImmutable $now,
    ) {
    }

    public function id(): string
    {
        return ItemKey::SOURCE_SOCIAL;
    }

    public function collect(?int $pid = null): array
    {
        $since = $this->now->setTime(0, 0)->modify('-' . self::WINDOW_DAYS . ' days');
        $rows = [];
        $screens = [];
        foreach ([...$this->sdohHistory($since, $pid), ...$this->questionnaires($since, $pid)] as [$screen, $row]) {
            $screens[] = $screen;
            $rows[$screen->kind . ':' . $screen->id] = $row;
        }
        if ($screens === []) {
            return [];
        }
        $pids = array_values(array_unique(array_map(static fn(SocialScreen $s): int => $s->pid, $screens)));
        $items = [];
        foreach (SocialNeedsRules::open($screens, $this->referrals($pids, $since), $this->services($pids, $since)) as $screen) {
            $items[] = $this->toItem($screen, $rows[$screen->kind . ':' . $screen->id]);
        }
        return $items;
    }

    /** @return list<array{0: SocialScreen, 1: array<string, mixed>}> */
    private function sdohHistory(\DateTimeImmutable $since, ?int $pid): array
    {
        if (!self::tableExists('form_history_sdoh')) {
            return [];
        }
        $domains = implode(', ', array_map(static fn(string $d): string => "h.`$d`", array_keys(SocialNeedsRules::POSITIVE_ANSWERS)));
        $sql = "SELECT h.id, h.pid, COALESCE(h.assessment_date, DATE(h.created_at)) AS day, $domains,
                fe.facility_id AS site_id, fe.sensitivity, " . PatientRef::SQL_COLUMNS . "
            FROM form_history_sdoh AS h
            JOIN patient_data AS pd ON pd.pid = h.pid
            LEFT JOIN form_encounter AS fe ON h.encounter > 0 AND fe.encounter = h.encounter AND fe.pid = h.pid
            WHERE COALESCE(h.declined_flag, 0) = 0 AND COALESCE(h.assessment_date, DATE(h.created_at)) >= ?" . ($pid !== null ? ' AND h.pid = ?' : '');
        $binds = [$since->format('Y-m-d')];
        if ($pid !== null) {
            $binds[] = $pid;
        }
        $out = [];
        foreach (QueryUtils::fetchRecords($sql, $binds) as $row) {
            $day = self::date($row['day'] ?? null);
            if ($day === null || PatientRef::fromRow((int)$row['pid'], $row, $this->now)->deceased) {
                continue;
            }
            $out[] = [new SocialScreen(SocialNeedsRules::KIND_SDOH, (int)$row['id'], (int)$row['pid'], $day, SocialNeedsRules::sdohDomains($row)), $row];
        }
        return $out;
    }

    /** @return list<array{0: SocialScreen, 1: array<string, mixed>}> */
    private function questionnaires(\DateTimeImmutable $since, ?int $pid): array
    {
        $sql = "SELECT qr.id, qr.patient_id AS pid, qr.create_time,
                COALESCE(NULLIF(qr.questionnaire, ''), rep.questionnaire) AS questionnaire_json, qr.questionnaire_response AS response_json,
                fe.facility_id AS site_id, fe.sensitivity, " . PatientRef::SQL_COLUMNS . "
            FROM questionnaire_response AS qr
            JOIN patient_data AS pd ON pd.pid = qr.patient_id
            LEFT JOIN questionnaire_repository AS rep ON rep.id = qr.questionnaire_foreign_id" . ResponseVisit::joins() . "
            WHERE qr.create_time >= ?
              AND (COALESCE(NULLIF(qr.questionnaire, ''), rep.questionnaire) LIKE ? OR COALESCE(NULLIF(qr.questionnaire, ''), rep.questionnaire) LIKE ?)
              AND NOT EXISTS (
                  SELECT 1 FROM form_questionnaire_assessments AS fqa
                  JOIN forms AS f ON f.form_id = fqa.id AND f.pid = fqa.pid AND f.formdir = 'questionnaire_assessments'
                  WHERE fqa.response_id = qr.response_id AND f.deleted = 1)" . ($pid !== null ? ' AND qr.patient_id = ?' : '');
        $binds = [$since->format('Y-m-d H:i:s'), '%"' . SocialNeedsRules::HVS_PANEL . '"%', '%"' . self::FSS_PANEL . '"%'];
        if ($pid !== null) {
            $binds[] = $pid;
        }
        // Which responses count, by status (QuestionnaireStatus); the visit is ResponseVisit's.
        [$statusFilter, $statusBinds] = QuestionnaireStatus::condition('qr');
        $sql .= " AND $statusFilter";
        array_push($binds, ...$statusBinds);
        $fss = [self::FSS_TOTAL => new ScreeningInstrument('Food security (six-item)', self::FSS_TOTAL, 2, null,
            'low or very low food security', 'USDA ERS, Six-Item Short Form user notes (May 2024)', null, null, [],
            panelCode: self::FSS_PANEL, questions: ['88123-5' => 1, '95248-1' => 1, '95249-9' => 1, '95250-7' => 1, '95251-5' => 1, '95252-3' => 1])];
        $out = [];
        foreach (QueryUtils::fetchRecords($sql, $binds) as $row) {
            $day = self::date($row['create_time'] ?? null);
            if ($day === null || PatientRef::fromRow((int)$row['pid'], $row, $this->now)->deceased) {
                continue;
            }
            $questionnaire = (string)($row['questionnaire_json'] ?? '');
            $response = json_decode((string)($row['response_json'] ?? ''), true);
            if (str_contains($questionnaire, '"' . SocialNeedsRules::HVS_PANEL . '"') && is_array($response)) {
                $positive = SocialNeedsRules::hungerVitalSign($response);
                if ($positive !== null) {
                    $out[] = [new SocialScreen(SocialNeedsRules::KIND_HVS, (int)$row['id'], (int)$row['pid'], $day, $positive ? ['food_insecurity'] : []), $row];
                }
                continue;
            }
            $score = ResponseScoring::read($questionnaire, (string)($row['response_json'] ?? ''), $fss)[self::FSS_TOTAL] ?? null;
            // Low food security from a raw score of 2; an unfinished survey that could still go either way is left out.
            if ($score !== null && ($score->low >= 2 || $score->high < 2)) {
                $out[] = [new SocialScreen(SocialNeedsRules::KIND_FSS, (int)$row['id'], (int)$row['pid'], $day, $score->low >= 2 ? ['food_insecurity'] : []),
                    $row + ['fss_score' => $score->low]];
            }
        }
        return $out;
    }

    /**
     * Referral days (Transactions › Referral: its Referral Date, else when it was entered), by patient.
     *
     * @param list<int> $pids
     * @return array<int, list<\DateTimeImmutable>>
     */
    private function referrals(array $pids, \DateTimeImmutable $since): array
    {
        $in = implode(', ', array_fill(0, count($pids), '?'));
        $sql = "SELECT t.pid, COALESCE(NULLIF(d.field_value, ''), DATE(t.date)) AS day
            FROM transactions AS t
            LEFT JOIN lbt_data AS d ON d.form_id = t.id AND d.field_id = 'refer_date'
            WHERE t.title = 'LBTref' AND t.pid IN ($in) AND COALESCE(NULLIF(d.field_value, ''), DATE(t.date)) >= ?";
        $out = [];
        foreach (QueryUtils::fetchRecords($sql, [...$pids, $since->format('Y-m-d')]) as $row) {
            $day = self::date($row['day'] ?? null);
            if ($day !== null) {
                $out[(int)$row['pid']][] = $day;
            }
        }
        return $out;
    }

    /**
     * Days a Table 6A Line 40, 41 or 42 service was billed, by patient and line, from the UDS module's
     * fetched value sets; none when that module or its value sets aren't there.
     *
     * @param list<int> $pids
     * @return array<int, array<string, list<\DateTimeImmutable>>>
     */
    private function services(array $pids, \DateTimeImmutable $since): array
    {
        // uds_value_set_code is the UDS Report module's table (its value set expansions): without that
        // module, or with a version whose table lacks these columns, services aren't read at all.
        if (!self::columnsExist('uds_value_set_code', ['code', 'oid'])) {
            return [];
        }
        $lineOf = [];
        foreach (self::LINE_VALUE_SETS as $line => $oids) {
            foreach ($oids as $oid) {
                $lineOf[$oid] = (string)$line;
            }
        }
        $types = [];
        foreach (self::TYPES_BY_SYSTEM as $system => $codeTypes) {
            foreach ($codeTypes as $codeType) {
                $types[] = "(vs.system = '$system' AND b.code_type = '$codeType')";
            }
        }
        $in = implode(', ', array_fill(0, count($pids), '?'));
        $oids = array_keys($lineOf);
        $sql = "SELECT DISTINCT b.pid, vs.oid, DATE(fe.date) AS day
            FROM billing AS b
            JOIN form_encounter AS fe ON fe.encounter = b.encounter AND fe.pid = b.pid
            JOIN uds_value_set_code AS vs ON vs.code = b.code AND (" . implode(' OR ', $types) . ")
            WHERE b.activity = 1 AND b.pid IN ($in) AND fe.date >= ?
              AND vs.oid IN (" . implode(', ', array_fill(0, count($oids), '?')) . ')';
        $out = [];
        foreach (QueryUtils::fetchRecords($sql, [...$pids, $since->format('Y-m-d'), ...$oids]) as $row) {
            $day = self::date($row['day'] ?? null);
            $line = $lineOf[(string)$row['oid']] ?? null;
            if ($day !== null && $line !== null) {
                $out[(int)$row['pid']][$line][] = $day;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $row */
    private function toItem(SocialScreen $screen, array $row): WorkItem
    {
        $labels = [
            'food_insecurity' => xl('food'), 'housing_instability' => xl('housing'), 'transportation_insecurity' => xl('transportation'),
            'utilities_insecurity' => xl('utilities'), 'interpersonal_safety' => xl('safety'), 'financial_strain' => xl('financial strain'),
            'social_isolation' => xl('social isolation'), 'childcare_needs' => xl('childcare'), 'digital_access' => xl('digital access'),
        ];
        $needs = implode(', ', array_map(static fn(string $d): string => $labels[$d] ?? $d, $screen->domains));
        $how = match ($screen->kind) {
            SocialNeedsRules::KIND_SDOH => xl('SDOH history'),
            SocialNeedsRules::KIND_HVS => xl('Hunger Vital Sign'),
            default => sprintf(xl('Food security survey, raw score %d'), (int)($row['fss_score'] ?? 0)),
        };
        $facts = [sprintf(xl('Screened %1$s with the %2$s'), oeFormatShortDate($screen->date->format('Y-m-d')), $how)];
        $facts[] = xl('Leaves the list with a referral on or after the screen, or for food, housing or financial strain a service UDS Table 6A Lines 40 to 42 count, billed on or after it');
        return new WorkItem(
            key: new ItemKey(ItemKey::SOURCE_SOCIAL, $screen->pid, $screen->ref()),
            patient: PatientRef::fromRow($screen->pid, $row, $this->now),
            title: sprintf(xl('Positive social needs screen: %s'), $needs),
            detail: implode(' · ', $facts),
            priority: in_array('interpersonal_safety', $screen->domains, true) ? WorkItem::PRIORITY_HIGH : WorkItem::PRIORITY_NORMAL,
            since: $screen->date,
            ownerId: null,
            actionLabel: xl('Open chart'),
            actionUrl: '',
            actionMode: 'chart',
            dismissible: true,
            siteId: ((int)($row['site_id'] ?? 0)) ?: null,
            sensitivity: is_string($row['sensitivity'] ?? null) ? $row['sensitivity'] : null,
        );
    }

    private static function tableExists(string $name): bool
    {
        return QueryUtils::fetchRecords('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$name]) !== [];
    }

    /** @param list<string> $columns */
    private static function columnsExist(string $table, array $columns): bool
    {
        $found = QueryUtils::fetchRecords(
            'SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name IN ('
                . implode(', ', array_fill(0, count($columns), '?')) . ')',
            [$table, ...$columns]
        );
        return count($found) === count($columns);
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
