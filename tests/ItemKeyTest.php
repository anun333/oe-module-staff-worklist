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

    public function testAScreeningRefMayEndInOneDigitTellingApartTheItemsOfOneResponse(): void
    {
        foreach (['screen:3:12', 'screen:3:12.2', 'screen:3:12.9'] as $raw) {
            $key = ItemKey::parse($raw);
            self::assertNotNull($key, $raw);
            self::assertSame($raw, (string)$key);
        }
        self::assertNotSame((string)ItemKey::parse('screen:3:12.2'), (string)ItemKey::parse('screen:3:122'));
    }

    public function testAScreenFromOpenEmrsOwnFormsNamesTheForm(): void
    {
        foreach (['screen:3:phq9-7', 'screen:3:gad7-7', 'screen:3:phq9-7.2'] as $raw) {
            $key = ItemKey::parse($raw);
            self::assertNotNull($key, $raw);
            self::assertSame($raw, (string)$key);
        }
        self::assertNotSame((string)ItemKey::parse('screen:3:phq9-7'), (string)ItemKey::parse('screen:3:7'), "a form's id and a response's never meet");
        self::assertNotNull(ItemKey::parse('auth:3:41'), 'a prior authorization');
    }

    public function testConstructorRejectsADottedRefOutsideScreening(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ItemKey('order', 1, '2.3');
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
            'dotted ref outside screening' => ['order:1:2.3'],
            'zero after the dot' => ['screen:1:12.0'],
            'two digits after the dot' => ['screen:1:12.10'],
            'dot with nothing after' => ['screen:1:12.'],
            'two dots' => ['screen:1:12.2.9'],
            'a form name outside screening' => ['order:1:phq9-7'],
            'an unknown form name' => ['screen:1:audit-7'],
            'a form name with no id' => ['screen:1:phq9-'],
            'a form name after the id' => ['screen:1:7-phq9'],
            'a dotted prior authorization' => ['auth:1:4.2'],
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
