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

final class ResponseVisit
{
    /**
     * The joins that give a questionnaire_response aliased qr its visit as fe (form_encounter),
     * both left joins: a response with no visit still reads. Grouped by response, so a response
     * saved twice through its form is still one row.
     */
    public const JOINS = "
            LEFT JOIN (SELECT vfqa.response_id, MAX(vf.encounter) AS encounter
                         FROM form_questionnaire_assessments AS vfqa
                         JOIN forms AS vf ON vf.form_id = vfqa.id AND vf.pid = vfqa.pid
                              AND vf.formdir = 'questionnaire_assessments' AND vf.deleted = 0 AND vf.encounter > 0
                        GROUP BY vfqa.response_id) AS rv ON rv.response_id = qr.response_id
            LEFT JOIN form_encounter AS fe ON fe.encounter = COALESCE(rv.encounter, NULLIF(qr.encounter, 0)) AND fe.pid = qr.patient_id";
}
