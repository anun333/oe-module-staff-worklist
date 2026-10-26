<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Claims\ClaimRules;
use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Model\ItemState;
use OpenHealthcare\StaffWorklist\Model\SiteAccess;
use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\PriorAuth\AuthorizationRules;
use OpenHealthcare\StaffWorklist\SlidingFee\ReassessmentRules;
use OpenHealthcare\StaffWorklist\Worklist\WorklistAssembler;
use OpenHealthcare\StaffWorklist\Worklist\WorklistFilter;
use PHPUnit\Framework\TestCase;

/**
 * A dismissal holds only the condition it was made on (0.8.6). Each test dismisses one condition on a
 * record, then lists the record with a later, different condition: it comes back. The same condition
 * stays dismissed.
 */
final class ConditionKeysTest extends TestCase
{
    private const ME = 5;

    /** The open keys after dismissing $dismissed, with $listed now produced. */
    private static function openAfterDismissing(string $dismissed, string $listed): array
    {
        $result = (new WorklistAssembler())->assemble(
            [Items::item($listed, WorkItem::PRIORITY_HIGH)],
            [$dismissed => Items::state($dismissed, ItemState::STATUS_DISMISSED)],
            new WorklistFilter(self::ME, ItemState::STATUS_OPEN, WorklistFilter::SCOPE_ALL, null, null),
            new \DateTimeImmutable('2026-10-26 10:00'),
            SiteAccess::unrestricted(self::ME),
        );
        return array_map(static fn($row): string => (string)$row->item->key, $result->rows);
    }

    private static function key(string $source, int $pid, string $ref): string
    {
        return (string)new ItemKey($source, $pid, $ref);
    }

    public function testADismissedDenialDoesNotHideTheUploadFailureOrTheNextDenial(): void
    {
        $denied = self::key(ItemKey::SOURCE_CLAIM, 12, ClaimRules::keyRef(345, ClaimRules::DENIED, 1));
        $notSent = self::key(ItemKey::SOURCE_CLAIM, 12, ClaimRules::keyRef(345, ClaimRules::UPLOAD_FAILED, 2));
        $deniedAgain = self::key(ItemKey::SOURCE_CLAIM, 12, ClaimRules::keyRef(345, ClaimRules::DENIED, 2));
        $noResponse = self::key(ItemKey::SOURCE_CLAIM, 12, ClaimRules::keyRef(345, ClaimRules::NO_RESPONSE, 1));
        $partly = self::key(ItemKey::SOURCE_CLAIM, 12, ClaimRules::keyRef(345, ClaimRules::PARTLY_POSTED, 1));

        self::assertSame([$notSent], self::openAfterDismissing($denied, $notSent), 'the claim billed again and not sent');
        self::assertSame([$deniedAgain], self::openAfterDismissing($denied, $deniedAgain), 'the claim billed again and denied again');
        self::assertSame([$partly], self::openAfterDismissing($noResponse, $partly), 'no response, then a remittance that left it open');
        self::assertSame([], self::openAfterDismissing($denied, $denied), 'the same denial stays dismissed');
    }

    public function testAClaimKeyNamesTheConditionAndTheVersion(): void
    {
        self::assertSame('345.1v2', ClaimRules::keyRef(345, ClaimRules::DENIED, 2));
        self::assertSame('345.3', ClaimRules::keyRef(345, ClaimRules::FLAGGED, 0), 'a visit with only a follow-up flag has no claim version');
        $digits = array_values(ClaimRules::KEY_DIGITS);
        self::assertSame(count($digits), count(array_unique($digits)), 'each condition has its own digit');
    }

    public function testDismissingEndsSoonDoesNotHideUnitsUsedUp(): void
    {
        $ending = self::key(ItemKey::SOURCE_AUTH, 7, AuthorizationRules::keyRef(41, AuthorizationRules::ENDING));
        $usedUp = self::key(ItemKey::SOURCE_AUTH, 7, AuthorizationRules::keyRef(41, AuthorizationRules::USED_UP));
        $low = self::key(ItemKey::SOURCE_AUTH, 7, AuthorizationRules::keyRef(41, AuthorizationRules::UNITS_LOW));

        self::assertSame([$usedUp], self::openAfterDismissing($ending, $usedUp));
        self::assertSame([$usedUp], self::openAfterDismissing($low, $usedUp), 'nor does "units low"');
        self::assertSame([], self::openAfterDismissing($ending, $ending), 'the same "ends soon" stays dismissed');
        $digits = array_values(AuthorizationRules::KEY_DIGITS);
        self::assertSame(count($digits), count(array_unique($digits)));
    }

    public function testDismissingRunsOutSoonDoesNotHideRanOut(): void
    {
        $soon = self::key(ItemKey::SOURCE_SLIDING, 9, ReassessmentRules::keyRef(88, ReassessmentRules::SOON));
        $expired = self::key(ItemKey::SOURCE_SLIDING, 9, ReassessmentRules::keyRef(88, ReassessmentRules::EXPIRED));

        self::assertSame([$expired], self::openAfterDismissing($soon, $expired));
        self::assertSame([], self::openAfterDismissing($soon, $soon), 'the same "runs out soon" stays dismissed');
        self::assertNotSame(
            ReassessmentRules::keyRef(null, ReassessmentRules::NEVER),
            ReassessmentRules::keyRef(1, ReassessmentRules::SOON),
            'never assessed and the first assessment ever saved (id 1) no longer share a key',
        );
        $digits = array_values(ReassessmentRules::KEY_DIGITS);
        self::assertSame(count($digits), count(array_unique($digits)));
    }
}
