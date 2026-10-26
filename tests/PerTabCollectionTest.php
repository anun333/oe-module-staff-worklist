<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Source\ResponseVisit;
use OpenHealthcare\StaffWorklist\Worklist\LegacyKeys;
use OpenHealthcare\StaffWorklist\Worklist\WorklistService;
use PHPUnit\Framework\TestCase;

/** A page collects only its tab, plus results to sign for the banner; the All tab collects every source. */
final class PerTabCollectionTest extends TestCase
{
    private const AVAILABLE = ['result', 'order', 'referral', 'caregap', 'screen', 'claim', 'social', 'revcycle'];

    public function testTheAllTabCollectsEverySource(): void
    {
        self::assertSame(self::AVAILABLE, WorklistService::sourcesToCollect(self::AVAILABLE, null));
    }

    public function testATabCollectsItselfAndResultsToSign(): void
    {
        self::assertSame(['result', 'claim'], WorklistService::sourcesToCollect(self::AVAILABLE, 'claim'));
        self::assertSame(['result'], WorklistService::sourcesToCollect(self::AVAILABLE, 'result'));
        self::assertSame(['result', 'revcycle'], WorklistService::sourcesToCollect(self::AVAILABLE, 'revcycle'), "another module's tab");
    }

    public function testATabTheUserDoesNotHaveCollectsOnlyResults(): void
    {
        self::assertSame(['result'], WorklistService::sourcesToCollect(self::AVAILABLE, 'auth'));
        self::assertSame(['claim'], WorklistService::sourcesToCollect(['order', 'claim'], 'claim'), 'no results tab, nothing extra');
    }

    public function testOnlyTheCollectedSourcesCarryOrDropTheir085Keys(): void
    {
        self::assertSame(['claim'], LegacyKeys::sourcesFor(['result', 'claim']));
        self::assertSame([], LegacyKeys::sourcesFor(['result']), 'the results tab leaves claims, authorizations and sliding fee rows alone');
        self::assertSame(LegacyKeys::SOURCES, LegacyKeys::sourcesFor(self::AVAILABLE + [8 => 'auth', 9 => 'sliding']));
    }

    public function testAResponsesVisitIsLookedUpByItsIdOnlyWhenThatColumnIsIndexed(): void
    {
        self::assertSame(ResponseVisit::JOINS, ResponseVisit::joins(false));
        $indexed = ResponseVisit::joins(true);
        self::assertStringContainsString('WHERE vfqa.response_id = qr.response_id', $indexed);
        self::assertStringNotContainsString('GROUP BY', $indexed);
        self::assertStringContainsString('GROUP BY', ResponseVisit::JOINS);
    }
}
