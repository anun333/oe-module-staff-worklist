<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\Common\OpenEmr;
use OpenHealthcare\StaffWorklist\PageLanguage;
use PHPUnit\Framework\TestCase;

/** The page declares the user's language through openhealthcare-common, English when there's no session. */
final class PageLanguageTest extends TestCase
{
    public function testTheLibraryInstalledWithTheModuleHasTheLanguageHelpers(): void
    {
        self::assertTrue(method_exists(OpenEmr::class, 'pageLang'), 'composer.json asks for openhealthcare-common ^1.4.0');
        self::assertTrue(method_exists(OpenEmr::class, 'pageDir'));
    }

    public function testWithNoSignedInUserThePageIsEnglishLeftToRight(): void
    {
        self::assertSame('en', PageLanguage::lang());
        self::assertSame('ltr', PageLanguage::dir());
    }

    public function testAnOlderLibraryWithoutTheHelpersFallsBackToEnglishLeftToRight(): void
    {
        self::assertSame('en', PageLanguage::lang(OldLibraryOpenEmr::class));
        self::assertSame('ltr', PageLanguage::dir(OldLibraryOpenEmr::class));
    }

    public function testALibraryWithTheHelpersIsAsked(): void
    {
        self::assertSame('es', PageLanguage::lang(SpanishLibraryOpenEmr::class));
        self::assertSame('rtl', PageLanguage::dir(SpanishLibraryOpenEmr::class));
    }

    public function testThePageNoLongerHardCodesEnglish(): void
    {
        $page = (string)file_get_contents(__DIR__ . '/../public/index.php');
        self::assertDoesNotMatchRegularExpression('/<html\s+lang="en"/', $page);
        self::assertStringContainsString('<html lang="<?php echo attr(PageLanguage::lang()); ?>" dir="<?php echo attr(PageLanguage::dir()); ?>">', $page);
    }
}

/** openhealthcare-common's OpenEmr before 1.4.0: no pageLang() or pageDir(). */
final class OldLibraryOpenEmr
{
    public static function version(): string
    {
        return '8.4.1';
    }
}

/** A stand-in for 1.4.0's OpenEmr, answering for a signed-in user. */
final class SpanishLibraryOpenEmr
{
    public static function pageLang(): string
    {
        return 'es';
    }

    public static function pageDir(): string
    {
        return 'rtl';
    }
}
