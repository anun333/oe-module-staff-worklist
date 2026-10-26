<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Worklist\LegacyKeys;
use PHPUnit\Framework\TestCase;

/** State saved under a 0.8.5 key is carried once to the record's 0.8.6 key, and the old row goes. */
final class LegacyKeysTest extends TestCase
{
    public function testA085KeyIsCarriedToTheConditionTheRecordShowsNow(): void
    {
        $items = [
            Items::item('claim:12:345.1v2'),
            Items::item('auth:7:41.2'),
            Items::item('sliding:9:88.2'),
            Items::item('order:12:345'), // another source: never carried
        ];
        $plan = LegacyKeys::plan($items, ['claim:12:345', 'auth:7:41', 'sliding:9:88'], []);
        self::assertSame(['claim:12:345' => 'claim:12:345.1v2', 'auth:7:41' => 'auth:7:41.2', 'sliding:9:88' => 'sliding:9:88.2'], $plan['moves']);
        self::assertSame([], $plan['drops']);
    }

    public function testAnOldRowWithNothingListedOrAlreadySupersededGoes(): void
    {
        $items = [Items::item('claim:12:345.6v2'), Items::item('claim:12:346.2v1')];
        $plan = LegacyKeys::plan($items, ['claim:12:345', 'claim:12:999', 'claim:12:346'], ['claim:12:346.2v1' => true]);
        self::assertSame(['claim:12:345' => 'claim:12:345.6v2'], $plan['moves']);
        self::assertSame(['claim:12:999', 'claim:12:346'], $plan['drops'], 'nothing listed for 999; 346 already has state of its own');
    }

    public function testADismissalIsCarriedOnlyOntoAConditionThatHadBegunWhenItWasMade(): void
    {
        $items = [
            Items::item('claim:12:345.1v2', since: '2026-09-20'), // the denial that was dismissed
            Items::item('claim:12:346.6v1', since: '2026-10-05'), // a file not sent, after the dismissal
            Items::item('auth:7:41.2', since: '2026-10-05'),      // assigned, not dismissed
            Items::item('sliding:9:88.2', since: '2026-09-01'),   // dismissed with no time on record
        ];
        $legacy = ['claim:12:345', 'claim:12:346', 'auth:7:41', 'sliding:9:88'];
        $dismissedAt = [
            'claim:12:345' => new \DateTimeImmutable('2026-10-01 10:00:00'),
            'claim:12:346' => new \DateTimeImmutable('2026-10-01 10:00:00'),
            'sliding:9:88' => null,
        ];
        $plan = LegacyKeys::plan($items, $legacy, [], $dismissedAt);
        self::assertSame(
            ['claim:12:345' => 'claim:12:345.1v2', 'claim:12:346' => 'claim:12:346.6v1', 'auth:7:41' => 'auth:7:41.2', 'sliding:9:88' => 'sliding:9:88.2'],
            $plan['moves'],
            'every row still goes across, with its assignee and note',
        );
        self::assertSame(
            ['claim:12:346', 'sliding:9:88'],
            $plan['reopen'],
            'the dismissal holds for the denial it was made on, not for a problem that began after it, nor when its time is unknown',
        );
    }

    public function testAConditionThatBeganTheSameMomentAsTheDismissalKeepsIt(): void
    {
        $at = new \DateTimeImmutable('2026-10-01 00:00:00');
        $plan = LegacyKeys::plan([Items::item('claim:12:345.1v2', since: '2026-10-01')], ['claim:12:345'], [], ['claim:12:345' => $at]);
        self::assertSame([], $plan['reopen']);
    }

    public function testTheScanEndsWhenNoneAreLeftAndDropsWhatsLeftAfterTheGrace(): void
    {
        $now = new \DateTimeImmutable('2026-11-10 12:00:00');
        self::assertSame(LegacyKeys::DONE, LegacyKeys::scanDecision(0, null, $now), 'nothing to carry: never scan again');
        self::assertSame(LegacyKeys::DONE, LegacyKeys::scanDecision(0, new \DateTimeImmutable('2026-11-09'), $now));
        self::assertSame(LegacyKeys::SCAN, LegacyKeys::scanDecision(3, null, $now), 'the first scan');
        self::assertSame(LegacyKeys::SCAN, LegacyKeys::scanDecision(3, $now->modify('-29 days'), $now));
        self::assertSame(LegacyKeys::EXPIRE, LegacyKeys::scanDecision(3, $now->modify('-' . LegacyKeys::GRACE_DAYS . ' days'), $now));
    }

    public function testThePatternFindsOnly085KeysOfTheSourcesAsked(): void
    {
        $pattern = '/' . LegacyKeys::pattern([ItemKey::SOURCE_CLAIM, ItemKey::SOURCE_SLIDING, ItemKey::SOURCE_RESULT]) . '/';
        self::assertSame(1, preg_match($pattern, 'claim:12:345'));
        self::assertSame(1, preg_match($pattern, 'sliding:9:88'));
        self::assertSame(0, preg_match($pattern, 'claim:12:345.1v2'), 'a 0.8.6 key');
        self::assertSame(0, preg_match($pattern, 'auth:7:41'), 'a source not asked');
        self::assertSame(0, preg_match($pattern, 'result:1:2'), 'a source whose keys never changed');
    }
}
