<?php
// ARCHI — დილის მისამართის გასწორება ნომრის გვერდიდან (/crm/deal/filterContactByPhone.php) დილის შექმნისას.
//
// პრობლემა: ნომრის გვერდის ღილაკები (HOT LEADS / addDeal / Ticketing / Rent) დილის ფორმას ხსნის გვერდით პანელში,
// Bitrix ბრაუზერის მისამართს წერს „ახალი დილის“ ბმულზე (/crm/deal/details/0/?...). შენახვის შემდეგ პანელში
// შექმნილი დილი იტვირთება, მაგრამ მისამართი არ ახლდება → refresh ისევ ცარიელ ფორმას ხსნის.
//
// გამოსავალი: მხოლოდ ამ გვერდიდან გახსნილ ფორმაზე (ბმულში UF_CRM_1731506183=YES ნიშანი) ვუსმენთ პანელის
// ჩატვირთვებს და როცა მასში შექმნილი დილი (/crm/deal/details/ID/) გაიხსნება, პანელსაც და ბრაუზერის მისამართსაც
// ვცვლით ამ დილზე. სხვა გვერდებს და Bitrix-ის სხვა „Create“ ღილაკებს არ ვეხებით. Uninstall აბრუნებს ყველაფერს.

namespace Archi\DealUrl;

use Bitrix\Main\Page\Asset;

class Handler
{
    const PHONE_PAGE = '/crm/deal/filterContactByPhone.php';
    const MARK = 'UF_CRM_1731506183=YES';

    public static function onProlog()
    {
        try {
            if (defined('ADMIN_SECTION') && ADMIN_SECTION === true) {
                return;
            }
            global $USER;
            if (!is_object($USER) || !$USER->IsAuthorized()) {
                return;
            }
            $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
            $isPhonePage = strpos($uri, self::PHONE_PAGE) === 0;
            $isCreateForm = strpos($uri, '/crm/deal/details/0/') === 0 && strpos($uri, self::MARK) !== false;
            if (!$isPhonePage && !$isCreateForm) {
                return;
            }
            Asset::getInstance()->addString('<script>' . self::script() . '</script>');
        } catch (\Throwable $e) {
            error_log('[archi.dealurl] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    private static function script()
    {
        $mark = json_encode(self::MARK);
        return <<<JS
(function () {
    var MARK = {$mark};

    function isCreateUrl(url) {
        url = url || '';
        return /\/crm\/deal\/details\/0\//.test(url) && url.indexOf(MARK) !== -1;
    }

    function createdDealId(path) {
        var m = /^\/crm\/deal\/details\/(\d+)\//.exec(path || '');
        return m && +m[1] > 0 ? +m[1] : 0;
    }

    function syncUrl(slider, frame) {
        try {
            var id = createdDealId(frame.contentWindow.location.pathname);
            if (!id) { return; }
            var url = '/crm/deal/details/' + id + '/';
            if (slider.getUrl && slider.getUrl() !== url && slider.setUrl) { slider.setUrl(url); }
            if (isCreateUrl(window.location.pathname + window.location.search)) {
                window.history.replaceState({}, '', url);
            }
        } catch (e) {}
    }

    function onSliderLoad(event) {
        try {
            var slider = event && event.getSlider ? event.getSlider() : null;
            if (!slider || !slider.getUrl || !isCreateUrl(slider.getUrl())) { return; }
            var frame = slider.iframe || (slider.getFrame ? slider.getFrame() : null);
            if (!frame || frame.__archiDealUrl) { return; }
            frame.__archiDealUrl = true;
            // Bitrix-ის onLoad მხოლოდ პირველ ჩატვირთვაზე ისვრის; შემდეგ ჩატვირთვებს (შენახვის შემდეგ) თვითონ ვიჭერთ
            frame.addEventListener('load', function () { syncUrl(slider, frame); });
        } catch (e) {}
    }

    function init() {
        if (window.BX && BX.addCustomEvent) {
            BX.addCustomEvent('SidePanel.Slider:onLoad', onSliderLoad);
        }
    }

    if (window.BX && BX.ready) { BX.ready(init); } else { document.addEventListener('DOMContentLoaded', init); }
})();
JS;
    }
}
