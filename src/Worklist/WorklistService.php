<?php

/**
 * Builds the worklist and applies staff actions to it.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\StaffWorklist\Worklist;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\OEGlobalsBag;
use OpenHealthcare\StaffWorklist\CareGaps\CareGapCache;
use OpenHealthcare\StaffWorklist\Event\CollectSourcesEvent;
use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Model\ItemState;
use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\Policy\ActionRules;
use OpenHealthcare\StaffWorklist\Policy\PriorityPolicy;
use OpenHealthcare\StaffWorklist\Policy\SensitivityPolicy;
use OpenHealthcare\StaffWorklist\Repository\SiteDirectory;
use OpenHealthcare\StaffWorklist\Repository\StateRepository;
use OpenHealthcare\StaffWorklist\Repository\UpcomingVisits;
use OpenHealthcare\StaffWorklist\Repository\UserDirectory;
use OpenHealthcare\StaffWorklist\Source\CareGapsSource;
use OpenHealthcare\StaffWorklist\Source\ClaimsFollowUpSource;
use OpenHealthcare\StaffWorklist\Source\OrdersMissingResultsSource;
use OpenHealthcare\StaffWorklist\Source\ReferralsAwaitingReplySource;
use OpenHealthcare\StaffWorklist\Source\ResultsAwaitingReviewSource;
use OpenHealthcare\StaffWorklist\Source\ScreeningFollowUpSource;
use OpenHealthcare\StaffWorklist\Source\PriorAuthorizationSource;
use OpenHealthcare\StaffWorklist\Source\SlidingFeeDueSource;
use OpenHealthcare\StaffWorklist\Source\SocialNeedsSource;
use OpenHealthcare\StaffWorklist\Source\WorkSourceInterface;
use OpenHealthcare\Common\OpenEmr;

final class WorklistService
{
    public const ERR_INVALID_KEY = 'invalid_key';
    public const ERR_NOT_FOUND = 'not_found';
    public const ERR_INVALID_ACTION = 'invalid_action';
    public const ERR_INVALID_USER = 'invalid_user';
    public const ERR_INVALID_DATE = 'invalid_date';
    public const ERR_ASSIGNEE_SITE = 'assignee_site';
    public const ERR_RESTRICTED = 'restricted';
    public const ERR_ASSIGNEE_SENSITIVITY = 'assignee_sensitivity';

    /** @var array<string, WorkSourceInterface> */
    private readonly array $sources;

    /** @var array<string, array{label: string, ownerRole: string}> */
    private array $contributed = [];

    /**
     * @param WorkSourceInterface[] $sources
     */
    public function __construct(
        array $sources,
        private readonly StateRepository $states,
        private readonly WorklistAssembler $assembler,
        private readonly ActionRules $rules,
        public readonly UserDirectory $users,
        public readonly SiteDirectory $sites,
        public readonly PriorityPolicy $policy = new PriorityPolicy(),
    ) {
        $byId = [];
        foreach ($sources as $source) {
            $byId[$source->id()] = $source;
        }
        $this->sources = $byId;
    }

    public static function create(\DateTimeImmutable $now): self
    {
        require_once OpenEmr::srcDir() . '/options.inc.php';

        $policy = new PriorityPolicy();
        $sources = [
            new ResultsAwaitingReviewSource($policy, $now, OEGlobalsBag::getInstance()->getBoolean('portal_onsite_two_enable')),
            new OrdersMissingResultsSource($policy, $now),
        ];
        // Referrals only for users core's transaction page admits: patients/trans, which
        // add_transaction.php checks from 8.5 (#14627). On 8.4 nothing checks it, so here
        // the worklist is the stricter of the two.
        if (AclMain::aclCheckCore('patients', 'trans')) {
            $sources[] = new ReferralsAwaitingReplySource($policy, $now);
        }
        $sources[] = new CareGapsSource($policy, new UpcomingVisits($now), new CareGapCache());
        $sources[] = new ScreeningFollowUpSource($now);
        // Claims work only for users core's Billing Manager admits (interface/billing/billing_report.php).
        if (AclMain::aclCheckCore('acct', 'eob', '', 'write') || AclMain::aclCheckCore('acct', 'bill', '', 'write')) {
            $sources[] = new ClaimsFollowUpSource($policy, $now);
        }
        // Sliding fee re-assessments, when that module is installed and enabled, for users who can
        // read Demographics, the check the Sliding Fee Discount module's own due list makes.
        if (AclMain::aclCheckCore('patients', 'demo') && SlidingFeeDueSource::available()) {
            $sources[] = new SlidingFeeDueSource($now);
        }
        // Prior authorizations, when OpenEMR's own Prior Authorizations module is enabled, for users who
        // can open that module's menu entry: patients/docs.
        if (AclMain::aclCheckCore('patients', 'docs') && PriorAuthorizationSource::available()) {
            $sources[] = new PriorAuthorizationSource($now);
        }
        // Social needs, for users OpenEMR's SDOH history admits (history_sdoh.php: patients/med, write or add-only).
        if (AclMain::aclCheckCore('patients', 'med', '', ['write', 'addonly'])) {
            $sources[] = new SocialNeedsSource($now);
        }
        // Other modules' tabs: each module decides whether this user gets its tab.
        $event = new CollectSourcesEvent($now);
        $kernel = OpenEmr::kernel();
        if (is_object($kernel) && method_exists($kernel, 'getEventDispatcher')) {
            $kernel->getEventDispatcher()->dispatch($event, CollectSourcesEvent::EVENT_NAME);
        }
        $contributed = [];
        foreach ($event->added() as $id => $added) {
            ItemKey::allow($id);
            $sources[] = $added['source'];
            $contributed[$id] = ['label' => $added['label'], 'ownerRole' => $added['ownerRole']];
        }
        $service = new self(
            $sources,
            new StateRepository(),
            new WorklistAssembler(),
            new ActionRules(),
            new UserDirectory(),
            new SiteDirectory(),
            $policy,
        );
        $service->contributed = $contributed;
        return $service;
    }

    /**
     * Other modules' tabs this user has, by source id: the label and the owner role each gave.
     *
     * @return array<string, array{label: string, ownerRole: string}>
     */
    public function contributed(): array
    {
        return $this->contributed;
    }

    public function build(WorklistFilter $filter, \DateTimeImmutable $today): WorklistResult
    {
        $items = [];
        foreach ($this->sources as $source) {
            array_push($items, ...$source->collect());
        }
        $states = $this->states->findByKeys(array_map(static fn(WorkItem $i): string => (string)$i->key, $items));
        return $this->assembler->assemble($items, $states, $filter, $today, $this->sites->accessFor($filter->userId));
    }

    /** Whether this worklist includes a source (the claims and referrals sources depend on the user). */
    public function hasSource(string $source): bool
    {
        return isset($this->sources[$source]);
    }

    public function rules(): ActionRules
    {
        return $this->rules;
    }

    /**
     * Whether a user may see what this work is, given the sensitivity of the
     * encounter it came from. Users who may not still see the row (so the work
     * isn't lost) but get it masked, and can only route it.
     *
     * @param string|null $username null for the logged-in user
     */
    public function canSeeDetails(WorkItem $item, ?string $username = null): bool
    {
        return SensitivityPolicy::isPermitted(
            $item->sensitivity,
            static fn(string $sensitivity): bool => AclMain::aclCheckCore('sensitivities', $sensitivity, $username ?? ''),
        );
    }

    /**
     * How current the care-gap rows are, as of the last build().
     *
     * @return array{unchecked: int, oldestCheck: ?\DateTimeImmutable}
     */
    public function careGapStatus(): array
    {
        $source = $this->sources[ItemKey::SOURCE_CAREGAP] ?? null;
        return $source instanceof CareGapsSource
            ? ['unchecked' => $source->uncheckedCount(), 'oldestCheck' => $source->oldestCheck()]
            : ['unchecked' => 0, 'oldestCheck' => null];
    }

    /**
     * Re-derive an item from current data so actions never trust the browser's copy.
     */
    public function find(ItemKey $key): ?WorkItem
    {
        $source = $this->sources[$key->source] ?? null;
        foreach ($source?->collect($key->pid) ?? [] as $item) {
            if ((string)$item->key === (string)$key) {
                return $item;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $input assigned_to, until (Y-m-d), note
     * @return string|null null on success, otherwise an ERR_* code from this class or ActionRules
     */
    public function apply(string $rawKey, string $action, array $input, int $userId, \DateTimeImmutable $today): ?string
    {
        $key = ItemKey::parse($rawKey);
        if ($key === null) {
            return self::ERR_INVALID_KEY;
        }
        $item = $this->find($key);
        $existing = $this->states->find((string)$key);
        // Work at a site the user may not reach is reported as missing, so its existence isn't revealed.
        if ($item === null || !$this->sites->accessFor($userId)->canSee($item, $existing)) {
            return self::ERR_NOT_FOUND;
        }

        // Work from a sensitive encounter the user may not see can only be routed to someone who can.
        if (!$this->canSeeDetails($item) && $action !== 'assign') {
            return self::ERR_RESTRICTED;
        }

        $state = $existing ?? ItemState::initial($key, $userId);
        $note = trim((string)($input['note'] ?? ''));

        switch ($action) {
            case 'assign':
                $raw = trim((string)($input['assigned_to'] ?? ''));
                $assignee = $raw === '' ? null : filter_var($raw, FILTER_VALIDATE_INT);
                if ($assignee === false || ($assignee !== null && !$this->users->isAssignable($assignee))) {
                    return self::ERR_INVALID_USER;
                }
                if ($assignee !== null && !$this->sites->accessFor($assignee)->allowsSite($item->siteId) && $assignee !== $item->ownerId) {
                    return self::ERR_ASSIGNEE_SITE;
                }
                if ($assignee !== null && !$this->canSeeDetails($item, $this->users->username($assignee))) {
                    return self::ERR_ASSIGNEE_SENSITIVITY;
                }
                $next = $state->with($userId, note: $note !== '' ? $note : null, changeAssignee: true, assignedTo: $assignee);
                break;

            case 'snooze':
                $until = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)($input['until'] ?? ''));
                if ($until === false) {
                    return self::ERR_INVALID_DATE;
                }
                $error = $this->rules->checkSnooze($item, $until, $today);
                if ($error !== null) {
                    return $error;
                }
                $next = $state->with($userId, ItemState::STATUS_SNOOZED, $until, $note !== '' ? $note : null);
                break;

            case 'dismiss':
                $error = $this->rules->checkDismiss($item, $note);
                if ($error !== null) {
                    return $error;
                }
                $next = $state->with($userId, ItemState::STATUS_DISMISSED, note: $note);
                break;

            case 'reopen':
                $next = $state->with($userId, ItemState::STATUS_OPEN);
                break;

            default:
                return self::ERR_INVALID_ACTION;
        }

        $this->states->save($next, $action);
        return null;
    }
}
