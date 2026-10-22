<?php

/**
 * Care management months met but not billed, from the Care Management module when it is installed
 * and enabled: an enrollment whose month is over, whose minutes met a code, and whose service has
 * nothing billed that month. It reads each month as that module's month list does (its MonthLines),
 * for the last three completed months. It only reads; the button opens the module's month list.
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
use OpenHealthcare\StaffWorklist\CareManagement\UnbilledMonths;
use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Model\PatientRef;
use OpenHealthcare\StaffWorklist\Model\WorkItem;

final class CareManagementUnbilledSource implements WorkSourceInterface
{
    private const MODULE = 'oe-module-care-management';
    private const LINES = 'OpenHealthcare\\CareManagement\\Repository\\MonthLines';
    private const MONTH = 'OpenHealthcare\\CareManagement\\Domain\\Month';
    private const LABELS = 'OpenHealthcare\\CareManagement\\Labels';
    private const ENROLLMENTS = 'OpenHealthcare\\CareManagement\\Repository\\EnrollmentRepository';
    /** BillingCheck::NOT_BILLED: the month is over, a code was met, nothing was billed. */
    private const NOT_BILLED = 'not_billed';

    public function __construct(
        private readonly \DateTimeImmutable $now,
    ) {
    }

    /** Whether the Care Management module is installed and enabled here, at a version that shares its month (0.3.2). */
    public static function available(): bool
    {
        $rows = QueryUtils::fetchRecords('SELECT 1 FROM `modules` WHERE `mod_directory` = ? AND `mod_active` = 1 LIMIT 1', [self::MODULE]);
        return $rows !== [] && class_exists(self::LINES) && class_exists(self::MONTH);
    }

    public function id(): string
    {
        return ItemKey::SOURCE_CAREMGMT;
    }

    public function collect(?int $pid = null): array
    {
        $today = $this->now->setTime(0, 0);
        $found = [];
        foreach (UnbilledMonths::previous($today) as $first) {
            $month = (self::MONTH)::of($first);
            foreach ((new (self::LINES)())->forMonth($month, true, $today) as $line) {
                $e = $line['e'];
                if (($line['check']['status'] ?? '') === self::NOT_BILLED && ($pid === null || $e->pid === $pid)) {
                    $found[] = [$first, $month, $line];
                }
            }
        }
        if ($found === []) {
            return [];
        }
        $pids = array_values(array_unique(array_map(static fn(array $f): int => $f[2]['e']->pid, $found)));
        $patients = [];
        foreach (QueryUtils::fetchRecords('SELECT pd.pid, ' . PatientRef::SQL_COLUMNS . ' FROM patient_data AS pd WHERE pd.pid IN ('
            . implode(', ', array_fill(0, count($pids), '?')) . ')', $pids) as $row) {
            $patients[(int)$row['pid']] = $row;
        }
        $practitioners = (new (self::ENROLLMENTS)())->practitioners();
        $items = [];
        foreach ($found as [$first, $month, $line]) {
            $e = $line['e'];
            if (!isset($patients[$e->pid])) {
                continue;
            }
            $met = [];
            foreach (array_filter($line['s']['routes']) as $route => $codes) {
                $met[] = (self::LABELS)::route($route, $e->service) . ': ' . (self::LABELS)::codes($codes);
            }
            $facts = [
                sprintf(xl('Clinical staff %1$d min, billing practitioner %2$d min'), (int)$line['s']['staff'], (int)$line['s']['practitioner']),
                xl('Met') . ': ' . implode('; ', $met),
            ];
            if (isset($practitioners[$e->practitionerId])) {
                $facts[] = xl('Billing practitioner') . ': ' . $practitioners[$e->practitionerId];
            }
            $items[] = new WorkItem(
                key: new ItemKey(ItemKey::SOURCE_CAREMGMT, $e->pid, UnbilledMonths::ref($e->id, $first)),
                patient: PatientRef::fromRow($e->pid, $patients[$e->pid], $this->now),
                title: sprintf(xl('%1$s month met, not billed: %2$s'), (self::LABELS)::service($e->service), xl($first->format('F')) . ' ' . $first->format('Y')),
                detail: implode(' · ', $facts),
                priority: WorkItem::PRIORITY_NORMAL,
                since: $month->last(),
                ownerId: $e->practitionerId > 0 ? $e->practitionerId : null,
                actionLabel: xl('Month list'),
                actionUrl: '/interface/modules/custom_modules/' . self::MODULE . '/public/index.php?month=' . $month->key(),
                actionMode: 'dialog',
                dismissible: true,
                siteId: null,
                sensitivity: null,
            );
        }
        return $items;
    }
}
