<?php

/**
 * A screening instrument the worklist follows up: the LOINC code of its total, the
 * published cut-off, and the codes that show the result was acted on. The cut-offs
 * and their wording are from the papers cited, as checked in Clinic Forms'
 * instrument references (anun333/oe-module-clinic-forms, data/instruments).
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
                'the paper\'s best cut-point for screening; the PHQ-9 follows',
                'Kroenke K, Spitzer RL, Williams JBW. Med Care. 2003;41(11):1284-1292. PMID 14583691',
                null,
                self::PHQ9,
                self::DEPRESSION,
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
