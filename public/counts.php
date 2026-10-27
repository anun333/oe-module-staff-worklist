<?php

/**
 * Staff Worklist tab count endpoint: one tab's count for the page's view, scope and sites, for a
 * tab whose count wasn't kept from the last TabCounts::TTL_MINUTES. The count is kept for the next
 * pages.
 *
 * GET source, view, scope, sites. Returns JSON {ok: true, source, count} or {ok: false, error}.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

// globals.php opens the session read-only and closes it at once, so slow counts don't hold it.
require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../floor-guard.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenHealthcare\StaffWorklist\Worklist\WorklistFilter;
use OpenHealthcare\StaffWorklist\Worklist\WorklistService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body);
    exit;
};

// The page's own check (index.php).
if (!AclMain::aclCheckCore('patients', 'med')) {
    $respond(403, ['ok' => false, 'error' => 'acl']);
}

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$userId = (int)$session->get('authUserID');
$now = new DateTimeImmutable();
$service = WorklistService::create($now);
$filter = WorklistFilter::fromRequest($userId, $_GET, array_keys($service->contributed()));
$sites = WorklistFilter::parseSites($_GET['sites'] ?? null);
$filter = $filter->withSites($sites === false ? null : $sites);

$source = $filter->source;
if ($source === null || $source !== ($_GET['source'] ?? null)) {
    $respond(400, ['ok' => false, 'error' => 'source']);
}
$count = $service->countFor($source, $filter, $now);
if ($count === null) {
    $respond(404, ['ok' => false, 'error' => 'source']);
}
$respond(200, ['ok' => true, 'source' => $source, 'count' => $count]);
