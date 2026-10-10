<?php

/**
 * Staff Worklist action endpoint: assign, snooze, dismiss, reopen.
 *
 * POST only. Returns JSON {ok: true} or {ok: false, error: code, message: text}.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenHealthcare\StaffWorklist\CareGaps\CareGapRefresher;
use OpenHealthcare\StaffWorklist\Policy\ActionRules;
use OpenHealthcare\StaffWorklist\Worklist\WorklistService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond(405, ['ok' => false, 'error' => 'method', 'message' => xl('POST required')]);
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', session: $session)) {
    $respond(403, ['ok' => false, 'error' => 'csrf', 'message' => xl('Your session expired. Reload the page.')]);
}
if (!AclMain::aclCheckCore('patients', 'med', '', 'write')) {
    $respond(403, ['ok' => false, 'error' => 'acl', 'message' => xl('You are not allowed to change worklist items.')]);
}

$messages = [
    WorklistService::ERR_INVALID_KEY => xl('That item could not be identified.'),
    WorklistService::ERR_NOT_FOUND => xl('This item is no longer outstanding. Reload the page.'),
    WorklistService::ERR_INVALID_ACTION => xl('Unknown action.'),
    WorklistService::ERR_INVALID_USER => xl('Choose an active user.'),
    WorklistService::ERR_INVALID_DATE => xl('Choose a date.'),
    WorklistService::ERR_ASSIGNEE_SITE => xl('That person is not assigned to this site in User admin.'),
    WorklistService::ERR_RESTRICTED => xl('This item comes from a sensitive visit you do not have access to. You can only assign it.'),
    WorklistService::ERR_ASSIGNEE_SENSITIVITY => xl('That person lacks access to the sensitivity level of this visit.'),
    ActionRules::ERR_CRITICAL_SNOOZE => xl('Critical results cannot be snoozed.'),
    ActionRules::ERR_SNOOZE_PAST => xl('Choose a date after today.'),
    ActionRules::ERR_SNOOZE_TOO_LONG => xl('That date is further out than this item can be snoozed.'),
    ActionRules::ERR_NOT_DISMISSIBLE => xl('This item can only be cleared by completing it in OpenEMR.'),
    ActionRules::ERR_REASON_REQUIRED => xl('Give a reason for dismissing this item.'),
];

$now = new DateTimeImmutable();

// "Check now": run the care-gap job in this request instead of waiting for the background service.
if (($_POST['action'] ?? '') === 'refresh_caregaps') {
    $outcome = CareGapRefresher::create($now)->refresh(25);
    $respond(200, ['ok' => true] + $outcome);
}

$service = WorklistService::create($now);
$error = $service->apply(
    (string)($_POST['key'] ?? ''),
    (string)($_POST['action'] ?? ''),
    [
        'assigned_to' => $_POST['assigned_to'] ?? '',
        'until' => $_POST['until'] ?? '',
        'note' => mb_substr((string)($_POST['note'] ?? ''), 0, 1000),
    ],
    (int)$session->get('authUserID'),
    $now,
);

if ($error !== null) {
    $respond(422, ['ok' => false, 'error' => $error, 'message' => $messages[$error] ?? $error]);
}
$respond(200, ['ok' => true]);
