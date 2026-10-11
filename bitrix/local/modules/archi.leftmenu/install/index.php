<?php
// ინსტალაცია: Marketplace → Installed solutions → "ARCHI: მარცხენა მენიუს კალენდრები" → Install.
// არეგისტრირებს მხოლოდ მოდულს და ერთ handler-ს (main:OnProlog). Uninstall ზუსტად ამავეს შლის.

use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

class archi_leftmenu extends CModule
{
    public $MODULE_ID = 'archi.leftmenu';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'ARCHI: მარცხენა მენიუს კალენდრები';
    public $MODULE_DESCRIPTION = 'ჯგუფი 69-ის (call center) მარცხენა მენიუს კალენდრების ბლოკს უმატებს დამატებით გამყიდველების კალენდრებს.';
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
        EventManager::getInstance()->registerEventHandlerCompatible('main', 'OnProlog', $this->MODULE_ID, 'Archi\\LeftMenu\\Handler', 'onProlog');
        return true;
    }

    public function DoUninstall()
    {
        EventManager::getInstance()->unRegisterEventHandler('main', 'OnProlog', $this->MODULE_ID, 'Archi\\LeftMenu\\Handler', 'onProlog');
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
