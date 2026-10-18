<?php

/**
 * The coded totals in a saved questionnaire response. OpenEMR's questionnaire runtime
 * saves the total as the answer of the Questionnaire's total item; the response
 * doesn't repeat the item's code, so the code comes from the Questionnaire, matched
 * by linkId.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Screening;

final class ResponseTotals
{
    /**
     * @param list<string> $wanted total codes to look for
     * @return array<string, float> total code => the answer saved for it; codes with no numeric answer left out
     */
    public static function read(string $questionnaireJson, string $responseJson, array $wanted): array
    {
        $questionnaire = json_decode($questionnaireJson, true);
        $response = json_decode($responseJson, true);
        if (!is_array($questionnaire) || !is_array($response)) {
            return [];
        }
        $codeOf = [];
        self::walkQuestionnaire($questionnaire['item'] ?? [], array_fill_keys($wanted, true), $codeOf);
        $totals = [];
        self::walkResponse($response['item'] ?? [], $codeOf, $totals);
        return $totals;
    }

    /**
     * @param array<string, true>   $wanted
     * @param array<string, string> $codeOf linkId => total code, filled in
     */
    private static function walkQuestionnaire(mixed $items, array $wanted, array &$codeOf): void
    {
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $linkId = $item['linkId'] ?? null;
            foreach (is_array($item['code'] ?? null) ? $item['code'] : [] as $coding) {
                $code = is_array($coding) ? ($coding['code'] ?? null) : null;
                if (is_string($linkId) && is_string($code) && isset($wanted[$code])) {
                    $codeOf[$linkId] = $code;
                }
            }
            self::walkQuestionnaire($item['item'] ?? [], $wanted, $codeOf);
        }
    }

    /**
     * @param array<string, string> $codeOf
     * @param array<string, float>  $totals filled in; the first answer found for a code wins
     */
    private static function walkResponse(mixed $items, array $codeOf, array &$totals): void
    {
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $linkId = $item['linkId'] ?? null;
            if (is_string($linkId) && isset($codeOf[$linkId]) && !isset($totals[$codeOf[$linkId]])) {
                $value = self::number($item['answer'] ?? null);
                if ($value !== null) {
                    $totals[$codeOf[$linkId]] = $value;
                }
            }
            self::walkResponse($item['item'] ?? [], $codeOf, $totals);
            foreach (is_array($item['answer'] ?? null) ? $item['answer'] : [] as $answer) {
                if (is_array($answer)) {
                    self::walkResponse($answer['item'] ?? [], $codeOf, $totals);
                }
            }
        }
    }

    private static function number(mixed $answers): ?float
    {
        $answer = is_array($answers) ? ($answers[0] ?? null) : null;
        if (!is_array($answer)) {
            return null;
        }
        $value = $answer['valueDecimal'] ?? $answer['valueInteger'] ?? (is_array($answer['valueQuantity'] ?? null) ? ($answer['valueQuantity']['value'] ?? null) : null);
        return is_int($value) || is_float($value) ? (float)$value : null;
    }
}
