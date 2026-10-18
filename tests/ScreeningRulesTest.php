<?php

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Tests;

use OpenHealthcare\StaffWorklist\Screening\FollowUpRecord;
use OpenHealthcare\StaffWorklist\Screening\ResponseTotals;
use OpenHealthcare\StaffWorklist\Screening\ScreenFinding;
use OpenHealthcare\StaffWorklist\Screening\ScreeningInstrument;
use OpenHealthcare\StaffWorklist\Screening\ScreeningRules;
use OpenHealthcare\StaffWorklist\Screening\ScreenResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScreeningRulesTest extends TestCase
{
    private const NOW = '2026-10-18 12:00:00';

    /** @return iterable<string, array{string, float, bool}> */
    public static function cutoffs(): iterable
    {
        yield 'PHQ-2 at 3' => [ScreeningInstrument::PHQ2, 3, true];
        yield 'PHQ-2 at 2' => [ScreeningInstrument::PHQ2, 2, false];
        yield 'PHQ-9 at 10' => [ScreeningInstrument::PHQ9, 10, true];
        yield 'PHQ-9 at 9' => [ScreeningInstrument::PHQ9, 9, false];
        yield 'PHQ-9 at 27' => [ScreeningInstrument::PHQ9, 27, true];
        yield 'GAD-7 at 10' => [ScreeningInstrument::GAD7, 10, true];
        yield 'GAD-7 at 9.5' => [ScreeningInstrument::GAD7, 9.5, false];
    }

    #[DataProvider('cutoffs')]
    public function testEachInstrumentIsListedAtItsPublishedCutoffAndNotBelow(string $code, float $score, bool $listed): void
    {
        $found = $this->rules()->findings([self::response(1, 7, '2026-10-01', [$code => $score])], [7 => 'Female'], []);

        $this->assertCount($listed ? 1 : 0, $found);
        if ($listed) {
            $this->assertSame(ScreeningInstrument::all()[$code]->cutoff, $found[0]->cutoff);
        }
    }

    public function testAuditCUsesTheCutoffForEachSexAndTheLowerOneWhenSexIsNotRecorded(): void
    {
        $responses = [
            self::response(1, 1, '2026-10-01', [ScreeningInstrument::AUDITC => 3]),
            self::response(2, 2, '2026-10-01', [ScreeningInstrument::AUDITC => 3]),
            self::response(3, 3, '2026-10-01', [ScreeningInstrument::AUDITC => 3]),
            self::response(4, 4, '2026-10-01', [ScreeningInstrument::AUDITC => 4]),
        ];

        $found = $this->rules()->findings($responses, [1 => 'Male', 2 => 'Female', 3 => '', 4 => 'Male'], []);

        $this->assertSame([2 => [3, false], 3 => [3, true], 4 => [4, false]], self::byPid($found, static fn(ScreenFinding $f): array => [$f->cutoff, $f->sexUnknown]));
    }

    public function testOnlyTheLatestResponseToAnInstrumentCounts(): void
    {
        $responses = [
            self::response(5, 1, '2026-09-01', [ScreeningInstrument::PHQ9 => 18]),
            self::response(9, 1, '2026-10-01', [ScreeningInstrument::PHQ9 => 4]),
            self::response(6, 2, '2026-09-01', [ScreeningInstrument::PHQ9 => 4]),
            self::response(8, 2, '2026-10-01', [ScreeningInstrument::PHQ9 => 12]),
            // Same time: the later row wins.
            self::response(11, 3, '2026-10-01', [ScreeningInstrument::PHQ9 => 12]),
            self::response(10, 3, '2026-10-01', [ScreeningInstrument::PHQ9 => 3]),
        ];

        $found = $this->rules()->findings($responses, [], []);

        $this->assertSame([2 => 8, 3 => 11], self::byPid($found, static fn(ScreenFinding $f): int => $f->response->id));
    }

    public function testAPhq2IsClearedByAPhq9TheSameDayOrLaterButNotEarlier(): void
    {
        $responses = [
            self::response(1, 1, '2026-10-01 09:00', [ScreeningInstrument::PHQ2 => 4]),
            self::response(2, 1, '2026-10-01 08:00', [ScreeningInstrument::PHQ9 => 6]),
            self::response(3, 2, '2026-10-01', [ScreeningInstrument::PHQ2 => 4]),
            self::response(4, 2, '2026-09-20', [ScreeningInstrument::PHQ9 => 6]),
            // One questionnaire carrying both totals: the PHQ-9 in it follows the PHQ-2.
            self::response(5, 3, '2026-10-01', [ScreeningInstrument::PHQ2 => 4, ScreeningInstrument::PHQ9 => 8]),
        ];

        $found = $this->rules()->findings($responses, [], []);

        $this->assertSame([2 => 'PHQ-2'], self::byPid($found, static fn(ScreenFinding $f): string => $f->instrument->name));
    }

    public function testAProblemOrVisitCodeForTheConditionClearsIt(): void
    {
        $responses = [];
        foreach (range(1, 7) as $pid) {
            $responses[] = self::response($pid, $pid, '2026-10-01', [ScreeningInstrument::PHQ9 => 14]);
        }
        $records = [
            new FollowUpRecord(1, 'ICD10', 'F32.9', null),                                     // open problem from before
            new FollowUpRecord(2, 'ICD10', 'F33.1', new \DateTimeImmutable('2026-10-01')),      // visit diagnosis that day
            new FollowUpRecord(3, 'ICD10', 'F341', new \DateTimeImmutable('2026-10-05')),       // dysthymia, stored without the dot
            new FollowUpRecord(4, 'ICD10', 'F32.9', new \DateTimeImmutable('2026-09-30')),      // ended the day before: not cleared
            new FollowUpRecord(5, 'ICD10', 'F41.1', null),                                     // anxiety doesn't clear a PHQ-9
            new FollowUpRecord(6, 'ICD10', 'F34.0', null),                                     // cyclothymia isn't in the PHQ-9's codes
            new FollowUpRecord(1, 'SNOMED-CT', '35489007', null),
        ];

        $found = $this->rules()->findings($responses, [], $records);

        $this->assertSame([4, 5, 6, 7], array_keys(self::byPid($found, static fn(ScreenFinding $f): int => $f->response->id)));
    }

    public function testAuditCIsClearedByABriefInterventionOrAnAlcoholCode(): void
    {
        $responses = [];
        foreach (range(1, 5) as $pid) {
            $responses[] = self::response($pid, $pid, '2026-10-01', [ScreeningInstrument::AUDITC => 7]);
        }
        $visit = new \DateTimeImmutable('2026-10-01');
        $records = [
            new FollowUpRecord(1, 'CPT4', '99408', $visit),
            new FollowUpRecord(2, 'HCPCS', 'G0443', $visit),
            new FollowUpRecord(3, 'ICD10', 'Z71.41', $visit),
            new FollowUpRecord(4, 'ICD10', 'F10.10', null),
            new FollowUpRecord(5, 'HCPCS', 'G0442', $visit), // the annual screening itself, not an intervention
        ];

        $found = $this->rules()->findings($responses, array_fill_keys(range(1, 5), 'Male'), $records);

        $this->assertSame([5], array_keys(self::byPid($found, static fn(ScreenFinding $f): int => $f->response->id)));
    }

    public function testOnlyTheLast180DaysAreRead(): void
    {
        $responses = [
            self::response(1, 1, '2026-04-21', [ScreeningInstrument::GAD7 => 16]),
            self::response(2, 2, '2026-04-20 23:59', [ScreeningInstrument::GAD7 => 16]),
        ];

        $found = $this->rules()->findings($responses, [], []);

        $this->assertSame([1], array_keys(self::byPid($found, static fn(ScreenFinding $f): bool => $f->severe)));
        $this->assertTrue($found[0]->severe, 'GAD-7 15 or more is the severe band');
    }

    public function testTheSevereBandIsHighPriorityOnlyWhereThePaperGivesOne(): void
    {
        $responses = [
            self::response(1, 1, '2026-10-01', [ScreeningInstrument::PHQ9 => 20]),
            self::response(2, 2, '2026-10-01', [ScreeningInstrument::PHQ9 => 19]),
            self::response(3, 3, '2026-10-01', [ScreeningInstrument::AUDITC => 12]),
        ];

        $found = $this->rules()->findings($responses, [3 => 'Male'], []);

        $this->assertSame([1 => true, 2 => false, 3 => false], self::byPid($found, static fn(ScreenFinding $f): bool => $f->severe));
    }

    public function testATotalThatIsNotCodedIsNotRead(): void
    {
        // LOINC's own PHQ-9 leaves its total item uncoded: the answer is there, the code isn't.
        $questionnaire = json_encode(['item' => [['linkId' => '/44250-9', 'code' => [['code' => '44250-9']]], ['linkId' => 'total']]]);
        $response = json_encode(['item' => [['linkId' => '/44250-9', 'answer' => [['valueCoding' => ['code' => 'LA6571-9']]]], ['linkId' => 'total', 'answer' => [['valueDecimal' => 27]]]]]);

        $this->assertSame([], ResponseTotals::read((string)$questionnaire, (string)$response, array_keys(ScreeningInstrument::all())));
    }

    public function testTotalsAreFoundThroughTheQuestionnaireCodeAtAnyDepth(): void
    {
        $questionnaire = json_encode(['item' => [['linkId' => 'g', 'item' => [
            ['linkId' => 'phq-9.total', 'code' => [['system' => 'http://loinc.org', 'code' => '44261-6']]],
            ['linkId' => 'phq-2.total', 'code' => [['code' => '55758-7']]],
            ['linkId' => 'gad-7.total', 'code' => [['code' => '70274-6']]],
        ]]]]);
        $response = json_encode(['item' => [['linkId' => 'g', 'item' => [
            ['linkId' => 'phq-9.total', 'answer' => [['valueDecimal' => 27]]],
            ['linkId' => 'phq-2.total', 'answer' => [['valueInteger' => 6]]],
            ['linkId' => 'gad-7.total', 'answer' => [['valueString' => 'twelve']]],
        ]]]]);

        $this->assertSame(
            [ScreeningInstrument::PHQ9 => 27.0, ScreeningInstrument::PHQ2 => 6.0],
            ResponseTotals::read((string)$questionnaire, (string)$response, array_keys(ScreeningInstrument::all())),
        );
        $this->assertSame([], ResponseTotals::read('not json', (string)$response, [ScreeningInstrument::PHQ9]));
    }

    public function testEveryInstrumentNamesItsSourceAndAPubmedId(): void
    {
        foreach (ScreeningInstrument::all() as $code => $instrument) {
            $this->assertSame($code, $instrument->totalCode);
            $this->assertMatchesRegularExpression('/PMID \d{7,8}$/', $instrument->source, $instrument->name);
            $this->assertNotSame('', $instrument->meaning);
            $this->assertNotSame([], $instrument->codes, $instrument->name . ' has codes that clear it');
        }
    }

    private function rules(): ScreeningRules
    {
        return new ScreeningRules(new \DateTimeImmutable(self::NOW));
    }

    /** @param array<string, float> $totals */
    private static function response(int $id, int $pid, string $date, array $totals): ScreenResponse
    {
        return new ScreenResponse($id, $pid, new \DateTimeImmutable($date), $totals);
    }

    /**
     * @template T
     * @param list<ScreenFinding>          $found
     * @param callable(ScreenFinding): T   $what
     * @return array<int, T> by pid, in pid order
     */
    private static function byPid(array $found, callable $what): array
    {
        $out = [];
        foreach ($found as $finding) {
            $out[$finding->response->pid] = $what($finding);
        }
        ksort($out);
        return $out;
    }
}
