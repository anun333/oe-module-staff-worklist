<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Social\SocialNeedsRules;
use OpenHealthcare\StaffWorklist\Social\SocialScreen;
use PHPUnit\Framework\TestCase;

final class SocialNeedsRulesTest extends TestCase
{
    private static function day(string $d): \DateTimeImmutable
    {
        return new \DateTimeImmutable($d);
    }

    public function testSdohDomainsUseOpenEmrsOwnPositiveAnswers(): void
    {
        $row = ['food_insecurity' => 'Often', 'housing_instability' => 'no', 'financial_strain' => 'very_hard', 'digital_access' => 'limited', 'social_isolation' => ''];
        self::assertSame(['food_insecurity', 'financial_strain', 'digital_access'], SocialNeedsRules::sdohDomains($row));
        self::assertSame([], SocialNeedsRules::sdohDomains(['food_insecurity' => 'never', 'digital_access' => 'yes']), "digital access is positive on 'no', not 'yes'");
    }

    public function testTheHungerVitalSign(): void
    {
        $response = static fn(array $answers): array => ['item' => [['linkId' => 'g', 'item' => array_map(
            static fn(string $q, array $coding): array => ['linkId' => "/$q", 'answer' => [['valueCoding' => $coding]]], array_keys($answers), $answers)]]];
        $loinc = static fn(string $code): array => ['system' => 'http://loinc.org', 'code' => $code];
        self::assertTrue(SocialNeedsRules::hungerVitalSign($response(['88122-7' => $loinc('LA28398-8'), '88123-5' => $loinc('LA6729-3')])), 'sometimes true on either is positive');
        self::assertFalse(SocialNeedsRules::hungerVitalSign($response(['88122-7' => $loinc('LA28398-8'), '88123-5' => $loinc('LA28398-8')])), 'never true on both');
        $position = static fn(string $code): array => ['system' => 'https://github.com/anun333/oe-module-clinic-forms/answers', 'code' => $code, 'display' => 'A menudo'];
        self::assertTrue(SocialNeedsRules::hungerVitalSign($response(['88122-7' => $position('1')])), "a translation's answers, by position: 1 is often true");
        self::assertFalse(SocialNeedsRules::hungerVitalSign($response(['88122-7' => $position('3')])));
        self::assertNull(SocialNeedsRules::hungerVitalSign(['item' => [['linkId' => '/44250-9', 'answer' => [['valueCoding' => $loinc('LA6569-3')]]]]]), 'not a Hunger Vital Sign');
    }

    public function testTheLatestScreenOfAKindDecidesAndFollowUpClearsIt(): void
    {
        $food = ['food_insecurity'];
        $screens = [
            new SocialScreen(SocialNeedsRules::KIND_SDOH, 1, 11, self::day('2026-09-01'), ['food_insecurity', 'transportation_insecurity']),
            new SocialScreen(SocialNeedsRules::KIND_SDOH, 2, 12, self::day('2026-08-01'), ['housing_instability']),
            new SocialScreen(SocialNeedsRules::KIND_SDOH, 3, 12, self::day('2026-09-15'), []),
            new SocialScreen(SocialNeedsRules::KIND_HVS, 4, 13, self::day('2026-09-10'), $food),
            new SocialScreen(SocialNeedsRules::KIND_FSS, 5, 14, self::day('2026-09-10'), $food),
            new SocialScreen(SocialNeedsRules::KIND_HVS, 6, 15, self::day('2026-09-10'), $food),
        ];
        $referrals = [13 => [self::day('2026-09-10')], 15 => [self::day('2026-09-09')]];
        $services = [11 => ['40' => [self::day('2026-09-02')]], 14 => ['40' => [self::day('2026-08-01')]]];
        $open = SocialNeedsRules::open($screens, $referrals, $services);
        $got = array_map(static fn(SocialScreen $s): string => $s->pid . ':' . $s->ref() . ':' . implode(',', $s->domains), $open);
        self::assertSame([
            '11:sdoh-1:transportation_insecurity', // food followed by a Line 40 service; transportation has no UDS line, so stays
            '14:qr-5:food_insecurity',             // the service was before the screen
            '15:qr-6:food_insecurity',             // the referral was the day before
        ], $got, '12 is negative now; 13 was referred the same day');
    }
}
