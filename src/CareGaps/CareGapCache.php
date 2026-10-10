<?php

/**
 * Rules-engine output per patient, so the worklist does not run the engine on page load.
 *
 * Stores option ids (rule_action item, rule_reminder_due_opt status), not
 * display text: the background job has no user language, so labels are
 * looked up when the page renders.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\CareGaps;

use OpenEMR\Common\Database\QueryUtils;

final class CareGapCache
{
    /**
     * @param int[] $pids
     * @return array<int, array{gaps: list<array{item: string, due: string}>, computedAt: \DateTimeImmutable}>
     */
    public function forPatients(array $pids): array
    {
        $entries = [];
        foreach (array_chunk(array_values(array_unique($pids)), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = QueryUtils::fetchRecords(
                "SELECT `pid`, `gaps_json`, `computed_at` FROM `staff_worklist_caregap_cache` WHERE `pid` IN ($placeholders)",
                $chunk,
            );
            foreach ($rows as $row) {
                $gaps = json_decode((string)$row['gaps_json'], true);
                $entries[(int)$row['pid']] = [
                    'gaps' => is_array($gaps) ? $gaps : [],
                    'computedAt' => new \DateTimeImmutable((string)$row['computed_at']),
                ];
            }
        }
        return $entries;
    }

    /**
     * @param list<array{item: string, due: string}> $gaps
     */
    public function save(int $pid, array $gaps, \DateTimeImmutable $computedAt): void
    {
        QueryUtils::sqlStatementThrowException(
            "INSERT INTO `staff_worklist_caregap_cache` (`pid`, `gaps_json`, `computed_at`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `gaps_json` = VALUES(`gaps_json`), `computed_at` = VALUES(`computed_at`)",
            [$pid, json_encode($gaps, JSON_THROW_ON_ERROR), $computedAt->format('Y-m-d H:i:s')],
            true,
        );
    }

    /**
     * Drop entries for patients who no longer have a visit coming up.
     *
     * @param int[] $keepPids
     */
    public function purgeExcept(array $keepPids, \DateTimeImmutable $olderThan): void
    {
        $binds = [$olderThan->format('Y-m-d H:i:s')];
        $keep = '';
        if ($keepPids !== []) {
            $keep = 'AND `pid` NOT IN (' . implode(',', array_fill(0, count($keepPids), '?')) . ')';
            array_push($binds, ...array_values($keepPids));
        }
        QueryUtils::sqlStatementThrowException(
            "DELETE FROM `staff_worklist_caregap_cache` WHERE `computed_at` < ? $keep",
            $binds,
            true,
        );
    }
}
