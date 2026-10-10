<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Model\ItemState;
use OpenHealthcare\StaffWorklist\Model\PatientRef;
use OpenHealthcare\StaffWorklist\Model\WorkItem;

/** Builders for test fixtures. */
final class Items
{
    public static function item(
        string $key = 'order:1:10',
        int $priority = WorkItem::PRIORITY_NORMAL,
        string $since = '2026-09-01',
        ?int $ownerId = null,
        bool $dismissible = true,
        ?int $siteId = null,
    ): WorkItem {
        $k = ItemKey::parse($key) ?? throw new \LogicException("bad fixture key $key");
        return new WorkItem(
            key: $k,
            patient: new PatientRef($k->pid, 'Doe, Jan', '1970-01-01', "P{$k->pid}"),
            title: 'title',
            detail: 'detail',
            priority: $priority,
            since: new \DateTimeImmutable($since),
            ownerId: $ownerId,
            actionLabel: 'Open',
            actionUrl: '/x',
            actionMode: 'dialog',
            dismissible: $dismissible,
            siteId: $siteId,
        );
    }

    public static function state(
        string $key,
        string $status = ItemState::STATUS_OPEN,
        ?string $snoozeUntil = null,
        ?int $assignedTo = null,
    ): ItemState {
        $k = ItemKey::parse($key) ?? throw new \LogicException("bad fixture key $key");
        return new ItemState(
            $key,
            $k->pid,
            $assignedTo,
            $status,
            $snoozeUntil === null ? null : new \DateTimeImmutable($snoozeUntil),
            '',
            1,
            null,
        );
    }
}
