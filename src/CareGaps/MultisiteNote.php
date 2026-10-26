<?php

/**
 * Whether the care gaps strip should say why its checks may be behind. From OpenEMR 8.2.0 to
 * 8.4.1, a background service started for a site other than "default" ran against the default
 * site's database (#14359, fixed in 8.5.0; the bug came with #11801 in 8.2.0). So on those
 * releases this module's care gaps job never refreshes another site's patients, and only
 * "Check now" does. The note shows only there, and only when the checks are behind.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\CareGaps;

final class MultisiteNote
{
    public const FIRST_AFFECTED = '8.2.0';
    public const FIXED_IN = '8.5.0';
    /** The job runs every 10 minutes; a day without a check means it isn't reaching this site. */
    public const BEHIND_HOURS = 24;

    /**
     * @param string                   $siteDir      OpenEMR's site folder (OE_SITE_DIR); its last part is the site
     * @param string                   $openEmrVersion such as 8.4.1, or '' when unknown
     * @param \DateTimeImmutable|null  $oldestCheck  the oldest care gaps check behind the listed items
     * @param int                      $unchecked    upcoming visits the job has not reached yet
     */
    public static function applies(
        string $siteDir,
        string $openEmrVersion,
        ?\DateTimeImmutable $oldestCheck,
        int $unchecked,
        \DateTimeImmutable $now,
    ): bool {
        $site = basename(rtrim(str_replace('\\', '/', $siteDir), '/'));
        if ($site === '' || $site === 'default') {
            return false;
        }
        if (preg_match('/^\d+\.\d+\.\d+/', $openEmrVersion, $m) !== 1
            || version_compare($m[0], self::FIRST_AFFECTED, '<')
            || version_compare($m[0], self::FIXED_IN, '>=')) {
            return false;
        }
        $behind = $oldestCheck !== null && $oldestCheck < $now->modify('-' . self::BEHIND_HOURS . ' hours');
        return $unchecked > 0 || $behind;
    }
}
