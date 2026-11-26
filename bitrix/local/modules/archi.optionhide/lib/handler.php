<?php
// ARCHI — ჩამოსაშლელი ველის ცალკეული ვარიანტების დამალვა კონკრეტულ ფაიფლაინში (მხოლოდ დილის ბარათზე).
//
// Bitrix-ში სიის ველის ვარიანტები ყველა ფაიფლაინში ერთნაირია. ეს მოდული RULES-ში ჩამოთვლილ ფაიფლაინებში
// დილის ბარათის ჩამოსაშლელ სიიდან მალავს მითითებულ ვარიანტებს. სხვა ფაიფლაინებში არაფერი იცვლება.
// 1.0.0 (2026-11-25): Hot Leads (35), ველი „აირჩიე ოფისი:“ — იმალება „ბათუმის ოფისი“ და „ქობულეთი“.
//
// მხოლოდ ვიზუალური ფილტრია: მონაცემებს არ ცვლის. თუ დილზე დამალული ვარიანტი უკვე არჩეულია, ის რჩება
// (მისი მოხსნა შეიძლება, ხელახლა არჩევა — არა). სხვის კოდს არ ვეხებით. Uninstall აბრუნებს ყველაფერს.

namespace Archi\OptionHide;

use Bitrix\Main\Page\Asset;

class Handler
{
    // ფაიფლაინი => [ველის კოდი => დასამალი ვარიანტების (enum) ID-ები]
    const RULES = array(
        35 => array(                                   // Hot Leads
            'UF_CRM_1636558017583' => array(3221, 12636),   // „აირჩიე ოფისი:“ — ბათუმის ოფისი, ქობულეთი
        ),
    );

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
            $request = \Bitrix\Main\Context::getCurrent()->getRequest();
            $page = (string)$request->getRequestedPage();
            if (!preg_match('#^/crm/deal/details/(\d+)/#', $page, $m)) {
                return;
            }
            $dealId = (int)$m[1];
            if ($dealId > 0) {
                if (!\Bitrix\Main\Loader::includeModule('crm')) {
                    return;
                }
                $deal = \CCrmDeal::GetListEx(array(), array('ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'), false, false, array('ID', 'CATEGORY_ID'))->Fetch();
                if (!$deal) {
                    return;
                }
                $category = (int)$deal['CATEGORY_ID'];
            } else {
                // ახალი დილის ფორმა: /crm/deal/details/0/?category_id=35
                $category = (int)$request->getQuery('category_id');
            }
            if (!isset(self::RULES[$category])) {
                return;
            }
            Asset::getInstance()->addString('<script>' . self::script(self::RULES[$category]) . '</script>');
        } catch (\Throwable $e) {
            error_log('[archi.optionhide] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    private static function script(array $rules)
    {
        // სიის დიალოგში ვარიანტის entityId არის ველის კოდი პატარა ასოებით + "[]" (მრავალჯერადი) ან მის გარეშე
        $map = array();
        foreach ($rules as $field => $ids) {
            $map[strtolower($field)] = array_map('intval', $ids);
        }
        $cfg = json_encode($map, JSON_HEX_TAG);

        return <<<JS
(function () {
    var RULES = {$cfg};

    function hideIn(dialog) {
        try {
            if (!dialog || !dialog.getItems) { return; }
            dialog.getItems().forEach(function (item) {
                var entity = String(item.getEntityId() || '').toLowerCase().replace(/\[\]$/, '');
                var ids = RULES[entity];
                if (ids && ids.indexOf(parseInt(item.getId(), 10)) !== -1 && !item.isSelected()) {
                    item.setHidden(true);
                }
            });
        } catch (e) {}
    }

    function sweep() {
        try {
            if (window.BX && BX.UI && BX.UI.EntitySelector && BX.UI.EntitySelector.Dialog) {
                BX.UI.EntitySelector.Dialog.getInstances().forEach(hideIn);
            }
        } catch (e) {}
    }

    function init() {
        if (!window.BX || !BX.Event || !BX.Event.EventEmitter) { return false; }
        ['BX.UI.EntitySelector.Dialog:onShow', 'BX.UI.EntitySelector.Dialog:onFirstShow', 'BX.UI.EntitySelector.Dialog:onLoad',
         'BX.UI.EntitySelector.Dialog:Item:onDeselect'].forEach(function (name) {
            BX.Event.EventEmitter.subscribe(name, function (event) { hideIn(event.getTarget()); sweep(); });
        });
        // ველი რედაქტირებაში გადასვლისას ახალ დიალოგს ქმნის — გახსნამდეც რომ დამალული იყოს
        setInterval(sweep, 1500);
        sweep();
        return true;
    }

    var tries = 0;
    (function wait() {
        if (init() || ++tries > 100) { return; }
        setTimeout(wait, 200);
    })();
})();
JS;
    }
}
