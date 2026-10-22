<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Event\CollectSourcesEvent;
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

    public function testTheSameSourceTwiceIsRefused(): void
    {
        $event = new CollectSourcesEvent(new \DateTimeImmutable());
        $event->addSource(self::source('mytab'), 'X', 'Y');
        $this->expectException(\InvalidArgumentException::class);
        $event->addSource(self::source('mytab'), 'X', 'Y');
    }
}
