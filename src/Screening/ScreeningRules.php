<?php

/**
 * Which screening results still need follow-up. For each patient and instrument only
 * the latest response in the window counts. It is listed when its total is at or above
 * the published cut-off and nothing that shows it was acted on stands on or after its
 * day: the longer instrument that follows it, or a problem, visit diagnosis or service
 * with one of the instrument's codes. The rules never call a screen positive; they
 * compare the recorded total with the paper's cut-off, and the clinician decides.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Screening;

final class ScreeningRules
{
    public const LOOKBACK_DAYS = 180;

    /** @var array<string, ScreeningInstrument> */
    private readonly array $instruments;

    /**
     * @param array<string, ScreeningInstrument>|null $instruments by total code; ScreeningInstrument::all() when null
     */
    public function __construct(
        private readonly \DateTimeImmutable $now,
        ?array $instruments = null,
        private readonly int $lookbackDays = self::LOOKBACK_DAYS,
    ) {
        $this->instruments = $instruments ?? ScreeningInstrument::all();
    }

    /** The earliest response day the window reaches. */
    public function windowStart(): \DateTimeImmutable
    {
        return $this->now->setTime(0, 0)->modify("-{$this->lookbackDays} days");
    }

    /**
     * @param list<ScreenResponse>              $responses any patients', in any order
     * @param array<int, string|null>           $sexByPid  patient_data.sex
     * @param list<FollowUpRecord>              $records   problems and billed codes of those patients
     * @return list<ScreenFinding>
     */
    public function findings(array $responses, array $sexByPid, array $records): array
    {
        $start = $this->windowStart();
        /** @var array<int, array<string, ScreenResponse>> $latest pid => total code => latest response */
        $latest = [];
        foreach ($responses as $response) {
            if ($response->date < $start) {
                continue;
            }
            foreach ($response->totals as $code => $_) {
                if (!isset($this->instruments[$code])) {
                    continue;
                }
                $current = $latest[$response->pid][$code] ?? null;
                if ($current === null || self::isLater($response, $current)) {
                    $latest[$response->pid][$code] = $response;
                }
            }
        }
        $recordsByPid = [];
        foreach ($records as $record) {
            $recordsByPid[$record->pid][] = $record;
        }

        $findings = [];
        foreach ($latest as $pid => $byCode) {
            foreach ($byCode as $code => $response) {
                $instrument = $this->instruments[$code];
                $score = $response->totals[$code];
                [$cutoff, $sexUnknown] = $instrument->cutoffFor($sexByPid[$pid] ?? null);
                if ($score < $cutoff || $this->followedUp($instrument, $response, $byCode, $recordsByPid[$pid] ?? [])) {
                    continue;
                }
                $findings[] = new ScreenFinding(
                    $response,
                    $instrument,
                    $score,
                    $cutoff,
                    $sexUnknown,
                    $instrument->severeAt !== null && $score >= $instrument->severeAt,
                );
            }
        }
        return $findings;
    }

    /**
     * @param array<string, ScreenResponse> $latestByCode the patient's latest response to each instrument
     * @param list<FollowUpRecord>          $records      the patient's
     */
    private function followedUp(ScreeningInstrument $instrument, ScreenResponse $response, array $latestByCode, array $records): bool
    {
        $next = $instrument->followedBy === null ? null : ($latestByCode[$instrument->followedBy] ?? null);
        if ($next !== null && $next->date->setTime(0, 0) >= $response->date->setTime(0, 0)) {
            return true;
        }
        foreach ($records as $record) {
            if ($instrument->isFollowUpCode($record->codeType, $record->code) && $record->standsOnOrAfter($response->date)) {
                return true;
            }
        }
        return false;
    }

    private static function isLater(ScreenResponse $a, ScreenResponse $b): bool
    {
        return $a->date > $b->date || ($a->date == $b->date && $a->id > $b->id);
    }
}
