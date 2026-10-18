<?php

/**
 * What OpenEMR holds about one encounter's claim, as the claims rules need it: the
 * latest claim version, whether anything was posted at its insurance level since,
 * and any lines staff flagged for follow-up while posting.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Claims;

final class ClaimRecord
{
    public const STATUS_BILLED = 2;
    public const STATUS_DENIED = 7;

    /**
     * @param int|null                       $status          claims.status of the latest version; null when the encounter has only flags
     * @param int                            $level           claims.payer_type: 1, 2 or 3 for the insurance level billed
     * @param \DateTimeImmutable|null        $versionTime     claims.bill_time of the latest version: when it was billed, or the denial posted
     * @param string                         $reasons         claims.process_file of a denial: code_modifier_group_reason, comma-separated
     * @param int                            $lastLevelClosed form_encounter.last_level_closed
     * @param bool                           $postedSince     a payment or adjustment at that level was posted at or after the version time
     * @param list<array{note: string, at: \DateTimeImmutable}> $flags ar_activity follow-up flags still set
     */
    public function __construct(
        public readonly int $pid,
        public readonly int $encounter,
        public readonly ?int $status,
        public readonly int $level,
        public readonly string $payer,
        public readonly ?\DateTimeImmutable $versionTime,
        public readonly string $reasons,
        public readonly int $lastLevelClosed,
        public readonly bool $postedSince,
        public readonly array $flags = [],
    ) {
    }
}
