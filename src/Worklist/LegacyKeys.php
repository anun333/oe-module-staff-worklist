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
     * carry it to, or the current key already has state of its own).
     *
     * @param WorkItem[]          $items      the items listed now, from the sources the keys came from
     * @param string[]            $legacyKeys 0.8.5 keys that have state
     * @param array<string, true> $stated     current keys that already have state
     * @return array{moves: array<string, string>, drops: list<string>} moves are old key => new key
     */
    public static function plan(array $items, array $legacyKeys, array $stated): array
    {
        $byRecord = [];
        foreach ($items as $item) {
            $key = $item->key;
            if (in_array($key->source, self::SOURCES, true)) {
                $byRecord[$key->source . ':' . $key->pid . ':' . $key->record()] = (string)$key;
            }
        }
        $moves = [];
        $drops = [];
        foreach ($legacyKeys as $old) {
            $new = $byRecord[$old] ?? null;
            if ($new === $old) {
                continue; // already the current key: its state is the item's own
            }
            if ($new === null || isset($stated[$new])) {
                $drops[] = $old;
                continue;
            }
            $moves[$old] = $new;
        }
        return ['moves' => $moves, 'drops' => $drops];
    }
}
