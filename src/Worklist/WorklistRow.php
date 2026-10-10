<?php

/**
 * A work item joined with its stored state, as shown on one worklist row.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Worklist;

use OpenHealthcare\StaffWorklist\Model\ItemState;
use OpenHealthcare\StaffWorklist\Model\WorkItem;

final class WorklistRow
{
    public function __construct(
        public readonly WorkItem $item,
        public readonly ?ItemState $state,
        public readonly string $status,
        public readonly ?int $assigneeId,
        public readonly bool $explicitlyAssigned,
    ) {
    }
}
