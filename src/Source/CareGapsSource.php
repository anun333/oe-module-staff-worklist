<?php

/**
 * Clinical reminders that are due for patients with a visit coming up.
 *
 * The reminders come from OpenEMR's own rules engine (the same call as the
 * Clinical Reminders widget on the chart), run in the background by
 * CareGapRefresher because it is too slow to run per page load. The schedule
 * is read live, so a cancelled visit drops off at once; the gaps themselves
 * can be up to CareGapRefresher::FRESH_MINUTES old, plus the job interval.
 *
 * One item per visit, not per reminder: staff prepare a visit as a whole, and a
 * patient with no history would otherwise fill the list with a row per rule.
 * The key is the appointment id, so dismissing means "prepared for this visit"
 * and the gaps come back for the next one.
 *
 * Requires library/options.inc.php.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Source;

use OpenHealthcare\StaffWorklist\CareGaps\CareGapCache;
use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Model\PatientRef;
use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\Policy\PriorityPolicy;
use OpenHealthcare\StaffWorklist\Repository\UpcomingVisits;

final class CareGapsSource implements WorkSourceInterface
{
    private int $unchecked = 0;
    private ?\DateTimeImmutable $oldestCheck = null;

    public function __construct(
        private readonly PriorityPolicy $policy,
        private readonly UpcomingVisits $visits,
        private readonly CareGapCache $cache,
    ) {
    }

    public function id(): string
    {
        return ItemKey::SOURCE_CAREGAP;
    }

    public function collect(?int $pid = null): array
    {
        $scheduled = $this->visits->nextVisitByPatient($pid);
        $cached = $this->cache->forPatients(array_keys($scheduled));

        $this->unchecked = 0;
        $this->oldestCheck = null;
        $items = [];
        foreach ($scheduled as $visitPid => $visit) {
            if (!isset($cached[$visitPid])) {
                $this->unchecked++;
                continue;
            }
            $computedAt = $cached[$visitPid]['computedAt'];
            if ($this->oldestCheck === null || $computedAt < $this->oldestCheck) {
                $this->oldestCheck = $computedAt;
            }
            $item = $this->itemFor($visitPid, $visit, $cached[$visitPid]['gaps']);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /** Scheduled patients the background job has not reached yet, as of the last collect(). */
    public function uncheckedCount(): int
    {
        return $this->unchecked;
    }

    /** Oldest rules-engine run behind the items from the last collect(). */
    public function oldestCheck(): ?\DateTimeImmutable
    {
        return $this->oldestCheck;
    }

    /**
     * @param array<string, mixed>                $visit
     * @param list<array{item: string, due: string}> $gaps
     */
    private function itemFor(int $pid, array $visit, array $gaps): ?WorkItem
    {
        // Past-due gaps first, each named the way the chart widget names it.
        $pastDue = [];
        $due = [];
        foreach ($gaps as $gap) {
            if (!$this->policy->isCareGap((string)($gap['due'] ?? ''))) {
                continue;
            }
            $name = getListItemTitle('rule_action', (string)$gap['item']);
            if ($gap['due'] === 'past_due') {
                $pastDue[] = $name;
            } else {
                $due[] = $name;
            }
        }
        $names = array_merge($pastDue, $due);
        if ($names === []) {
            return null;
        }

        $visitAt = new \DateTimeImmutable($visit['pc_eventDate'] . ' ' . ($visit['pc_startTime'] ?: '00:00:00'));
        $facts = [xl('Visit') . ' ' . oeFormatShortDate($visitAt->format('Y-m-d')) . ' ' . oeFormatTime($visitAt->format('H:i:s'))];
        if ($pastDue !== []) {
            $facts[] = count($pastDue) . ' ' . xl('past due');
        }
        $facts[] = implode(', ', $names);

        return new WorkItem(
            key: new ItemKey(ItemKey::SOURCE_CAREGAP, $pid, (string)(int)$visit['pc_eid']),
            patient: PatientRef::fromRow($pid, $visit),
            title: xl('Care gaps to address at visit') . ': ' . count($names),
            detail: implode(' · ', $facts),
            priority: $this->policy->forCareGapVisit(),
            since: $visitAt,
            ownerId: ((int)$visit['pc_aid']) ?: null,
            actionLabel: xl('Open chart'),
            actionUrl: '',
            actionMode: 'chart',
            dismissible: true,
            siteId: ((int)($visit['pc_facility'] ?? 0)) ?: null,
        );
    }
}
