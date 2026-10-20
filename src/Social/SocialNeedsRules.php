<?php

/**
 * Positive social-needs screens with nothing after them. A screen is:
 *  - an entry in OpenEMR's SDOH history (form_history_sdoh), positive in a domain when its answer is
 *    one OpenEMR itself treats as positive for that domain (HistorySdohService, the answers that become
 *    a health concern; OpenEMR master 9df74e7274);
 *  - a Hunger Vital Sign questionnaire (LOINC 88121-9), positive when either question is answered
 *    "Often true" or "Sometimes true" (Hager et al., Pediatrics 2010); food;
 *  - a six-item food security survey (LOINC 95361-2, total 95379-4), positive at a raw score of 2 or
 *    more, low or very low food security (USDA ERS, May 2024 user notes); food.
 * A patient's latest screen of a kind decides: a later negative one of the same kind clears an earlier
 * positive one. A positive screen is followed up by a referral (Transactions › Referral) on or after its
 * day, or, for food, housing and financial strain, a service in the UDS module's value sets for Table 6A
 * Lines 40, 41 and 42, billed on or after its day: what those lines count.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    anun333 <anun333@posteo.net>
 * @copyright Copyright (c) 2026 anun333 <anun333@posteo.net>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Social;

final class SocialNeedsRules
{
    public const KIND_SDOH = 'sdoh';
    public const KIND_HVS = 'hvs';
    public const KIND_FSS = 'fss';

    /** OpenEMR's own positive answers per SDOH history domain (HistorySdohService). */
    public const POSITIVE_ANSWERS = [
        'food_insecurity' => ['yes', 'positive', 'at_risk', 'often', 'sometimes'],
        'housing_instability' => ['yes', 'positive', 'at_risk'],
        'transportation_insecurity' => ['yes', 'positive', 'at_risk'],
        'utilities_insecurity' => ['yes', 'positive', 'at_risk', 'already_shut_off'],
        'interpersonal_safety' => ['yes', 'positive'],
        'financial_strain' => ['yes', 'positive', 'high', 'very_hard', 'hard'],
        'social_isolation' => ['yes', 'positive', 'often', 'sometimes'],
        'childcare_needs' => ['yes', 'positive', 'needs'],
        'digital_access' => ['no', 'lack', 'limited', 'barrier'],
    ];

    /** The Table 6A line whose services follow up a domain (UDS 2026: Lines 40 to 42). */
    public const DOMAIN_LINES = [
        'food_insecurity' => '40',
        'housing_instability' => '41',
        'utilities_insecurity' => '42',
        'financial_strain' => '42',
    ];

    /** The Hunger Vital Sign's two questions, and its answers that are positive, as LOINC codes them. */
    public const HVS_PANEL = '88121-9';
    public const HVS_QUESTIONS = ['88122-7', '88123-5'];
    private const HVS_POSITIVE = ['LA28397-0', 'LA6729-3'];
    private const HVS_NEGATIVE = ['LA28398-8'];
    /**
     * A translated Clinic Forms questionnaire codes its answers by position in the published order
     * (QuestionnaireExport::ANSWER_SYSTEM): "1" often true, "2" sometimes true, "3" never true.
     */
    private const POSITION_SYSTEM = 'https://github.com/anun333/oe-module-clinic-forms/answers';

    /**
     * The domains an SDOH history entry is positive in, in OpenEMR's order.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public static function sdohDomains(array $row): array
    {
        $out = [];
        foreach (self::POSITIVE_ANSWERS as $domain => $answers) {
            $value = strtolower(trim((string)($row[$domain] ?? '')));
            if ($value !== '' && in_array($value, $answers, true)) {
                $out[] = $domain;
            }
        }
        return $out;
    }

    /**
     * Whether a Hunger Vital Sign response is positive: true, false, or null when neither question is answered.
     *
     * @param array<string, mixed> $response a QuestionnaireResponse
     */
    public static function hungerVitalSign(array $response): ?bool
    {
        $seen = null;
        $walk = static function (array $items) use (&$walk, &$seen): void {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $linkId = ltrim((string)($item['linkId'] ?? ''), '/');
                if (in_array($linkId, self::HVS_QUESTIONS, true)) {
                    foreach (is_array($item['answer'] ?? null) ? $item['answer'] : [] as $answer) {
                        $coding = is_array($answer['valueCoding'] ?? null) ? $answer['valueCoding'] : [];
                        $code = (string)($coding['code'] ?? '');
                        $system = (string)($coding['system'] ?? '');
                        $positive = in_array($code, self::HVS_POSITIVE, true) || ($system === self::POSITION_SYSTEM && in_array($code, ['1', '2'], true));
                        $negative = in_array($code, self::HVS_NEGATIVE, true) || ($system === self::POSITION_SYSTEM && $code === '3');
                        if ($positive) {
                            $seen = true;
                        } elseif ($negative && $seen === null) {
                            $seen = false;
                        }
                    }
                }
                $walk(is_array($item['item'] ?? null) ? $item['item'] : []);
            }
        };
        $walk(is_array($response['item'] ?? null) ? $response['item'] : []);
        return $seen;
    }

    /**
     * Each patient's latest screen of each kind that is positive and not followed up.
     *
     * @param list<SocialScreen> $screens
     * @param array<int, list<\DateTimeImmutable>> $referrals pid => referral days
     * @param array<int, array<string, list<\DateTimeImmutable>>> $services pid => Table 6A line => days a service on it was billed
     * @return list<SocialScreen>
     */
    public static function open(array $screens, array $referrals, array $services): array
    {
        $latest = [];
        foreach ($screens as $screen) {
            $key = $screen->pid . ':' . $screen->kind;
            if (!isset($latest[$key]) || $screen->date > $latest[$key]->date
                || ($screen->date == $latest[$key]->date && $screen->id > $latest[$key]->id)) {
                $latest[$key] = $screen;
            }
        }
        $out = [];
        foreach ($latest as $screen) {
            if ($screen->domains === []) {
                continue; // a negative screen, the latest of its kind
            }
            $day = $screen->date->setTime(0, 0);
            $after = static fn(\DateTimeImmutable $d): bool => $d->setTime(0, 0) >= $day;
            if (array_filter($referrals[$screen->pid] ?? [], $after) !== []) {
                continue;
            }
            $open = array_values(array_filter($screen->domains, static function (string $domain) use ($screen, $services, $after): bool {
                $line = self::DOMAIN_LINES[$domain] ?? null;
                return $line === null || array_filter($services[$screen->pid][$line] ?? [], $after) === [];
            }));
            if ($open !== []) {
                $out[] = $screen->withDomains($open);
            }
        }
        usort($out, static fn(SocialScreen $a, SocialScreen $b): int => [$a->pid, $a->kind] <=> [$b->pid, $b->kind]);
        return $out;
    }
}
