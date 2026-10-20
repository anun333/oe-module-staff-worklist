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
    /** The work item's ref: the response's id, or for OpenEMR's own PHQ-9 or GAD-7 form "phq9-<form id>". */
    public readonly string $ref;

    /**
     * @param int                            $id     questionnaire_response.id, or the form's own id
     * @param array<string, InstrumentScore> $scores total code => score (ResponseScoring::read, CoreFormScoring::score)
     * @param string                         $form   the core form it came from ("phq9", "gad7"), or '' for a questionnaire response
     */
    public function __construct(
        public readonly int $id,
        public readonly int $pid,
        public readonly \DateTimeImmutable $date,
        public readonly array $scores,
        public readonly string $form = '',
    ) {
        $this->ref = ($form !== '' ? $form . '-' : '') . $id;
    }
}
