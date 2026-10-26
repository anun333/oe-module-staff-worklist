<?php

/**
 * Runs the rules engine for scheduled patients and fills CareGapCache.
 *
 * Called by the background service every few minutes and by "Check now".
 * Works soonest visit first and stops at a time budget, so a busy schedule
 * is covered over successive runs instead of one very long request.
 *
 * Requires library/clinical_rules.php.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\CareGaps;

use OpenHealthcare\StaffWorklist\Policy\PriorityPolicy;
use OpenHealthcare\StaffWorklist\Repository\UpcomingVisits;
use OpenHealthcare\Common\OpenEmr;

final class CareGapRefresher
{
    public const FRESH_MINUTES = 10;

    public function __construct(
        private readonly UpcomingVisits $visits,
        private readonly CareGapCache $cache,
        private readonly PriorityPolicy $policy,
    ) {
    }

    public static function create(\DateTimeImmutable $now): self
    {
        require_once OpenEmr::srcDir() . '/clinical_rules.php';
        return new self(new UpcomingVisits($now), new CareGapCache(), new PriorityPolicy());
    }

    /**
     * @return array{refreshed: int, remaining: int}
     */
    public function refresh(int $budgetSeconds): array
    {
        $started = microtime(true);
        $now = new \DateTimeImmutable();
        $scheduled = $this->visits->nextVisitByPatient();
        $cached = $this->cache->forPatients(array_keys($scheduled));
        $freshAfter = $now->modify('-' . self::FRESH_MINUTES . ' minutes');

        $due = [];
        foreach (array_keys($scheduled) as $pid) {
            if (!isset($cached[$pid]) || $cached[$pid]['computedAt'] < $freshAfter) {
                $due[] = $pid;
            }
        }

        $refreshed = 0;
        foreach ($due as $pid) {
            if (microtime(true) - $started > $budgetSeconds) {
                break;
            }
            $this->cache->save($pid, $this->gapsFor($pid, $now), new \DateTimeImmutable());
            $refreshed++;
        }

        $this->cache->purgeExcept(array_keys($scheduled), $now->modify('-2 days'));
        return ['refreshed' => $refreshed, 'remaining' => count($due) - $refreshed];
    }

    /**
     * The same call the chart's Clinical Reminders widget makes, without a user
     * filter: the job runs as no one, so it records every active rule's reminders.
     *
     * @return list<array{item: string, due: string}>
     */
    private function gapsFor(int $pid, \DateTimeImmutable $now): array
    {
        $actions = test_rules_clinic('', 'passive_alert', $now->format('Y-m-d H:i:s'), 'reminders-due', $pid);
        $gaps = [];
        foreach ($actions as $action) {
            $due = (string)($action['due_status'] ?? '');
            $item = (string)($action['item'] ?? '');
            // Two rules can name the same action; keep the more overdue status.
            if ($item !== '' && $this->policy->isCareGap($due) && ($gaps[$item]['due'] ?? '') !== 'past_due') {
                $gaps[$item] = ['item' => $item, 'due' => $due];
            }
        }
        return array_values($gaps);
    }
}
