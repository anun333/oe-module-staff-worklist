<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Model\ItemState;
use OpenHealthcare\StaffWorklist\Model\SiteAccess;
use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\Worklist\WorklistAssembler;
use OpenHealthcare\StaffWorklist\Worklist\WorklistFilter;
use OpenHealthcare\StaffWorklist\Worklist\WorklistResult;
use PHPUnit\Framework\TestCase;

final class WorklistAssemblerTest extends TestCase
{
    private const ME = 5;
    private const OTHER = 6;

    private WorklistAssembler $assembler;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->assembler = new WorklistAssembler();
        $this->today = new \DateTimeImmutable('2026-10-06 10:00');
    }

    /**
     * @param WorkItem[]    $items
     * @param ItemState[]   $states
     */
    private function assembleWith(
        array $items,
        array $states,
        string $view = ItemState::STATUS_OPEN,
        string $scope = WorklistFilter::SCOPE_ALL,
        ?string $source = null,
        ?array $sites = null,
        ?SiteAccess $access = null,
    ): WorklistResult {
        $byKey = [];
        foreach ($states as $s) {
            $byKey[$s->key] = $s;
        }
        return $this->assembler->assemble(
            $items,
            $byKey,
            new WorklistFilter(self::ME, $view, $scope, $source, $sites),
            $this->today,
            $access ?? SiteAccess::unrestricted(self::ME),
        );
    }

    /** @return string[] */
    private static function keys(WorklistResult $r): array
    {
        return array_map(static fn($row) => (string)$row->item->key, $r->rows);
    }

    public function testItemWithoutStateIsOpen(): void
    {
        $r = $this->assembleWith([Items::item('order:1:1')], []);
        self::assertSame(['order:1:1'], self::keys($r));
        self::assertSame(ItemState::STATUS_OPEN, $r->rows[0]->status);
    }

    public function testFutureSnoozeHidesFromOpenAndShowsUnderSnoozed(): void
    {
        $items = [Items::item('order:1:1')];
        $states = [Items::state('order:1:1', ItemState::STATUS_SNOOZED, '2026-10-07')];
        self::assertSame([], self::keys($this->assembleWith($items, $states)));
        self::assertSame(['order:1:1'], self::keys($this->assembleWith($items, $states, ItemState::STATUS_SNOOZED)));
    }

    public function testSnoozeEndingTodayHasExpired(): void
    {
        $states = [Items::state('order:1:1', ItemState::STATUS_SNOOZED, '2026-10-06')];
        self::assertSame(['order:1:1'], self::keys($this->assembleWith([Items::item('order:1:1')], $states)));
    }

    public function testCriticalItemIgnoresSnooze(): void
    {
        $items = [Items::item('result:1:1', WorkItem::PRIORITY_CRITICAL, dismissible: false)];
        $states = [Items::state('result:1:1', ItemState::STATUS_SNOOZED, '2026-10-08')];
        self::assertSame(['result:1:1'], self::keys($this->assembleWith($items, $states)));
    }

    public function testDismissalIsIgnoredForUndismissibleItems(): void
    {
        $items = [Items::item('result:1:1', dismissible: false), Items::item('order:2:2')];
        $states = [
            Items::state('result:1:1', ItemState::STATUS_DISMISSED),
            Items::state('order:2:2', ItemState::STATUS_DISMISSED),
        ];
        self::assertSame(['result:1:1'], self::keys($this->assembleWith($items, $states)));
        self::assertSame(['order:2:2'], self::keys($this->assembleWith($items, $states, ItemState::STATUS_DISMISSED)));
    }

    public function testMineFallsBackToOwnerAndAssignmentOverridesIt(): void
    {
        $items = [
            Items::item('order:1:1', ownerId: self::ME),
            Items::item('order:2:2', ownerId: self::ME),
            Items::item('order:3:3', ownerId: self::OTHER),
            Items::item('order:4:4'),
        ];
        $states = [
            Items::state('order:2:2', assignedTo: self::OTHER),
            Items::state('order:3:3', assignedTo: self::ME),
        ];
        $mine = $this->assembleWith($items, $states, scope: WorklistFilter::SCOPE_MINE);
        self::assertSame(['order:1:1', 'order:3:3'], self::keys($mine));
        self::assertFalse($mine->rows[0]->explicitlyAssigned);
        self::assertTrue($mine->rows[1]->explicitlyAssigned);

        self::assertSame(['order:4:4'], self::keys($this->assembleWith($items, $states, scope: WorklistFilter::SCOPE_UNASSIGNED)));
    }

    public function testCountsCoverAllSourcesWhileRowsFollowSourceFilter(): void
    {
        $items = [Items::item('order:1:1'), Items::item('order:2:2'), Items::item('referral:3:3')];
        $r = $this->assembleWith($items, [], source: 'referral');
        self::assertSame(['referral:3:3'], self::keys($r));
        self::assertSame(['order' => 2, 'referral' => 1], $r->countsBySource);
        self::assertSame(3, $r->total());
    }

    public function testSortsByPriorityThenOldestFirst(): void
    {
        $items = [
            Items::item('order:1:1', WorkItem::PRIORITY_NORMAL, '2026-09-01'),
            Items::item('order:2:2', WorkItem::PRIORITY_HIGH, '2026-10-01'),
            Items::item('order:3:3', WorkItem::PRIORITY_HIGH, '2026-09-15'),
            Items::item('result:4:4', WorkItem::PRIORITY_CRITICAL, '2026-10-05', dismissible: false),
        ];
        self::assertSame(['result:4:4', 'order:3:3', 'order:2:2', 'order:1:1'], self::keys($this->assembleWith($items, [])));
    }

    public function testFilterFromRequestFallsBackOnUnknownValues(): void
    {
        $f = WorklistFilter::fromRequest(self::ME, ['view' => 'deleted', 'scope' => 'everyone', 'source' => 'notes']);
        self::assertSame(ItemState::STATUS_OPEN, $f->view);
        self::assertSame(WorklistFilter::SCOPE_MINE, $f->scope);
        self::assertNull($f->source);

        $f = WorklistFilter::fromRequest(self::ME, ['view' => 'snoozed', 'scope' => 'all', 'source' => 'caregap']);
        self::assertSame([ItemState::STATUS_SNOOZED, WorklistFilter::SCOPE_ALL, 'caregap'], [$f->view, $f->scope, $f->source]);
    }

    public function testRestrictedUserSeesOnlyAllowedSitesPlusOwnAndAssignedWork(): void
    {
        $items = [
            Items::item('order:1:1', siteId: 3),
            Items::item('order:2:2', siteId: 7),
            Items::item('order:3:3', ownerId: self::ME, siteId: 7),
            Items::item('order:4:4', siteId: 7),
            Items::item('order:5:5'),
        ];
        $states = [Items::state('order:4:4', assignedTo: self::ME)];
        $access = new SiteAccess(self::ME, true, [3]);
        $r = $this->assembleWith($items, $states, access: $access);
        self::assertSame(['order:1:1', 'order:3:3', 'order:4:4'], self::keys($r));
        self::assertSame(3, $r->total(), 'hidden work is not counted');
        self::assertSame([3 => 1, 7 => 2], $r->countsBySite);
    }

    public function testUnrestrictedUserSeesEverySiteIncludingUnknown(): void
    {
        $items = [Items::item('order:1:1', siteId: 3), Items::item('order:2:2')];
        $r = $this->assembleWith($items, []);
        self::assertSame(['order:1:1', 'order:2:2'], self::keys($r));
        self::assertSame([3 => 1, SiteAccess::NO_SITE => 1], $r->countsBySite);
    }

    public function testSiteSelectionNarrowsRowsAndSourceCountsButNotSiteCounts(): void
    {
        $items = [
            Items::item('order:1:1', siteId: 3),
            Items::item('referral:2:2', siteId: 7),
            Items::item('order:3:3'),
        ];
        $r = $this->assembleWith($items, [], sites: [7, SiteAccess::NO_SITE]);
        self::assertSame(['order:3:3', 'referral:2:2'], self::keys($r));
        self::assertSame(['referral' => 1, 'order' => 1], $r->countsBySource);
        self::assertSame([3 => 1, 7 => 1, SiteAccess::NO_SITE => 1], $r->countsBySite);
    }

    public function testParseSites(): void
    {
        self::assertNull(WorklistFilter::parseSites('all'));
        self::assertSame([3, 0, 12], WorklistFilter::parseSites('3,0,12,3'));
        self::assertFalse(WorklistFilter::parseSites(null));
        self::assertFalse(WorklistFilter::parseSites(''));
        self::assertFalse(WorklistFilter::parseSites('3,x'));
        self::assertFalse(WorklistFilter::parseSites('3,-1'));
        self::assertFalse(WorklistFilter::parseSites(['3']));
        self::assertFalse(WorklistFilter::parseSites("3\n"));
    }
}
