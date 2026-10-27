<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\Common\Contract\Worklist\ItemKey as SharedKey;
use OpenHealthcare\Common\Contract\Worklist\PatientRef as SharedPatient;
use OpenHealthcare\Common\Contract\Worklist\WorkItem as SharedItem;
use OpenHealthcare\Common\Contract\Worklist\Worklist;
use OpenHealthcare\Common\Contract\Worklist\WorkSource;
use OpenHealthcare\StaffWorklist\Event\CollectSourcesEvent;
use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\Source\WorkSourceInterface;
use PHPUnit\Framework\TestCase;

final class CollectSourcesEventTest extends TestCase
{
    private static function source(string $id): WorkSourceInterface
    {
        return new class ($id) implements WorkSourceInterface {
            public function __construct(private readonly string $sourceId)
            {
            }

            public function id(): string
            {
                return $this->sourceId;
            }

            public function collect(?int $pid = null): array
            {
                return [];
            }
        };
    }

    public function testAModuleAddsATabWithItsLabelAndOwnerRole(): void
    {
        $event = new CollectSourcesEvent(new \DateTimeImmutable('2026-10-22'));
        $event->addSource(self::source('caremgmt'), 'Care management, not billed', 'Billing practitioner');
        $added = $event->added();
        $this->assertSame(['caremgmt'], array_keys($added));
        $this->assertSame(['Care management, not billed', 'Billing practitioner'], [$added['caremgmt']['label'], $added['caremgmt']['ownerRole']]);
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield "one of the worklist's own" => ['screen'];
        yield 'too short' => ['ab'];
        yield 'upper case' => ['CareMgmt'];
        yield 'a hyphen' => ['care-mgmt'];
        yield 'starts with a digit' => ['1care'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testASourceIdThatWouldClashOrBreakAKeyIsRefused(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CollectSourcesEvent(new \DateTimeImmutable()))->addSource(self::source($id), 'X', 'Y');
    }

    /** A tab built on openhealthcare-common's contract, as Behavioral Health's is from 0.3.0. */
    private static function sharedSource(string $id): WorkSource
    {
        return new class ($id) implements WorkSource {
            public function __construct(private readonly string $sourceId)
            {
            }

            public function id(): string
            {
                return $this->sourceId;
            }

            public function collect(?int $pid = null): array
            {
                $items = [];
                foreach ([7 => '42', 9 => '43'] as $patient => $ref) {
                    if ($pid === null || $pid === $patient) {
                        $items[] = new SharedItem(
                            new SharedKey($this->sourceId, $patient, $ref),
                            new SharedPatient($patient, 'Doe, Jan', '1980-02-03', "P$patient"),
                            'Treatment plan review due',
                            'Last reviewed 2026-07-01',
                            SharedItem::PRIORITY_HIGH,
                            new \DateTimeImmutable('2026-10-01'),
                            3,
                            'Open plan',
                            '/interface/patient_file/summary/demographics.php',
                            'chart',
                            true,
                            siteId: 4,
                        );
                    }
                }
                return $items;
            }
        };
    }

    public function testATabOnTheSharedContractIsAddedAndItsItemsBecomeTheWorklists(): void
    {
        $event = new CollectSourcesEvent(new \DateTimeImmutable('2026-10-26'));
        $this->assertTrue(Worklist::contribute($event, self::sharedSource('bhplan'), 'Treatment plan reviews', 'Clinician'));
        $added = $event->added();
        $this->assertSame(['bhplan'], array_keys($added));
        $this->assertSame(['Treatment plan reviews', 'Clinician'], [$added['bhplan']['label'], $added['bhplan']['ownerRole']]);
        $this->assertSame('2026-10-26', Worklist::now($event)->format('Y-m-d'));

        // As WorklistService does once the module has added it, then collects every patient and one.
        ItemKey::allow('bhplan');
        $items = $added['bhplan']['source']->collect();
        $this->assertContainsOnlyInstancesOf(WorkItem::class, $items);
        $this->assertSame(['bhplan:7:42', 'bhplan:9:43'], array_map(static fn(WorkItem $i): string => (string)$i->key, $items));
        $this->assertSame([4, 'P7', 'chart', WorkItem::PRIORITY_HIGH], [$items[0]->siteId, $items[0]->patient->pubpid, $items[0]->actionMode, $items[0]->priority]);
        $this->assertSame(['bhplan:9:43'], array_map(static fn(WorkItem $i): string => (string)$i->key, $added['bhplan']['source']->collect(9)));
    }

    public function testATabOnTheSharedContractMeetsTheSameRefusals(): void
    {
        $event = new CollectSourcesEvent(new \DateTimeImmutable());
        $event->contribute(self::sharedSource('mytab'), 'X', 'Y');
        foreach (['screen', 'mytab'] as $id) {
            try {
                $event->contribute(self::sharedSource($id), 'X', 'Y');
                $this->fail("$id was accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // An old-style source and a shared one can't take the same id either.
        $this->expectException(\InvalidArgumentException::class);
        $event->addSource(self::source('mytab'), 'X', 'Y');
    }

    public function testTheSameSourceTwiceIsRefused(): void
    {
        $event = new CollectSourcesEvent(new \DateTimeImmutable());
        $event->addSource(self::source('mytab'), 'X', 'Y');
        $this->expectException(\InvalidArgumentException::class);
        $event->addSource(self::source('mytab'), 'X', 'Y');
    }
}
