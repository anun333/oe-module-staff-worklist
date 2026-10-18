<?php

/**
 * Which claims need billing staff, and why:
 *  - denied: the latest claim version is a denial OpenEMR recorded from a remittance
 *    (status 7, nothing posted), with nothing posted at that level since and the level open;
 *  - no response: billed to an insurer longer ago than the policy allows, with nothing
 *    posted at that level since and the level open;
 *  - flagged: staff ticked Follow Up on a line while posting.
 * A denial outranks no response, which outranks a flag; the facts of the others are kept.
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
        if ($open && $claim->status === ClaimRecord::STATUS_BILLED) {
            $days = PriorityPolicy::ageDays($claim->versionTime, $this->now);
            if ($days > $this->policy->claimNoResponseDays) {
                $priority = $days > $this->policy->claimNoResponseHighDays ? WorkItem::PRIORITY_HIGH : WorkItem::PRIORITY_NORMAL;
                return ['kind' => self::NO_RESPONSE, 'priority' => $priority, 'since' => $claim->versionTime, 'flagged' => $flagged];
            }
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

    /** A reason code's description without the CARC list's "Usage:" note. */
    public static function shortDescription(string $description): string
    {
        $cut = strpos($description, ' Usage:');
        return trim($cut === false ? $description : substr($description, 0, $cut));
    }
}
