<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\SlidingFee\ReassessmentRules;
use PHPUnit\Framework\TestCase;

final class ReassessmentRulesTest extends TestCase
{
    public function testNeverExpiredSoonAndNotDue(): void
    {
        $today = new \DateTimeImmutable('2026-10-19');
        $on = static fn(string $d): array => ['id' => 5, 'on' => new \DateTimeImmutable($d)];
        $this->assertSame(ReassessmentRules::NEVER, ReassessmentRules::decide(null, $today, 12)['status'] ?? null);
        $this->assertSame(ReassessmentRules::EXPIRED, ReassessmentRules::decide($on('2025-10-19'), $today, 12)['status'] ?? null, 'runs out today');
        $this->assertSame(ReassessmentRules::SOON, ReassessmentRules::decide($on('2025-11-18'), $today, 12)['status'] ?? null, 'runs out in 30 days');
        $this->assertNull(ReassessmentRules::decide($on('2025-11-19'), $today, 12), 'runs out in 31 days');
        $this->assertSame('2026-04-19', ReassessmentRules::decide($on('2025-10-19'), $today, 6)['dueOn']?->format('Y-m-d'), 'a 6-month period');
    }

    public function testTheMonthsComeFromTheNewestMainSchedule(): void
    {
        $this->assertSame(6, ReassessmentRules::months(['{"service":"dental","reassessMonths":3}', '{"reassessMonths":6}', '{"reassessMonths":24}']),
            'a service schedule does not set it; a schedule without a service key is the main one');
        $this->assertSame(12, ReassessmentRules::months(['{"service":"main"}']), 'missing: 12');
        $this->assertNull(ReassessmentRules::months([]), 'no schedule yet');
        $this->assertNull(ReassessmentRules::months(['not json']));
    }
}
