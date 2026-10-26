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
        self::assertNotNull(ItemKey::parse('social:3:sdoh-41'), 'a social needs item from the SDOH history');
        self::assertNotNull(ItemKey::parse('social:3:qr-41'), 'or from a questionnaire response');
    }

    public function testClaimsAuthorizationAndSlidingFeeKeysNameTheirCondition(): void
    {
        foreach (['claim:3:345.1v2', 'claim:3:345.3', 'auth:3:41.2', 'sliding:3:88.3', 'claim:3:345', 'auth:3:41'] as $raw) {
            $key = ItemKey::parse($raw);
            self::assertNotNull($key, $raw);
            self::assertSame($raw, (string)$key);
        }
        self::assertSame('345', ItemKey::parse('claim:3:345.1v2')?->record(), 'the record the condition is about');
        self::assertSame('41', ItemKey::parse('auth:3:41')?->record(), 'a key saved before 0.8.6');
        self::assertSame('phq9-7', ItemKey::parse('screen:3:phq9-7.2')?->record());
    }

        public function testAnotherModulesSourceIsAKeyOnlyOnceItsBeenAdded(): void
    {
        $this->assertNull(ItemKey::parse('caremgmtx:9001:12202609'), 'not yet added');
        ItemKey::allow('caremgmtx');
        $key = ItemKey::parse('caremgmtx:9001:12202609');
        $this->assertNotNull($key);
        $this->assertSame(['caremgmtx', 9001, '12202609'], [$key->source, $key->pid, $key->ref]);
        $this->assertNull(ItemKey::parse('caremgmtx:9001:12202609.1'), 'a dotted ref is screening only');
        ItemKey::allow('Bad-Id');
        $this->assertNull(ItemKey::parse('Bad-Id:9001:1'), 'an id outside the pattern is never allowed');
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
            'a claim version outside claims' => ['auth:1:4.2v3'],
            'a claim version on a screen' => ['screen:1:12.2v1'],
            'a dotted order' => ['order:1:10.2'],
            'a claim version with no condition' => ['claim:1:10v2'],
            'claim version zero' => ['claim:1:10.1v0'],
            'a social needs item with no prefix' => ['social:1:41'],
            'a screening form name on a social item' => ['social:1:phq9-41'],
            'a social prefix on a screening item' => ['screen:1:sdoh-41'],
            'a dotted social item' => ['social:1:qr-41.2'],
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
