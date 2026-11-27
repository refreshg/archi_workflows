<?php
// ARCHI: ღილაკი „Broker გადატანა“ Sales ფაიფლაინის (0) დილის ბარათზე. არეგისტრირებს ერთ handler-ს (main:OnProlog).
// Uninstall ზუსტად ამავეს შლის. მონაცემთა ბაზაში არაფერს ქმნის.

use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

class archi_brokermove extends CModule
{
    public $MODULE_ID = 'archi.brokermove';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'ARCHI: Broker გადატანა (ღილაკი დილის ბარათზე)';
    public $MODULE_DESCRIPTION = 'Sales ფაიფლაინის დილის ბარათზე ღილაკი „Broker გადატანა“: დადასტურების შემდეგ უშვებს ბიზნეს-პროცეს 1301-ს.';
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
        EventManager::getInstance()->registerEventHandler('main', 'OnProlog', $this->MODULE_ID, 'Archi\\BrokerMove\\Handler', 'onProlog');
        return true;
    }

    public function DoUninstall()
    {
        EventManager::getInstance()->unRegisterEventHandler('main', 'OnProlog', $this->MODULE_ID, 'Archi\\BrokerMove\\Handler', 'onProlog');
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
