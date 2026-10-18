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
    /**
     * @param bool $sexUnknown the women's cut-off was used because the patient's sex isn't recorded as male or female
     * @param bool $severe     the total is in the paper's severe band
     */
    public function __construct(
        public readonly ScreenResponse $response,
        public readonly ScreeningInstrument $instrument,
        public readonly float $score,
        public readonly int $cutoff,
        public readonly bool $sexUnknown,
        public readonly bool $severe,
    ) {
    }
}
