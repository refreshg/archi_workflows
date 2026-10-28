<?php
// ინსტალაცია: Marketplace → Installed solutions → "ARCHI: CRM მენიუს დაჩქარება" → Install.
// არეგისტრირებს მოდულს და ერთ აგენტს (ყოველ 30 წუთში), და ეგრევე ავსებს ქეშს.
// Uninstall შლის აგენტს და ჩვენს ქეშ-ჩანაწერს — ბიტრიქსი ძველებურად მუშაობს.

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

class archi_crmmenufix extends CModule
{
    public $MODULE_ID = 'archi.crmmenufix';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'ARCHI: CRM მენიუს დაჩქარება';
    public $MODULE_DESCRIPTION = 'CRM-ის ზედა მენიუს Marketplace-ის (მიწოდების აპლიკაციების) შემოწმებას ქეშიდან აწვდის, რომ ყოველ გვერდზე ქსელს არ ელოდოს.';
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
        CAgent::RemoveModuleAgents($this->MODULE_ID);
        CAgent::AddAgent('\\Archi\\CrmMenuFix\\Agent::run();', $this->MODULE_ID, 'N', 1800);
        if (Loader::includeModule($this->MODULE_ID)) {
            \Archi\CrmMenuFix\Agent::run();
        }
        return true;
    }

    public function DoUninstall()
    {
        CAgent::RemoveModuleAgents($this->MODULE_ID);
        if (Loader::includeModule($this->MODULE_ID)) {
            \Archi\CrmMenuFix\Agent::clear();
        }
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
