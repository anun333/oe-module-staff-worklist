<?php

/**
 * Staff Worklist page.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';
// OpenEMR 8.3 autoloads library/user.inc.php (getUserSetting, setUserSetting); 8.1 and 8.2 don't.
if (!function_exists('getUserSetting')) {
    require_once dirname(__DIR__, 5) . '/library/user.inc.php';
}

use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;
use OpenHealthcare\StaffWorklist\Bootstrap;
use OpenHealthcare\StaffWorklist\Model\ItemKey;
use OpenHealthcare\StaffWorklist\Model\ItemState;
use OpenHealthcare\StaffWorklist\Model\SiteAccess;
use OpenHealthcare\StaffWorklist\Model\WorkItem;
use OpenHealthcare\StaffWorklist\Policy\PriorityPolicy;
use OpenHealthcare\StaffWorklist\Worklist\WorklistFilter;
use OpenHealthcare\StaffWorklist\Worklist\WorklistRow;
use OpenHealthcare\StaffWorklist\Worklist\WorklistService;

if (!AclMain::aclCheckCore('patients', 'med')) {
    AccessDeniedHelper::denyWithTemplate('ACL check failed for patients/med: Staff Worklist', xl('Worklist'));
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$userId = (int)$session->get('authUserID');
$now = new DateTimeImmutable();
$filter = WorklistFilter::fromRequest($userId, $_GET);

// Site selection: choosing sites (?sites=) saves the choice for this user; otherwise the saved one applies.
// It only narrows the list. Which sites a user may see at all is decided by SiteAccess.
$requestedSites = WorklistFilter::parseSites($_GET['sites'] ?? null);
if ($requestedSites !== false) {
    setUserSetting('staff_worklist_sites', $requestedSites === null ? 'all' : implode(',', $requestedSites));
    $selectedSites = $requestedSites;
} else {
    $savedSites = WorklistFilter::parseSites((string)(getUserSetting('staff_worklist_sites') ?? 'all'));
    $selectedSites = $savedSites === false ? null : $savedSites;
}
$filter = $filter->withSites($selectedSites);

$service = WorklistService::create($now);
$result = $service->build($filter, $now);
$careGapStatus = $service->careGapStatus();
$canAct = AclMain::aclCheckCore('patients', 'med', '', 'write');
$webroot = OEGlobalsBag::getInstance()->getWebRoot();
$moduleUrl = $webroot . Bootstrap::MODULE_INSTALLATION_PATH;

$sourceLabels = [
    ItemKey::SOURCE_RESULT => xl('Results to sign'),
    ItemKey::SOURCE_ORDER => xl('No result yet'),
    ItemKey::SOURCE_REFERRAL => xl('Referral replies'),
    ItemKey::SOURCE_CAREGAP => xl('Care gaps, upcoming visits'),
    ItemKey::SOURCE_SCREEN => xl('Screening follow-up'),
    ItemKey::SOURCE_CLAIM => xl('Claims follow-up'),
    ItemKey::SOURCE_SLIDING => xl('Sliding fee re-assessments'),
];
$sourceLabels = array_filter($sourceLabels, static fn(string $source): bool => $service->hasSource($source), ARRAY_FILTER_USE_KEY);
$viewLabels = [
    ItemState::STATUS_OPEN => xl('Open'),
    ItemState::STATUS_SNOOZED => xl('Snoozed'),
    ItemState::STATUS_DISMISSED => xl('Dismissed'),
];
$scopeLabels = [
    WorklistFilter::SCOPE_MINE => xl('Mine'),
    WorklistFilter::SCOPE_UNASSIGNED => xl('Unassigned'),
    WorklistFilter::SCOPE_ALL => xl('Everyone'),
];
// Shown under the assignee when nobody has been assigned and the item falls back to its owner.
$ownerRoles = [
    ItemKey::SOURCE_RESULT => xl('Ordering provider'),
    ItemKey::SOURCE_ORDER => xl('Ordering provider'),
    ItemKey::SOURCE_REFERRAL => xl('Referring provider'),
    ItemKey::SOURCE_CAREGAP => xl('Visit provider'),
    ItemKey::SOURCE_SCREEN => xl('Visit or patient provider'),
    ItemKey::SOURCE_CLAIM => xl('Billing staff'),
    ItemKey::SOURCE_SLIDING => xl('Front desk'),
];
$priorityBadges = [
    WorkItem::PRIORITY_CRITICAL => ['badge-danger', xl('Critical')],
    WorkItem::PRIORITY_HIGH => ['badge-warning', xl('High')],
    WorkItem::PRIORITY_NORMAL => ['badge-light border', xl('Normal')],
];

/** Query string for this page with some parameters changed. */
$link = static function (array $changes) use ($filter): string {
    $params = array_filter(
        array_merge(['view' => $filter->view, 'scope' => $filter->scope, 'source' => $filter->source], $changes),
        static fn($v) => $v !== null,
    );
    return '?' . http_build_query($params);
};

$waiting = static function (WorklistRow $row) use ($now): string {
    $item = $row->item;
    if ($item->source() === ItemKey::SOURCE_CAREGAP) {
        $days = (int)$now->setTime(0, 0)->diff($item->since->setTime(0, 0))->format('%r%a');
        return match (true) {
            $days <= 0 => xl('Visit today'),
            $days === 1 => xl('Visit tomorrow'),
            default => xl('Visit in') . ' ' . $days . ' ' . xl('days'),
        };
    }
    $days = PriorityPolicy::ageDays($item->since, $now);
    return $days === 0 ? xl('Today') : ($days . ' ' . ($days === 1 ? xl('day') : xl('days')));
};

$rules = $service->rules();

// Installs that never used result sign-off can have thousands of unsigned results.
// Rows are already sorted most urgent first, so render the top of the list and say so.
$maxRows = 300;
$shownRows = array_slice($result->rows, 0, $maxRows);

// Audit trail: this page shows many patients at once, so record whose work was listed to whom.
$viewedPids = array_values(array_unique(array_map(static fn(WorklistRow $r): int => $r->item->patient->pid, $shownRows)));
EventAuditLogger::getInstance()->newEvent(
    'patient-record-select',
    (string)$session->get('authUser'),
    (string)$session->get('authProvider'),
    1,
    'Staff Worklist viewed (' . $filter->view . '/' . $filter->scope . '/' . ($filter->source ?? 'all')
        . '), ' . count($shownRows) . ' rows, pids: ' . implode(',', $viewedPids),
);

// Sites for the picker: those with visible work, those the user is assigned, and the current selection.
$siteAccess = $service->sites->accessFor($userId);
$allSites = $service->sites->all();
$siteName = static function (int $id) use ($allSites): string {
    if ($id === SiteAccess::NO_SITE) {
        return xl('No site');
    }
    return ($allSites[$id]['name'] ?? ('#' . $id)) . (!empty($allSites[$id]['inactive']) ? ' (' . xl('inactive') . ')' : '');
};
$pickerSites = array_keys($result->countsBySite);
if ($siteAccess->restricted) {
    array_push($pickerSites, ...$siteAccess->allowedSiteIds);
}
array_push($pickerSites, ...array_filter($selectedSites ?? [], $siteAccess->allowsSite(...)));
$pickerSites = array_values(array_unique($pickerSites));
usort($pickerSites, static fn(int $a, int $b): int =>
    [$a === SiteAccess::NO_SITE, $siteName($a)] <=> [$b === SiteAccess::NO_SITE, $siteName($b)]);
$siteSummary = match (true) {
    $selectedSites === null => xl('All sites'),
    count($selectedSites) === 1 => $siteName($selectedSites[0]),
    default => count($selectedSites) . ' ' . xl('sites'),
};

// Sensitivity levels on this page, and which assignable users hold each, for the assign list.
$pageSensitivities = array_values(array_unique(array_filter(
    array_map(static fn(WorklistRow $r): string => $r->item->sensitivity ?? '', $shownRows),
    static fn(string $s): bool => $s !== '',
)));
$assigneeSensitivities = [];
foreach (array_keys($service->users->assignable()) as $assignableId) {
    $username = (string)$service->users->username($assignableId);
    $assigneeSensitivities[$assignableId] = array_values(array_filter(
        $pageSensitivities,
        static fn(string $s): bool => AclMain::aclCheckCore('sensitivities', $s, $username),
    ));
}

// When OpenEMR restricts users to facilities, only offer assignees who can reach the item's site.
$assigneeSites = null;
if ($service->sites->isRestrictionEnabled()) {
    $assigneeSites = [];
    foreach (array_keys($service->users->assignable()) as $assignableId) {
        $access = $service->sites->accessFor($assignableId);
        $assigneeSites[$assignableId] = $access->restricted ? $access->allowedSiteIds : null;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Worklist'); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <link rel="stylesheet" href="<?php echo attr($moduleUrl); ?>/public/assets/worklist.css?v=<?php echo attr_url(OEGlobalsBag::getInstance()->getString('v_js_includes')); ?>">
</head>
<body class="body_top">
<div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
        <h1 class="h4 mb-2 mr-3"><?php echo xlt('Worklist'); ?></h1>
        <div class="d-flex flex-wrap">
            <div class="btn-group btn-group-sm mr-2 mb-2" role="group" aria-label="<?php echo xla('Whose work'); ?>">
                <?php foreach ($scopeLabels as $scope => $label) { ?>
                    <a class="btn <?php echo $filter->scope === $scope ? 'btn-primary' : 'btn-outline-primary'; ?>"
                       href="<?php echo attr($link(['scope' => $scope])); ?>"><?php echo text($label); ?></a>
                <?php } ?>
            </div>
            <div class="btn-group btn-group-sm mr-2 mb-2" role="group" aria-label="<?php echo xla('Status'); ?>">
                <?php foreach ($viewLabels as $view => $label) { ?>
                    <a class="btn <?php echo $filter->view === $view ? 'btn-secondary' : 'btn-outline-secondary'; ?>"
                       href="<?php echo attr($link(['view' => $view])); ?>"><?php echo text($label); ?></a>
                <?php } ?>
            </div>
            <div class="dropdown mb-2">
                <button type="button" class="btn btn-sm btn-outline-dark dropdown-toggle" id="worklist-sites-toggle"
                        data-toggle="dropdown" data-display="static" aria-haspopup="true" aria-expanded="false">
                    <?php echo xlt('Sites'); ?>: <?php echo text($siteSummary); ?>
                </button>
                <form class="dropdown-menu dropdown-menu-right p-2 worklist-sites" id="worklist-sites" aria-labelledby="worklist-sites-toggle"
                      data-base="<?php echo attr($link([])); ?>">
                    <?php foreach ($pickerSites as $siteId) { ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="<?php echo attr((string)$siteId); ?>"
                                   id="worklist-site-<?php echo attr((string)$siteId); ?>"
                                <?php echo ($selectedSites === null || in_array($siteId, $selectedSites, true)) ? 'checked' : ''; ?>>
                            <label class="form-check-label d-flex justify-content-between" for="worklist-site-<?php echo attr((string)$siteId); ?>">
                                <span class="mr-3"><?php echo text($siteName($siteId)); ?></span>
                                <span class="text-muted"><?php echo text((string)($result->countsBySite[$siteId] ?? 0)); ?></span>
                            </label>
                        </div>
                    <?php } ?>
                    <?php if ($pickerSites === []) { ?>
                        <div class="text-muted small"><?php echo xlt('No sites have work in this view.'); ?></div>
                    <?php } ?>
                    <div class="d-flex justify-content-between mt-2">
                        <button type="button" class="btn btn-sm btn-link px-0 js-sites-all"><?php echo xlt('All sites'); ?></button>
                        <button type="submit" class="btn btn-sm btn-primary"><?php echo xlt('Apply'); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php if ($siteAccess->restricted) { ?>
        <p class="small text-muted mt-n2 mb-2">
            <?php echo xlt('You see work for the sites assigned to you in User admin, plus items you own or were assigned.'); ?>
        </p>
    <?php } ?>

    <?php if ($result->overdueCount > 0) { ?>
        <div class="alert alert-danger d-flex flex-wrap justify-content-between align-items-center" role="alert">
            <span>
                <strong><?php echo text((string)$result->overdueCount); ?></strong>
                <?php echo xlt('critical results are unsigned past the'); ?>
                <?php echo text((string)$service->policy->criticalOverdueHours); ?> <?php echo xlt('hour limit.'); ?>
            </span>
            <a class="alert-link" href="<?php echo attr('?' . http_build_query(['view' => 'open', 'scope' => 'all', 'source' => 'result', 'sites' => 'all'])); ?>"><?php echo xlt('Show all results to sign'); ?></a>
        </div>
    <?php } ?>

    <ul class="nav nav-tabs mb-0">
        <li class="nav-item">
            <a class="nav-link <?php echo $filter->source === null ? 'active' : ''; ?>"
               href="<?php echo attr($link(['source' => null])); ?>">
                <?php echo xlt('All'); ?> <span class="badge badge-pill badge-secondary"><?php echo text((string)$result->total()); ?></span>
            </a>
        </li>
        <?php foreach ($sourceLabels as $source => $label) { ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $filter->source === $source ? 'active' : ''; ?>"
                   href="<?php echo attr($link(['source' => $source])); ?>">
                    <?php echo text($label); ?>
                    <span class="badge badge-pill badge-secondary"><?php echo text((string)($result->countsBySource[$source] ?? 0)); ?></span>
                </a>
            </li>
        <?php } ?>
    </ul>

    <?php if (
        in_array($filter->source, [null, ItemKey::SOURCE_CAREGAP], true)
        && ($careGapStatus['unchecked'] > 0 || $careGapStatus['oldestCheck'] !== null)
) { ?>
        <div class="border border-top-0 px-2 py-1 small text-muted d-flex flex-wrap align-items-center">
            <span class="mr-2">
                <?php if ($careGapStatus['oldestCheck'] !== null) { ?>
                    <?php echo xlt('Care gaps checked'); ?> <?php echo text(oeFormatShortDate($careGapStatus['oldestCheck']->format('Y-m-d')) . ' ' . oeFormatTime($careGapStatus['oldestCheck']->format('H:i:s'))); ?>.
                <?php } ?>
                <?php if ($careGapStatus['unchecked'] > 0) { ?>
                    <?php echo text((string)$careGapStatus['unchecked']); ?> <?php echo xlt('upcoming visits not checked yet.'); ?>
                <?php } ?>
            </span>
            <?php if ($canAct) { ?>
                <button type="button" class="btn btn-link btn-sm p-0 js-refresh-caregaps"><?php echo xlt('Check now'); ?></button>
            <?php } ?>
        </div>
    <?php } ?>

    <?php if (count($result->rows) === 0) { ?>
        <div class="border border-top-0 p-4 text-muted">
            <?php echo $filter->view === ItemState::STATUS_OPEN ? xlt('Nothing outstanding here.') : xlt('No items.'); ?>
        </div>
    <?php } else { ?>
        <div class="table-responsive border border-top-0">
            <table class="table table-sm table-hover mb-0 worklist-table">
                <thead class="thead-light">
                <tr>
                    <th scope="col"><?php echo xlt('Priority'); ?></th>
                    <th scope="col"><?php echo xlt('Patient'); ?></th>
                    <th scope="col"><?php echo xlt('Site'); ?></th>
                    <th scope="col"><?php echo xlt('Work'); ?></th>
                    <th scope="col"><?php echo xlt('Waiting'); ?></th>
                    <th scope="col"><?php echo xlt('Assigned'); ?></th>
                    <th scope="col" class="text-right"><span class="sr-only"><?php echo xlt('Actions'); ?></span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($shownRows as $row) {
                    $item = $row->item;
                    [$badgeClass, $badgeLabel] = $priorityBadges[$item->priority];
                    $canSnooze = $item->priority !== WorkItem::PRIORITY_CRITICAL;
                    // Work from an encounter whose sensitivity this user lacks: keep the row, hide what it is.
                    $restricted = !$service->canSeeDetails($item);
                    $title = $restricted ? xl('Restricted: work from a sensitive visit') : $item->title;
                    ?>
                    <tr data-key="<?php echo attr((string)$item->key); ?>"
                        data-title="<?php echo attr($title); ?>"
                        data-sensitivity="<?php echo attr($item->sensitivity ?? ''); ?>"
                        data-patient="<?php echo attr($item->patient->name); ?>"
                        data-assignee="<?php echo attr((string)($row->explicitlyAssigned ? $row->assigneeId : '')); ?>"
                        data-owner="<?php echo attr((string)($item->ownerId ?? '')); ?>"
                        data-site="<?php echo attr((string)$item->siteKey()); ?>"
                        data-max-snooze-days="<?php echo attr((string)$rules->maxSnoozeDays($item)); ?>">
                        <td>
                            <span class="badge <?php echo attr($badgeClass); ?>"><?php echo text($badgeLabel); ?></span>
                            <?php if ($item->overdue) { ?>
                                <div><span class="badge badge-danger mt-1"><?php echo xlt('Overdue'); ?></span></div>
                            <?php } ?>
                        </td>
                        <td>
                            <a href="#" class="js-open-chart" data-pid="<?php echo attr((string)$item->patient->pid); ?>"><?php echo text($item->patient->name); ?></a>
                            <div class="small text-muted">
                                <?php echo xlt('DOB'); ?> <?php echo text(oeFormatShortDate($item->patient->dob)); ?>
                                <?php if ($item->patient->pubpid !== '') { ?> · <?php echo xlt('ID'); ?> <?php echo text($item->patient->pubpid); ?><?php } ?>
                            </div>
                            <?php if ($item->patient->deceased) { ?>
                                <span class="badge badge-dark"><?php echo xlt('Deceased'); ?></span>
                            <?php } ?>
                        </td>
                        <td class="<?php echo $item->siteId === null ? 'text-muted' : ''; ?>"><?php echo text($siteName($item->siteKey())); ?></td>
                        <td>
                            <div class="font-weight-bold"><?php echo text($title); ?></div>
                            <?php if ($restricted) { ?>
                                <div class="small text-muted"><?php echo xlt('Details are hidden because this visit is marked sensitive and you lack access to that level. Assign it to someone who has access.'); ?></div>
                            <?php } else { ?>
                                <div class="small text-muted"><?php echo text($item->detail); ?></div>
                            <?php } ?>
                            <?php if (!$restricted && $row->state !== null && $row->state->note !== '') { ?>
                                <div class="small worklist-note"><?php echo xlt('Note'); ?>: <?php echo text($row->state->note); ?></div>
                            <?php } ?>
                        </td>
                        <td class="text-nowrap"><?php echo text($waiting($row)); ?></td>
                        <td>
                            <?php if ($row->assigneeId === null) { ?>
                                <span class="text-muted"><?php echo xlt('Nobody'); ?></span>
                            <?php } else { ?>
                                <?php echo text($service->users->name($row->assigneeId)); ?>
                                <?php if (!$row->explicitlyAssigned) { ?>
                                    <div class="small text-muted"><?php echo text($ownerRoles[$item->source()]); ?></div>
                                <?php } ?>
                            <?php } ?>
                            <?php if ($row->status === ItemState::STATUS_SNOOZED && $row->state?->snoozeUntil !== null) { ?>
                                <div class="small text-muted"><?php echo xlt('Until'); ?> <?php echo text(oeFormatShortDate($row->state->snoozeUntil->format('Y-m-d'))); ?></div>
                            <?php } ?>
                        </td>
                        <td class="text-right text-nowrap">
                            <?php if ($restricted) { ?>
                                <span class="badge badge-secondary"><?php echo xlt('Restricted'); ?></span>
                            <?php } else { ?>
                                <button type="button" class="btn btn-sm btn-primary js-do-work"
                                        data-mode="<?php echo attr($item->actionMode); ?>"
                                        data-url="<?php echo attr($item->actionUrl); ?>"
                                        data-pid="<?php echo attr((string)$item->patient->pid); ?>"><?php echo text($item->actionLabel); ?></button>
                            <?php } ?>
                            <?php if ($canAct) { ?>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-toggle="dropdown"
                                            aria-haspopup="true" aria-expanded="false"><span class="sr-only"><?php echo xlt('More actions'); ?></span></button>
                                    <div class="dropdown-menu dropdown-menu-right">
                                        <button type="button" class="dropdown-item js-action" data-action="assign"><?php echo xlt('Assign'); ?>…</button>
                                        <?php if (!$restricted && $canSnooze && $row->status !== ItemState::STATUS_SNOOZED) { ?>
                                            <button type="button" class="dropdown-item js-action" data-action="snooze"><?php echo xlt('Snooze'); ?>…</button>
                                        <?php } ?>
                                        <?php if (!$restricted && $item->dismissible && $row->status !== ItemState::STATUS_DISMISSED) { ?>
                                            <button type="button" class="dropdown-item js-action" data-action="dismiss"><?php echo xlt('Dismiss'); ?>…</button>
                                        <?php } ?>
                                        <?php if (!$restricted && $row->status !== ItemState::STATUS_OPEN) { ?>
                                            <button type="button" class="dropdown-item js-action" data-action="reopen"><?php echo xlt('Reopen'); ?></button>
                                        <?php } ?>
                                    </div>
                                </div>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php if (count($result->rows) > $maxRows) { ?>
            <div class="alert alert-warning mt-2 mb-0">
                <?php echo xlt('Showing the most urgent'); ?> <?php echo text((string)$maxRows); ?>
                <?php echo xlt('of'); ?> <?php echo text((string)count($result->rows)); ?>.
                <?php echo xlt('Pick a tab or Mine to narrow the list.'); ?>
            </div>
        <?php } ?>
    <?php } ?>

    <p class="small text-muted mt-3 mb-0">
        <?php echo xlt('Items leave this list when the work is done in OpenEMR. Snooze and dismiss only hide an item here; they do not change the chart. Unsigned results cannot be dismissed, and critical results cannot be snoozed.'); ?>
    </p>
</div>

<?php if ($canAct) { ?>
<div class="modal fade" id="worklist-action" tabindex="-1" role="dialog" aria-labelledby="worklist-action-title" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <form class="modal-content" id="worklist-action-form" novalidate>
            <div class="modal-header">
                <h2 class="modal-title h5" id="worklist-action-title"></h2>
                <button type="button" class="close" data-dismiss="modal" aria-label="<?php echo xla('Close'); ?>"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <p class="mb-3" id="worklist-action-subject"></p>
                <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken(session: $session)); ?>">
                <input type="hidden" name="key">
                <input type="hidden" name="action">
                <div class="form-group" data-for="assign">
                    <label for="worklist-assignee"><?php echo xlt('Assign to'); ?></label>
                    <select class="form-control" id="worklist-assignee" name="assigned_to">
                        <option value=""><?php echo xlt('Nobody (use the item owner)'); ?></option>
                        <?php foreach ($service->users->assignable() as $id => $name) { ?>
                            <option value="<?php echo attr((string)$id); ?>"><?php echo text($name); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="form-group" data-for="snooze">
                    <label for="worklist-until"><?php echo xlt('Hide until'); ?></label>
                    <input type="date" class="form-control" id="worklist-until" name="until">
                    <small class="form-text text-muted" id="worklist-until-help"></small>
                </div>
                <div class="form-group" data-for="assign snooze dismiss">
                    <label for="worklist-note" id="worklist-note-label"></label>
                    <textarea class="form-control" id="worklist-note" name="note" rows="2" maxlength="1000"></textarea>
                </div>
                <div class="alert alert-danger d-none mb-0" role="alert" id="worklist-action-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo xlt('Cancel'); ?></button>
                <button type="submit" class="btn btn-primary" id="worklist-action-submit"><?php echo xlt('Save'); ?></button>
            </div>
        </form>
    </div>
</div>
<?php } ?>

<script>
    window.staffWorklist = {
        webroot: <?php echo js_escape($webroot); ?>,
        actionUrl: <?php echo js_escape($moduleUrl . '/public/action.php'); ?>,
        // user id => site ids they may reach (null = all); null overall when sites are not restricted
        assigneeSites: <?php echo json_encode($assigneeSites === null ? null : (object)$assigneeSites); ?>,
        // user id => sensitivity levels (among those on this page) the user holds
        assigneeSensitivities: <?php echo json_encode((object)$assigneeSensitivities); ?>,
        today: <?php echo js_escape($now->format('Y-m-d')); ?>,
        text: {
            assign: <?php echo xlj('Assign'); ?>,
            snooze: <?php echo xlj('Snooze'); ?>,
            dismiss: <?php echo xlj('Dismiss'); ?>,
            reopen: <?php echo xlj('Reopen'); ?>,
            noteOptional: <?php echo xlj('Note (optional)'); ?>,
            reasonRequired: <?php echo xlj('Reason (required)'); ?>,
            snoozeMax: <?php echo xlj('At most'); ?>,
            days: <?php echo xlj('days from today'); ?>,
            saveFailed: <?php echo xlj('Could not save. Reload the page and try again.'); ?>,
            checking: <?php echo xlj('Checking'); ?>
        }
    };
</script>
<script src="<?php echo attr($moduleUrl); ?>/public/assets/worklist.js?v=<?php echo attr_url(OEGlobalsBag::getInstance()->getString('v_js_includes')); ?>"></script>
</body>
</html>
