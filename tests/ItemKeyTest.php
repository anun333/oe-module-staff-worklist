<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Model\ItemKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ItemKeyTest extends TestCase
{
    public function testParsesSimpleKey(): void
    {
        $key = ItemKey::parse('result:42:1007');
        self::assertNotNull($key);
        self::assertSame('result', $key->source);
        self::assertSame(42, $key->pid);
        self::assertSame('1007', $key->ref);
        self::assertSame('result:42:1007', (string)$key);
    }

    public function testParsesCareGapKeyForAnAppointment(): void
    {
        $key = ItemKey::parse('caregap:7:9001');
        self::assertNotNull($key);
        self::assertSame('caregap', $key->source);
        self::assertSame(7, $key->pid);
        self::assertSame('9001', $key->ref);
    }

    /** @return array<string, array{string}> */
    public static function invalidKeys(): array
    {
        return [
            'unknown source' => ['note:1:2'],
            'zero pid' => ['order:0:2'],
            'negative pid' => ['order:-1:2'],
            'missing ref' => ['order:1:'],
            'quote in ref' => ["order:1:2'"],
            'space in ref' => ['order:1:2 3'],
            'non-numeric ref' => ['order:1:abc'],
            'zero ref' => ['order:1:0'],
            'extra segment' => ['caregap:1:2:3'],
            'pid overflow' => ['order:99999999999999999999:1'],
            'trailing newline' => ["order:1:2\n"],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsInvalidKeys(string $raw): void
    {
        self::assertNull(ItemKey::parse($raw));
    }

    public function testConstructorRejectsUnknownSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ItemKey('note', 1, '2');
    }
}
