<?php
// ინსტალაცია: Marketplace → Installed solutions → "ARCHI: International ლიდის ნიშანი" → Install.
// არეგისტრირებს მხოლოდ მოდულს და ერთ handler-ს (crm:OnBeforeCrmDealUpdate). Uninstall ზუსტად ამავეს შლის.

use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

class archi_intlmark extends CModule
{
    public $MODULE_ID = 'archi.intlmark';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'ARCHI: International ლიდის ნიშანი';
    public $MODULE_DESCRIPTION = 'International ლიდს Sales/Hot Leads-ში სათაურის თავში უწერს ⭐INT-ს, ხოლო +995 ნომრის შემთხვევაში ⭐GE-ს.';
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
        EventManager::getInstance()->registerEventHandlerCompatible('crm', 'OnBeforeCrmDealUpdate', $this->MODULE_ID, 'Archi\\IntlMark\\Handler', 'onBeforeUpdate');
        return true;
    }

    public function DoUninstall()
    {
        EventManager::getInstance()->unRegisterEventHandler('crm', 'OnBeforeCrmDealUpdate', $this->MODULE_ID, 'Archi\\IntlMark\\Handler', 'onBeforeUpdate');
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
