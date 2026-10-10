<?php

/**
 * Decides when outstanding work becomes a worklist item and how urgent it is.
 *
 * Every threshold the worklist uses lives here so the rules can be read and
 * tested in one place. Methods that return null mean "not overdue yet".
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Policy;

use OpenHealthcare\StaffWorklist\Model\WorkItem;

final class PriorityPolicy
{
    // procedure_result.abnormal folded to a level: 2 = panic value, 1 = abnormal, 0 = normal/unknown
    public const ABNORMAL_NONE = 0;
    public const ABNORMAL_FLAGGED = 1;
    public const ABNORMAL_PANIC = 2;

    // ord_priority option ids that get the short follow-up window
    private const URGENT_ORDER_PRIORITIES = ['stat', 'asap', 'urgent', 'high'];

    public function __construct(
        public readonly int $staleResultDays = 3,
        public readonly int $routineOrderDays = 7,
        public readonly int $urgentOrderDays = 1,
        public readonly int $referralDays = 14,
        // How long a panic-value result may sit unsigned before the worklist calls it overdue.
        // Set to the clinic's critical-results policy.
        public readonly int $criticalOverdueHours = 4,
    ) {
    }

    public static function ageHours(\DateTimeImmutable $since, \DateTimeImmutable $now): int
    {
        return max(0, intdiv($now->getTimestamp() - $since->getTimestamp(), 3600));
    }

    public function isCriticalOverdue(int $abnormalLevel, int $ageHours): bool
    {
        return $abnormalLevel >= self::ABNORMAL_PANIC && $ageHours >= $this->criticalOverdueHours;
    }

    public static function abnormalLevel(string $abnormal): int
    {
        return match (strtolower(trim($abnormal))) {
            'vhigh', 'vlow' => self::ABNORMAL_PANIC,
            'yes', 'high', 'low' => self::ABNORMAL_FLAGGED,
            default => self::ABNORMAL_NONE,
        };
    }

    public static function ageDays(\DateTimeImmutable $since, \DateTimeImmutable $now): int
    {
        $days = (int)$since->setTime(0, 0)->diff($now->setTime(0, 0))->format('%r%a');
        return max(0, $days);
    }

    /**
     * A received result is always on the list until a clinician signs it.
     */
    public function forResult(int $abnormalLevel, int $ageDays): int
    {
        return match (true) {
            $abnormalLevel >= self::ABNORMAL_PANIC => WorkItem::PRIORITY_CRITICAL,
            $abnormalLevel === self::ABNORMAL_FLAGGED => WorkItem::PRIORITY_HIGH,
            $ageDays > $this->staleResultDays => WorkItem::PRIORITY_HIGH,
            default => WorkItem::PRIORITY_NORMAL,
        };
    }

    public function orderThresholdDays(string $orderPriority): int
    {
        return in_array(strtolower($orderPriority), self::URGENT_ORDER_PRIORITIES, true)
            ? $this->urgentOrderDays
            : $this->routineOrderDays;
    }

    public function forOrder(string $orderPriority, int $ageDays): ?int
    {
        $threshold = $this->orderThresholdDays($orderPriority);
        if ($ageDays < $threshold) {
            return null;
        }
        $urgent = in_array(strtolower($orderPriority), self::URGENT_ORDER_PRIORITIES, true);
        return ($urgent || $ageDays >= 2 * $threshold) ? WorkItem::PRIORITY_HIGH : WorkItem::PRIORITY_NORMAL;
    }

    public function forReferral(string $riskLevel, int $ageDays): ?int
    {
        if ($ageDays < $this->referralDays) {
            return null;
        }
        return (strtolower($riskLevel) === 'high' || $ageDays >= 2 * $this->referralDays)
            ? WorkItem::PRIORITY_HIGH
            : WorkItem::PRIORITY_NORMAL;
    }

    /**
     * Whether a rules-engine reminder counts as a gap to prepare for.
     *
     * @param string $dueStatus rule_reminder_due_opt option id from the rules engine
     */
    public function isCareGap(string $dueStatus): bool
    {
        return in_array($dueStatus, ['past_due', 'due', 'soon_due'], true);
    }

    /**
     * Pre-visit care gaps are preparation, not an open safety loop like an unsigned
     * result, so they stay Normal and sort by visit time within that band.
     */
    public function forCareGapVisit(): int
    {
        return WorkItem::PRIORITY_NORMAL;
    }
}
