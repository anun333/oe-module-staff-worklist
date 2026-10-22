<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\CareManagement\UnbilledMonths;
use PHPUnit\Framework\TestCase;

final class UnbilledMonthsTest extends TestCase
{
    public function testTheThreeCompletedMonthsBeforeThisOneNewestFirst(): void
    {
        $months = array_map(static fn(\DateTimeImmutable $d): string => $d->format('Y-m-d'), UnbilledMonths::previous(new \DateTimeImmutable('2026-10-22 15:30')));
        $this->assertSame(['2026-09-01', '2026-08-01', '2026-07-01'], $months);
    }

    public function testOnTheFirstOfAMonthTheMonthJustEndedIsTheNewest(): void
    {
        $this->assertSame('2026-09-01', UnbilledMonths::previous(new \DateTimeImmutable('2026-10-01 00:00'))[0]->format('Y-m-d'));
    }

    public function testAcrossTheYearAndFromTheThirtyFirst(): void
    {
        // 'first day of' first, so the 31st doesn't skip a short month.
        $months = array_map(static fn(\DateTimeImmutable $d): string => $d->format('Y-m'), UnbilledMonths::previous(new \DateTimeImmutable('2027-03-31')));
        $this->assertSame(['2027-02', '2027-01', '2026-12'], $months);
    }

    public function testTheRefIsTheEnrollmentThenTheMonth(): void
    {
        $this->assertSame('12202609', UnbilledMonths::ref(12, new \DateTimeImmutable('2026-09-01')));
        $this->assertNotSame(UnbilledMonths::ref(1, new \DateTimeImmutable('2026-09-01')), UnbilledMonths::ref(1, new \DateTimeImmutable('2026-08-01')));
    }
}
