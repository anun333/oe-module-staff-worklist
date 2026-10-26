<?php

/**
 * The visit a saved questionnaire response belongs to. Before OpenEMR 8.5.0 (#14654) a response
 * row's own encounter held only the leading digits of the visit's uuid, or 0, and rows saved then
 * are never repaired. So the visit is read from the questionnaire form's forms row first, which
 * was always right, and from the response row only when it has no form (an API client's).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Source;

use OpenEMR\Common\Database\QueryUtils;

final class ResponseVisit
{
    /**
     * The joins that give a questionnaire_response aliased qr its visit as fe (form_encounter),
     * both left joins: a response with no visit still reads. Grouped by response, so a response
     * saved twice through its form is still one row.
     *
     * The visit's lookup reads form_questionnaire_assessments by response_id, which core leaves
     * unindexed (a TEXT column). This form, a grouped derived table, is the one to use without an
     * index; with one, joins() picks the correlated lookup, which reads only each response's rows.
     */
    public const JOINS = "
            LEFT JOIN (SELECT vfqa.response_id, MAX(vf.encounter) AS encounter
                         FROM form_questionnaire_assessments AS vfqa
                         JOIN forms AS vf ON vf.form_id = vfqa.id AND vf.pid = vfqa.pid
                              AND vf.formdir = 'questionnaire_assessments' AND vf.deleted = 0 AND vf.encounter > 0
                        GROUP BY vfqa.response_id) AS rv ON rv.response_id = qr.response_id
            LEFT JOIN form_encounter AS fe ON fe.encounter = COALESCE(rv.encounter, NULLIF(qr.encounter, 0)) AND fe.pid = qr.patient_id";

    /**
     * The same visit, looked up per response: the latest undeleted questionnaire form's encounter,
     * else the response row's own. With an index on response_id this reads a handful of rows per
     * response; without one it scans the table per response, so it is used only with the index.
     */
    public const JOINS_INDEXED = "
            LEFT JOIN form_encounter AS fe ON fe.encounter = COALESCE((
                SELECT MAX(vf.encounter)
                  FROM form_questionnaire_assessments AS vfqa
                  JOIN forms AS vf ON vf.form_id = vfqa.id AND vf.pid = vfqa.pid
                       AND vf.formdir = 'questionnaire_assessments' AND vf.deleted = 0 AND vf.encounter > 0
                 WHERE vfqa.response_id = qr.response_id), NULLIF(qr.encounter, 0)) AND fe.pid = qr.patient_id";

    private static ?bool $indexed = null;

    /** The joins to use: the correlated lookup when response_id is indexed, the grouped one otherwise. */
    public static function joins(?bool $indexed = null): string
    {
        return ($indexed ?? self::indexed()) ? self::JOINS_INDEXED : self::JOINS;
    }

    /**
     * Whether form_questionnaire_assessments has an index that starts with response_id: this
     * module's install adds one (named response_id), and so do other modules or a later core.
     * Asked once per request.
     */
    public static function indexed(): bool
    {
        if (self::$indexed === null) {
            $rows = QueryUtils::fetchRecords(
                "SHOW INDEX FROM `form_questionnaire_assessments` WHERE `Column_name` = 'response_id' AND `Seq_in_index` = 1"
            );
            self::$indexed = $rows !== [];
        }
        return self::$indexed;
    }
}
