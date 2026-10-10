<?php

/**
 * Each patient's next scheduled visit in the care-gap window.
 *
 * Read live on every page load, so a cancelled or moved visit drops its care
 * gaps immediately even though the gaps themselves come from a cache.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Repository;

use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\StaffWorklist\Model\PatientRef;

final class UpcomingVisits
{
    // Appointment statuses that mean the visit will not happen (apptstat list).
    private const NOT_COMING = ['x', '%', '?', '!'];

    public function __construct(
        private readonly \DateTimeImmutable $now,
        public readonly int $daysAhead = 2,
        private readonly int $maxPatients = 500,
    ) {
    }

    /**
     * Earliest upcoming non-recurring appointment per patient, soonest first.
     *
     * @return array<int, array<string, mixed>> pid => pc_eid, pc_aid, pc_facility, pc_eventDate, pc_startTime, fname, lname, DOB, pubpid
     */
    public function nextVisitByPatient(?int $pid = null): array
    {
        $binds = [$this->now->format('Y-m-d'), $this->now->modify("+{$this->daysAhead} days")->format('Y-m-d')];
        $placeholders = implode(',', array_fill(0, count(self::NOT_COMING), '?'));
        array_push($binds, ...self::NOT_COMING);
        $patientFilter = '';
        if ($pid !== null) {
            $patientFilter = 'AND e.pc_pid = ?';
            $binds[] = (string)$pid;
        }

        // Deceased patients are left out: a visit still on the books for them is not one to prepare.
        $sql = "SELECT e.pc_eid, e.pc_pid, e.pc_aid, e.pc_facility, e.pc_eventDate, e.pc_startTime,
                " . PatientRef::SQL_COLUMNS . "
            FROM openemr_postcalendar_events AS e
            JOIN patient_data AS pd ON pd.pid = e.pc_pid
            WHERE e.pc_eventDate BETWEEN ? AND ?
              AND e.pc_recurrtype = 0
              AND COALESCE(e.pc_apptstatus, '') NOT IN ($placeholders)
              AND (pd.deceased_date IS NULL OR pd.deceased_date < '1000-01-01' OR pd.deceased_date > NOW())
              $patientFilter
            ORDER BY e.pc_eventDate, e.pc_startTime, e.pc_eid";

        $visits = [];
        foreach (QueryUtils::fetchRecords($sql, $binds) as $row) {
            $visitPid = (int)$row['pc_pid'];
            if ($visitPid > 0 && !isset($visits[$visitPid])) {
                $visits[$visitPid] = $row;
                if (count($visits) >= $this->maxPatients) {
                    break;
                }
            }
        }
        return $visits;
    }
}
