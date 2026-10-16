<?php
// ინსტალაცია: Marketplace → Installed solutions → "ARCHI: კონკრეტული მენეჯერის მოთხოვნა" → Install.
// არეგისტრირებს მხოლოდ მოდულს და ერთ handler-ს (crm:OnBeforeCrmDealAdd). Uninstall ზუსტად ამავეს შლის.

use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

class archi_dealadvisor extends CModule
{
    public $MODULE_ID = 'archi.dealadvisor';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'ARCHI: კონკრეტული მენეჯერის მოთხოვნა';
    public $MODULE_DESCRIPTION = 'Sales-ში ახალი დილის შექმნისას, თუ „მოთხოვნა“ = „მოითხოვა კონკრეტული მენეჯერი“, პასუხისმგებელი უნდა იყოს ჯგუფ salemanager-იდან.';
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
        EventManager::getInstance()->registerEventHandlerCompatible('crm', 'OnBeforeCrmDealAdd', $this->MODULE_ID, 'Archi\\DealAdvisor\\Handler', 'onBeforeDealAdd');
        return true;
    }

    public function DoUninstall()
    {
        EventManager::getInstance()->unRegisterEventHandler('crm', 'OnBeforeCrmDealAdd', $this->MODULE_ID, 'Archi\\DealAdvisor\\Handler', 'onBeforeDealAdd');
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
