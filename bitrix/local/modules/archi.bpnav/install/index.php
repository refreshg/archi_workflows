<?php
// ინსტალაცია: Marketplace → Installed solutions → "ARCHI: Workflows გვერდების ნავიგაცია" → Install.
// არეგისტრირებს მხოლოდ მოდულს და ერთ handler-ს (main:OnProlog). Uninstall ზუსტად ამავეს შლის.

use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

class archi_bpnav extends CModule
{
    public $MODULE_ID = 'archi.bpnav';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'ARCHI: Workflows გვერდების ნავიგაცია';
    public $MODULE_DESCRIPTION = '/bizproc/userprocesses/ გვერდზე Previous / Next ღილაკებს ?page=N ბმულებად აქცევს (ბიტრიქსის bizproc-ის ჯამური რაოდენობის შეცდომის გვერდის ავლით).';
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
        EventManager::getInstance()->registerEventHandlerCompatible('main', 'OnProlog', $this->MODULE_ID, 'Archi\\BpNav\\Handler', 'onProlog');
        return true;
    }

    public function DoUninstall()
    {
        EventManager::getInstance()->unRegisterEventHandler('main', 'OnProlog', $this->MODULE_ID, 'Archi\\BpNav\\Handler', 'onProlog');
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
