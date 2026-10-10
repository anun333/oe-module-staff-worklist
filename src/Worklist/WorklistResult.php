<?php

/**
 * Sorted rows for the current filter, plus counts for the tabs and the site picker.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Worklist;

final class WorklistResult
{
    /**
     * @param WorklistRow[]      $rows
     * @param array<string, int> $countsBySource for the view, scope and selected sites, before the source filter
     * @param array<int, int>    $countsBySite   for the view and scope across every visible site, before the site filter
     * @param int                $overdueCount   overdue items the user may see, regardless of view, scope, site or tab
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $countsBySource,
        public readonly array $countsBySite = [],
        public readonly int $overdueCount = 0,
    ) {
    }

    public function total(): int
    {
        return array_sum($this->countsBySource);
    }
}
