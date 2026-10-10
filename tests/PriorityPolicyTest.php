<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\Policy\PriorityPolicy;
use PHPUnit\Framework\TestCase;

final class PriorityPolicyTest extends TestCase
{
    private PriorityPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PriorityPolicy();
    }

    public function testAbnormalFlagsFoldToLevels(): void
    {
        self::assertSame(PriorityPolicy::ABNORMAL_PANIC, PriorityPolicy::abnormalLevel('vhigh'));
        self::assertSame(PriorityPolicy::ABNORMAL_PANIC, PriorityPolicy::abnormalLevel('VLOW'));
        self::assertSame(PriorityPolicy::ABNORMAL_FLAGGED, PriorityPolicy::abnormalLevel('high'));
        self::assertSame(PriorityPolicy::ABNORMAL_FLAGGED, PriorityPolicy::abnormalLevel('yes'));
        self::assertSame(PriorityPolicy::ABNORMAL_NONE, PriorityPolicy::abnormalLevel('no'));
        self::assertSame(PriorityPolicy::ABNORMAL_NONE, PriorityPolicy::abnormalLevel(''));
    }

    public function testAgeDaysCountsCalendarDaysAndNeverGoesNegative(): void
    {
        $now = new \DateTimeImmutable('2026-10-06 08:00');
        self::assertSame(1, PriorityPolicy::ageDays(new \DateTimeImmutable('2026-10-05 23:59'), $now));
        self::assertSame(0, PriorityPolicy::ageDays(new \DateTimeImmutable('2026-10-06 00:01'), $now));
        self::assertSame(0, PriorityPolicy::ageDays(new \DateTimeImmutable('2026-10-09'), $now));
    }

    public function testResultPriority(): void
    {
        self::assertSame(WorkItem::PRIORITY_CRITICAL, $this->policy->forResult(PriorityPolicy::ABNORMAL_PANIC, 0));
        self::assertSame(WorkItem::PRIORITY_HIGH, $this->policy->forResult(PriorityPolicy::ABNORMAL_FLAGGED, 0));
        self::assertSame(WorkItem::PRIORITY_NORMAL, $this->policy->forResult(PriorityPolicy::ABNORMAL_NONE, 3));
        self::assertSame(WorkItem::PRIORITY_HIGH, $this->policy->forResult(PriorityPolicy::ABNORMAL_NONE, 4));
    }

    public function testRoutineOrderBecomesOverdueAfterAWeekAndEscalatesAtTwo(): void
    {
        self::assertNull($this->policy->forOrder('routine', 6));
        self::assertSame(WorkItem::PRIORITY_NORMAL, $this->policy->forOrder('routine', 7));
        self::assertSame(WorkItem::PRIORITY_NORMAL, $this->policy->forOrder('', 13));
        self::assertSame(WorkItem::PRIORITY_HIGH, $this->policy->forOrder('normal', 14));
    }

    public function testUrgentOrderIsHighPriorityAfterOneDay(): void
    {
        self::assertNull($this->policy->forOrder('stat', 0));
        self::assertSame(WorkItem::PRIORITY_HIGH, $this->policy->forOrder('stat', 1));
        self::assertSame(WorkItem::PRIORITY_HIGH, $this->policy->forOrder('ASAP', 1));
        self::assertSame(1, $this->policy->orderThresholdDays('urgent'));
        self::assertSame(7, $this->policy->orderThresholdDays('routine'));
    }

    public function testReferralPriority(): void
    {
        self::assertNull($this->policy->forReferral('high', 13));
        self::assertSame(WorkItem::PRIORITY_HIGH, $this->policy->forReferral('high', 14));
        self::assertSame(WorkItem::PRIORITY_NORMAL, $this->policy->forReferral('low', 14));
        self::assertSame(WorkItem::PRIORITY_HIGH, $this->policy->forReferral('', 28));
    }

    public function testCareGapsCountDueAndPastDueAndStayNormalPriority(): void
    {
        self::assertTrue($this->policy->isCareGap('past_due'));
        self::assertTrue($this->policy->isCareGap('due'));
        self::assertTrue($this->policy->isCareGap('soon_due'));
        self::assertFalse($this->policy->isCareGap('not_due'));
        self::assertFalse($this->policy->isCareGap(''));
        self::assertSame(WorkItem::PRIORITY_NORMAL, $this->policy->forCareGapVisit());
    }

    public function testThresholdsAreConfigurable(): void
    {
        $policy = new PriorityPolicy(routineOrderDays: 3, referralDays: 7);
        self::assertSame(WorkItem::PRIORITY_NORMAL, $policy->forOrder('routine', 3));
        self::assertSame(WorkItem::PRIORITY_NORMAL, $policy->forReferral('', 7));
    }
}
