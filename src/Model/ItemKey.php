<?php

/**
 * Stable identifier for a work item: "<source>:<pid>:<ref>", where ref is the
 * id of the underlying record (order, transaction or appointment). A screening
 * item's ref may end in one digit after a dot, telling apart the items one
 * questionnaire response can give: "12" for a total at the cut-off, "12.2" for an
 * unfinished PHQ-9 (the digit is the instrument's), so dismissing an unfinished screen
 * never hides the result it later becomes. A screen from OpenEMR's own PHQ-9 or GAD-7
 * encounter form has its form's name before the id ("phq9-7", "gad7-7.3"), since those
 * ids are another table's.
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
    public const SOURCE_SCREEN = 'screen';
    public const SOURCE_CLAIM = 'claim';
    public const SOURCE_SLIDING = 'sliding';
    public const SOURCE_AUTH = 'auth';

    private const PATTERN = '/^(result|order|referral|caregap|screen|claim|sliding|auth):([1-9]\d{0,17}):((?:(?:phq9|gad7)-)?[1-9]\d{0,17}(?:\.[1-9])?)$/D';

    public function __construct(
        public readonly string $source,
        public readonly int $pid,
        public readonly string $ref,
    ) {
        if (!self::valid("$source:$pid:$ref")) {
            throw new \InvalidArgumentException('Invalid work item key');
        }
    }

    public static function parse(string $key): ?self
    {
        if (!self::valid($key) || !preg_match(self::PATTERN, $key, $m)) {
            return null;
        }
        return new self($m[1], (int)$m[2], $m[3]);
    }

    /** The pattern, and a dotted or form-named ref only on a screening item. */
    private static function valid(string $key): bool
    {
        return preg_match(self::PATTERN, $key, $m) === 1
            && ((!str_contains($m[3], '.') && ctype_digit($m[3])) || $m[1] === self::SOURCE_SCREEN);
    }

    public function __toString(): string
    {
        return "{$this->source}:{$this->pid}:{$this->ref}";
    }
}
