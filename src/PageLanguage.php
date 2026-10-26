<?php

/**
 * The language and direction a page declares on its html element: the user's OpenEMR language,
 * from openhealthcare-common 1.4.0's OpenEmr::pageLang() and pageDir().
 *
 * A copied-in install loads the shared library from whichever module registers it first, so an
 * older module's copy (before 1.4.0, without these methods) can be the one in use. Then the page
 * says English, left to right, as it did before 0.8.7, rather than stop.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist;

use OpenHealthcare\Common\OpenEmr;

final class PageLanguage
{
    /**
     * A BCP 47 tag, such as en or es.
     *
     * @param class-string $library the shared library's OpenEmr class (a parameter so a test can hand in an old one)
     */
    public static function lang(string $library = OpenEmr::class): string
    {
        return method_exists($library, 'pageLang') ? (string)$library::pageLang() : 'en';
    }

    /**
     * ltr or rtl.
     *
     * @param class-string $library
     */
    public static function dir(string $library = OpenEmr::class): string
    {
        return method_exists($library, 'pageDir') ? (string)$library::pageDir() : 'ltr';
    }
}
