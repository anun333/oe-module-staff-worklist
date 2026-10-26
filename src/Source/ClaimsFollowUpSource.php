<?php

/**
 * Claims that need billing staff (ClaimRules): denials OpenEMR recorded from a
 * remittance without posting anything, claim files its SFTP upload never sent, claims
 * billed with no payer response, and lines flagged for follow-up while posting. Read from
 * claims, ar_activity, x12_remote_tracker and form_encounter; the worklist only lists them,
 * and the work is done in core's posting page or its Claim File Tracker.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Source;

use OpenEMR\Billing\BillingUtilities;
use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\StaffWorklist\Claims\ClaimRecord;
use OpenHealthcare\StaffWorklist\Claims\ClaimRules;
use OpenHealthcare\StaffWorklist\Claims\ClaimUpload;
use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Model\PatientRef;
use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\Policy\PriorityPolicy;

final class ClaimsFollowUpSource implements WorkSourceInterface
{
    private readonly ClaimRules $rules;

    public function __construct(
        PriorityPolicy $policy,
        private readonly \DateTimeImmutable $now,
    ) {
        $this->rules = new ClaimRules($policy, $now);
    }

    public function id(): string
    {
        return ItemKey::SOURCE_CLAIM;
    }

    public function collect(?int $pid = null): array
    {
        $since = $this->rules->windowStart()->format('Y-m-d H:i:s');
        $patientFilter = $pid === null ? '' : 'AND c.patient_id = ?';
        $encounterColumns = "fe.id AS fe_id, fe.date AS service_date, fe.facility_id AS site_id, fe.sensitivity,
                COALESCE(fe.last_level_closed, 0) AS last_level_closed, " . PatientRef::SQL_COLUMNS;

        // The latest version of each encounter's claim, and whether anything was posted at its level since.
        $sql = "SELECT c.patient_id AS pid, c.encounter_id AS encounter, c.version, c.status, c.payer_type, c.bill_time, c.process_file,
                ic.name AS payer_name,
                EXISTS (SELECT 1 FROM ar_activity AS a
                    WHERE a.pid = c.patient_id AND a.encounter = c.encounter_id AND a.deleted IS NULL
                      AND a.payer_type = c.payer_type AND a.post_time >= c.bill_time
                      AND (a.pay_amount <> 0 OR a.adj_amount <> 0)) AS posted_since,
                (SELECT MAX(a.post_time) FROM ar_activity AS a
                    WHERE a.pid = c.patient_id AND a.encounter = c.encounter_id AND a.deleted IS NULL
                      AND a.payer_type = c.payer_type AND a.post_time >= c.bill_time
                      AND (a.pay_amount <> 0 OR a.adj_amount <> 0)) AS last_posted,
                $encounterColumns
            FROM claims AS c
            JOIN (SELECT patient_id, encounter_id, MAX(version) AS version FROM claims GROUP BY patient_id, encounter_id) AS latest
              ON latest.patient_id = c.patient_id AND latest.encounter_id = c.encounter_id AND latest.version = c.version
            JOIN form_encounter AS fe ON fe.pid = c.patient_id AND fe.encounter = c.encounter_id
            JOIN patient_data AS pd ON pd.pid = c.patient_id
            LEFT JOIN insurance_companies AS ic ON ic.id = c.payer_id
            WHERE c.status IN (?, ?) AND c.bill_time >= ?
              $patientFilter";
        $binds = [ClaimRecord::STATUS_BILLED, ClaimRecord::STATUS_DENIED, $since];
        if ($pid !== null) {
            $binds[] = $pid;
        }
        $rows = [];
        foreach (QueryUtils::fetchRecords($sql, $binds) as $row) {
            $rows[$row['pid'] . ':' . $row['encounter']] = $row;
        }

        $flagSql = "SELECT a.pid, a.encounter, a.follow_up_note, a.post_time, $encounterColumns
            FROM ar_activity AS a
            JOIN form_encounter AS fe ON fe.pid = a.pid AND fe.encounter = a.encounter
            JOIN patient_data AS pd ON pd.pid = a.pid
            WHERE a.follow_up = 'y' AND a.deleted IS NULL AND a.post_time >= ?
              " . ($pid === null ? '' : 'AND a.pid = ?') . "
            ORDER BY a.post_time";
        $flags = [];
        foreach (QueryUtils::fetchRecords($flagSql, $pid === null ? [$since] : [$since, $pid]) as $row) {
            $key = $row['pid'] . ':' . $row['encounter'];
            $at = self::date($row['post_time'] ?? null);
            if ($at !== null) {
                $flags[$key][] = ['note' => trim((string)($row['follow_up_note'] ?? '')), 'at' => $at];
            }
            $rows[$key] ??= $row;
        }

        // Lines a remittance adjusted off ("Adjust code NN", as OpenEMR posts them) while paying
        // nothing for them in that remittance.
        $writeOffSql = "SELECT a.pid, a.encounter, a.payer_type, a.code, a.memo, a.adj_amount, a.post_time
            FROM ar_activity AS a
            WHERE a.deleted IS NULL AND a.payer_type > 0 AND a.adj_amount > 0 AND a.memo LIKE 'Adjust code %'
              AND a.post_time >= ? " . ($pid === null ? '' : 'AND a.pid = ?') . "
              AND NOT EXISTS (SELECT 1 FROM ar_activity AS p
                  WHERE p.pid = a.pid AND p.encounter = a.encounter AND p.code = a.code AND p.session_id = a.session_id
                    AND p.payer_type = a.payer_type AND p.deleted IS NULL AND p.pay_amount <> 0)
            ORDER BY a.post_time";
        $writeOffs = [];
        foreach (QueryUtils::fetchRecords($writeOffSql, $pid === null ? [$since] : [$since, $pid]) as $row) {
            $reason = ClaimRules::writeOffReason((string)$row['memo']);
            $at = self::date($row['post_time'] ?? null);
            if ($reason !== null && $at !== null) {
                $writeOffs[$row['pid'] . ':' . $row['encounter']][] = ['level' => (int)$row['payer_type'], 'code' => trim((string)$row['code']),
                    'reason' => $reason, 'amount' => (float)$row['adj_amount'], 'at' => $at];
            }
        }
        $uploads = self::uploads($since);
        $items = [];
        foreach ($rows as $key => $row) {
            $versionTime = self::date($row['bill_time'] ?? null);
            $level = (int)($row['payer_type'] ?? 0);
            // Only write-offs at the level billed, posted since this claim version.
            $lines = array_values(array_map(
                static fn(array $w): array => ['code' => $w['code'], 'reason' => $w['reason'], 'amount' => $w['amount'], 'at' => $w['at']],
                array_filter($writeOffs[$key] ?? [], static fn(array $w): bool => $w['level'] === $level && $versionTime !== null && $w['at'] >= $versionTime),
            ));
            $claim = new ClaimRecord(
                (int)$row['pid'],
                (int)$row['encounter'],
                isset($row['status']) ? (int)$row['status'] : null,
                (int)($row['payer_type'] ?? 0),
                trim((string)($row['payer_name'] ?? '')),
                self::date($row['bill_time'] ?? null),
                (string)($row['process_file'] ?? ''),
                (int)$row['last_level_closed'],
                (int)($row['posted_since'] ?? 0) === 1,
                $flags[$key] ?? [],
                self::date($row['last_posted'] ?? null),
                $lines,
                $uploads[$key] ?? null,
                (int)($row['version'] ?? 0),
            );
            $decision = $this->rules->decide($claim);
            if ($decision !== null) {
                $items[] = $this->toItem($claim, $decision, $row);
            }
        }
        return $items;
    }

    /**
     * @param array{kind: string, priority: int, since: \DateTimeImmutable, flagged: bool} $decision
     * @param array<string, mixed>                                                      $row
     */
    private function toItem(ClaimRecord $claim, array $decision, array $row): WorkItem
    {
        $payer = $claim->payer !== '' ? $claim->payer : xl('the insurer');
        $facts = [xl('Service date') . ' ' . oeFormatShortDate(substr((string)($row['service_date'] ?? ''), 0, 10))];
        $title = match ($decision['kind']) {
            ClaimRules::DENIED => xl('Claim denied by') . ' ' . $payer,
            ClaimRules::NO_RESPONSE => xl('No response from') . ' ' . $payer . ' ' . xl('in') . ' '
                . PriorityPolicy::ageDays($decision['since'], $this->now) . ' ' . xl('days'),
            ClaimRules::WRITTEN_OFF => xl('Line written off with a denial reason by') . ' ' . $payer,
            ClaimRules::UPLOAD_FAILED => xl('Claim file not sent to') . ' ' . self::partner($claim),
            ClaimRules::NOT_SENT => xl('Claim file still waiting to be sent to') . ' ' . self::partner($claim) . ' ' . xl('after') . ' '
                . PriorityPolicy::ageHours($decision['since'], $this->now) . ' ' . xl('hours'),
            ClaimRules::PARTLY_POSTED => xl('Remittance posted, insurance still open after') . ' '
                . PriorityPolicy::ageDays($decision['since'], $this->now) . ' ' . xl('days'),
            default => xl('Flagged for follow-up'),
        };
        if ($decision['kind'] === ClaimRules::DENIED) {
            $facts[] = xl('Denied') . ' ' . oeFormatShortDate($decision['since']->format('Y-m-d'));
            $descriptions = BillingUtilities::CLAIM_ADJUSTMENT_REASON_CODES;
            $reasons = ClaimRules::parseReasons($claim->reasons);
            foreach ($reasons as $r) {
                $line = trim($r['code'] . ($r['modifier'] !== '' ? '-' . $r['modifier'] : '')) . ': ' . $r['group'] . '-' . $r['reason'];
                $description = $descriptions[$r['reason']] ?? null;
                $facts[] = $line . (is_string($description) ? ' ' . ClaimRules::shortDescription($description) : '');
            }
            if ($reasons === []) {
                $facts[] = xl('No reason recorded');
            }
        } elseif ($decision['kind'] === ClaimRules::WRITTEN_OFF) {
            $descriptions = BillingUtilities::CLAIM_ADJUSTMENT_REASON_CODES;
            foreach ($claim->writeOffs as $w) {
                $description = $descriptions[$w['reason']] ?? null;
                $facts[] = $w['code'] . ': ' . xl('reason') . ' ' . $w['reason'] . (is_string($description) ? ' ' . ClaimRules::shortDescription($description) : '')
                    . ', ' . sprintf('%.2f', $w['amount']) . ' ' . xl('written off') . ' ' . oeFormatShortDate($w['at']->format('Y-m-d'));
            }
        } elseif ($decision['kind'] === ClaimRules::PARTLY_POSTED) {
            $facts[] = xl('Last posted') . ' ' . oeFormatShortDate($decision['since']->format('Y-m-d'));
            $facts[] = xl('A line OpenEMR could not post stops the rest of the claim from posting and leaves the insurance open');
        } elseif (in_array($decision['kind'], [ClaimRules::UPLOAD_FAILED, ClaimRules::NOT_SENT], true) && $claim->upload !== null) {
            $upload = $claim->upload;
            $facts[] = xl('Queued') . ' ' . oeFormatShortDate($upload['at']->format('Y-m-d'));
            if ($decision['kind'] === ClaimRules::UPLOAD_FAILED) {
                $facts[] = xl('Upload status') . ' ' . $upload['status'];
            }
            if ($upload['file'] !== '') {
                $facts[] = xl('File') . ' ' . $upload['file'];
            }
            if ($upload['message'] !== '') {
                $facts[] = $upload['message'];
            }
            if ($decision['kind'] === ClaimRules::NOT_SENT) {
                $facts[] = xl('The SFTP background service may not be running');
            }
        } elseif ($decision['kind'] === ClaimRules::NO_RESPONSE && $claim->versionTime !== null) {
            $facts[] = xl('Billed') . ' ' . oeFormatShortDate($claim->versionTime->format('Y-m-d'));
        }
        foreach ($claim->flags as $flag) {
            $facts[] = xl('Flagged') . ' ' . oeFormatShortDate($flag['at']->format('Y-m-d')) . ($flag['note'] !== '' ? ': ' . $flag['note'] : '');
        }

        $tracker = in_array($decision['kind'], [ClaimRules::UPLOAD_FAILED, ClaimRules::NOT_SENT], true);
        return new WorkItem(
            // The condition and the claim version are in the key, so a dismissed denial never hides the
            // upload failure after it, or the denial of the claim billed again.
            key: new ItemKey(ItemKey::SOURCE_CLAIM, $claim->pid, ClaimRules::keyRef($claim->encounter, $decision['kind'], $claim->version)),
            patient: PatientRef::fromRow($claim->pid, $row, $this->now),
            title: $title,
            detail: implode(' · ', $facts),
            priority: $decision['priority'],
            since: $decision['since'],
            ownerId: null,
            actionLabel: $tracker ? xl('Open claim file tracker') : xl('Open posting'),
            actionUrl: $tracker ? '/interface/billing/billing_tracker.php' : '/interface/billing/sl_eob_invoice.php?id=' . (int)$row['fe_id'],
            actionMode: 'window',
            dismissible: true,
            siteId: ((int)($row['site_id'] ?? 0)) ?: null,
            sensitivity: is_string($row['sensitivity'] ?? null) ? $row['sensitivity'] : null,
        );
    }

    /**
     * The latest SFTP upload of each claim queued since the window started, keyed "pid:encounter".
     * Empty when the site has no x12_remote_tracker table.
     *
     * @return array<string, array{status: string, at: \DateTimeImmutable, file: string, partner: string, message: string}>
     */
    private static function uploads(string $since): array
    {
        $exists = QueryUtils::fetchRecords(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            ['x12_remote_tracker']
        );
        if ($exists === []) {
            return [];
        }
        return ClaimUpload::latestByClaim(QueryUtils::fetchRecords(
            "SELECT r.id, r.status, r.x12_filename, r.messages, r.claims, r.created_at, p.name AS partner_name
            FROM x12_remote_tracker AS r
            LEFT JOIN x12_partners AS p ON p.id = r.x12_partner_id
            WHERE r.created_at >= ?",
            [$since]
        ));
    }

    private static function partner(ClaimRecord $claim): string
    {
        $name = $claim->upload['partner'] ?? '';
        return $name !== '' ? $name : xl('the clearinghouse');
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
