<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\CareGaps\MultisiteNote;
use PHPUnit\Framework\TestCase;

final class MultisiteNoteTest extends TestCase
{
    private const NOW = '2026-10-26 12:00:00';
    private const OTHER_SITE = '/var/www/localhost/htdocs/openemr/sites/clinic2';

    public function testItShowsOnAnotherSiteFrom82To841WhenTheChecksAreBehind(): void
    {
        foreach (['8.2.0', '8.3.0', '8.4.0', '8.4.1'] as $version) {
            $this->assertTrue(self::applies(self::OTHER_SITE, $version, '2026-10-25 11:00:00', 0), "$version, checked 25 hours ago");
            $this->assertTrue(self::applies(self::OTHER_SITE, $version, null, 3), "$version, visits not checked yet");
        }
        $this->assertTrue(self::applies(self::OTHER_SITE . '/', '8.4.1-dev', null, 1), 'a trailing slash and a suffix');
        $this->assertTrue(self::applies('C:\\openemr\\sites\\clinic2', '8.3.0', null, 1), 'Windows paths');
    }

    public function testItStaysOffWhereTheJobReachesTheSite(): void
    {
        $this->assertFalse(self::applies('/var/www/openemr/sites/default', '8.4.1', null, 5), 'the default site');
        $this->assertFalse(self::applies(self::OTHER_SITE, '8.5.0', null, 5), 'fixed in 8.5.0');
        $this->assertFalse(self::applies(self::OTHER_SITE, '8.6.0', null, 5));
        $this->assertFalse(self::applies(self::OTHER_SITE, '8.1.0', null, 5), 'before the bug (8.2.0)');
        $this->assertFalse(self::applies(self::OTHER_SITE, '', null, 5), 'version unknown');
        $this->assertFalse(self::applies('', '8.4.1', null, 5), 'site unknown');
    }

    public function testItStaysOffWhenTheChecksAreCurrent(): void
    {
        $this->assertFalse(self::applies(self::OTHER_SITE, '8.4.1', '2026-10-26 11:00:00', 0), 'checked an hour ago');
        $this->assertFalse(self::applies(self::OTHER_SITE, '8.4.1', '2026-10-25 12:00:00', 0), 'exactly a day');
        $this->assertFalse(self::applies(self::OTHER_SITE, '8.4.1', null, 0), 'nothing to check');
    }

    private static function applies(string $siteDir, string $version, ?string $oldestCheck, int $unchecked): bool
    {
        return MultisiteNote::applies($siteDir, $version, $oldestCheck === null ? null : new \DateTimeImmutable($oldestCheck), $unchecked, new \DateTimeImmutable(self::NOW));
    }
}
