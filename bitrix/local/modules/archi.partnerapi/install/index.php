<?php
// ARCHI: partner API (read only). Registers one handler, rest:OnRestServiceBuildDescription, which adds the scope
// „archipartner“ with archi.partner.project.list and archi.partner.unit.list. Uninstall removes exactly that.

use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

class archi_partnerapi extends CModule
{
    public $MODULE_ID = 'archi.partnerapi';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'ARCHI: პარტნიორის API (პროექტები, ბინები, ფასები)';
    public $MODULE_DESCRIPTION = 'REST scope archipartner: archi.partner.project.list, archi.partner.unit.list — მხოლოდ კითხვა.';
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
        EventManager::getInstance()->registerEventHandlerCompatible('rest', 'OnRestServiceBuildDescription', $this->MODULE_ID, 'Archi\\PartnerApi\\Rest', 'describe');
        return true;
    }

    public function DoUninstall()
    {
        EventManager::getInstance()->unRegisterEventHandler('rest', 'OnRestServiceBuildDescription', $this->MODULE_ID, 'Archi\\PartnerApi\\Rest', 'describe');
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
