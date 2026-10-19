<?php

/**
 * Scores a saved questionnaire response from its answers, for each instrument the
 * Questionnaire carries. An instrument is carried when the Questionnaire, or one of its
 * items, has the instrument's total code or panel code; its questions are found by
 * their LOINC codes, never the other way round, so the PHQ-2's two questions inside a
 * PHQ-9 don't make it a PHQ-2.
 *
 * An answer's points come from the Questionnaire's answer options (the ordinalValue or
 * itemWeight extension of the option that has the answer's code), else from the
 * answer's own copy of the extension, which OpenEMR's runtime keeps. An answer whose
 * points can't be read counts as unanswered, so it can widen the range but never
 * lower the total. When the Questionnaire doesn't code the instrument's questions, the
 * total it saved is read instead, as 0.2.0 did (ResponseTotals).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Screening;

final class ResponseScoring
{
    /** R4's ordinalValue, R5's itemWeight, and the two older forms LOINC and LHC-Forms have used. */
    private const POINTS_EXTENSIONS = [
        'http://hl7.org/fhir/StructureDefinition/ordinalValue',
        'http://hl7.org/fhir/StructureDefinition/itemWeight',
        'http://hl7.org/fhir/StructureDefinition/iso21090-CO-value',
        'http://hl7.org/fhir/StructureDefinition/questionnaire-ordinalValue',
    ];

    /**
     * @param array<string, ScreeningInstrument> $instruments by total code
     * @return array<string, InstrumentScore> total code => score, for the instruments the response has answers to
     */
    public static function read(string $questionnaireJson, string $responseJson, array $instruments): array
    {
        $questionnaire = json_decode($questionnaireJson, true);
        $response = json_decode($responseJson, true);
        if (!is_array($questionnaire) || !is_array($response)) {
            return [];
        }

        $codes = array_fill_keys(self::codes($questionnaire['code'] ?? null), true);
        $linkIdOf = [];
        $points = [];
        self::walkQuestionnaire($questionnaire['item'] ?? [], $codes, $linkIdOf, $points);
        $answers = [];
        self::walkResponse($response['item'] ?? [], $answers);
        $saved = ResponseTotals::read($questionnaireJson, $responseJson, array_keys($instruments));

        $scores = [];
        foreach ($instruments as $code => $instrument) {
            $carried = isset($codes[$instrument->totalCode]) || ($instrument->panelCode !== '' && isset($codes[$instrument->panelCode]));
            if (!$carried) {
                continue;
            }
            $score = self::score($instrument, $linkIdOf, $points, $answers, $saved[$code] ?? null);
            if ($score !== null) {
                $scores[$code] = $score;
            }
        }
        return $scores;
    }

    /**
     * @param array<string, string>               $linkIdOf question code => linkId
     * @param array<string, array<string, float>> $points   linkId => answer code => points
     * @param array<string, array<string, mixed>> $answers  linkId => first answer
     */
    private static function score(ScreeningInstrument $instrument, array $linkIdOf, array $points, array $answers, ?float $savedTotal): ?InstrumentScore
    {
        $low = 0.0;
        $high = 0.0;
        $answered = 0;
        $asked = 0;
        $present = 0;
        foreach ($instrument->questions as $questionCode => $most) {
            $linkId = $linkIdOf[$questionCode] ?? null;
            $answer = $linkId === null ? null : ($answers[$linkId] ?? null);
            $asked += $linkId === null ? 0 : 1;
            $present += $answer === null ? 0 : 1;
            $value = $answer === null || $linkId === null ? null : self::points($answer, $points[$linkId] ?? [], $most);
            if ($value === null) {
                $high += $most;
            } else {
                $low += $value;
                $high += $value;
                $answered++;
            }
        }
        if ($asked === 0 || ($answered === 0 && $present > 0)) {
            // The questions aren't coded, or none of their answers can be scored: read the saved total.
            return $savedTotal === null ? null : InstrumentScore::saved($savedTotal);
        }
        if ($answered === 0) {
            // Nothing answered is no screen, whatever total the runtime shows for an empty form.
            return null;
        }
        return new InstrumentScore($low, $high, $answered, count($instrument->questions));
    }

    /**
     * @param array<string, mixed> $answer
     * @param array<string, float> $optionPoints answer code => points
     * @return float|null the answer's points, or null when it can't be scored
     */
    private static function points(array $answer, array $optionPoints, int $most): ?float
    {
        $coding = is_array($answer['valueCoding'] ?? null) ? $answer['valueCoding'] : null;
        if ($coding !== null) {
            $code = is_string($coding['code'] ?? null) ? $coding['code'] : null;
            $value = $code !== null && isset($optionPoints[$code]) ? $optionPoints[$code] : self::extensionPoints($coding['extension'] ?? null);
        } else {
            $raw = $answer['valueInteger'] ?? $answer['valueDecimal'] ?? null;
            $value = is_int($raw) || is_float($raw) ? (float)$raw : null;
        }
        return $value === null || $value < 0 || $value > $most ? null : $value;
    }

    private static function extensionPoints(mixed $extensions): ?float
    {
        foreach (is_array($extensions) ? $extensions : [] as $extension) {
            if (!is_array($extension) || !in_array($extension['url'] ?? null, self::POINTS_EXTENSIONS, true)) {
                continue;
            }
            $value = $extension['valueDecimal'] ?? $extension['valueInteger'] ?? null;
            if (is_int($value) || is_float($value)) {
                return (float)$value;
            }
        }
        return null;
    }

    /** @return list<string> */
    private static function codes(mixed $codings): array
    {
        $out = [];
        foreach (is_array($codings) ? $codings : [] as $coding) {
            if (is_array($coding) && is_string($coding['code'] ?? null) && $coding['code'] !== '') {
                $out[] = $coding['code'];
            }
        }
        return $out;
    }

    /**
     * @param array<string, true>                 $codes    filled in: every code on an item
     * @param array<string, string>               $linkIdOf filled in: question code => the first linkId with it
     * @param array<string, array<string, float>> $points   filled in
     */
    private static function walkQuestionnaire(mixed $items, array &$codes, array &$linkIdOf, array &$points): void
    {
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $linkId = is_string($item['linkId'] ?? null) ? $item['linkId'] : null;
            foreach (self::codes($item['code'] ?? null) as $code) {
                $codes[$code] = true;
                if ($linkId !== null) {
                    $linkIdOf[$code] ??= $linkId;
                }
            }
            foreach ($linkId === null || !is_array($item['answerOption'] ?? null) ? [] : $item['answerOption'] as $option) {
                $coding = is_array($option) && is_array($option['valueCoding'] ?? null) ? $option['valueCoding'] : null;
                $code = $coding !== null && is_string($coding['code'] ?? null) ? $coding['code'] : null;
                if ($code === null) {
                    continue;
                }
                $value = self::extensionPoints($coding['extension'] ?? null) ?? self::extensionPoints($option['extension'] ?? null);
                if ($value !== null) {
                    $points[$linkId][$code] = $value;
                }
            }
            self::walkQuestionnaire($item['item'] ?? [], $codes, $linkIdOf, $points);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $answers filled in: linkId => its first answer
     */
    private static function walkResponse(mixed $items, array &$answers): void
    {
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $linkId = $item['linkId'] ?? null;
            $first = is_array($item['answer'] ?? null) ? ($item['answer'][0] ?? null) : null;
            if (is_string($linkId) && is_array($first) && !isset($answers[$linkId])) {
                $answers[$linkId] = $first;
            }
            self::walkResponse($item['item'] ?? [], $answers);
            foreach (is_array($item['answer'] ?? null) ? $item['answer'] : [] as $answer) {
                if (is_array($answer)) {
                    self::walkResponse($answer['item'] ?? [], $answers);
                }
            }
        }
    }
}
