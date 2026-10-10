<?php

/**
 * Staff names for display and the people an item can be assigned to.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Repository;

use OpenEMR\Common\Database\QueryUtils;

final class UserDirectory
{
    /** @var array<int, array{name: string, username: string, active: bool}>|null */
    private ?array $users = null;

    public function name(?int $id): string
    {
        if ($id === null) {
            return '';
        }
        return $this->all()[$id]['name'] ?? '#' . $id;
    }

    /**
     * Active login accounts, for the assign menu.
     *
     * @return array<int, string> id => display name
     */
    public function assignable(): array
    {
        $list = [];
        foreach ($this->all() as $id => $user) {
            if ($user['active']) {
                $list[$id] = $user['name'];
            }
        }
        return $list;
    }

    public function username(int $id): ?string
    {
        return $this->all()[$id]['username'] ?? null;
    }

    public function isAssignable(int $id): bool
    {
        return ($this->all()[$id]['active'] ?? false) === true;
    }

    /**
     * @return array<int, array{name: string, username: string, active: bool}>
     */
    private function all(): array
    {
        if ($this->users === null) {
            $this->users = [];
            $rows = QueryUtils::fetchRecords(
                "SELECT `id`, `fname`, `lname`, `username`, `active` FROM `users`
                  WHERE `username` IS NOT NULL AND `username` <> ''
                  ORDER BY `lname`, `fname`",
            );
            foreach ($rows as $row) {
                $name = trim(($row['fname'] ?? '') . ' ' . ($row['lname'] ?? '')) ?: (string)$row['username'];
                $this->users[(int)$row['id']] = [
                    'name' => $name,
                    'username' => (string)$row['username'],
                    'active' => (int)$row['active'] === 1,
                ];
            }
        }
        return $this->users;
    }
}
