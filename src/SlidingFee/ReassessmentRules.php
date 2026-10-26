<?php

/**
 * Who is due for a sliding fee re-assessment, when the Sliding Fee Discount module is installed:
 * patients seen in the last 12 months whose assessment runs out within 30 days, has run out, or
 * who were never assessed. The same rule as that module's own "Re-assessments due" page
 * (anun333/oe-module-sliding-fee, src/Domain/Reassessment.php); the worklist only reads its tables.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\SlidingFee;

use OpenHealthcare\StaffWorklist\Model\WorkItem;

final class ReassessmentRules
{
    public const NEVER = 'never';
    public const EXPIRED = 'expired';
    public const SOON = 'soon';
    public const WINDOW_DAYS = 30;
    /** Each condition's digit in an item key (ItemKey); fixed, since saved dismissals carry it. */
    public const KEY_DIGITS = [self::NEVER => 1, self::SOON => 2, self::EXPIRED => 3];

    /**
     * An item's key ref: the latest assessment's id (1 for a patient never assessed) and the condition's
     * digit, so "runs out soon" never hides "ran out".
     */
    public static function keyRef(?int $assessmentId, string $status): string
    {
        return ($assessmentId ?? 1) . '.' . (self::KEY_DIGITS[$status] ?? 9);
    }

    /**
     * @param array{id: int, on: \DateTimeImmutable}|null $latest the patient's latest assessment on or before today
     * @return array{status: string, priority: int, dueOn: ?\DateTimeImmutable}|null null when not due
     */
    public static function decide(?array $latest, \DateTimeImmutable $today, int $months): ?array
    {
        if ($latest === null) {
            return ['status' => self::NEVER, 'priority' => WorkItem::PRIORITY_NORMAL, 'dueOn' => null];
        }
        $dueOn = $latest['on']->setTime(0, 0)->modify('+' . max(1, $months) . ' months');
        if ($dueOn <= $today) {
            return ['status' => self::EXPIRED, 'priority' => WorkItem::PRIORITY_NORMAL, 'dueOn' => $dueOn];
        }
        if ($dueOn <= $today->modify('+' . self::WINDOW_DAYS . ' days')) {
            return ['status' => self::SOON, 'priority' => WorkItem::PRIORITY_NORMAL, 'dueOn' => $dueOn];
        }
        return null;
    }

    /**
     * The re-assessment period from the newest main schedule: its JSON's reassessMonths, 12 if
     * missing. Service schedules (any "service" other than "main") don't set it.
     *
     * @param list<string> $schedulesNewestFirst sliding_fee_schedule.schedule_json values
     */
    public static function months(array $schedulesNewestFirst): ?int
    {
        foreach ($schedulesNewestFirst as $json) {
            $data = json_decode($json, true);
            if (!is_array($data) || ($data['service'] ?? 'main') !== 'main') {
                continue;
            }
            return is_numeric($data['reassessMonths'] ?? null) ? max(1, (int)$data['reassessMonths']) : 12;
        }
        return null;
    }
}
