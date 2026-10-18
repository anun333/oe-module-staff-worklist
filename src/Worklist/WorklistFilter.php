<?php

/**
 * Which rows the user asked to see.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Worklist;

use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Model\ItemState;

final class WorklistFilter
{
    public const SCOPE_MINE = 'mine';
    public const SCOPE_UNASSIGNED = 'unassigned';
    public const SCOPE_ALL = 'all';

    private const VIEWS = [ItemState::STATUS_OPEN, ItemState::STATUS_SNOOZED, ItemState::STATUS_DISMISSED];
    private const SCOPES = [self::SCOPE_MINE, self::SCOPE_UNASSIGNED, self::SCOPE_ALL];
    private const SOURCES = [
        ItemKey::SOURCE_RESULT, ItemKey::SOURCE_ORDER, ItemKey::SOURCE_REFERRAL, ItemKey::SOURCE_CAREGAP, ItemKey::SOURCE_SCREEN,
    ];

    /**
     * @param int[]|null $sites site ids to show (SiteAccess::NO_SITE for work with no known site); null = every site the user can see
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $view = ItemState::STATUS_OPEN,
        public readonly string $scope = self::SCOPE_MINE,
        public readonly ?string $source = null,
        public readonly ?array $sites = null,
    ) {
    }

    /**
     * @param int[]|null $sites
     */
    public function withSites(?array $sites): self
    {
        return new self($this->userId, $this->view, $this->scope, $this->source, $sites);
    }

    /**
     * Parse a site selection ("all", or comma-separated ids). Returns false when absent or malformed.
     *
     * @return int[]|null|false
     */
    public static function parseSites(mixed $raw): array|null|false
    {
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        if ($raw === 'all') {
            return null;
        }
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            if (!preg_match('/^(0|[1-9]\d{0,9})$/D', $part)) {
                return false;
            }
            $ids[(int)$part] = true;
        }
        return array_keys($ids);
    }

    /**
     * Build from request parameters, falling back to defaults for anything unrecognized.
     *
     * @param array<string, mixed> $params
     */
    public static function fromRequest(int $userId, array $params): self
    {
        $pick = static fn(string $name, array $allowed, ?string $default): ?string =>
            in_array($params[$name] ?? null, $allowed, true) ? $params[$name] : $default;

        return new self(
            $userId,
            $pick('view', self::VIEWS, ItemState::STATUS_OPEN),
            $pick('scope', self::SCOPES, self::SCOPE_MINE),
            $pick('source', self::SOURCES, null),
        );
    }
}
