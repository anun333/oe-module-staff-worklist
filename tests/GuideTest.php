<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\Common\GuideLabels;
use OpenHealthcare\StaffWorklist\Guide;
use PHPUnit\Framework\TestCase;

/**
 * GUIDE.md quotes the labels staff click in bold; each must be a string the module translates,
 * so a renamed button fails here instead of leaving the guide pointing at nothing. The Help link
 * opens the guide at the installed version's tag.
 */
final class GuideTest extends TestCase
{
    public function testEveryLabelTheGuideQuotesIsOnThePage(): void
    {
        $result = GuideLabels::check(dirname(__DIR__));
        self::assertGreaterThan(40, $result['labels'], 'the guide quotes its labels');
        self::assertGreaterThan(100, $result['strings'], 'the module translates its strings');
        self::assertSame([], $result['missing']);
    }

    public function testTheHelpLinkOpensTheGuideAtThisVersion(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Guide::version());
        self::assertSame('https://github.com/anun333/oe-module-staff-worklist/blob/v' . Guide::version() . '/GUIDE.md', Guide::url());
        self::assertStringContainsString("\$v_minor = '" . explode('.', Guide::version())[1] . "';", (string)file_get_contents(dirname(__DIR__) . '/version.php'));
    }
}
