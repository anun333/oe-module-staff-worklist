<?php

/**
 * One saved questionnaire response, reduced to what the screening rules need.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Screening;

final class ScreenResponse
{
    /**
     * @param int                  $id     questionnaire_response.id
     * @param array<string, float> $totals total code => score (ResponseTotals::read)
     */
    public function __construct(
        public readonly int $id,
        public readonly int $pid,
        public readonly \DateTimeImmutable $date,
        public readonly array $totals,
    ) {
    }
}
