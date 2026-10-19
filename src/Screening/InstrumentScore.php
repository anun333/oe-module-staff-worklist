<?php

/**
 * What one response says about one instrument: the points its answered questions give,
 * and the most the total could reach if every unanswered question were answered at its
 * highest. Nothing is filled in for a skipped question, so a total at or above the
 * cut-off with questions missing is still at or above it, and one that couldn't reach
 * the cut-off still couldn't; anything between is an unfinished screen.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Screening;

final class InstrumentScore
{
    /**
     * @param float $low       the points of the questions answered: the total so far
     * @param float $high      $low plus the most each unanswered question could add
     * @param int   $answered  scored questions answered
     * @param int   $questions the instrument's scored questions; 0 when only a saved total could be read
     */
    public function __construct(
        public readonly float $low,
        public readonly float $high,
        public readonly int $answered,
        public readonly int $questions,
    ) {
    }

    /** A total read as saved, with no questions to count: the 0.2.0 reading, kept for questionnaires whose questions aren't coded. */
    public static function saved(float $total): self
    {
        return new self($total, $total, 0, 0);
    }

    public function isFinished(): bool
    {
        return $this->answered >= $this->questions;
    }
}
