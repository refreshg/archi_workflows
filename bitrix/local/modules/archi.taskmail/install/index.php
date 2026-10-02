<?php
// ინსტალაცია: Admin → Settings → Modules → "ARCHI: თასქების მეილ-შეტყობინებები" → Install.
// აკეთებს მხოლოდ ორ რამეს: არეგისტრირებს მოდულს და მის event handler-ებს (b_module_to_module).
// წაშლა (Uninstall) ზუსტად ამავეს შლის — სხვა ფაილებს/კოდს არ ეხება.

use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

class archi_taskmail extends CModule
{
    public $MODULE_ID = 'archi.taskmail';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'ARCHI: თასქების მეილ-შეტყობინებები';
    public $MODULE_DESCRIPTION = 'მეილი თასქის მონაწილეებს: შექმნა, კომენტარი, ფაილი, დასრულება.';
    public $PARTNER_NAME = 'ARCHI';
    public $PARTNER_URI = 'https://crm.archi.ge';

    private static $events = array(
        array('tasks', 'OnTaskAdd', 'onTaskAdd'),
        array('tasks', 'OnBeforeTaskUpdate', 'onBeforeTaskUpdate'),
        array('tasks', 'OnTaskUpdate', 'onTaskUpdate'),
        array('tasks', 'OnAfterCommentAdd', 'onCommentAdd'),
    );

    public function __construct()
    {
        $arModuleVersion = array();
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
    }

    public function DoInstall()
    {
        ModuleManager::registerModule($this->MODULE_ID);
        $this->InstallEvents();
        return true;
    }

    public function DoUninstall()
    {
        $this->UnInstallEvents();
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }

    public function InstallEvents()
    {
        $manager = EventManager::getInstance();
        foreach (self::$events as $event) {
            $manager->registerEventHandlerCompatible($event[0], $event[1], $this->MODULE_ID, 'Archi\\TaskMail\\Handler', $event[2]);
        }
        return true;
    }

    public function UnInstallEvents()
    {
        $manager = EventManager::getInstance();
        foreach (self::$events as $event) {
            $manager->unRegisterEventHandler($event[0], $event[1], $this->MODULE_ID, 'Archi\\TaskMail\\Handler', $event[2]);
        }
        return true;
    }
}
