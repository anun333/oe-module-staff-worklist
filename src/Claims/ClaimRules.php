<?php

/**
 * Which claims need billing staff, and why:
 *  - denied: the latest claim version is a denial OpenEMR recorded from a remittance
 *    (status 7, nothing posted), with nothing posted at that level since and the level open;
 *  - written off: a remittance adjusted a line off, paying nothing for it, with a reason
 *    other than the contractual 45 or 59. OpenEMR before 8.4.1 posted such a line denial as an
 *    "Adjust code" adjustment; since #11868 (in 8.4.1) it refuses to post it, as here;
 *  - not sent: the claim file OpenEMR queued for its SFTP upload to an X12 partner failed
 *    (settings, the local file, login, the remote folder, or from 8.5.0 the upload itself), so
 *    the insurer never got it; or it is still queued long after, which means the upload
 *    service isn't running. Each comes before "no response", which it explains;
 *  - no response: billed to an insurer longer ago than the policy allows, with nothing
 *    posted at that level since and the level open;
 *  - partly posted: a remittance posted to the claim and its level is still open long after.
 *    Since 8.4.1 a line OpenEMR won't post stops the rest of that claim's posting and leaves
 *    the level open, which is what this catches;
 *  - flagged: staff ticked Follow Up on a line while posting.
 * Each outranks the next; the facts of the others are kept.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Claims;

use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\Policy\PriorityPolicy;

final class ClaimRules
{
    public const DENIED = 'denied';
    public const NO_RESPONSE = 'no_response';
    public const FLAGGED = 'flagged';
    public const WRITTEN_OFF = 'written_off';
    public const PARTLY_POSTED = 'partly_posted';
    public const UPLOAD_FAILED = 'upload_failed';
    public const NOT_SENT = 'not_sent';
    /** Adjustment reasons OpenEMR treats as contractual write-offs (CO-45, CO-59) when a line is paid nothing. */
    public const CONTRACTUAL = ['45', '59'];
    /** Each condition's digit in an item key (ItemKey); fixed, since saved dismissals carry it. */
    public const KEY_DIGITS = [
        self::DENIED => 1, self::NO_RESPONSE => 2, self::FLAGGED => 3, self::WRITTEN_OFF => 4,
        self::PARTLY_POSTED => 5, self::UPLOAD_FAILED => 6, self::NOT_SENT => 7,
    ];

    /**
     * An item's key ref: the encounter, the condition's digit and the claim version, so a dismissal holds
     * only the condition it was made on, for that version of the claim ("345.1v2"). A visit with only a
     * follow-up flag has no claim version (0), and its ref has none.
     */
    public static function keyRef(int $encounter, string $kind, int $version): string
    {
        return $encounter . '.' . (self::KEY_DIGITS[$kind] ?? 9) . ($version > 0 ? 'v' . $version : '');
    }

    public function __construct(
        private readonly PriorityPolicy $policy,
        private readonly \DateTimeImmutable $now,
    ) {
    }

    /** The earliest billing, denial or flag time the window reaches. */
    public function windowStart(): \DateTimeImmutable
    {
        return $this->now->modify("-{$this->policy->claimWindowDays} days");
    }

    /**
     * @return array{kind: string, priority: int, since: \DateTimeImmutable, flagged: bool}|null
     */
    public function decide(ClaimRecord $claim): ?array
    {
        $open = $claim->versionTime !== null
            && $claim->versionTime >= $this->windowStart()
            && $claim->level >= 1
            && $claim->lastLevelClosed < $claim->level
            && !$claim->postedSince;
        $flagged = $claim->flags !== [];

        if ($open && $claim->status === ClaimRecord::STATUS_DENIED) {
            return ['kind' => self::DENIED, 'priority' => WorkItem::PRIORITY_HIGH, 'since' => $claim->versionTime, 'flagged' => $flagged];
        }
        if ($claim->writeOffs !== [] && $claim->versionTime !== null && $claim->versionTime >= $this->windowStart()) {
            $since = min(array_map(static fn(array $w): \DateTimeImmutable => $w['at'], $claim->writeOffs));
            return ['kind' => self::WRITTEN_OFF, 'priority' => WorkItem::PRIORITY_HIGH, 'since' => $since, 'flagged' => $flagged];
        }
        if ($open && $claim->status === ClaimRecord::STATUS_BILLED && $claim->upload !== null) {
            $upload = $claim->upload;
            if (ClaimUpload::failed($upload['status'])) {
                return ['kind' => self::UPLOAD_FAILED, 'priority' => WorkItem::PRIORITY_HIGH, 'since' => $upload['at'], 'flagged' => $flagged];
            }
            if (ClaimUpload::pending($upload['status']) && PriorityPolicy::ageHours($upload['at'], $this->now) > $this->policy->claimNotSentHours) {
                return ['kind' => self::NOT_SENT, 'priority' => WorkItem::PRIORITY_NORMAL, 'since' => $upload['at'], 'flagged' => $flagged];
            }
        }
        if ($open && $claim->status === ClaimRecord::STATUS_BILLED) {
            $days = PriorityPolicy::ageDays($claim->versionTime, $this->now);
            if ($days > $this->policy->claimNoResponseDays) {
                $priority = $days > $this->policy->claimNoResponseHighDays ? WorkItem::PRIORITY_HIGH : WorkItem::PRIORITY_NORMAL;
                return ['kind' => self::NO_RESPONSE, 'priority' => $priority, 'since' => $claim->versionTime, 'flagged' => $flagged];
            }
        }
        $levelOpen = $claim->versionTime !== null && $claim->versionTime >= $this->windowStart()
            && $claim->level >= 1 && $claim->lastLevelClosed < $claim->level;
        if ($levelOpen && $claim->status === ClaimRecord::STATUS_BILLED && $claim->postedSince && $claim->lastPosted !== null
            && PriorityPolicy::ageDays($claim->lastPosted, $this->now) > $this->policy->claimPartlyPostedDays) {
            return ['kind' => self::PARTLY_POSTED, 'priority' => WorkItem::PRIORITY_NORMAL, 'since' => $claim->lastPosted, 'flagged' => $flagged];
        }
        if ($flagged) {
            $since = min(array_map(static fn(array $f): \DateTimeImmutable => $f['at'], $claim->flags));
            return ['kind' => self::FLAGGED, 'priority' => WorkItem::PRIORITY_NORMAL, 'since' => $since, 'flagged' => true];
        }
        return null;
    }

    /**
     * The denial reasons OpenEMR keeps in claims.process_file: "code_modifier_group_reason",
     * comma-separated, one per adjustment on each service line. Malformed entries are skipped.
     *
     * @return list<array{code: string, modifier: string, group: string, reason: string}>
     */
    public static function parseReasons(string $processFile): array
    {
        $out = [];
        foreach (explode(',', $processFile) as $entry) {
            $parts = explode('_', trim($entry));
            if (count($parts) !== 4 || $parts[3] === '') {
                continue;
            }
            $out[] = ['code' => $parts[0], 'modifier' => $parts[1], 'group' => $parts[2], 'reason' => $parts[3]];
        }
        return $out;
    }

    /**
     * The reason in an adjustment OpenEMR posted from a remittance ("Adjust code 50"), or null
     * for any other memo. Contractual reasons (45, 59) are left out: they aren't denials.
     */
    public static function writeOffReason(string $memo): ?string
    {
        if (preg_match('/^Adjust code (\w+)$/', trim($memo), $m) !== 1 || in_array($m[1], self::CONTRACTUAL, true)) {
            return null;
        }
        return $m[1];
    }

    /** A reason code's description without the CARC list's "Usage:" note. */
    public static function shortDescription(string $description): string
    {
        $cut = strpos($description, ' Usage:');
        return trim($cut === false ? $description : substr($description, 0, $cut));
    }
}
