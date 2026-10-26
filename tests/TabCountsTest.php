<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Worklist\TabCounts;
use OpenHealthcare\StaffWorklist\Worklist\WorklistFilter;
use PHPUnit\Framework\TestCase;

/** A page collects one tab; the others show a count kept for 10 minutes, or are counted after it loads. */
final class TabCountsTest extends TestCase
{
    public function testACountIsKeptForTheViewScopeAndSitesItWasTakenFor(): void
    {
        self::assertSame('open|mine|all', TabCounts::filterKey(new WorklistFilter(5)));
        self::assertSame(
            'snoozed|all|0,3,12',
            TabCounts::filterKey(new WorklistFilter(5, 'snoozed', 'all', 'claim', [12, 0, 3])),
            'sites in any order give one key; the tab is not part of it',
        );
        self::assertSame(
            TabCounts::filterKey(new WorklistFilter(5, 'open', 'all', 'claim')),
            TabCounts::filterKey(new WorklistFilter(5, 'open', 'all', null)),
        );
    }

    public function testEverySourceCollectedHasAFreshCountEvenWithNothingToDo(): void
    {
        self::assertSame(['result' => 4, 'claim' => 0], TabCounts::fresh(['result', 'claim'], ['result' => 4]));
    }

    public function testFreshThenKeptWithinTenMinutesThenUnknown(): void
    {
        $now = new \DateTimeImmutable('2026-10-26 12:00:00');
        $merged = TabCounts::merge(
            ['result', 'order', 'claim', 'screen', 'social'],
            ['result' => 4, 'claim' => 7],
            [
                'order' => ['count' => 3, 'countedAt' => new \DateTimeImmutable('2026-10-26 11:50:00')],  // exactly 10 minutes: kept
                'claim' => ['count' => 99, 'countedAt' => new \DateTimeImmutable('2026-10-26 11:59:00')], // counted now wins
                'screen' => ['count' => 2, 'countedAt' => new \DateTimeImmutable('2026-10-26 11:49:59')], // too old
            ],
            $now,
        );
        self::assertSame(['result', 'order', 'claim', 'screen', 'social'], array_keys($merged), 'tab order');
        self::assertSame([4, 3, 7, null, null], array_column($merged, 'count'));
        self::assertSame([true, false, true, false, false], array_column($merged, 'fresh'));
        self::assertEquals(new \DateTimeImmutable('2026-10-26 11:50:00'), $merged['order']['countedAt']);
        self::assertNull(TabCounts::total($merged), 'All waits until every tab is counted');
        self::assertEquals(new \DateTimeImmutable('2026-10-26 11:50:00'), TabCounts::oldest($merged));
    }

    public function testTheAllCountIsTheSumOnceEveryTabIsKnown(): void
    {
        $now = new \DateTimeImmutable('2026-10-26 12:00:00');
        $merged = TabCounts::merge(
            ['result', 'order'],
            ['result' => 4, 'order' => 0],
            [],
            $now,
        );
        self::assertSame(4, TabCounts::total($merged));
        self::assertNull(TabCounts::oldest($merged), 'every count fresh: no "counts as of" note');
    }
}
