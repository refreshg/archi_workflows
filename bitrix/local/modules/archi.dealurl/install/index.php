<?php
// ინსტალაცია: Marketplace → Installed solutions → "ARCHI: დილის მისამართი ნომრის გვერდიდან" → Install.
// არეგისტრირებს მხოლოდ მოდულს და ერთ handler-ს (main:OnProlog). Uninstall ზუსტად ამავეს შლის.

use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

class archi_dealurl extends CModule
{
    public $MODULE_ID = 'archi.dealurl';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'ARCHI: დილის მისამართი ნომრის გვერდიდან';
    public $MODULE_DESCRIPTION = 'ნომრის გვერდიდან (filterContactByPhone.php) შექმნილი დილის შემდეგ ბრაუზერის მისამართს შექმნილ დილზე ცვლის, რომ refresh-მა ცარიელი ფორმა არ გახსნას.';
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
        EventManager::getInstance()->registerEventHandlerCompatible('main', 'OnProlog', $this->MODULE_ID, 'Archi\\DealUrl\\Handler', 'onProlog');
        return true;
    }

    public function DoUninstall()
    {
        EventManager::getInstance()->unRegisterEventHandler('main', 'OnProlog', $this->MODULE_ID, 'Archi\\DealUrl\\Handler', 'onProlog');
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
