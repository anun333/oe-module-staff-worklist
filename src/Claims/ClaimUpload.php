<?php

/**
 * Claim files OpenEMR queued for its SFTP upload to an X12 partner, read from core's
 * x12_remote_tracker ("Auto SFTP claims to X12 partner"): one row per batch file and partner,
 * with the batch's claims as JSON and a status the SFTP background service sets. For each claim,
 * the latest upload that carried it is the one that counts, so a later successful resend
 * clears an earlier failure.
 *
 * Before OpenEMR 8.5.0 a file whose upload itself failed was recorded as success (#14336), so
 * there only the failures before the upload (settings, the local file, login, the remote
 * folder) show up.
 *
 * Every date here is the row's created_at, when the file was queued. Core stamps updated_at
 * on the 12-hour clock with no AM or PM (`date('Y-m-d h:i:s')` in X12RemoteTracker::update()),
 * so an afternoon update reads twelve hours early.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Claims;

final class ClaimUpload
{
    public const WAITING = 'waiting';
    public const IN_PROGRESS = 'in-progress';
    public const SUCCESS = 'success';
    /** X12RemoteTracker's statuses for a file that never reached the partner. */
    public const FAILED = ['parameter-error', 'claim-file-error', 'login-error', 'chdir-error', 'upload-error'];

    /**
     * The claims a tracker row carried, as "pid:encounter" keys. The column holds the batch's
     * BillingClaim objects as JSON, each with its pid and encounter; anything else is skipped.
     *
     * @return list<string>
     */
    public static function claimKeys(?string $claimsJson): array
    {
        if ($claimsJson === null || $claimsJson === '') {
            return [];
        }
        $claims = json_decode($claimsJson, true);
        if (!is_array($claims)) {
            return [];
        }
        $keys = [];
        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                continue;
            }
            $pid = $claim['pid'] ?? null;
            $encounter = $claim['encounter'] ?? null;
            if (!is_numeric($pid) || !is_numeric($encounter) || (int)$pid <= 0 || (int)$encounter <= 0) {
                continue;
            }
            $keys[] = (int)$pid . ':' . (int)$encounter;
        }
        return array_values(array_unique($keys));
    }

    /**
     * The latest upload of each claim, from tracker rows (id, status, x12_filename, messages,
     * claims, created_at, and the partner's name as partner_name), dated when it was queued.
     *
     * @param iterable<array<string, mixed>> $rows
     * @return array<string, array{status: string, at: \DateTimeImmutable, file: string, partner: string, message: string}>
     */
    public static function latestByClaim(iterable $rows): array
    {
        $latest = [];
        $order = [];
        foreach ($rows as $row) {
            $queued = self::date($row['created_at'] ?? null);
            if ($queued === null) {
                continue;
            }
            $upload = [
                'status' => trim((string)($row['status'] ?? '')),
                'at' => $queued,
                'file' => trim((string)($row['x12_filename'] ?? '')),
                'partner' => trim((string)($row['partner_name'] ?? '')),
                'message' => self::firstMessage(is_string($row['messages'] ?? null) ? $row['messages'] : null),
            ];
            $rank = [$queued->getTimestamp(), (int)($row['id'] ?? 0)];
            foreach (self::claimKeys(is_string($row['claims'] ?? null) ? $row['claims'] : null) as $key) {
                if (!isset($order[$key]) || $rank > $order[$key]) {
                    $order[$key] = $rank;
                    $latest[$key] = $upload;
                }
            }
        }
        return $latest;
    }

    public static function failed(string $status): bool
    {
        return in_array($status, self::FAILED, true);
    }

    /** Still queued or mid-upload: not yet sent, and not failed either. */
    public static function pending(string $status): bool
    {
        return $status === self::WAITING || $status === self::IN_PROGRESS;
    }

    /** The first of the row's messages (a JSON list of strings), or ''. */
    public static function firstMessage(?string $messagesJson): string
    {
        if ($messagesJson === null || $messagesJson === '') {
            return '';
        }
        $messages = json_decode($messagesJson, true);
        if (!is_array($messages)) {
            return '';
        }
        foreach ($messages as $message) {
            if (is_string($message) && trim($message) !== '') {
                return trim($message);
            }
        }
        return '';
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
