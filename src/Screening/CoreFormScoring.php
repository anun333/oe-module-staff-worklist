<?php

/**
 * Scores OpenEMR's own PHQ-9 and GAD-7 encounter forms (interface/forms/phq9 and gad7, tables
 * form_phq9 and form_gad7), which keep each question's points in a column of its own: "0" to "3",
 * or "undef" for a question left unanswered. The total goes under the instrument's LOINC total
 * code, so the same cut-offs and follow-up rules apply as to a questionnaire response. The forms'
 * own difficulty question isn't scored, as on the published instruments.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Screening;

final class CoreFormScoring
{
    /** Each form: its table, the instrument's total code, and its scored questions' columns in order. */
    public const FORMS = [
        'phq9' => [
            'table' => 'form_phq9',
            'name' => 'PHQ-9',
            'code' => ScreeningInstrument::PHQ9,
            'columns' => ['interest_score', 'hopeless_score', 'sleep_score', 'fatigue_score', 'appetite_score',
                'failure_score', 'focus_score', 'psychomotor_score', 'suicide_score'],
        ],
        'gad7' => [
            'table' => 'form_gad7',
            'name' => 'GAD-7',
            'code' => ScreeningInstrument::GAD7,
            'columns' => ['nervous_score', 'control_worry_score', 'worry_score', 'relax_score',
                'restless_score', 'irritable_score', 'fear_score'],
        ],
    ];

    /** The most points one question gives on both forms. */
    private const MOST = 3;

    /**
     * The form's score under its instrument's total code; empty when no question was answered.
     *
     * @param array<string, mixed> $row a form_phq9 or form_gad7 row
     * @return array<string, InstrumentScore>
     */
    public static function score(string $formdir, array $row): array
    {
        $form = self::FORMS[$formdir] ?? null;
        if ($form === null) {
            return [];
        }
        $low = 0;
        $answered = 0;
        foreach ($form['columns'] as $column) {
            $value = $row[$column] ?? null;
            if (is_string($value) && preg_match('/^[0-3]$/', trim($value)) === 1) {
                $low += (int)trim($value);
                $answered++;
            } elseif (is_int($value) && $value >= 0 && $value <= self::MOST) {
                $low += $value;
                $answered++;
            }
        }
        if ($answered === 0) {
            return [];
        }
        $questions = count($form['columns']);
        return [$form['code'] => new InstrumentScore($low, $low + self::MOST * ($questions - $answered), $answered, $questions)];
    }
}
