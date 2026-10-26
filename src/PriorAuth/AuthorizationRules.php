<?php

/**
 * Which prior authorizations need someone, from the rows OpenEMR's Prior Authorizations module
 * keeps (module_prior_authorizations: start and end dates, units at the start and remaining).
 * One in force now is listed when:
 *  - its units are used up (high priority: the next visit isn't covered);
 *  - it ends within 14 days;
 *  - a tenth or less of its units are left, and at most one in any case worth asking for.
 * One not started yet, or already ended, isn't listed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\PriorAuth;

use OpenHealthcare\StaffWorklist\Model\WorkItem;

final class AuthorizationRules
{
    public const USED_UP = 'used_up';
    public const ENDING = 'ending';
    public const UNITS_LOW = 'units_low';
    public const ENDING_DAYS = 14;
    /** Each condition's digit in an item key (ItemKey); fixed, since saved dismissals carry it. */
    public const KEY_DIGITS = [self::USED_UP => 1, self::ENDING => 2, self::UNITS_LOW => 3];

    /** An item's key ref: the authorization's id and its condition's digit, so "ends soon" never hides "units used up". */
    public static function keyRef(int $id, string $status): string
    {
        return $id . '.' . (self::KEY_DIGITS[$status] ?? 9);
    }

    /**
     * @return array{status: string, priority: int, since: \DateTimeImmutable}|null
     */
    public static function decide(?\DateTimeImmutable $start, ?\DateTimeImmutable $end, ?int $initial, ?int $remaining, \DateTimeImmutable $today): ?array
    {
        $today = $today->setTime(0, 0);
        if (($start !== null && $start->setTime(0, 0) > $today) || ($end !== null && $end->setTime(0, 0) < $today)) {
            return null;
        }
        if ($remaining !== null && $remaining <= 0) {
            return ['status' => self::USED_UP, 'priority' => WorkItem::PRIORITY_HIGH, 'since' => $today];
        }
        if ($end !== null && $end->setTime(0, 0) <= $today->modify('+' . self::ENDING_DAYS . ' days')) {
            return ['status' => self::ENDING, 'priority' => WorkItem::PRIORITY_NORMAL, 'since' => $end->setTime(0, 0)];
        }
        if ($remaining !== null && $initial !== null && $initial > 1 && $remaining <= max(1, intdiv($initial, 10))) {
            return ['status' => self::UNITS_LOW, 'priority' => WorkItem::PRIORITY_NORMAL, 'since' => $today];
        }
        return null;
    }
}
