<?php

/**
 * Adds the Worklist entry to the main menu, next to Messages.
 *
 * @package   OpenHealthcare\StaffWorklist
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

namespace OpenHealthcare\StaffWorklist;

use OpenEMR\Menu\MenuEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_INSTALLATION_PATH = '/interface/modules/custom_modules/oe-module-staff-worklist';

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addMenuItem(...));
    }

    public function addMenuItem(MenuEvent $event): MenuEvent
    {
        $item = new \stdClass();
        $item->requirement = 0;
        $item->target = 'wkl';
        $item->menu_id = 'wkl0';
        $item->label = xlt('Worklist');
        $item->url = self::MODULE_INSTALLATION_PATH . '/public/index.php';
        $item->children = [];
        $item->acl_req = ['patients', 'med'];
        $item->global_req = [];

        $menu = $event->getMenu();
        $position = count($menu);
        foreach ($menu as $i => $entry) {
            if (($entry->menu_id ?? '') === 'msg0') {
                $position = $i + 1;
                break;
            }
        }
        array_splice($menu, $position, 0, [$item]);
        $event->setMenu($menu);
        return $event;
    }
}
