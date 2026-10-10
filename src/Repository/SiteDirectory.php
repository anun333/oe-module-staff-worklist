<?php

/**
 * Sites (OpenEMR facilities) and who may reach them.
 *
 * Access comes from OpenEMR's own getUserFacilities() so the worklist follows
 * exactly the rule the calendar and user admin apply.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\OEGlobalsBag;
use OpenHealthcare\StaffWorklist\Model\SiteAccess;

final class SiteDirectory
{
    /** @var array<int, array{name: string, inactive: bool}>|null */
    private ?array $sites = null;

    /** @var array<int, SiteAccess> */
    private array $access = [];

    public function isRestrictionEnabled(): bool
    {
        return OEGlobalsBag::getInstance()->getBoolean('restrict_user_facility');
    }

    /**
     * @return array<int, array{name: string, inactive: bool}> id => site, by name
     */
    public function all(): array
    {
        if ($this->sites === null) {
            $this->sites = [];
            foreach (QueryUtils::fetchRecords('SELECT `id`, `name`, `inactive` FROM `facility` ORDER BY `name`') as $row) {
                $this->sites[(int)$row['id']] = ['name' => (string)$row['name'], 'inactive' => (int)$row['inactive'] === 1];
            }
        }
        return $this->sites;
    }

    public function accessFor(int $userId): SiteAccess
    {
        if (isset($this->access[$userId])) {
            return $this->access[$userId];
        }
        $restricted = $this->isRestrictionEnabled()
            && (int)QueryUtils::fetchSingleValue(
                "SELECT COUNT(*) AS n FROM `users_facility` WHERE `tablename` = 'users' AND `table_id` = ?",
                'n',
                [$userId],
            ) > 0;

        if (!$restricted) {
            return $this->access[$userId] = SiteAccess::unrestricted($userId);
        }
        $allowed = array_map(static fn(array $f): int => (int)$f['id'], getUserFacilities($userId));
        return $this->access[$userId] = new SiteAccess($userId, true, $allowed);
    }
}
