<?php

/**
 * Reads and writes staff state for work items, logging every change.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\StaffWorklist\Model\ItemState;

final class StateRepository
{
    private const CHUNK = 500;

    /**
     * @param string[] $keys
     * @return array<string, ItemState> keyed by item key
     */
    public function findByKeys(array $keys): array
    {
        $states = [];
        foreach (array_chunk(array_values(array_unique($keys)), self::CHUNK) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = QueryUtils::fetchRecords(
                "SELECT * FROM `staff_worklist_state` WHERE `item_key` IN ($placeholders)",
                $chunk,
            );
            foreach ($rows as $row) {
                $state = self::fromRow($row);
                $states[$state->key] = $state;
            }
        }
        return $states;
    }

    public function find(string $key): ?ItemState
    {
        return $this->findByKeys([$key])[$key] ?? null;
    }

    public function save(ItemState $state, string $action): void
    {
        $snooze = $state->snoozeUntil?->format('Y-m-d');
        QueryUtils::inTransaction(function () use ($state, $action, $snooze): void {
            QueryUtils::sqlStatementThrowException(
                "INSERT INTO `staff_worklist_state`
                    (`item_key`, `pid`, `assigned_to`, `status`, `snooze_until`, `note`, `updated_by`)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    `assigned_to` = VALUES(`assigned_to`), `status` = VALUES(`status`),
                    `snooze_until` = VALUES(`snooze_until`), `note` = VALUES(`note`),
                    `updated_by` = VALUES(`updated_by`)",
                [$state->key, $state->pid, $state->assignedTo, $state->status, $snooze, $state->note, $state->updatedBy],
            );
            QueryUtils::sqlInsert(
                "INSERT INTO `staff_worklist_log`
                    (`item_key`, `pid`, `action`, `assigned_to`, `status`, `snooze_until`, `note`, `user_id`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$state->key, $state->pid, $action, $state->assignedTo, $state->status, $snooze, $state->note, $state->updatedBy],
            );
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function fromRow(array $row): ItemState
    {
        return new ItemState(
            (string)$row['item_key'],
            (int)$row['pid'],
            $row['assigned_to'] === null ? null : (int)$row['assigned_to'],
            (string)$row['status'],
            empty($row['snooze_until']) ? null : new \DateTimeImmutable((string)$row['snooze_until']),
            (string)($row['note'] ?? ''),
            (int)$row['updated_by'],
            empty($row['updated_at']) ? null : new \DateTimeImmutable((string)$row['updated_at']),
        );
    }
}
