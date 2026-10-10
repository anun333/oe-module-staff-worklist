<?php

/**
 * Staff Worklist module bootstrap, loaded by OpenEMR on every request while the module is enabled.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

use OpenEMR\Core\ModulesClassLoader;
use OpenEMR\Core\OEGlobalsBag;
use OpenHealthcare\StaffWorklist\Bootstrap;

$classLoader = new ModulesClassLoader(OEGlobalsBag::getInstance()->getProjectDir());
$classLoader->registerNamespaceIfNotExists('OpenHealthcare\\StaffWorklist\\', __DIR__ . DIRECTORY_SEPARATOR . 'src');

(new Bootstrap(OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher()))->subscribeToEvents();
