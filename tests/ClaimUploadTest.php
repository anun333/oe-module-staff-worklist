<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Claims\ClaimUpload;
use PHPUnit\Framework\TestCase;

final class ClaimUploadTest extends TestCase
{
    /** The claims column as BillingClaimBatch writes it: each BillingClaim's properties, pid and encounter as strings. */
    private const BATCH = '[{"id":"11","encounter":"70","pid":"7","partner":"3","payor_id":"5","target":"X12","is_last":false},'
        . '{"id":"12","encounter":"81","pid":"8","partner":"3","payor_id":"5","target":"X12","is_last":true}]';

    public function testTheClaimsOfABatchAreReadAsTheyAreStored(): void
    {
        $this->assertSame(['7:70', '8:81'], ClaimUpload::claimKeys(self::BATCH));
        $this->assertSame(['7:70'], ClaimUpload::claimKeys('[{"pid":"7","encounter":"70"},{"pid":"7","encounter":"70"},{"pid":"x","encounter":"1"},'
            . '{"pid":"0","encounter":"1"},{"encounter":"9"},"text",null]'), 'duplicates, bad ids and other shapes skipped');
        $this->assertSame([], ClaimUpload::claimKeys('not json'));
        $this->assertSame([], ClaimUpload::claimKeys('{"pid":"7","encounter":"70"}'), 'one object is not a batch');
        $this->assertSame([], ClaimUpload::claimKeys(''));
        $this->assertSame([], ClaimUpload::claimKeys(null));
    }

    public function testTheLatestUploadOfEachClaimCounts(): void
    {
        $rows = [
            self::row(1, 'login-error', '2026-10-01 09:00:00', self::BATCH, '["Could not log in.","SFTP: auth failed"]'),
            self::row(2, 'success', '2026-10-02 09:00:00', '[{"pid":"7","encounter":"70"}]'),
            self::row(3, 'upload-error', '2026-09-30 09:00:00', '[{"pid":"7","encounter":"70"}]'),
        ];
        $latest = ClaimUpload::latestByClaim($rows);

        $this->assertSame('success', $latest['7:70']['status'], 'resent the next day: the success counts, whatever order the rows come in');
        $this->assertSame('login-error', $latest['8:81']['status'], 'the other claim in the failed batch was never resent');
        $this->assertSame('Could not log in.', $latest['8:81']['message']);
        $this->assertSame('Clearinghouse A', $latest['8:81']['partner']);
        $this->assertSame('batch-1.txt', $latest['8:81']['file']);
        $this->assertEquals(new \DateTimeImmutable('2026-10-01 09:00:00'), $latest['8:81']['at'], 'dated when it was queued');
    }

    public function testTwoUploadsQueuedTheSameSecondAreOrderedById(): void
    {
        $latest = ClaimUpload::latestByClaim([
            self::row(9, 'chdir-error', '2026-10-01 09:00:00', '[{"pid":"7","encounter":"70"}]'),
            self::row(8, 'success', '2026-10-01 09:00:00', '[{"pid":"7","encounter":"70"}]'),
        ]);
        $this->assertSame('chdir-error', $latest['7:70']['status']);
    }

    public function testARowWithoutAQueuedDateIsSkipped(): void
    {
        $this->assertSame([], ClaimUpload::latestByClaim([self::row(1, 'upload-error', null, self::BATCH), self::row(2, 'upload-error', '0000-00-00 00:00:00', self::BATCH)]));
    }

    public function testFailedAndPendingStatusesAreCoresOwn(): void
    {
        foreach (['parameter-error', 'claim-file-error', 'login-error', 'chdir-error', 'upload-error'] as $status) {
            $this->assertTrue(ClaimUpload::failed($status), $status);
            $this->assertFalse(ClaimUpload::pending($status), $status);
        }
        $this->assertTrue(ClaimUpload::pending('waiting'));
        $this->assertTrue(ClaimUpload::pending('in-progress'));
        $this->assertFalse(ClaimUpload::failed('success'));
        $this->assertFalse(ClaimUpload::pending('success'));
        $this->assertFalse(ClaimUpload::failed(''));
    }

    public function testTheFirstMessageIsShown(): void
    {
        $this->assertSame('Could not upload file.', ClaimUpload::firstMessage('["", "Could not upload file.", "SFTP: quota"]'));
        $this->assertSame('', ClaimUpload::firstMessage('[]'));
        $this->assertSame('', ClaimUpload::firstMessage('null'));
        $this->assertSame('', ClaimUpload::firstMessage('broken'));
        $this->assertSame('', ClaimUpload::firstMessage(null));
    }

    /** @return array<string, mixed> */
    private static function row(int $id, string $status, ?string $created, string $claims, ?string $messages = null): array
    {
        return ['id' => $id, 'status' => $status, 'x12_filename' => "batch-$id.txt", 'messages' => $messages, 'claims' => $claims,
            'created_at' => $created, 'partner_name' => 'Clearinghouse A'];
    }
}
