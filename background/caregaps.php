<?php

/**
 * Background service entry point (registered in sql/install.sql as StaffWorklistCareGaps).
 *
 * OpenEMR's runner includes this file and calls the function below in a
 * subprocess, every execute_interval minutes while someone is logged in or
 * from cron (library/ajax/execute_background_services.php).
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;
use OpenHealthcare\StaffWorklist\CareGaps\CareGapRefresher;

function staffWorklistRefreshCareGaps(): void
{
    if (!class_exists(CareGapRefresher::class)) {
        $loader = new ModulesClassLoader(OEGlobalsBag::getInstance()->getProjectDir());
        $loader->registerNamespaceIfNotExists('OpenHealthcare\\StaffWorklist\\', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src');
    }
    // Stay well inside the runner's lease, which is derived from the 10 minute interval.
    CareGapRefresher::create(new DateTimeImmutable())->refresh(240);
}
