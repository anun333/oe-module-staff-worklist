<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Model\PatientRef;
use OpenHealthcare\StaffWorklist\Model\SiteAccess;
use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\Policy\PriorityPolicy;
use OpenHealthcare\StaffWorklist\Policy\SensitivityPolicy;
use OpenHealthcare\StaffWorklist\Worklist\WorklistAssembler;
use OpenHealthcare\StaffWorklist\Worklist\WorklistFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sensitivity masking, critical-result overdue rule, deceased flag.
 */
final class ComplianceRulesTest extends TestCase
{
    /** @return array<string, array{?string, list<string>, bool}> */
    public static function sensitivityCases(): array
    {
        return [
            'no encounter' => [null, [], true],
            'blank sensitivity' => ['', [], true],
            'normal granted' => ['normal', ['normal'], true],
            'high not granted' => ['high', ['normal'], false],
            'high granted' => ['high', ['normal', 'high'], true],
        ];
    }

    /** @param list<string> $granted */
    #[DataProvider('sensitivityCases')]
    public function testSensitivityPolicy(?string $sensitivity, array $granted, bool $expected): void
    {
        self::assertSame($expected, SensitivityPolicy::isPermitted($sensitivity, static fn(string $s): bool => in_array($s, $granted, true)));
    }

    public function testAclIsNotConsultedWithoutASensitivity(): void
    {
        $called = false;
        SensitivityPolicy::isPermitted(null, static function () use (&$called): bool {
            $called = true;
            return false;
        });
        self::assertFalse($called);
    }

    public function testAgeHoursIsWholeHoursAndNeverNegative(): void
    {
        $now = new \DateTimeImmutable('2026-10-06 12:00');
        self::assertSame(3, PriorityPolicy::ageHours(new \DateTimeImmutable('2026-10-06 08:30'), $now));
        self::assertSame(0, PriorityPolicy::ageHours(new \DateTimeImmutable('2026-10-06 13:00'), $now));
        self::assertSame(26, PriorityPolicy::ageHours(new \DateTimeImmutable('2026-10-05 10:00'), $now));
    }

    public function testOnlyPanicResultsBecomeOverdueAndOnlyAtTheLimit(): void
    {
        $policy = new PriorityPolicy();
        self::assertFalse($policy->isCriticalOverdue(PriorityPolicy::ABNORMAL_PANIC, 3));
        self::assertTrue($policy->isCriticalOverdue(PriorityPolicy::ABNORMAL_PANIC, 4));
        self::assertFalse($policy->isCriticalOverdue(PriorityPolicy::ABNORMAL_FLAGGED, 48));
        self::assertTrue((new PriorityPolicy(criticalOverdueHours: 1))->isCriticalOverdue(PriorityPolicy::ABNORMAL_PANIC, 1));
    }

    public function testDeceasedFlag(): void
    {
        $now = new \DateTimeImmutable('2026-10-06 12:00');
        self::assertTrue(PatientRef::isDeceased('2026-10-05 09:00:00', $now));
        self::assertFalse(PatientRef::isDeceased('2026-10-07 09:00:00', $now), 'a future date is a data error, not a death');
        self::assertFalse(PatientRef::isDeceased(null, $now));
        self::assertFalse(PatientRef::isDeceased('', $now));
        self::assertFalse(PatientRef::isDeceased('0000-00-00 00:00:00', $now));
        self::assertFalse(PatientRef::isDeceased('not a date', $now));
    }

    public function testPatientRefReadsFlagsFromRow(): void
    {
        $p = PatientRef::fromRow(7, [
            'fname' => 'Jan', 'lname' => 'Doe', 'DOB' => '1970-01-01', 'pubpid' => 'X7',
            'deceased_date' => '2026-01-01 00:00:00', 'portal_access' => '1',
        ], new \DateTimeImmutable('2026-10-06'));
        self::assertTrue($p->deceased);
        self::assertTrue($p->portalAccess);
        self::assertSame('Doe, Jan', $p->name);
    }

    public function testOverdueCountIgnoresFiltersButNotSiteAccess(): void
    {
        $overdueHere = self::overdueItem('result:1:1', 3);
        $overdueElsewhere = self::overdueItem('result:2:2', 7);
        $normal = Items::item('order:3:3', siteId: 3);

        $result = (new WorklistAssembler())->assemble(
            [$overdueHere, $overdueElsewhere, $normal],
            [],
            new WorklistFilter(5, scope: WorklistFilter::SCOPE_MINE, source: 'order', sites: [3]),
            new \DateTimeImmutable('2026-10-06'),
            new SiteAccess(5, true, [3]),
        );
        self::assertSame(1, $result->overdueCount, 'counted although Mine/order filters hide it; site 7 is not visible');
        self::assertSame([], $result->rows);
    }

    private static function overdueItem(string $key, int $siteId): WorkItem
    {
        $base = Items::item($key, WorkItem::PRIORITY_CRITICAL, dismissible: false, siteId: $siteId);
        return new WorkItem(
            key: $base->key,
            patient: $base->patient,
            title: $base->title,
            detail: $base->detail,
            priority: $base->priority,
            since: $base->since,
            ownerId: $base->ownerId,
            actionLabel: $base->actionLabel,
            actionUrl: $base->actionUrl,
            actionMode: $base->actionMode,
            dismissible: $base->dismissible,
            siteId: $base->siteId,
            overdue: true,
        );
    }
}
