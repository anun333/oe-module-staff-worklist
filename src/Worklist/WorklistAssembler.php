<?php

/**
 * Joins freshly computed work items with stored state, then filters and sorts.
 *
 * Pure: no database or OpenEMR calls, so the visibility rules are unit tested.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Worklist;

use OpenHealthcare\StaffWorklist\Model\ItemState;
use OpenHealthcare\StaffWorklist\Model\SiteAccess;
use OpenHealthcare\StaffWorklist\Model\WorkItem;

final class WorklistAssembler
{
    /**
     * @param WorkItem[]               $items
     * @param array<string, ItemState> $states keyed by item key
     */
    public function assemble(
        array $items,
        array $states,
        WorklistFilter $filter,
        \DateTimeImmutable $today,
        SiteAccess $access,
    ): WorklistResult {
        $rows = [];
        $bySource = [];
        $bySite = [];
        $overdue = 0;
        foreach ($items as $item) {
            $state = $states[(string)$item->key] ?? null;
            // Work at sites the user may not reach is dropped before anything is counted,
            // so no count reveals how much work another site has.
            if (!$access->canSee($item, $state)) {
                continue;
            }
            // Overdue work is counted whatever the view, scope, site or tab, so a banner can
            // say it exists even when the current filters hide it.
            if ($item->overdue) {
                $overdue++;
            }
            $row = $this->row($item, $state, $today);
            if ($row->status !== $filter->view || !$this->inScope($row, $filter)) {
                continue;
            }
            $bySite[$item->siteKey()] = ($bySite[$item->siteKey()] ?? 0) + 1;
            if ($filter->sites !== null && !in_array($item->siteKey(), $filter->sites, true)) {
                continue;
            }
            $bySource[$item->source()] = ($bySource[$item->source()] ?? 0) + 1;
            if ($filter->source === null || $filter->source === $item->source()) {
                $rows[] = $row;
            }
        }

        usort($rows, static fn(WorklistRow $a, WorklistRow $b): int =>
            [$a->item->priority, $a->item->since, (string)$a->item->key]
            <=> [$b->item->priority, $b->item->since, (string)$b->item->key]);

        return new WorklistResult($rows, $bySource, $bySite, $overdue);
    }

    public function row(WorkItem $item, ?ItemState $state, \DateTimeImmutable $today): WorklistRow
    {
        return new WorklistRow(
            $item,
            $state,
            $this->effectiveStatus($item, $state, $today),
            $state?->assignedTo ?? $item->ownerId,
            $state?->assignedTo !== null,
        );
    }

    private function effectiveStatus(WorkItem $item, ?ItemState $state, \DateTimeImmutable $today): string
    {
        if ($state === null) {
            return ItemState::STATUS_OPEN;
        }
        // A dismissal stored before an item became undismissible (or a hand-edited row)
        // must not hide work that only completing it in OpenEMR can clear.
        if ($state->status === ItemState::STATUS_DISMISSED && $item->dismissible) {
            return ItemState::STATUS_DISMISSED;
        }
        // Same reasoning: a result that turned critical while snoozed comes back immediately.
        if (
            $state->status === ItemState::STATUS_SNOOZED
            && $state->snoozeUntil !== null
            && $state->snoozeUntil->setTime(0, 0) > $today->setTime(0, 0)
            && $item->priority !== WorkItem::PRIORITY_CRITICAL
        ) {
            return ItemState::STATUS_SNOOZED;
        }
        return ItemState::STATUS_OPEN;
    }

    private function inScope(WorklistRow $row, WorklistFilter $filter): bool
    {
        return match ($filter->scope) {
            WorklistFilter::SCOPE_MINE => $row->assigneeId === $filter->userId,
            WorklistFilter::SCOPE_UNASSIGNED => $row->assigneeId === null,
            default => true,
        };
    }
}
