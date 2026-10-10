<?php

/**
 * Orders past their follow-up window with no final result.
 *
 * Orders that have an unsigned report are left to ResultsAwaitingReviewSource
 * so the same order is never listed twice. The window depends on order
 * priority; see PriorityPolicy::orderThresholdDays().
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Source;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Model\PatientRef;
use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\Policy\PriorityPolicy;

final class OrdersMissingResultsSource implements WorkSourceInterface
{
    public function __construct(
        private readonly PriorityPolicy $policy,
        private readonly \DateTimeImmutable $now,
    ) {
    }

    public function id(): string
    {
        return ItemKey::SOURCE_ORDER;
    }

    public function collect(?int $pid = null): array
    {
        // Fetch everything past the shortest window; forOrder() applies the per-priority one.
        // An order placed before midnight (shortest - 1) days ago is at least `shortest` days old.
        $shortest = min($this->policy->urgentOrderDays, $this->policy->routineOrderDays);
        $cutoff = $this->now->setTime(0, 0)->modify('-' . ($shortest - 1) . ' days')->format('Y-m-d H:i:s');
        $binds = [$cutoff];
        $patientFilter = '';
        if ($pid !== null) {
            $patientFilter = 'AND po.patient_id = ?';
            $binds[] = $pid;
        }

        // Site: the visit the order was placed in, else the ordering provider's home facility.
        $sql = "SELECT po.procedure_order_id, po.patient_id, po.provider_id, po.date_ordered,
                po.order_priority, po.date_transmitted, pp.name AS lab_name,
                COALESCE(NULLIF(fe.facility_id, 0), NULLIF(ou.facility_id, 0)) AS site_id,
                fe.sensitivity, " . PatientRef::SQL_COLUMNS . ",
                (SELECT GROUP_CONCAT(poc.procedure_name ORDER BY poc.procedure_order_seq SEPARATOR ', ')
                   FROM procedure_order_code AS poc
                  WHERE poc.procedure_order_id = po.procedure_order_id) AS test_names
            FROM procedure_order AS po
            JOIN patient_data AS pd ON pd.pid = po.patient_id
            LEFT JOIN procedure_providers AS pp ON pp.ppid = po.lab_id
            LEFT JOIN form_encounter AS fe ON fe.pid = po.patient_id AND fe.encounter = po.encounter_id
            LEFT JOIN users AS ou ON ou.id = po.provider_id
            WHERE po.activity = 1
              AND COALESCE(po.order_status, '') NOT IN ('complete', 'canceled')
              AND COALESCE(po.history_order, '0') <> '1'
              AND po.date_ordered IS NOT NULL
              AND po.date_ordered < ?
              AND NOT EXISTS (
                  SELECT 1 FROM procedure_report AS pr
                   WHERE pr.procedure_order_id = po.procedure_order_id
                     AND COALESCE(pr.report_status, '') IN ('final', 'correct', 'review'))
              AND NOT EXISTS (
                  SELECT 1 FROM procedure_report AS pr
                   WHERE pr.procedure_order_id = po.procedure_order_id
                     AND COALESCE(pr.review_status, '') <> 'reviewed'
                     AND COALESCE(pr.report_status, '') NOT IN ('cancel', 'error'))
              $patientFilter";

        $items = [];
        foreach (QueryUtils::fetchRecords($sql, $binds) as $row) {
            $item = $this->toItem($row);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toItem(array $row): ?WorkItem
    {
        $since = new \DateTimeImmutable((string)$row['date_ordered']);
        $orderPriority = (string)($row['order_priority'] ?? '');
        $priority = $this->policy->forOrder($orderPriority, PriorityPolicy::ageDays($since, $this->now));
        if ($priority === null) {
            return null;
        }

        $pid = (int)$row['patient_id'];
        $orderId = (int)$row['procedure_order_id'];
        $facts = [xl('Ordered') . ' ' . oeFormatShortDate($since->format('Y-m-d'))];
        if ($orderPriority !== '' && $this->policy->orderThresholdDays($orderPriority) === $this->policy->urgentOrderDays) {
            $facts[] = getListItemTitle('ord_priority', $orderPriority);
        }
        if (!empty($row['lab_name'])) {
            $facts[] = (string)$row['lab_name'];
        }
        if (empty($row['date_transmitted'])) {
            $facts[] = xl('Not marked as sent');
        }

        return new WorkItem(
            key: new ItemKey(ItemKey::SOURCE_ORDER, $pid, (string)$orderId),
            patient: PatientRef::fromRow($pid, $row, $this->now),
            title: xl('No result yet') . ': ' . ($row['test_names'] ?: xl('Order') . " #$orderId"),
            detail: implode(' · ', $facts),
            priority: $priority,
            since: $since,
            ownerId: ((int)$row['provider_id']) ?: null,
            actionLabel: xl('View order'),
            actionUrl: '/interface/orders/order_manifest.php?orderid=' . $orderId,
            actionMode: 'dialog',
            dismissible: true,
            siteId: ((int)$row['site_id']) ?: null,
            sensitivity: is_string($row['sensitivity'] ?? null) ? $row['sensitivity'] : null,
        );
    }
}
