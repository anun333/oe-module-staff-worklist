<?php

/**
 * One social-needs screen: an SDOH history entry, or a Hunger Vital Sign or food security
 * questionnaire response, with the domains it is positive in (none for a negative screen).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Social;

final class SocialScreen
{
    /**
     * @param string       $kind    SocialNeedsRules::KIND_*
     * @param int          $id      form_history_sdoh.id, or questionnaire_response.id
     * @param list<string> $domains the domains it is positive in (SocialNeedsRules::POSITIVE_ANSWERS keys)
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $id,
        public readonly int $pid,
        public readonly \DateTimeImmutable $date,
        public readonly array $domains,
    ) {
    }

    /** The work item's ref: "sdoh-<id>" for an SDOH history entry, "qr-<id>" for a questionnaire response. */
    public function ref(): string
    {
        return ($this->kind === SocialNeedsRules::KIND_SDOH ? 'sdoh-' : 'qr-') . $this->id;
    }

    /** @param list<string> $domains */
    public function withDomains(array $domains): self
    {
        return new self($this->kind, $this->id, $this->pid, $this->date, $domains);
    }
}
