<?php
// ინსტალაცია: Marketplace → Installed solutions → "ARCHI: დილის დავალიანების API" → Install.
// არეგისტრირებს მხოლოდ მოდულს და ერთ handler-ს (rest:OnRestServiceBuildDescription),
// რომელიც ამატებს REST მეთოდს archi.deal.debt. Uninstall ზუსტად ამავეს შლის.

use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

class archi_dealapi extends CModule
{
    public $MODULE_ID = 'archi.dealapi';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'ARCHI: დილის დავალიანების API';
    public $MODULE_DESCRIPTION = 'REST მეთოდი archi.deal.debt: დავალიანება, დეტალები, სტატუსი და საიდენტიფიკაციო კოდი (ლისტი 231).';
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
        EventManager::getInstance()->registerEventHandlerCompatible('rest', 'OnRestServiceBuildDescription', $this->MODULE_ID, 'Archi\\DealApi\\Rest', 'describe');
        return true;
    }

    public function DoUninstall()
    {
        EventManager::getInstance()->unRegisterEventHandler('rest', 'OnRestServiceBuildDescription', $this->MODULE_ID, 'Archi\\DealApi\\Rest', 'describe');
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
