<?php

/**
 * A screening total at or above its published cut-off with nothing recorded after it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Screening;

final class ScreenFinding
{
    /** The total, counting only the questions answered, is at or above the cut-off. */
    public const AT_CUTOFF = 'at-cutoff';

    /** Questions are unanswered, and the total could end up either side of the cut-off. */
    public const UNFINISHED = 'unfinished';

    /**
     * @param string $kind       one of the constants above
     * @param float  $score      the points of the questions answered
     * @param bool   $sexUnknown the women's cut-off was used because the patient's sex isn't recorded as male or female
     * @param bool   $severe     the total is in the paper's severe band
     */
    public function __construct(
        public readonly ScreenResponse $response,
        public readonly ScreeningInstrument $instrument,
        public readonly float $score,
        public readonly int $cutoff,
        public readonly bool $sexUnknown,
        public readonly bool $severe,
        public readonly string $kind = self::AT_CUTOFF,
        public readonly ?InstrumentScore $detail = null,
    ) {
    }
}
