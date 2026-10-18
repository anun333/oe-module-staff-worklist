<?php

/**
 * A coded record that can show a screening result was acted on: a problem on the
 * problem list, or a diagnosis or service billed at a visit.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Screening;

final class FollowUpRecord
{
    /**
     * @param string                  $codeType ICD10, CPT4 or HCPCS
     * @param \DateTimeImmutable|null $until    a problem's end date, a visit's date; null for a problem still open
     */
    public function __construct(
        public readonly int $pid,
        public readonly string $codeType,
        public readonly string $code,
        public readonly ?\DateTimeImmutable $until,
    ) {
    }

    /** Whether the record stands on or after a screen's day: a problem not ended before it, a visit that day or later. */
    public function standsOnOrAfter(\DateTimeImmutable $day): bool
    {
        return $this->until === null || $this->until->setTime(0, 0) >= $day->setTime(0, 0);
    }
}
