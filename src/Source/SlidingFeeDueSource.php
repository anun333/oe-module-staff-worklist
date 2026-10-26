<?php

/**
 * Sliding fee re-assessments due, from the Sliding Fee Discount module's tables when that module is
 * installed and enabled: one row per patient seen in the last 12 months whose assessment runs out
 * within 30 days, has run out, or who was never assessed. It only reads; the button opens that
 * module's assessment page.
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
use OpenHealthcare\StaffWorklist\SlidingFee\ReassessmentRules;

final class SlidingFeeDueSource implements WorkSourceInterface
{
    private const MODULE = 'oe-module-sliding-fee';

    public function __construct(
        private readonly \DateTimeImmutable $now,
    ) {
    }

    /**
     * Whether the Sliding Fee Discount module is installed and enabled here, with the columns this
     * reads: sliding_fee_schedule and sliding_fee_assessment are that module's tables, so a version
     * of it without them leaves the tab off rather than failing the Worklist.
     */
    public static function available(): bool
    {
        $rows = QueryUtils::fetchRecords('SELECT 1 FROM `modules` WHERE `mod_directory` = ? AND `mod_active` = 1 LIMIT 1', [self::MODULE]);
        if ($rows === []) {
            return false;
        }
        $found = QueryUtils::fetchRecords(
            "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
                AND ((table_name = 'sliding_fee_schedule' AND column_name IN ('id', 'schedule_json'))
                  OR (table_name = 'sliding_fee_assessment' AND column_name IN ('id', 'pid', 'assessed_on')))"
        );
        return count($found) === 5;
    }

    public function id(): string
    {
        return ItemKey::SOURCE_SLIDING;
    }

    public function collect(?int $pid = null): array
    {
        $schedules = array_map(static fn(array $r): string => (string)$r['schedule_json'],
            QueryUtils::fetchRecords('SELECT `schedule_json` FROM `sliding_fee_schedule` ORDER BY `id` DESC'));
        $months = ReassessmentRules::months($schedules);
        if ($months === null) {
            return []; // no schedule yet, so nobody can be placed
        }
        $today = $this->now->setTime(0, 0);
        $since = $today->modify('-12 months')->format('Y-m-d');
        $sql = "SELECT fe.pid, fe.date AS last_visit, fe.facility_id AS site_id, " . PatientRef::SQL_COLUMNS . ",
                (SELECT a.id FROM sliding_fee_assessment AS a WHERE a.pid = fe.pid AND a.assessed_on <= ?
                  ORDER BY a.assessed_on DESC, a.id DESC LIMIT 1) AS assessment_id,
                (SELECT a.assessed_on FROM sliding_fee_assessment AS a WHERE a.pid = fe.pid AND a.assessed_on <= ?
                  ORDER BY a.assessed_on DESC, a.id DESC LIMIT 1) AS assessed_on
            FROM form_encounter AS fe
            JOIN patient_data AS pd ON pd.pid = fe.pid
            WHERE fe.id = (SELECT f2.id FROM form_encounter AS f2 WHERE f2.pid = fe.pid ORDER BY f2.date DESC, f2.id DESC LIMIT 1)
              AND fe.date >= ? " . ($pid === null ? '' : 'AND fe.pid = ?');
        $binds = [$today->format('Y-m-d'), $today->format('Y-m-d'), $since];
        if ($pid !== null) {
            $binds[] = $pid;
        }
        $items = [];
        foreach (QueryUtils::fetchRecords($sql, $binds) as $row) {
            $latest = $row['assessment_id'] !== null && is_string($row['assessed_on'] ?? null)
                ? ['id' => (int)$row['assessment_id'], 'on' => new \DateTimeImmutable($row['assessed_on'])] : null;
            $decision = ReassessmentRules::decide($latest, $today, $months);
            if ($decision === null) {
                continue;
            }
            $lastVisit = new \DateTimeImmutable(substr((string)$row['last_visit'], 0, 10));
            $title = match ($decision['status']) {
                ReassessmentRules::NEVER => xl('Sliding fee: never assessed'),
                ReassessmentRules::EXPIRED => xl('Sliding fee assessment ran out'),
                default => xl('Sliding fee assessment runs out soon'),
            };
            $facts = [xl('Last visit') . ' ' . oeFormatShortDate($lastVisit->format('Y-m-d'))];
            if ($latest !== null && $decision['dueOn'] !== null) {
                $facts[] = xl('Assessed') . ' ' . oeFormatShortDate($latest['on']->format('Y-m-d'));
                $facts[] = xl('Re-assess by') . ' ' . oeFormatShortDate($decision['dueOn']->format('Y-m-d'));
            }
            $items[] = new WorkItem(
                // A new assessment gives a new key, so a dismissed row comes back when that one runs out; and
                // the condition is in the key, so dismissing "runs out soon" never hides "ran out".
                key: new ItemKey(ItemKey::SOURCE_SLIDING, (int)$row['pid'], ReassessmentRules::keyRef($latest['id'] ?? null, $decision['status'])),
                patient: PatientRef::fromRow((int)$row['pid'], $row, $this->now),
                title: $title,
                detail: implode(' · ', $facts),
                priority: $decision['priority'],
                since: $decision['dueOn'] ?? $lastVisit,
                ownerId: null,
                actionLabel: xl('Assess'),
                actionUrl: '/interface/modules/custom_modules/' . self::MODULE . '/public/patient.php?pid=' . (int)$row['pid'],
                actionMode: 'dialog',
                dismissible: true,
                siteId: ((int)($row['site_id'] ?? 0)) ?: null,
                sensitivity: null,
            );
        }
        return $items;
    }
}
