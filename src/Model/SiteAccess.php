<?php

/**
 * Which sites (OpenEMR facilities) a user may see work for.
 *
 * Mirrors OpenEMR's "Restrict Users to Facilities" setting: when it is on and
 * the user has facilities set in User admin, they reach only their default
 * facility plus those (getUserFacilities() in library/calendar.inc.php).
 * Otherwise they reach every site.
 *
 * Under restriction a user still sees items they own (ordering, referring or
 * visit provider) or were assigned: they are responsible for that work and
 * already have the treatment relationship with the patient.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Model;

final class SiteAccess
{
    /** Pseudo site id for work whose site could not be determined. */
    public const NO_SITE = 0;

    /**
     * @param int[] $allowedSiteIds only meaningful when $restricted
     */
    public function __construct(
        public readonly int $userId,
        public readonly bool $restricted,
        public readonly array $allowedSiteIds = [],
    ) {
    }

    public static function unrestricted(int $userId): self
    {
        return new self($userId, false);
    }

    /**
     * Restricted users never see work with no known site unless it is theirs:
     * there is no site to grant it by.
     */
    public function allowsSite(?int $siteId): bool
    {
        return !$this->restricted || ($siteId !== null && in_array($siteId, $this->allowedSiteIds, true));
    }

    public function canSee(WorkItem $item, ?ItemState $state): bool
    {
        return $this->allowsSite($item->siteId)
            || $item->ownerId === $this->userId
            || ($state?->assignedTo !== null && $state->assignedTo === $this->userId);
    }
}
