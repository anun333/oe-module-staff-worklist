<?php

/**
 * Which months the care management tab looks at, and how a row is named: the completed months
 * before this one (a month can't be billed as met until it's over), and one key per enrollment
 * and month, so a row dismissed for one month comes back for the next.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\CareManagement;

final class UnbilledMonths
{
    /** The completed months looked at: long enough to catch a month missed at month end, well inside Medicare's year to file. */
    public const MONTHS_BACK = 3;

    /**
     * The first day of each completed month before $today's, newest first.
     *
     * @return list<\DateTimeImmutable>
     */
    public static function previous(\DateTimeImmutable $today, int $count = self::MONTHS_BACK): array
    {
        $first = $today->setTime(0, 0)->modify('first day of this month');
        $out = [];
        for ($n = 1; $n <= $count; $n++) {
            $out[] = $first->modify("-$n months");
        }
        return $out;
    }

    /** A row's reference: the enrollment's id, then the month as YYYYMM, so it reads back as both. */
    public static function ref(int $enrollmentId, \DateTimeImmutable $monthFirst): string
    {
        return $enrollmentId . $monthFirst->format('Ym');
    }
}
