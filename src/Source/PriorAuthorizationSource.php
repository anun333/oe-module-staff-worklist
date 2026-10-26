<?php

/**
 * Prior authorizations that need someone (AuthorizationRules), from the table OpenEMR's own Prior
 * Authorizations module keeps (oe-module-prior-authorizations, module_prior_authorizations), when
 * that module is installed and enabled. It only reads; the button opens the chart, where that
 * module's page records a new authorization.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Source;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Model\PatientRef;
use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\PriorAuth\AuthorizationRules;

final class PriorAuthorizationSource implements WorkSourceInterface
{
    private const MODULE = 'oe-module-prior-authorizations';

    public function __construct(
        private readonly \DateTimeImmutable $now,
    ) {
    }

    /** Whether OpenEMR's Prior Authorizations module is installed and enabled here. */
    public static function available(): bool
    {
        $rows = QueryUtils::fetchRecords('SELECT 1 FROM `modules` WHERE `mod_directory` = ? AND `mod_active` = 1 LIMIT 1', [self::MODULE]);
        return $rows !== [];
    }

    public function id(): string
    {
        return ItemKey::SOURCE_AUTH;
    }

    public function collect(?int $pid = null): array
    {
        $today = $this->now->setTime(0, 0);
        $sql = "SELECT a.id, a.pid, a.auth_num, a.start_date, a.end_date, a.cpt, a.init_units, a.remaining_units, " . PatientRef::SQL_COLUMNS . "
            FROM module_prior_authorizations AS a
            JOIN patient_data AS pd ON pd.pid = a.pid
            WHERE (a.end_date IS NULL OR a.end_date >= ?) AND (a.start_date IS NULL OR a.start_date <= ?)"
            . ($pid === null ? '' : ' AND a.pid = ?');
        $binds = [$today->format('Y-m-d'), $today->format('Y-m-d')];
        if ($pid !== null) {
            $binds[] = $pid;
        }
        $items = [];
        foreach (QueryUtils::fetchRecords($sql, $binds) as $row) {
            $start = self::date($row['start_date'] ?? null);
            $end = self::date($row['end_date'] ?? null);
            $initial = is_numeric($row['init_units'] ?? null) ? (int)$row['init_units'] : null;
            $remaining = is_numeric($row['remaining_units'] ?? null) ? (int)$row['remaining_units'] : null;
            $decision = AuthorizationRules::decide($start, $end, $initial, $remaining, $today);
            $patient = PatientRef::fromRow((int)$row['pid'], $row, $this->now);
            if ($decision === null || $patient->deceased) {
                continue;
            }
            $number = trim((string)($row['auth_num'] ?? ''));
            $title = match ($decision['status']) {
                AuthorizationRules::USED_UP => sprintf(xl('Prior authorization %s: units used up'), $number),
                AuthorizationRules::ENDING => sprintf(xl('Prior authorization %1$s ends %2$s'), $number, oeFormatShortDate($end?->format('Y-m-d') ?? '')),
                default => sprintf(xl('Prior authorization %1$s: %2$d of %3$d units left'), $number, (int)$remaining, (int)$initial),
            };
            $facts = [];
            if (trim((string)($row['cpt'] ?? '')) !== '') {
                $facts[] = xl('Codes') . ' ' . trim((string)$row['cpt']);
            }
            if ($start !== null || $end !== null) {
                $facts[] = xl('In force') . ' ' . ($start !== null ? oeFormatShortDate($start->format('Y-m-d')) : '…') . ' – ' . ($end !== null ? oeFormatShortDate($end->format('Y-m-d')) : '…');
            }
            if ($remaining !== null && $initial !== null) {
                $facts[] = sprintf(xl('%1$d of %2$d units left'), $remaining, $initial);
            }
            $items[] = new WorkItem(
                // A renewed authorization is a new row, so a dismissed one comes back for the next; and the
                // condition is in the key, so dismissing "ends soon" never hides "units used up".
                key: new ItemKey(ItemKey::SOURCE_AUTH, (int)$row['pid'], AuthorizationRules::keyRef((int)$row['id'], $decision['status'])),
                patient: $patient,
                title: $title,
                detail: implode(' · ', $facts),
                priority: $decision['priority'],
                since: $decision['since'],
                ownerId: null,
                actionLabel: xl('Open chart'),
                actionUrl: '',
                actionMode: 'chart',
                dismissible: true,
                siteId: null,
                sensitivity: null,
            );
        }
        return $items;
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
