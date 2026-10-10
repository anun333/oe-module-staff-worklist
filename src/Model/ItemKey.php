<?php

/**
 * Stable identifier for a work item: "<source>:<pid>:<ref>", where ref is the
 * id of the underlying record (order, transaction or appointment).
 *
 * The pid is part of the key so an action request can be re-checked against
 * the source for that one patient instead of trusting what the browser sent.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Model;

final class ItemKey
{
    public const SOURCE_RESULT = 'result';
    public const SOURCE_ORDER = 'order';
    public const SOURCE_REFERRAL = 'referral';
    public const SOURCE_CAREGAP = 'caregap';

    private const PATTERN = '/^(result|order|referral|caregap):([1-9]\d{0,17}):([1-9]\d{0,17})$/D';

    public function __construct(
        public readonly string $source,
        public readonly int $pid,
        public readonly string $ref,
    ) {
        if (!preg_match(self::PATTERN, "$source:$pid:$ref")) {
            throw new \InvalidArgumentException('Invalid work item key');
        }
    }

    public static function parse(string $key): ?self
    {
        if (!preg_match(self::PATTERN, $key, $m)) {
            return null;
        }
        return new self($m[1], (int)$m[2], $m[3]);
    }

    public function __toString(): string
    {
        return "{$this->source}:{$this->pid}:{$this->ref}";
    }
}
