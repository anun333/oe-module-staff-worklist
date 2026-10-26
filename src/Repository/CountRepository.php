<?php

/**
 * Each user's tab counts (staff_worklist_count), so a page can show every tab's count while
 * collecting only the tab it shows. TabCounts decides which counts are recent enough to show.
 *
 * Before the 0.8.7 upgrade SQL has run the table may not exist; then nothing is kept and every
 * tab is counted after the page loads, which is slower but still right.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Database\SqlQueryException;
use OpenHealthcare\StaffWorklist\Worklist\TabCounts;

final class CountRepository
{
    /**
     * @return array<string, array{count: int, countedAt: \DateTimeImmutable}> by source
     */
    public function forUser(int $userId, string $filterKey): array
    {
        try {
            $rows = QueryUtils::fetchRecords(
                'SELECT `source`, `item_count`, `counted_at` FROM `staff_worklist_count` WHERE `user_id` = ? AND `filter_key` = ?',
                [$userId, $filterKey],
            );
        } catch (SqlQueryException) {
            return [];
        }
        $counts = [];
        foreach ($rows as $row) {
            $at = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$row['counted_at']);
            if ($at !== false) {
                $counts[(string)$row['source']] = ['count' => (int)$row['item_count'], 'countedAt' => $at];
            }
        }
        return $counts;
    }

    /**
     * Save counts taken now, replacing earlier ones, and drop this user's counts too old to show.
     *
     * @param array<string,int> $counts by source
     */
    public function store(int $userId, string $filterKey, array $counts, \DateTimeImmutable $now): void
    {
        if ($counts === []) {
            return;
        }
        $at = $now->format('Y-m-d H:i:s');
        $values = [];
        $binds = [];
        foreach ($counts as $source => $count) {
            $values[] = '(?, ?, ?, ?, ?)';
            array_push($binds, $userId, $filterKey, (string)$source, $count, $at);
        }
        try {
            QueryUtils::sqlStatementThrowException(
                'INSERT INTO `staff_worklist_count` (`user_id`, `filter_key`, `source`, `item_count`, `counted_at`) VALUES '
                    . implode(', ', $values)
                    . ' ON DUPLICATE KEY UPDATE `item_count` = VALUES(`item_count`), `counted_at` = VALUES(`counted_at`)',
                $binds,
            );
            QueryUtils::sqlStatementThrowException(
                'DELETE FROM `staff_worklist_count` WHERE `user_id` = ? AND `counted_at` < ?',
                [$userId, $now->modify('-' . (TabCounts::TTL_MINUTES * 6) . ' minutes')->format('Y-m-d H:i:s')],
            );
        } catch (SqlQueryException) {
            // No table yet (the upgrade SQL hasn't run): nothing is kept.
        }
    }

    /** Forget every user's counts for one tab, after someone changed an item on it. */
    public function forget(string $source): void
    {
        try {
            QueryUtils::sqlStatementThrowException(
                'DELETE FROM `staff_worklist_count` WHERE `source` = ?',
                [$source],
            );
        } catch (SqlQueryException) {
        }
    }
}
