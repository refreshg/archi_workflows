<?php
// ინსტალაცია: Marketplace → Installed solutions → "ARCHI: თიქეთის მომართვის ტიპი" → Install.
// არეგისტრირებს მოდულს და სამ handler-ს (crm: OnBeforeCrmDealAdd, OnBeforeCrmDealUpdate, OnAfterCrmDealUpdate).
// Uninstall ზუსტად ამავეს შლის; ველები და მათი მნიშვნელობები რჩება.

use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

class archi_tickettype extends CModule
{
    public $MODULE_ID = 'archi.tickettype';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'ARCHI: თიქეთის მომართვის ტიპი';
    public $MODULE_DESCRIPTION = 'Tickets (9): „მომართვის ტიპი“ სავალდებულოა ბარათიდან შექმნისას; ტიპის შეცვლას სჭირდება მიზეზი და იწერება ტაიმლაინში.';
    public $PARTNER_NAME = 'ARCHI';
    public $PARTNER_URI = 'https://crm.archi.ge';

    const EVENTS = array(
        array('OnBeforeCrmDealAdd', 'onBeforeAdd'),
        array('OnBeforeCrmDealUpdate', 'onBeforeUpdate'),
        array('OnAfterCrmDealUpdate', 'onAfterUpdate'),
    );

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
        foreach (self::EVENTS as $e) {
            EventManager::getInstance()->registerEventHandlerCompatible('crm', $e[0], $this->MODULE_ID, 'Archi\TicketType\Handler', $e[1]);
        }
        return true;
    }

    public function DoUninstall()
    {
        foreach (self::EVENTS as $e) {
            EventManager::getInstance()->unRegisterEventHandler('crm', $e[0], $this->MODULE_ID, 'Archi\TicketType\Handler', $e[1]);
        }
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
