<?php

/**
 * Where the staff guide is for the installed version: GUIDE.md at its release tag, so the Help
 * link never shows a later release's steps.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist;

final class Guide
{
    public const REPOSITORY = 'https://github.com/anun333/oe-module-staff-worklist';

    /** The module's version, from its version.php (the file OpenEMR's module manager reads). */
    public static function version(): string
    {
        $read = static function (string $file): string {
            $v_major = '0';
            $v_minor = '0';
            $v_patch = '0';
            include $file;
            return $v_major . '.' . $v_minor . '.' . $v_patch;
        };
        return $read(dirname(__DIR__) . '/version.php');
    }

    public static function url(): string
    {
        return self::REPOSITORY . '/blob/v' . self::version() . '/GUIDE.md';
    }
}
