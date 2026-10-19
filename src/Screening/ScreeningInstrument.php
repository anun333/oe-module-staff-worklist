<?php

/**
 * A screening instrument the worklist follows up: the LOINC codes of its total and its
 * panel, its scored questions with the most points each can give, the published
 * cut-off, and the codes that show the result was acted on. The cut-offs and their
 * wording are from the papers cited; the questions and points are LOINC's, as checked
 * in Clinic Forms' instrument references (anun333/oe-module-clinic-forms, data/instruments).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Screening;

final class ScreeningInstrument
{
    public const PHQ2 = '55758-7';
    public const PHQ9 = '44261-6';
    public const GAD7 = '70274-6';
    public const AUDITC = '75626-2';

    // ICD-10 codes, as prefixes, for a problem or visit diagnosis of the condition screened for.
    private const DEPRESSION = ['ICD10' => ['F32', 'F33', 'F34.1']];


    /** Each PHQ and GAD-7 question scores 0 (not at all) to 3 (nearly every day). */
    private const PHQ2_QUESTIONS = ['44250-9' => 3, '44255-8' => 3];

    /**
     * @param string                       $name        the instrument's usual name
     * @param string                       $totalCode   LOINC code of its total score item
     * @param int                          $cutoff      listed at or above this total
     * @param int|null                     $womenCutoff a lower cut-off the paper gives for women (AUDIT-C)
     * @param string                       $meaning     what the paper says the cut-off means, untranslated
     * @param string                       $source      the paper, with its PubMed id
     * @param int|null                     $severeAt    the paper's severe band starts here (High priority)
     * @param string|null                  $followedBy  total code of the longer instrument that follows it
     * @param array<string, list<string>>  $codes       code type => code prefixes that show it was acted on
     * @param string                       $panelCode   LOINC code of the whole instrument, as Questionnaire.code
     * @param array<string, int>           $questions   LOINC code of each scored question => the most points it gives
     * @param int                          $keyDigit    1 to 8, telling apart this instrument's unfinished-screen items
     */
    public function __construct(
        public readonly string $name,
        public readonly string $totalCode,
        public readonly int $cutoff,
        public readonly ?int $womenCutoff,
        public readonly string $meaning,
        public readonly string $source,
        public readonly ?int $severeAt,
        public readonly ?string $followedBy,
        public readonly array $codes,
        public readonly string $panelCode = '',
        public readonly array $questions = [],
        public readonly int $keyDigit = 0,
    ) {
    }

    /**
     * The instruments followed up, by total code.
     *
     * @return array<string, self>
     */
    public static function all(): array
    {
        $list = [
            new self(
                'PHQ-2',
                self::PHQ2,
                3,
                null,
                'the best cut-point for screening in the paper; the PHQ-9 follows',
                'Kroenke K, Spitzer RL, Williams JBW. Med Care. 2003;41(11):1284-1292. PMID 14583691',
                null,
                self::PHQ9,
                self::DEPRESSION,
                panelCode: '55757-9',
                questions: self::PHQ2_QUESTIONS,
                keyDigit: 1,
            ),
            new self(
                'PHQ-9',
                self::PHQ9,
                10,
                null,
                'moderate depression or more; 88% sensitivity and 88% specificity for major depression',
                'Kroenke K, Spitzer RL, Williams JBW. J Gen Intern Med. 2001;16(9):606-613. PMID 11556941',
                20,
                null,
                self::DEPRESSION,
                panelCode: '44249-1',
                questions: self::PHQ2_QUESTIONS + [
                    '44259-0' => 3, '44254-1' => 3, '44251-7' => 3, '44258-2' => 3,
                    '44252-5' => 3, '44253-3' => 3, '44260-8' => 3,
                ],
                keyDigit: 2,
            ),
            new self(
                'GAD-7',
                self::GAD7,
                10,
                null,
                'moderate anxiety or more; the cut point for generalized anxiety disorder (sensitivity 89%, specificity 82%)',
                'Spitzer RL, Kroenke K, Williams JBW, Löwe B. Arch Intern Med. 2006;166(10):1092-1097. PMID 16717171',
                15,
                null,
                ['ICD10' => ['F41']],
                panelCode: '69737-5',
                questions: [
                    '69725-0' => 3, '68509-9' => 3, '69733-4' => 3, '69734-2' => 3,
                    '69735-9' => 3, '69689-8' => 3, '69736-7' => 3,
                ],
                keyDigit: 3,
            ),
            new self(
                'AUDIT-C',
                self::AUDITC,
                4,
                3,
                'the thresholds that best balanced sensitivity and specificity for alcohol misuse: 4 or more for men, 3 or more for women',
                'Bradley KA, DeBenedetti AF, Volk RJ, et al. Alcohol Clin Exp Res. 2007;31(7):1208-1217. PMID 17451397',
                null,
                null,
                ['ICD10' => ['F10', 'Z71.41'], 'CPT4' => ['99408', '99409'], 'HCPCS' => ['G0443']],
                panelCode: '72109-2',
                // Each AUDIT-C question scores 0 to 4.
                questions: ['68518-0' => 4, '68519-8' => 4, '68520-6' => 4],
                keyDigit: 4,
            ),
        ];
        $byCode = [];
        foreach ($list as $instrument) {
            $byCode[$instrument->totalCode] = $instrument;
        }
        return $byCode;
    }

    /**
     * The cut-off for a patient, and whether it had to be chosen without knowing their sex.
     *
     * @param string|null $sex patient_data.sex
     * @return array{int, bool} the cut-off, and true when the women's cut-off stood in for an unrecorded sex
     */
    public function cutoffFor(?string $sex): array
    {
        if ($this->womenCutoff === null) {
            return [$this->cutoff, false];
        }
        return match (strtolower(trim((string)$sex))) {
            'male' => [$this->cutoff, false],
            'female' => [$this->womenCutoff, false],
            default => [$this->womenCutoff, true],
        };
    }

    /** Whether a code (as OpenEMR stores it, with or without dots) shows this result was acted on. */
    public function isFollowUpCode(string $codeType, string $code): bool
    {
        $code = strtoupper(str_replace('.', '', trim($code)));
        foreach ($this->codes[strtoupper(trim($codeType))] ?? [] as $prefix) {
            if ($code !== '' && str_starts_with($code, str_replace('.', '', $prefix))) {
                return true;
            }
        }
        return false;
    }
}
