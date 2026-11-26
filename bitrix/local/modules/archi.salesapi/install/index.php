<?php
// ARCHI: Sales API გარე სისტემისთვის. არეგისტრირებს ერთ handler-ს (rest:OnRestServiceBuildDescription), რომელიც ამატებს
// scope-ს „archisales“ მეთოდებით archi.sales.deal.stage და archi.sales.product.status. Uninstall ზუსტად ამავეს შლის.

use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

class archi_salesapi extends CModule
{
    public $MODULE_ID = 'archi.salesapi';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'ARCHI: Sales API (ეტაპი, პროდუქტის სტატუსი)';
    public $MODULE_DESCRIPTION = 'REST scope archisales: archi.sales.deal.stage (Sales დილის ეტაპის შეცვლა), archi.sales.product.status (დილის პროდუქტის დაჯავშნა / გათავისუფლება).';
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
        EventManager::getInstance()->registerEventHandlerCompatible('rest', 'OnRestServiceBuildDescription', $this->MODULE_ID, 'Archi\\SalesApi\\Rest', 'describe');
        return true;
    }

    public function DoUninstall()
    {
        EventManager::getInstance()->unRegisterEventHandler('rest', 'OnRestServiceBuildDescription', $this->MODULE_ID, 'Archi\\SalesApi\\Rest', 'describe');
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
