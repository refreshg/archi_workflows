<?php
// ინსტალაცია: Marketplace → Installed solutions → "ARCHI: SMS (Bird) — International" → Install.
// არეგისტრირებს მოდულს, ერთ handler-ს (main:OnProlog) და ქმნის ლოგის ცხრილს b_archi_birdsms_log.
// Uninstall შლის handler-ს და მოდულს; ლოგის ცხრილი რჩება (ისტორია არ დაიკარგოს).

use Bitrix\Main\Application;
use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

class archi_birdsms extends CModule
{
    public $MODULE_ID = 'archi.birdsms';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'ARCHI: SMS (Bird) — International';
    public $MODULE_DESCRIPTION = 'International Lead Generation (40) დილის ბარათზე „SMS“ ღილაკი: გაგზავნა Bird API-ით, ლოგი, განმეორების გაფრთხილება.';
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
        Application::getConnection()->query("
            CREATE TABLE IF NOT EXISTS b_archi_birdsms_log (
                ID INT UNSIGNED NOT NULL AUTO_INCREMENT,
                CREATED DATETIME NOT NULL,
                DEAL_ID INT UNSIGNED NOT NULL,
                DEAL_TITLE VARCHAR(255) NULL,
                CLIENT_NAME VARCHAR(255) NULL,
                PHONE VARCHAR(32) NOT NULL,
                COUNTRY VARCHAR(64) NULL,
                MESSAGE TEXT NOT NULL,
                USER_ID INT UNSIGNED NOT NULL,
                STATUS VARCHAR(16) NOT NULL,
                BIRD_MESSAGE_ID VARCHAR(64) NULL,
                ERROR_TEXT TEXT NULL,
                MODE VARCHAR(8) NOT NULL,
                PRIMARY KEY (ID),
                KEY IX_ARCHI_BIRDSMS_DEAL (DEAL_ID, CREATED)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        ModuleManager::registerModule($this->MODULE_ID);
        EventManager::getInstance()->registerEventHandler('main', 'OnProlog', $this->MODULE_ID, 'Archi\\BirdSms\\Handler', 'onProlog');
        return true;
    }

    public function DoUninstall()
    {
        EventManager::getInstance()->unRegisterEventHandler('main', 'OnProlog', $this->MODULE_ID, 'Archi\\BirdSms\\Handler', 'onProlog');
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
