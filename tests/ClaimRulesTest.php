<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Claims\ClaimRecord;
use OpenHealthcare\StaffWorklist\Claims\ClaimRules;
use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\Policy\PriorityPolicy;
use PHPUnit\Framework\TestCase;

final class ClaimRulesTest extends TestCase
{
    private const NOW = '2026-10-18 12:00:00';

    public function testADenialIsHighPriorityAndDatedWhenItWasPosted(): void
    {
        $decision = $this->rules()->decide(self::claim(ClaimRecord::STATUS_DENIED, '2026-10-01 09:00:00'));

        $this->assertSame(ClaimRules::DENIED, $decision['kind'] ?? null);
        $this->assertSame(WorkItem::PRIORITY_HIGH, $decision['priority']);
        $this->assertEquals(new \DateTimeImmutable('2026-10-01 09:00:00'), $decision['since']);
    }

    public function testADenialLeavesWhenTheLevelIsClosedSomethingIsPostedOrItIsBilledAgain(): void
    {
        $rules = $this->rules();
        $this->assertNull($rules->decide(self::claim(ClaimRecord::STATUS_DENIED, '2026-10-01', lastLevelClosed: 1)), 'level closed');
        $this->assertNull($rules->decide(self::claim(ClaimRecord::STATUS_DENIED, '2026-10-01', postedSince: true)), 'posted since');
        // Billed again: the latest version is a fresh billing, not yet late.
        $this->assertNull($rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-10-10')), 'rebilled');
        // A denial at the secondary level stays open while only the primary is closed.
        $this->assertSame(ClaimRules::DENIED, $rules->decide(self::claim(ClaimRecord::STATUS_DENIED, '2026-10-01', level: 2, lastLevelClosed: 1))['kind'] ?? null);
    }

    public function testNoResponseStartsAfter45DaysAndIsHighAfter90(): void
    {
        $rules = $this->rules();
        $this->assertNull($rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-09-03 12:00:00')), '45 days: not yet');
        $this->assertSame([ClaimRules::NO_RESPONSE, WorkItem::PRIORITY_NORMAL], self::kindAndPriority($rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-09-02 12:00:00'))), '46 days');
        $this->assertSame([ClaimRules::NO_RESPONSE, WorkItem::PRIORITY_NORMAL], self::kindAndPriority($rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-07-20 12:00:00'))), '90 days');
        $this->assertSame([ClaimRules::NO_RESPONSE, WorkItem::PRIORITY_HIGH], self::kindAndPriority($rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-07-19 12:00:00'))), '91 days');
        $this->assertNull($rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-07-01', postedSince: true)), 'answered');
        $this->assertNull($rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-07-01', level: 0)), 'billed to no insurer');
    }

    public function testClaimsOutsideTheYearAreLeftOut(): void
    {
        $this->assertNull($this->rules()->decide(self::claim(ClaimRecord::STATUS_DENIED, '2025-10-17 12:00:00')));
        $this->assertNotNull($this->rules()->decide(self::claim(ClaimRecord::STATUS_DENIED, '2025-10-19 12:00:00')));
    }

    public function testAFlagIsListedOnItsOwnAndKeptAlongsideADenial(): void
    {
        $flags = [['note' => 'call payer', 'at' => new \DateTimeImmutable('2026-10-05')], ['note' => '', 'at' => new \DateTimeImmutable('2026-10-02')]];
        $alone = $this->rules()->decide(self::claim(null, null, flags: $flags));
        $this->assertSame([ClaimRules::FLAGGED, WorkItem::PRIORITY_NORMAL], self::kindAndPriority($alone));
        $this->assertEquals(new \DateTimeImmutable('2026-10-02'), $alone['since'] ?? null, 'since the earliest flag');

        $both = $this->rules()->decide(self::claim(ClaimRecord::STATUS_DENIED, '2026-10-01', flags: $flags));
        $this->assertSame(ClaimRules::DENIED, $both['kind'] ?? null);
        $this->assertTrue($both['flagged']);
        // A flag on a claim that was answered still lists, as a flag.
        $this->assertSame(ClaimRules::FLAGGED, $this->rules()->decide(self::claim(ClaimRecord::STATUS_DENIED, '2026-10-01', postedSince: true, flags: $flags))['kind'] ?? null);
    }

    public function testTheDenialReasonsAreReadAsOpenEmrStoresThem(): void
    {
        $this->assertSame(
            [
                ['code' => '99213', 'modifier' => '', 'group' => 'CO', 'reason' => '16'],
                ['code' => '90471', 'modifier' => '25', 'group' => 'PR', 'reason' => '204'],
            ],
            ClaimRules::parseReasons('99213__CO_16,90471_25_PR_204,broken,a_b_c_,'),
        );
        $this->assertSame([], ClaimRules::parseReasons(''));
        $this->assertSame('Claim/service lacks information.', ClaimRules::shortDescription('Claim/service lacks information. Usage: Do not use this code'));
        $this->assertSame('Duplicate claim/service.', ClaimRules::shortDescription('Duplicate claim/service.'));
    }

    private function rules(): ClaimRules
    {
        return new ClaimRules(new PriorityPolicy(), new \DateTimeImmutable(self::NOW));
    }

    public function testALineWrittenOffWithADenialReasonIsHighPriorityEvenWithTheLevelClosed(): void
    {
        $line = ['code' => '99213', 'reason' => '50', 'amount' => 120.0, 'at' => new \DateTimeImmutable('2026-09-20 10:00:00')];
        $decision = $this->rules()->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-09-01', lastLevelClosed: 1, postedSince: true, writeOffs: [$line]));
        $this->assertSame([ClaimRules::WRITTEN_OFF, WorkItem::PRIORITY_HIGH], self::kindAndPriority($decision), 'before 8.4.1 OpenEMR closed the level as it wrote the line off');
        $this->assertEquals($line['at'], $decision['since']);
        $this->assertNull($this->rules()->decide(self::claim(ClaimRecord::STATUS_BILLED, '2025-09-01', lastLevelClosed: 1, postedSince: true, writeOffs: [$line])),
            'outside the year');
    }

    public function testOnlyRemittanceAdjustmentsWithADenialReasonAreWriteOffs(): void
    {
        $this->assertSame('50', ClaimRules::writeOffReason('Adjust code 50'));
        $this->assertSame('B7', ClaimRules::writeOffReason(' Adjust code B7 '));
        $this->assertNull(ClaimRules::writeOffReason('Adjust code 45'), 'contractual');
        $this->assertNull(ClaimRules::writeOffReason('Adjust code 59'), 'contractual');
        $this->assertNull(ClaimRules::writeOffReason('Ins1 dedbl: 20.00'));
        $this->assertNull(ClaimRules::writeOffReason('Sliding fee discount'));
        $this->assertNull(ClaimRules::writeOffReason('Adjust code'));
    }

    public function testARemittanceThatLeftTheLevelOpenIsListedAfter30Days(): void
    {
        $rules = $this->rules();
        $this->assertNull($rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-08-01', postedSince: true, lastPosted: '2026-09-25')), '23 days: not yet');
        $this->assertSame([ClaimRules::PARTLY_POSTED, WorkItem::PRIORITY_NORMAL],
            self::kindAndPriority($rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-08-01', postedSince: true, lastPosted: '2026-09-01'))), '47 days');
        $this->assertNull($rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-08-01', lastLevelClosed: 1, postedSince: true, lastPosted: '2026-09-01')),
            'the level was closed');
        $this->assertSame(ClaimRules::NO_RESPONSE, $rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-08-01'))['kind'] ?? null,
            'nothing posted is no response, not partly posted');
    }

    /**
     * @param list<array{note: string, at: \DateTimeImmutable}> $flags
     * @param list<array{code: string, reason: string, amount: float, at: \DateTimeImmutable}> $writeOffs
     */
    private static function claim(
        ?int $status,
        ?string $time,
        int $level = 1,
        int $lastLevelClosed = 0,
        bool $postedSince = false,
        array $flags = [],
        ?string $lastPosted = null,
        array $writeOffs = [],
        ?array $upload = null,
    ): ClaimRecord {
        return new ClaimRecord(7, 70, $status, $level, 'Payer', $time === null ? null : new \DateTimeImmutable($time), '99213__CO_16', $lastLevelClosed, $postedSince, $flags,
            $lastPosted === null ? null : new \DateTimeImmutable($lastPosted), $writeOffs, $upload);
    }

    /** @return array{status: string, at: \DateTimeImmutable, file: string, partner: string, message: string} */
    private static function upload(string $status, string $at): array
    {
        return ['status' => $status, 'at' => new \DateTimeImmutable($at), 'file' => 'batch.txt', 'partner' => 'Clearinghouse', 'message' => ''];
    }

    public function testAFailedUploadIsHighPriorityAndComesBeforeNoResponse(): void
    {
        $rules = $this->rules();
        foreach (['parameter-error', 'claim-file-error', 'login-error', 'chdir-error', 'upload-error'] as $status) {
            $decision = $rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-10-17 09:00:00', upload: self::upload($status, '2026-10-17 09:00:05')));
            $this->assertSame([ClaimRules::UPLOAD_FAILED, WorkItem::PRIORITY_HIGH], self::kindAndPriority($decision), $status);
            $this->assertEquals(new \DateTimeImmutable('2026-10-17 09:00:05'), $decision['since']);
        }
        // Billed 60 days ago and never sent: the failure explains the silence, so it is what lists.
        $this->assertSame(ClaimRules::UPLOAD_FAILED,
            $rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-08-19', upload: self::upload('login-error', '2026-08-19')))['kind'] ?? null);
    }

    public function testAnUploadFailureLeavesWhenTheInsurerAnsweredTheLevelClosedOrTheYearPassed(): void
    {
        $rules = $this->rules();
        $failed = self::upload('upload-error', '2026-10-01');
        $this->assertNull($rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-10-01', postedSince: true, upload: $failed)), 'posted since');
        $this->assertNull($rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-10-01', lastLevelClosed: 1, upload: $failed)), 'level closed');
        $this->assertNull($rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2025-10-01', upload: $failed)), 'outside the year');
        // A denial means the insurer had the claim: the denial lists, not the old upload.
        $this->assertSame(ClaimRules::DENIED, $rules->decide(self::claim(ClaimRecord::STATUS_DENIED, '2026-10-01', upload: $failed))['kind'] ?? null);
    }

    public function testAFileStillQueuedAfterADayIsNotSentAndASentOneIsJustBilled(): void
    {
        $rules = $this->rules();
        $this->assertSame([ClaimRules::NOT_SENT, WorkItem::PRIORITY_NORMAL],
            self::kindAndPriority($rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-10-17 11:00:00', upload: self::upload('waiting', '2026-10-17 11:00:00')))), '25 hours');
        $this->assertSame(ClaimRules::NOT_SENT,
            $rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-10-17 11:00:00', upload: self::upload('in-progress', '2026-10-17 11:00:00')))['kind'] ?? null, 'stuck mid-upload');
        $this->assertNull($rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-10-17 12:00:00', upload: self::upload('waiting', '2026-10-17 12:00:00'))), '24 hours: not yet');
        $this->assertNull($rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-10-01', upload: self::upload('success', '2026-10-01'))), 'sent, and not late yet');
        $this->assertSame(ClaimRules::NO_RESPONSE,
            $rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-08-19', upload: self::upload('success', '2026-08-19')))['kind'] ?? null, 'sent, then no response');
        $this->assertSame(ClaimRules::NO_RESPONSE,
            $rules->decide(self::claim(ClaimRecord::STATUS_BILLED, '2026-08-19', upload: self::upload('something-new', '2026-08-19')))['kind'] ?? null,
            'a status this module does not know is neither failed nor pending');
    }

    /**
     * @param array{kind: string, priority: int, since: \DateTimeImmutable, flagged: bool}|null $decision
     * @return array{string, int}|null
     */
    private static function kindAndPriority(?array $decision): ?array
    {
        return $decision === null ? null : [$decision['kind'], $decision['priority']];
    }
    public function testADenialRecordedAgainByARepostedEraKeepsItsFirstVersion(): void
    {
        $v = static fn(int $version, int $status, string $reasons = '99213__CO_197', int $payer = 3, int $level = 1): array =>
            ['version' => $version, 'status' => $status, 'payer_id' => $payer, 'payer_type' => $level, 'process_file' => $reasons, 'bill_time' => "2026-10-0$version 09:00:00"];

        // Billed (1), denied (2), then the same ERA posted twice more (3, 4): the denial is version 2's.
        $this->assertSame(['version' => 2, 'bill_time' => '2026-10-02 09:00:00'],
            ClaimRules::firstOfSameDenial([$v(4, 7), $v(3, 7), $v(2, 7), $v(1, 2)]));
        $this->assertSame(['version' => 3, 'bill_time' => '2026-10-03 09:00:00'],
            ClaimRules::firstOfSameDenial([$v(4, 7), $v(3, 7), $v(2, 7, '99213__CO_16')]), 'other reasons are another denial');
        $this->assertSame(['version' => 4, 'bill_time' => '2026-10-04 09:00:00'],
            ClaimRules::firstOfSameDenial([$v(4, 7), $v(3, 7, payer: 5)]), 'another payer is another denial');
        $this->assertSame(['version' => 4, 'bill_time' => '2026-10-04 09:00:00'],
            ClaimRules::firstOfSameDenial([$v(4, 7), $v(3, 7, level: 2)]), 'another level is another denial');
        $this->assertSame(['version' => 4, 'bill_time' => '2026-10-04 09:00:00'],
            ClaimRules::firstOfSameDenial([$v(4, 7), $v(3, 2), $v(2, 7)]), 'billed again in between: a new denial');
        $this->assertNull(ClaimRules::firstOfSameDenial([$v(4, 2), $v(3, 7)]), 'the latest version is not a denial');
        $this->assertNull(ClaimRules::firstOfSameDenial([]));
    }
}
