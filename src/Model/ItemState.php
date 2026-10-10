<?php

/**
 * What staff have recorded against a work item: who has it, and whether it
 * is snoozed or dismissed. Stored in staff_worklist_state.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Model;

final class ItemState
{
    public const STATUS_OPEN = 'open';
    public const STATUS_SNOOZED = 'snoozed';
    public const STATUS_DISMISSED = 'dismissed';

    public function __construct(
        public readonly string $key,
        public readonly int $pid,
        public readonly ?int $assignedTo,
        public readonly string $status,
        public readonly ?\DateTimeImmutable $snoozeUntil,
        public readonly string $note,
        public readonly int $updatedBy,
        public readonly ?\DateTimeImmutable $updatedAt,
    ) {
    }

    public static function initial(ItemKey $key, int $userId): self
    {
        return new self((string)$key, $key->pid, null, self::STATUS_OPEN, null, '', $userId, null);
    }

    public function with(
        int $updatedBy,
        ?string $status = null,
        ?\DateTimeImmutable $snoozeUntil = null,
        ?string $note = null,
        bool $changeAssignee = false,
        ?int $assignedTo = null,
    ): self {
        $status ??= $this->status;
        return new self(
            $this->key,
            $this->pid,
            $changeAssignee ? $assignedTo : $this->assignedTo,
            $status,
            $status === self::STATUS_SNOOZED ? ($snoozeUntil ?? $this->snoozeUntil) : null,
            $note ?? $this->note,
            $updatedBy,
            null,
        );
    }
}
