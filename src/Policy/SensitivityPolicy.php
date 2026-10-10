<?php

/**
 * Whether a user may see work that comes from a sensitive encounter.
 *
 * OpenEMR lets an encounter carry a sensitivity level (form_encounter.sensitivity,
 * "normal" or "high" by default). Chart screens hide such encounters' details
 * from users without the matching `sensitivities` ACL. The worklist applies the
 * same rule to results and orders placed in those encounters: it still lists the
 * work, so it isn't lost, but masks what it is and offers only routing actions.
 *
 * Same decision as core's ProcedureOrderAccess::isSensitivityPermitted()
 * (openemr/openemr#14442), duplicated here because 8.4.x does not ship it.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Policy;

final class SensitivityPolicy
{
    /**
     * @param callable(string): bool $hasSensitivityAcl consulted only for a non-blank sensitivity
     */
    public static function isPermitted(?string $sensitivity, callable $hasSensitivityAcl): bool
    {
        if ($sensitivity === null || $sensitivity === '') {
            return true;
        }
        return $hasSensitivityAcl($sensitivity);
    }
}
