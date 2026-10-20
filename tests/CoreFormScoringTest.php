<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Screening\CoreFormScoring;
use OpenHealthcare\StaffWorklist\Screening\InstrumentScore;
use OpenHealthcare\StaffWorklist\Screening\ScreeningInstrument;
use PHPUnit\Framework\TestCase;

final class CoreFormScoringTest extends TestCase
{
    /** @param list<string> $values */
    private static function phq9(array $values): array
    {
        return array_combine(CoreFormScoring::FORMS['phq9']['columns'], $values) + ['difficulty' => '3'];
    }

    public function testAFinishedPhq9FormIsItsTotal(): void
    {
        $score = CoreFormScoring::score('phq9', self::phq9(['3', '3', '2', '2', '1', '1', '0', '0', '0']))[ScreeningInstrument::PHQ9] ?? null;
        self::assertEquals(new InstrumentScore(12, 12, 9, 9), $score);
        self::assertTrue($score->isFinished());
    }

    public function testAnUnansweredQuestionWidensTheRangeNeverLowersIt(): void
    {
        $score = CoreFormScoring::score('phq9', self::phq9(['3', '3', 'undef', '2', '1', '1', '0', '0', '']))[ScreeningInstrument::PHQ9] ?? null;
        self::assertEquals(new InstrumentScore(10, 16, 7, 9), $score, 'two unanswered, up to 3 points each');
    }

    public function testTheDifficultyQuestionIsNotScored(): void
    {
        $quiet = CoreFormScoring::score('phq9', self::phq9(array_fill(0, 9, '0')))[ScreeningInstrument::PHQ9];
        self::assertSame(0.0, $quiet->low, 'difficulty "3" adds nothing');
    }

    public function testGad7AndItsCode(): void
    {
        $row = array_combine(CoreFormScoring::FORMS['gad7']['columns'], ['2', '2', '2', '1', '1', '1', '1']);
        self::assertEquals([ScreeningInstrument::GAD7 => new InstrumentScore(10, 10, 7, 7)], CoreFormScoring::score('gad7', $row));
    }

    public function testNothingAnsweredOrAnUnknownFormGivesNothing(): void
    {
        self::assertSame([], CoreFormScoring::score('phq9', self::phq9(array_fill(0, 9, 'undef'))));
        self::assertSame([], CoreFormScoring::score('phq9', self::phq9(array_fill(0, 9, '4'))), 'out of range is not an answer');
        self::assertSame([], CoreFormScoring::score('audit', []));
    }
}
