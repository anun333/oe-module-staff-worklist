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
use OpenHealthcare\StaffWorklist\Worklist\LegacyKeys;

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

    /**
     * Keys saved before 0.8.6 for these sources (LegacyKeys::SOURCES): the ones with no condition.
     *
     * @param string[] $sources
     * @return string[]
     */
    public function legacyKeys(array $sources): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT `item_key` FROM `staff_worklist_state` WHERE `item_key` REGEXP ?',
            [LegacyKeys::pattern($sources)],
        );
        return array_map(static fn(array $r): string => (string)$r['item_key'], $rows);
    }

    /**
     * Moves a row's state to another key, logged as "rekey" with the state it carried. Does nothing
     * if the row has gone or the new key already has a row.
     */
    public function rekey(string $from, string $to, int $userId): void
    {
        QueryUtils::inTransaction(function () use ($from, $to, $userId): void {
            if ($this->find($to) !== null || $this->find($from) === null) {
                return;
            }
            QueryUtils::sqlStatementThrowException(
                'UPDATE `staff_worklist_state` SET `item_key` = ?, `updated_by` = ? WHERE `item_key` = ?',
                [$to, $userId, $from],
            );
            $state = $this->find($to);
            if ($state !== null) {
                $this->log($state, 'rekey', $userId);
            }
        });
    }

    /** Removes a row, logged as "drop" with the state it had. */
    public function drop(string $key, int $userId): void
    {
        QueryUtils::inTransaction(function () use ($key, $userId): void {
            $state = $this->find($key);
            if ($state === null) {
                return;
            }
            QueryUtils::sqlStatementThrowException('DELETE FROM `staff_worklist_state` WHERE `item_key` = ?', [$key]);
            $this->log($state, 'drop', $userId);
        });
    }

    private function log(ItemState $state, string $action, int $userId): void
    {
        QueryUtils::sqlInsert(
            "INSERT INTO `staff_worklist_log`
                (`item_key`, `pid`, `action`, `assigned_to`, `status`, `snooze_until`, `note`, `user_id`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$state->key, $state->pid, $action, $state->assignedTo, $state->status, $state->snoozeUntil?->format('Y-m-d'), $state->note, $userId],
        );
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
