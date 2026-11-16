<?php
// ინსტალაცია: Marketplace → Installed solutions → "ARCHI: International → Sales ღილაკი" → Install.
// არეგისტრირებს მხოლოდ მოდულს და ერთ handler-ს (main:OnProlog). Uninstall ზუსტად ამავეს შლის.

use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

class archi_intltosales extends CModule
{
    public $MODULE_ID = 'archi.intltosales';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'ARCHI: International → Sales ღილაკი';
    public $MODULE_DESCRIPTION = 'International Lead Generation (40) დილის ბარათზე ღილაკი, რომელიც უშვებს ბიზნეს-პროცესს 1300 „Inter > Sales > Geo“.';
    public $PARTNER_NAME = 'ARCHI';
    public $PARTNER_URI = 'https://crm.archi.ge';

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
        EventManager::getInstance()->registerEventHandler('main', 'OnProlog', $this->MODULE_ID, 'Archi\IntlToSales\Handler', 'onProlog');
        return true;
    }

    public function DoUninstall()
    {
        EventManager::getInstance()->unRegisterEventHandler('main', 'OnProlog', $this->MODULE_ID, 'Archi\IntlToSales\Handler', 'onProlog');
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
