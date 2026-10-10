<?php

/**
 * Outgoing referrals with no reply recorded after the follow-up window.
 *
 * Reads the stock referral transaction (LBTref) the same way
 * Reports > Clients > Referrals does. The item clears when someone fills in
 * the referral's Reply Date.
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

final class ReferralsAwaitingReplySource implements WorkSourceInterface
{
    public function __construct(
        private readonly PriorityPolicy $policy,
        private readonly \DateTimeImmutable $now,
    ) {
    }

    public function id(): string
    {
        return ItemKey::SOURCE_REFERRAL;
    }

    public function collect(?int $pid = null): array
    {
        $cutoff = $this->now->modify("-{$this->policy->referralDays} days")->format('Y-m-d');
        $binds = [$cutoff];
        $patientFilter = '';
        if ($pid !== null) {
            $patientFilter = 'AND t.pid = ?';
            $binds[] = $pid;
        }

        // Site: the referring provider's home facility, as Reports > Referrals filters it.
        $sql = "SELECT t.id, t.pid,
                d1.field_value AS refer_date, d8.field_value AS refer_from,
                dr.field_value AS risk_level, dd.field_value AS refer_diag,
                ut.organization AS to_org, ut.fname AS to_fname, ut.lname AS to_lname,
                uf.facility_id AS site_id,
                " . PatientRef::SQL_COLUMNS . "
            FROM transactions AS t
            JOIN patient_data AS pd ON pd.pid = t.pid
            JOIN lbt_data AS d1 ON d1.form_id = t.id AND d1.field_id = 'refer_date'
            LEFT JOIN lbt_data AS d3 ON d3.form_id = t.id AND d3.field_id = 'reply_date'
            LEFT JOIN lbt_data AS d7 ON d7.form_id = t.id AND d7.field_id = 'refer_to'
            LEFT JOIN lbt_data AS d8 ON d8.form_id = t.id AND d8.field_id = 'refer_from'
            LEFT JOIN lbt_data AS dr ON dr.form_id = t.id AND dr.field_id = 'refer_risk_level'
            LEFT JOIN lbt_data AS dd ON dd.form_id = t.id AND dd.field_id = 'refer_diag'
            LEFT JOIN users AS ut ON ut.id = d7.field_value
            LEFT JOIN users AS uf ON uf.id = d8.field_value
            WHERE t.title = 'LBTref'
              AND COALESCE(d3.field_value, '') = ''
              AND d1.field_value <> ''
              AND d1.field_value <= ?
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
        $since = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string)$row['refer_date'], 0, 10));
        if ($since === false) {
            return null;
        }
        $priority = $this->policy->forReferral((string)($row['risk_level'] ?? ''), PriorityPolicy::ageDays($since, $this->now));
        if ($priority === null) {
            return null;
        }

        $pid = (int)$row['pid'];
        $transId = (int)$row['id'];
        $recipient = trim((string)($row['to_org'] ?? ''))
            ?: trim(($row['to_fname'] ?? '') . ' ' . ($row['to_lname'] ?? ''))
            ?: xl('Unknown recipient');

        $facts = [xl('Referred') . ' ' . oeFormatShortDate($since->format('Y-m-d'))];
        if (!empty($row['refer_diag'])) {
            $facts[] = (string)$row['refer_diag'];
        }
        if (strtolower((string)($row['risk_level'] ?? '')) === 'high') {
            $facts[] = xl('High risk');
        }

        return new WorkItem(
            key: new ItemKey(ItemKey::SOURCE_REFERRAL, $pid, (string)$transId),
            patient: PatientRef::fromRow($pid, $row, $this->now),
            title: xl('No referral reply') . ': ' . $recipient,
            detail: implode(' · ', $facts),
            priority: $priority,
            since: $since,
            ownerId: ((int)($row['refer_from'] ?? 0)) ?: null,
            actionLabel: xl('Record reply'),
            actionUrl: '/interface/patient_file/transaction/add_transaction.php?transid=' . $transId . '&title=LBTref&inmode=edit',
            actionMode: 'chart',
            dismissible: true,
            siteId: ((int)($row['site_id'] ?? 0)) ?: null,
        );
    }
}
