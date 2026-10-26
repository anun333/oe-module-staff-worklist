<?php

/**
 * Carries staff state saved under a 0.8.5 key to the 0.8.6 key of the same record.
 *
 * From 0.8.6 a claims follow-up, prior authorization or sliding fee item's key names its
 * condition ("claim:12:345.1v2", see ItemKey), so a dismissal holds only the condition it was
 * made on. A key saved before has no condition ("claim:12:345"). Its state (assignee, note,
 * snooze or dismissal) is carried, once, to the key of the condition the record shows the first
 * time 0.8.6 lists it, and the old row goes, so it can never be carried onto a later condition.
 * A 0.8.5 row whose record lists nothing now goes too: what it was about has cleared.
 *
 * A dismissal is carried only when the condition the record shows now had already begun when it
 * was dismissed (the item's `since` is no later than the dismissal). If the condition began
 * after, the dismissal was about something else: the assignee and note go across and the item
 * opens (0.8.7; 0.8.6 carried the dismissal too). A dismissal saved with no time opens as well.
 * The comparison is to the second, so a condition dated by day only that began later on the day
 * of the dismissal still counts as begun before it.
 *
 * The scan for 0.8.5 keys stops for good once none are left (scanDecision). Rows for a source
 * nobody lists would otherwise keep it running, so GRACE_DAYS after the first scan any 0.8.5
 * rows still there go: their items show again, nothing is hidden.
 *
 * Pure: the service reads the rows and applies the plan.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Worklist;

use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Model\WorkItem;

final class LegacyKeys
{
    /** The sources whose keys gained a condition in 0.8.6. */
    public const SOURCES = [ItemKey::SOURCE_CLAIM, ItemKey::SOURCE_AUTH, ItemKey::SOURCE_SLIDING];

    /** Days after the first scan that 0.8.5 rows nobody's list has carried are dropped. */
    public const GRACE_DAYS = 30;

    public const SCAN = 'scan';
    public const DONE = 'done';
    public const EXPIRE = 'expire';

    /**
     * Whether to look for 0.8.5 keys on this build: DONE when none are left (never again), EXPIRE
     * when GRACE_DAYS have passed since the first scan with some still there (drop them, then never
     * again), otherwise SCAN.
     */
    public static function scanDecision(int $remaining, ?\DateTimeImmutable $firstScan, \DateTimeImmutable $now): string
    {
        if ($remaining === 0) {
            return self::DONE;
        }
        if ($firstScan !== null && $now >= $firstScan->modify('+' . self::GRACE_DAYS . ' days')) {
            return self::EXPIRE;
        }
        return self::SCAN;
    }

    /**
     * The sources whose 0.8.5 keys a list may carry or drop: those it collected. A page collects
     * only its own tab, and a source it didn't collect would look as if its records listed nothing.
     *
     * @param list<string> $collected source ids the list collected
     * @return list<string>
     */
    public static function sourcesFor(array $collected): array
    {
        return array_values(array_intersect(self::SOURCES, $collected));
    }

    /**
     * A MySQL REGEXP matching a 0.8.5 key of these sources: no dot in the ref.
     *
     * @param string[] $sources some of SOURCES
     */
    public static function pattern(array $sources): string
    {
        $sources = array_values(array_intersect(self::SOURCES, $sources));
        return '^(' . implode('|', $sources) . '):[0-9]+:[0-9]+$';
    }

    /**
     * What to do with each 0.8.5 key: carry it to the current item's key, or drop it (nothing to
     * carry it to, or the current key already has state of its own). A carried dismissal whose
     * item's condition began after it was made is listed under reopen: carry the rest, open it.
     *
     * @param WorkItem[]                              $items       the items listed now, from the sources the keys came from
     * @param string[]                                $legacyKeys  0.8.5 keys that have state
     * @param array<string, true>                     $stated      current keys that already have state
     * @param array<string, ?\DateTimeImmutable>      $dismissedAt 0.8.5 keys whose state is a dismissal, with when it was made
     * @return array{moves: array<string, string>, drops: list<string>, reopen: list<string>} moves are old key => new key; reopen lists old keys
     */
    public static function plan(array $items, array $legacyKeys, array $stated, array $dismissedAt = []): array
    {
        $byRecord = [];
        foreach ($items as $item) {
            $key = $item->key;
            if (in_array($key->source, self::SOURCES, true)) {
                $byRecord[$key->source . ':' . $key->pid . ':' . $key->record()] = $item;
            }
        }
        $moves = [];
        $drops = [];
        $reopen = [];
        foreach ($legacyKeys as $old) {
            $item = $byRecord[$old] ?? null;
            if ($item === null) {
                $drops[] = $old;
                continue;
            }
            $new = (string)$item->key;
            if ($new === $old) {
                continue; // already the current key: its state is the item's own
            }
            if (isset($stated[$new])) {
                $drops[] = $old;
                continue;
            }
            $moves[$old] = $new;
            if (array_key_exists($old, $dismissedAt)) {
                $at = $dismissedAt[$old];
                if ($at === null || $item->since > $at) {
                    $reopen[] = $old;
                }
            }
        }
        return ['moves' => $moves, 'drops' => $drops, 'reopen' => $reopen];
    }
}
