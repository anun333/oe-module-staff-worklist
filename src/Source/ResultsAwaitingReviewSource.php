<?php

/**
 * Received results that no clinician has signed.
 *
 * Same condition as "Received, unreviewed" in Procedures > Patient Results,
 * grouped per order because results are signed per order in
 * single_order_results.php. The item clears when that sign-off sets
 * procedure_report.review_status = 'reviewed'.
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

final class ResultsAwaitingReviewSource implements WorkSourceInterface
{
    /**
     * @param bool $portalEnabled whether OpenEMR's patient portal is turned on (portal_onsite_two_enable)
     */
    public function __construct(
        private readonly PriorityPolicy $policy,
        private readonly \DateTimeImmutable $now,
        private readonly bool $portalEnabled = false,
    ) {
    }

    public function id(): string
    {
        return ItemKey::SOURCE_RESULT;
    }

    public function collect(?int $pid = null): array
    {
        $binds = [];
        $patientFilter = '';
        if ($pid !== null) {
            $patientFilter = 'AND po.patient_id = ?';
            $binds[] = $pid;
        }

        // Site: the visit the order was placed in, else the ordering provider's home facility.
        $sql = "SELECT r.procedure_order_id, r.first_report, r.report_statuses, r.abnormal_flags,
                po.patient_id, po.provider_id, po.date_ordered,
                COALESCE(NULLIF(fe.facility_id, 0), NULLIF(ou.facility_id, 0)) AS site_id,
                fe.sensitivity, " . PatientRef::SQL_COLUMNS . ",
                (SELECT GROUP_CONCAT(poc.procedure_name ORDER BY poc.procedure_order_seq SEPARATOR ', ')
                   FROM procedure_order_code AS poc
                  WHERE poc.procedure_order_id = po.procedure_order_id) AS test_names
            FROM (
                SELECT pr.procedure_order_id,
                       COALESCE(MIN(pr.date_report), MIN(pr.date_collected)) AS first_report,
                       GROUP_CONCAT(DISTINCT pr.report_status) AS report_statuses,
                       GROUP_CONCAT(DISTINCT ps.abnormal) AS abnormal_flags
                  FROM procedure_report AS pr
                  LEFT JOIN procedure_result AS ps ON ps.procedure_report_id = pr.procedure_report_id
                 WHERE COALESCE(pr.review_status, '') <> 'reviewed'
                   AND COALESCE(pr.report_status, '') NOT IN ('cancel', 'error')
                 GROUP BY pr.procedure_order_id
            ) AS r
            JOIN procedure_order AS po ON po.procedure_order_id = r.procedure_order_id
            JOIN patient_data AS pd ON pd.pid = po.patient_id
            LEFT JOIN form_encounter AS fe ON fe.pid = po.patient_id AND fe.encounter = po.encounter_id
            LEFT JOIN users AS ou ON ou.id = po.provider_id
            WHERE po.activity = 1 $patientFilter";

        $items = [];
        foreach (QueryUtils::fetchRecords($sql, $binds) as $row) {
            $items[] = $this->toItem($row);
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toItem(array $row): WorkItem
    {
        $pid = (int)$row['patient_id'];
        $orderId = (int)$row['procedure_order_id'];
        $since = new \DateTimeImmutable((string)($row['first_report'] ?: $row['date_ordered'] ?: 'now'));

        $abnormal = PriorityPolicy::ABNORMAL_NONE;
        foreach (explode(',', (string)$row['abnormal_flags']) as $flag) {
            $abnormal = max($abnormal, PriorityPolicy::abnormalLevel($flag));
        }

        $patient = PatientRef::fromRow($pid, $row, $this->now);
        $ageHours = PriorityPolicy::ageHours($since, $this->now);
        $overdue = $this->policy->isCriticalOverdue($abnormal, $ageHours);

        $facts = [xl('Reported') . ' ' . oeFormatShortDate($since->format('Y-m-d'))];
        if ($overdue) {
            $facts[] = xl('Unsigned for') . ' ' . $ageHours . ' ' . xl('hours, past the')
                . ' ' . $this->policy->criticalOverdueHours . ' ' . xl('hour limit');
        }
        if (in_array('prelim', explode(',', (string)$row['report_statuses']), true)) {
            $facts[] = xl('Preliminary');
        }
        if ($abnormal === PriorityPolicy::ABNORMAL_PANIC) {
            $facts[] = xl('Panic value');
        } elseif ($abnormal === PriorityPolicy::ABNORMAL_FLAGGED) {
            $facts[] = xl('Abnormal');
        }
        // The portal shows results whether or not anyone has signed them (portal/get_lab_results.php).
        if ($this->portalEnabled && $patient->portalAccess && $abnormal > PriorityPolicy::ABNORMAL_NONE) {
            $facts[] = xl('Patient can already see this in the portal');
        }

        return new WorkItem(
            key: new ItemKey(ItemKey::SOURCE_RESULT, $pid, (string)$orderId),
            patient: $patient,
            title: xl('Result to review') . ': ' . ($row['test_names'] ?: xl('Order') . " #$orderId"),
            detail: implode(' · ', $facts),
            priority: $this->policy->forResult($abnormal, PriorityPolicy::ageDays($since, $this->now)),
            since: $since,
            ownerId: ((int)$row['provider_id']) ?: null,
            actionLabel: xl('Review and sign'),
            actionUrl: '/interface/orders/single_order_results.php?orderid=' . $orderId,
            // Core opens this page as a popup and its Close button calls window.close().
            actionMode: 'window',
            dismissible: false,
            siteId: ((int)$row['site_id']) ?: null,
            sensitivity: is_string($row['sensitivity'] ?? null) ? $row['sensitivity'] : null,
            overdue: $overdue,
        );
    }
}
