<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\PriorAuth\AuthorizationRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthorizationRulesTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, ?int, ?int, ?string, ?int}> */
    public static function cases(): iterable
    {
        yield 'in force, plenty left' => ['2026-09-01', '2026-12-31', 20, 15, null, null];
        yield 'units used up' => ['2026-09-01', '2026-12-31', 20, 0, AuthorizationRules::USED_UP, WorkItem::PRIORITY_HIGH];
        yield 'ends in 14 days' => ['2026-09-01', '2026-11-03', 20, 15, AuthorizationRules::ENDING, WorkItem::PRIORITY_NORMAL];
        yield 'ends in 15 days' => ['2026-09-01', '2026-11-04', 20, 15, null, null];
        yield 'ends today' => ['2026-09-01', '2026-10-20', 20, 15, AuthorizationRules::ENDING, WorkItem::PRIORITY_NORMAL];
        yield 'two of twenty left' => ['2026-09-01', '2026-12-31', 20, 2, AuthorizationRules::UNITS_LOW, WorkItem::PRIORITY_NORMAL];
        yield 'three of twenty left' => ['2026-09-01', '2026-12-31', 20, 3, null, null];
        yield 'one of six left' => ['2026-09-01', '2026-12-31', 6, 1, AuthorizationRules::UNITS_LOW, WorkItem::PRIORITY_NORMAL];
        yield 'a single-unit authorization, unused' => ['2026-09-01', '2026-12-31', 1, 1, null, null];
        yield 'used up beats ending' => ['2026-09-01', '2026-10-25', 20, 0, AuthorizationRules::USED_UP, WorkItem::PRIORITY_HIGH];
        yield 'ended yesterday' => ['2026-09-01', '2026-10-19', 20, 0, null, null];
        yield 'not started yet' => ['2026-10-21', '2026-12-31', 20, 0, null, null];
        yield 'no dates, no units recorded' => [null, null, null, null, null, null];
        yield 'no end date, units used up' => [null, null, 10, 0, AuthorizationRules::USED_UP, WorkItem::PRIORITY_HIGH];
    }

    #[DataProvider('cases')]
    public function testWhichAuthorizationsNeedSomeone(?string $start, ?string $end, ?int $initial, ?int $remaining, ?string $status, ?int $priority): void
    {
        $d = static fn(?string $s): ?\DateTimeImmutable => $s === null ? null : new \DateTimeImmutable($s);
        $decision = AuthorizationRules::decide($d($start), $d($end), $initial, $remaining, new \DateTimeImmutable('2026-10-20 09:30'));
        self::assertSame($status, $decision['status'] ?? null);
        self::assertSame($priority, $decision['priority'] ?? null);
    }
}
