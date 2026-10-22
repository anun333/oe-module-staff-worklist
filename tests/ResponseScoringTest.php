<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Screening\InstrumentScore;
use OpenHealthcare\StaffWorklist\Screening\ResponseScoring;
use OpenHealthcare\StaffWorklist\Screening\ScreeningInstrument;
use PHPUnit\Framework\TestCase;

final class ResponseScoringTest extends TestCase
{
    private const ORDINAL = 'http://hl7.org/fhir/StructureDefinition/ordinalValue';

    /** LOINC's answer codes for 0 to 3: not at all, several days, more than half the days, nearly every day. */
    private const ANSWERS = ['LA6568-5' => 'Not at all', 'LA6569-3' => 'Several days', 'LA6570-1' => 'More than half the days', 'LA6571-9' => 'Nearly every day'];

    public function testAFinishedPhq9IsScoredFromItsAnswers(): void
    {
        // The saved total disagrees: the answers are what counts.
        $score = $this->phq9([0, 1, 2, 3, 0, 1, 2, 3, 1], savedTotal: 99);

        $this->assertEquals(new InstrumentScore(13, 13, 9, 9), $score);
        $this->assertTrue($score->isFinished());
    }

    public function testUnansweredQuestionsAreCountedAtTheirHighestForTheTopOfTheRange(): void
    {
        $score = $this->phq9([3, 3, null, 1, null, 0, null, null, 0]);

        $this->assertSame([7.0, 19.0, 5, 9], [$score->low, $score->high, $score->answered, $score->questions]);
        $this->assertFalse($score->isFinished());
    }

    public function testQuestion9LeftBlankCountsAsUnansweredLikeAnyOther(): void
    {
        $score = $this->phq9([1, 1, 1, 1, 1, 1, 1, 1, null]);

        $this->assertSame([8.0, 11.0, 8], [$score->low, $score->high, $score->answered]);
    }

    public function testAFormWithNothingAnsweredIsNoScreenWhateverTotalItSaved(): void
    {
        // OpenEMR's runtime shows and saves 0 for an empty PHQ-9.
        $this->assertSame([], $this->read($this->questionnaire(), $this->response(array_fill(0, 9, null), savedTotal: 0)));
    }

    public function testLoincsOwnPhq9WithItsTotalUncodedIsFoundByItsPanelCode(): void
    {
        $questionnaire = $this->questionnaire(totalCoded: false);
        $questionnaire['code'] = [['system' => 'http://loinc.org', 'code' => '44249-1']];

        $scores = $this->read($questionnaire, $this->response([3, 3, 3, 0, 0, 0, 0, 0, 0]));

        $this->assertSame([ScreeningInstrument::PHQ9], array_keys($scores));
        $this->assertSame(9.0, $scores[ScreeningInstrument::PHQ9]->low);
    }

    public function testAPhq9IsNotAlsoReadAsAPhq2ThoughItHasThePhq2Questions(): void
    {
        $this->assertSame([ScreeningInstrument::PHQ9], array_keys($this->read($this->questionnaire(), $this->response([3, 3, 0, 0, 0, 0, 0, 0, 0]))));
    }

    public function testAPhq4IsReadAsItsTwoPartsAndNotAsAPhq2(): void
    {
        // Shaped like Clinic Forms' PHQ-4: its panel code, the GAD-2's two questions then the PHQ-2's, a coded total.
        $codes = ['69725-0', '68509-9', '44250-9', '44255-8'];
        $questions = [];
        foreach ($codes as $code) {
            $options = [];
            foreach (array_keys(self::ANSWERS) as $points => $answer) {
                $options[] = ['valueCoding' => ['system' => 'http://loinc.org', 'code' => $answer, 'extension' => [['url' => self::ORDINAL, 'valueDecimal' => $points]]]];
            }
            $questions[] = ['linkId' => '/' . $code, 'code' => [['system' => 'http://loinc.org', 'code' => $code]], 'type' => 'choice', 'answerOption' => $options];
        }
        $questions[] = ['linkId' => 'b2.total', 'code' => [['system' => 'http://loinc.org', 'code' => '70272-0']], 'type' => 'decimal', 'readOnly' => true];
        $questionnaire = ['resourceType' => 'Questionnaire', 'code' => [['system' => 'http://loinc.org', 'code' => '69724-3']], 'item' => [['linkId' => 'b2', 'type' => 'group', 'item' => $questions]]];
        $answerCodes = array_keys(self::ANSWERS);
        $answer = static fn(string $code, int $points): array => ['linkId' => '/' . $code, 'answer' => [['valueCoding' => ['system' => 'http://loinc.org', 'code' => $answerCodes[$points]]]]];
        $response = ['resourceType' => 'QuestionnaireResponse', 'item' => [['linkId' => 'b2', 'item' => [
            $answer('69725-0', 2), $answer('68509-9', 2), $answer('44250-9', 0), $answer('44255-8', 1),
        ]]]];

        $scores = $this->read($questionnaire, $response);

        $this->assertEqualsCanonicalizing([ScreeningInstrument::PHQ4_DEPRESSION, ScreeningInstrument::PHQ4_ANXIETY], array_keys($scores));
        $this->assertEquals(new InstrumentScore(4, 4, 2, 2), $scores[ScreeningInstrument::PHQ4_ANXIETY]);
        $this->assertEquals(new InstrumentScore(1, 1, 2, 2), $scores[ScreeningInstrument::PHQ4_DEPRESSION]);
    }

    public function testWhenTheQuestionsArentCodedTheSavedTotalIsRead(): void
    {
        $questionnaire = $this->questionnaire();
        foreach ($questionnaire['item'][0]['item'] as $i => $question) {
            if ($question['linkId'] !== 'b2.total') {
                unset($questionnaire['item'][0]['item'][$i]['code']);
            }
        }

        $scores = $this->read($questionnaire, $this->response([1, 1, 1, 1, 1, 1, 1, 1, 1], savedTotal: 12));

        $this->assertEquals([ScreeningInstrument::PHQ9 => InstrumentScore::saved(12)], $scores);
    }

    public function testWhenNoAnswerCanBeScoredTheSavedTotalIsRead(): void
    {
        $questionnaire = $this->questionnaire(weights: false);

        // Codes the options don't weight, and no extension on the answers.
        $scores = $this->read($questionnaire, $this->response([2, 2, 2, 2, 2, 2, 0, 0, 0], savedTotal: 12, copyExtension: false));

        $this->assertEquals([ScreeningInstrument::PHQ9 => InstrumentScore::saved(12)], $scores);
    }

    public function testPointsComeFromTheAnswersOwnExtensionWhenTheOptionsCarryNone(): void
    {
        $scores = $this->read($this->questionnaire(weights: false), $this->response([2, 2, 2, 2, 2, 2, 0, 0, 0]));

        $this->assertSame(12.0, $scores[ScreeningInstrument::PHQ9]->low);
        $this->assertSame(9, $scores[ScreeningInstrument::PHQ9]->answered);
    }

    public function testAnItemWeightOnTheOptionIsReadToo(): void
    {
        $questionnaire = $this->questionnaire(weights: false);
        foreach ($questionnaire['item'][0]['item'] as $i => $question) {
            foreach ($question['answerOption'] ?? [] as $j => $option) {
                $questionnaire['item'][0]['item'][$i]['answerOption'][$j]['extension'] = [
                    ['url' => 'http://hl7.org/fhir/StructureDefinition/itemWeight', 'valueDecimal' => $j],
                ];
            }
        }

        $scores = $this->read($questionnaire, $this->response([3, 3, 3, 3, 0, 0, 0, 0, 0], copyExtension: false));

        $this->assertSame(12.0, $scores[ScreeningInstrument::PHQ9]->low);
    }

    public function testAnAnswerOutsideTheQuestionsRangeCountsAsUnanswered(): void
    {
        $response = $this->response([1, 1, 1, 1, 1, 1, 1, 1, 1]);
        $response['item'][0]['item'][0]['answer'] = [['valueInteger' => 7]];

        $score = $this->read($this->questionnaire(), $response)[ScreeningInstrument::PHQ9];

        $this->assertSame([8.0, 11.0, 8], [$score->low, $score->high, $score->answered]);
    }

    public function testAnswersNestedUnderAnAnswerAreFound(): void
    {
        $response = $this->response([1, 1, 1, 1, 1, 1, 1, 1, 1]);
        $group = $response['item'][0];
        $response['item'] = [['linkId' => 'outer', 'answer' => [['valueBoolean' => true, 'item' => [$group]]]]];

        $this->assertSame(9.0, $this->read($this->questionnaire(), $response)[ScreeningInstrument::PHQ9]->low);
    }

    public function testMalformedJsonGivesNothing(): void
    {
        $this->assertSame([], ResponseScoring::read('not json', '{}', ScreeningInstrument::all()));
        $this->assertSame([], ResponseScoring::read('{}', '[', ScreeningInstrument::all()));
    }

    /**
     * @param list<int|null> $answers points for each PHQ-9 question in order; null is unanswered
     */
    private function phq9(array $answers, ?float $savedTotal = null): InstrumentScore
    {
        $scores = $this->read($this->questionnaire(), $this->response($answers, $savedTotal));
        $this->assertArrayHasKey(ScreeningInstrument::PHQ9, $scores);
        return $scores[ScreeningInstrument::PHQ9];
    }

    /**
     * @param array<string, mixed> $questionnaire
     * @param array<string, mixed> $response
     * @return array<string, InstrumentScore>
     */
    private function read(array $questionnaire, array $response): array
    {
        return ResponseScoring::read((string)json_encode($questionnaire), (string)json_encode($response), ScreeningInstrument::all());
    }

    /** @return list<string> the PHQ-9's question codes, in order */
    private static function questionCodes(): array
    {
        return array_keys(ScreeningInstrument::all()[ScreeningInstrument::PHQ9]->questions);
    }

    /**
     * A PHQ-9 shaped like Clinic Forms' export: a group of coded questions whose options
     * carry ordinalValue, and a coded total.
     *
     * @return array<string, mixed>
     */
    private function questionnaire(bool $weights = true, bool $totalCoded = true): array
    {
        $questions = [];
        foreach (self::questionCodes() as $code) {
            $options = [];
            $points = 0;
            foreach (self::ANSWERS as $answer => $display) {
                $coding = ['system' => 'http://loinc.org', 'code' => $answer, 'display' => $display];
                if ($weights) {
                    $coding['extension'] = [['url' => self::ORDINAL, 'valueDecimal' => $points]];
                }
                $options[] = ['valueCoding' => $coding];
                $points++;
            }
            $questions[] = ['linkId' => '/' . $code, 'code' => [['system' => 'http://loinc.org', 'code' => $code]], 'type' => 'choice', 'answerOption' => $options];
        }
        $total = ['linkId' => 'b2.total', 'type' => 'decimal', 'readOnly' => true];
        if ($totalCoded) {
            $total['code'] = [['system' => 'http://loinc.org', 'code' => ScreeningInstrument::PHQ9]];
        }
        $questions[] = $total;
        return ['resourceType' => 'Questionnaire', 'item' => [['linkId' => 'b2', 'type' => 'group', 'item' => $questions]]];
    }

    /**
     * @param list<int|null> $answers
     * @return array<string, mixed>
     */
    private function response(array $answers, ?float $savedTotal = null, bool $copyExtension = true): array
    {
        $codes = array_keys(self::ANSWERS);
        $items = [];
        foreach (self::questionCodes() as $i => $code) {
            $points = $answers[$i] ?? null;
            if ($points === null) {
                continue;
            }
            $coding = ['system' => 'http://loinc.org', 'code' => $codes[$points], 'display' => self::ANSWERS[$codes[$points]]];
            if ($copyExtension) {
                // OpenEMR's runtime copies the option's extension into the answer.
                $coding['extension'] = [['url' => self::ORDINAL, 'valueDecimal' => $points]];
            }
            $items[] = ['linkId' => '/' . $code, 'answer' => [['valueCoding' => $coding]]];
        }
        if ($savedTotal !== null) {
            $items[] = ['linkId' => 'b2.total', 'answer' => [['valueDecimal' => $savedTotal]]];
        }
        return ['resourceType' => 'QuestionnaireResponse', 'item' => [['linkId' => 'b2', 'item' => $items]]];
    }
}
