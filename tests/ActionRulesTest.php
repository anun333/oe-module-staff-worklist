<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\Policy\ActionRules;
use PHPUnit\Framework\TestCase;

final class ActionRulesTest extends TestCase
{
    private ActionRules $rules;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->rules = new ActionRules();
        $this->today = new \DateTimeImmutable('2026-10-06 15:30');
    }

    public function testCriticalItemsCannotBeSnoozed(): void
    {
        $item = Items::item(priority: WorkItem::PRIORITY_CRITICAL, dismissible: false);
        self::assertSame(
            ActionRules::ERR_CRITICAL_SNOOZE,
            $this->rules->checkSnooze($item, new \DateTimeImmutable('2026-10-07'), $this->today),
        );
    }

    public function testSnoozeMustEndAfterToday(): void
    {
        $item = Items::item();
        self::assertSame(ActionRules::ERR_SNOOZE_PAST, $this->rules->checkSnooze($item, new \DateTimeImmutable('2026-10-06'), $this->today));
        self::assertNull($this->rules->checkSnooze($item, new \DateTimeImmutable('2026-10-07'), $this->today));
    }

    public function testUndismissibleItemsHaveShortSnoozeLimit(): void
    {
        $result = Items::item(key: 'result:1:5', dismissible: false);
        self::assertNull($this->rules->checkSnooze($result, new \DateTimeImmutable('2026-10-09'), $this->today));
        self::assertSame(
            ActionRules::ERR_SNOOZE_TOO_LONG,
            $this->rules->checkSnooze($result, new \DateTimeImmutable('2026-10-10'), $this->today),
        );
    }

    public function testDismissibleItemsUseLongSnoozeLimit(): void
    {
        $order = Items::item();
        self::assertNull($this->rules->checkSnooze($order, new \DateTimeImmutable('2026-11-05'), $this->today));
        self::assertSame(
            ActionRules::ERR_SNOOZE_TOO_LONG,
            $this->rules->checkSnooze($order, new \DateTimeImmutable('2026-11-06'), $this->today),
        );
    }

    public function testDismissRequiresDismissibleItemAndReason(): void
    {
        self::assertSame(ActionRules::ERR_NOT_DISMISSIBLE, $this->rules->checkDismiss(Items::item(dismissible: false), 'seen'));
        self::assertSame(ActionRules::ERR_REASON_REQUIRED, $this->rules->checkDismiss(Items::item(), "  \n"));
        self::assertNull($this->rules->checkDismiss(Items::item(), 'Patient declined; documented'));
    }
}
