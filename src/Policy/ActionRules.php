<?php

/**
 * Which worklist actions are allowed on which items.
 *
 * Snoozing and dismissing only hide an item from the list; they never change
 * the chart. The limits here keep that from hiding work that must be done:
 * unsigned results can't be dismissed, and panic values can't be snoozed.
 *
 * Methods return null when the action is allowed, or an ERR_* code.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Policy;

use OpenHealthcare\StaffWorklist\Model\WorkItem;

final class ActionRules
{
    public const ERR_CRITICAL_SNOOZE = 'critical_snooze';
    public const ERR_SNOOZE_PAST = 'snooze_past';
    public const ERR_SNOOZE_TOO_LONG = 'snooze_too_long';
    public const ERR_NOT_DISMISSIBLE = 'not_dismissible';
    public const ERR_REASON_REQUIRED = 'reason_required';

    public function __construct(
        public readonly int $maxSnoozeDays = 30,
        public readonly int $maxSnoozeDaysUndismissible = 3,
    ) {
    }

    public function maxSnoozeDays(WorkItem $item): int
    {
        return $item->dismissible ? $this->maxSnoozeDays : $this->maxSnoozeDaysUndismissible;
    }

    public function checkSnooze(WorkItem $item, \DateTimeImmutable $until, \DateTimeImmutable $today): ?string
    {
        if ($item->priority === WorkItem::PRIORITY_CRITICAL) {
            return self::ERR_CRITICAL_SNOOZE;
        }
        $days = (int)$today->setTime(0, 0)->diff($until->setTime(0, 0))->format('%r%a');
        if ($days < 1) {
            return self::ERR_SNOOZE_PAST;
        }
        if ($days > $this->maxSnoozeDays($item)) {
            return self::ERR_SNOOZE_TOO_LONG;
        }
        return null;
    }

    public function checkDismiss(WorkItem $item, string $reason): ?string
    {
        if (!$item->dismissible) {
            return self::ERR_NOT_DISMISSIBLE;
        }
        if (trim($reason) === '') {
            return self::ERR_REASON_REQUIRED;
        }
        return null;
    }
}
