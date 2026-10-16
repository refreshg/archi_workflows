<?php
// ინსტალაცია: Marketplace → Installed solutions → "ARCHI: პროექტების (სია 111) ცვლილებების ჟურნალი" → Install.
// არეგისტრირებს მხოლოდ მოდულს და iblock მოვლენების handler-ებს. Uninstall ზუსტად ამავეს შლის (ჟურნალის სია 234 რჩება).

use Bitrix\Main\EventManager;
use Bitrix\Main\ModuleManager;

class archi_listlog extends CModule
{
    public $MODULE_ID = 'archi.listlog';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME = 'ARCHI: პროექტების (სია 111) ცვლილებების ჟურნალი';
    public $MODULE_DESCRIPTION = 'სია 111-ის ყოველი ცვლილება (ვინ, როდის, რომელი ველი, რა იყო → რა გახდა) იწერება სია 234-ში.';
    public $PARTNER_NAME = 'ARCHI';
    public $PARTNER_URI = 'https://crm.archi.ge';

    const HANDLERS = array(
        array('OnBeforeIBlockElementUpdate', 'onBeforeUpdate'),
        array('OnAfterIBlockElementUpdate', 'onAfterUpdate'),
        array('OnIBlockElementSetPropertyValues', 'onBeforeSetProps'),
        array('OnAfterIBlockElementSetPropertyValues', 'onAfterSetProps'),
        array('OnIBlockElementSetPropertyValuesEx', 'onBeforeSetProps'),
        array('OnAfterIBlockElementSetPropertyValuesEx', 'onAfterSetProps'),
        array('OnAfterIBlockElementAdd', 'onAfterAdd'),
        array('OnBeforeIBlockElementDelete', 'onBeforeDelete'),
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
        $em = EventManager::getInstance();
        foreach (self::HANDLERS as $h) {
            $em->registerEventHandlerCompatible('iblock', $h[0], $this->MODULE_ID, 'Archi\\ListLog\\Handler', $h[1]);
        }
        return true;
    }

    public function DoUninstall()
    {
        $em = EventManager::getInstance();
        foreach (self::HANDLERS as $h) {
            $em->unRegisterEventHandler('iblock', $h[0], $this->MODULE_ID, 'Archi\\ListLog\\Handler', $h[1]);
        }
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }
}
