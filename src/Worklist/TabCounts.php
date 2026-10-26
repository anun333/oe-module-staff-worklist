<?php

/**
 * The counts on the worklist's tabs. A page collects only the tab it shows, so the other tabs'
 * counts come from the last time each was counted for this user, kept for TTL_MINUTES; a tab
 * not counted in that time is counted after the page loads (public/counts.php).
 *
 * Pure: no database or OpenEMR calls.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Worklist;

final class TabCounts
{
    public const TTL_MINUTES = 10;

    /**
     * What a tab's count depends on besides the tab: the view, the scope and the sites chosen.
     */
    public static function filterKey(WorklistFilter $filter): string
    {
        $sites = $filter->sites;
        if ($sites !== null) {
            sort($sites);
        }
        return $filter->view . '|' . $filter->scope . '|' . ($sites === null ? 'all' : implode(',', $sites));
    }

    /**
     * The counts taken in this request: every source collected gets one, 0 when it found nothing.
     *
     * @param list<string>      $collected source ids collected
     * @param array<string,int> $bySource  the assembled counts (sources with work only)
     * @return array<string,int>
     */
    public static function fresh(array $collected, array $bySource): array
    {
        $fresh = [];
        foreach ($collected as $source) {
            $fresh[$source] = $bySource[$source] ?? 0;
        }
        return $fresh;
    }

    /**
     * Each tab's count for the page, in the order of $sources: counted now (fresh), counted
     * within TTL_MINUTES (with when), or not known (count null), for the page to count later.
     *
     * @param list<string>                                                  $sources the tabs this user has
     * @param array<string,int>                                             $fresh   counted in this request
     * @param array<string,array{count: int, countedAt: \DateTimeImmutable}> $cached  saved earlier
     * @return array<string, array{count: ?int, countedAt: ?\DateTimeImmutable, fresh: bool}>
     */
    public static function merge(array $sources, array $fresh, array $cached, \DateTimeImmutable $now): array
    {
        $oldest = $now->modify('-' . self::TTL_MINUTES . ' minutes');
        $merged = [];
        foreach ($sources as $source) {
            if (array_key_exists($source, $fresh)) {
                $merged[$source] = ['count' => $fresh[$source], 'countedAt' => $now, 'fresh' => true];
            } elseif (isset($cached[$source]) && $cached[$source]['countedAt'] >= $oldest) {
                $merged[$source] = ['count' => $cached[$source]['count'], 'countedAt' => $cached[$source]['countedAt'], 'fresh' => false];
            } else {
                $merged[$source] = ['count' => null, 'countedAt' => null, 'fresh' => false];
            }
        }
        return $merged;
    }

    /**
     * The All tab's count: the sum, once every tab's count is known.
     *
     * @param array<string, array{count: ?int, countedAt: ?\DateTimeImmutable, fresh: bool}> $merged
     */
    public static function total(array $merged): ?int
    {
        $total = 0;
        foreach ($merged as $entry) {
            if ($entry['count'] === null) {
                return null;
            }
            $total += $entry['count'];
        }
        return $total;
    }

    /**
     * The earliest time among counts not taken in this request, or null when every count is fresh
     * or unknown: what the page's "counts as of" note shows.
     *
     * @param array<string, array{count: ?int, countedAt: ?\DateTimeImmutable, fresh: bool}> $merged
     */
    public static function oldest(array $merged): ?\DateTimeImmutable
    {
        $oldest = null;
        foreach ($merged as $entry) {
            if (!$entry['fresh'] && $entry['countedAt'] !== null && ($oldest === null || $entry['countedAt'] < $oldest)) {
                $oldest = $entry['countedAt'];
            }
        }
        return $oldest;
    }
}
