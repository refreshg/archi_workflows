<?php
// ARCHI — მარცხენა მენიუს დამატებითი კალენდრები ჰოთ ლიდის გუნდისთვის (ჯგუფი 69, „call center“).
//
// არსებულ (ხელით დაწერილ) მენიუს სკრიპტს არ ვეხებით. ის ჯგუფი 69-ს უხატავს გამყიდველების კალენდრებს.
// ეს მოდული მხოლოდ ჯგუფი 69-ის წევრებს გვერდზე უმატებს პატარა სკრიპტს, რომელიც ელოდება იმ ბლოკის
// დახატვას და მის ბოლოს (მარიამ ხარატიშვილის შემდეგ) იმავე სტილით ამატებს ქვემოთ ჩამოთვლილ კალენდრებს.
// თუ ბმული უკვე არის მენიუში, მეორედ აღარ ემატება. Uninstall აბრუნებს ყველაფერს.
//
// 1.1.0: დამატებით, კონკრეტულ იუზერებს (USER_ITEMS) მენიუს ხილული პუნქტების ბოლოში ემატება ბმული.
// 1.2.0: USER_ITEMS-ში 16 გამყიდველის კალენდარი CAL_USERS-ისთვის (5544, 5601, 5532).
// 1.3.0: ROLE_ITEMS — CRM როლის წევრებს ემატება ბმული (როლი „Projects“ 27 → „თიქეთების ბორდი“).

namespace Archi\LeftMenu;

use Bitrix\Main\Page\Asset;

class Handler
{
    const GROUP_ID = 69;

    // [მენიუს ელემენტის ID, ბმული, სათაური]
    const ITEMS = array(
        array('archi_cal_5566', '/company/personal/user/5566/calendar/', 'თამარ კუკავა calendar'),
        array('archi_cal_5563', '/company/personal/user/5563/calendar/', 'ასმათი თაბაგარი calendar'),
        array('archi_cal_5050', '/company/personal/user/5050/calendar/', 'სოფიკო კობახიძე calendar'),
    );

    // გამყიდველების კალენდრები: მარიამ ყულეჯიშვილი (5544), მარიამ მიქელაძე (5601), სალომე მალასიძე (5532)
    const CAL_USERS = array(5544, 5601, 5532);

    // [მენიუს ელემენტის ID, ბმული, სათაური, იუზერების ID-ები]
    const USER_ITEMS = array(
        // სანდრო გელაშვილი (4341), Soner Sevim (4707) — MEP
        array('archi_pub_site_32', '/pub/site/32/', 'ვაკანსიის დაინიცირება', array(4341, 4707)),

        array('archi_ucal_1252', '/company/personal/user/1252/calendar/', 'თინათინ პეტაშვილი', self::CAL_USERS),
        array('archi_ucal_1362', '/company/personal/user/1362/calendar/', 'ნინო დონაძე', self::CAL_USERS),
        array('archi_ucal_1360', '/company/personal/user/1360/calendar/', 'ქეთევან კურტანიძე', self::CAL_USERS),
        array('archi_ucal_4407', '/company/personal/user/4407/calendar/', 'მარიამ საყვარელიძე', self::CAL_USERS),
        array('archi_ucal_4462', '/company/personal/user/4462/calendar/', 'ეკა გურაბანიძე', self::CAL_USERS),
        array('archi_ucal_4642', '/company/personal/user/4642/calendar/', 'თამარ დავითულიანი', self::CAL_USERS),
        array('archi_ucal_4286', '/company/personal/user/4286/calendar/', 'შორენა მოწონელიძე', self::CAL_USERS),
        array('archi_ucal_4287', '/company/personal/user/4287/calendar/', 'გვანცა ოქროპირიძე', self::CAL_USERS),
        array('archi_ucal_1361', '/company/personal/user/1361/calendar/', 'მაია მიქაძე', self::CAL_USERS),
        array('archi_ucal_4700', '/company/personal/user/4700/calendar/', 'ნინო რეკვავა', self::CAL_USERS),
        array('archi_ucal_4711', '/company/personal/user/4711/calendar/', 'თეონა გეგეჭკორი', self::CAL_USERS),
        array('archi_ucal_4149', '/company/personal/user/4149/calendar/', 'ნათია გიქოშვილი', self::CAL_USERS),
        array('archi_ucal_5530', '/company/personal/user/5530/calendar/', 'მარიამ ხარატიშვილი', self::CAL_USERS),
        array('archi_ucal_5566', '/company/personal/user/5566/calendar/', 'თამარ კუკავა', self::CAL_USERS),
        array('archi_ucal_5563', '/company/personal/user/5563/calendar/', 'ასმათი თაბაგარი', self::CAL_USERS),
        array('archi_ucal_5050', '/company/personal/user/5050/calendar/', 'სოფიკო კობახიძე', self::CAL_USERS),
    );

    // CRM როლის წევრებისთვის: [მენიუს ელემენტის ID, ბმული, სათაური, CRM როლების ID-ები]
    const ROLE_ITEMS = array(
        // როლი „Projects“ (27)
        array('archi_ticket_board', 'https://project-dep-ticket-board.vercel.app/#/summary?p=1', 'თიქეთების ბორდი', array(27)),
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
            $groups = array_map('intval', (array)$USER->GetUserGroupArray());
            if (in_array(self::GROUP_ID, $groups, true)) {
                Asset::getInstance()->addString('<script>' . self::script() . '</script>');
            }
            $userId = (int)$USER->GetID();
            $mine = array();
            foreach (self::USER_ITEMS as $item) {
                if (in_array($userId, $item[3], true)) {
                    $mine[] = array($item[0], $item[1], $item[2]);
                }
            }
            foreach (self::ROLE_ITEMS as $item) {
                if (self::inCrmRoles($USER, $item[3])) {
                    $mine[] = array($item[0], $item[1], $item[2]);
                }
            }
            if ($mine) {
                // ჯგუფების მენიუს სკრიპტი პუნქტებს style.display='none'-ით მალავს — !important მას ჯობნის.
                // data-link-ით ვაჩენთ როგორც ჩვენს პუნქტს, ისე იმავე ბმულით მენიუში უკვე არსებულს.
                $css = '';
                foreach ($mine as $item) {
                    $css .= '.menu-items>li[data-link="' . str_replace('"', '', $item[1]) . '"]{display:flex!important}';
                }
                Asset::getInstance()->addString('<style>' . $css . '</style>');
                Asset::getInstance()->addString('<script>' . self::userItemsScript($mine) . '</script>');
            }
        } catch (\Throwable $e) {
            error_log('[archi.leftmenu] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    // მომხმარებელი CRM როლშია, თუ როლის რომელიმე წევრი (U.., G.., D.., DR..) მის access კოდებს ემთხვევა.
    private static function inCrmRoles($user, array $roleIds)
    {
        $relations = self::crmRoleRelations();
        $codes = array_flip((array)$user->GetAccessCodes());
        foreach ($roleIds as $roleId) {
            foreach ($relations[(int)$roleId] ?? array() as $code) {
                if (isset($codes[$code])) {
                    return true;
                }
            }
        }
        return false;
    }

    // როლი => წევრების კოდები; 10 წუთით იქეშება, რომ ყოველ გვერდზე ბაზას არ მივმართოთ.
    private static function crmRoleRelations()
    {
        static $relations = null;
        if ($relations !== null) {
            return $relations;
        }
        $cache = \Bitrix\Main\Data\Cache::createInstance();
        if ($cache->initCache(600, 'archi_leftmenu_crm_roles', '/archi.leftmenu')) {
            $relations = $cache->getVars();
            return $relations;
        }
        $relations = array();
        if (\Bitrix\Main\Loader::includeModule('crm')) {
            if (method_exists('\CCrmRole', 'GetRelation')) {
                $res = \CCrmRole::GetRelation();
                while ($row = $res->Fetch()) {
                    $relations[(int)$row['ROLE_ID']][] = $row['RELATION'];
                }
            } elseif (class_exists('\Bitrix\Crm\Security\Role\Model\RoleRelationTable')) {
                $res = \Bitrix\Crm\Security\Role\Model\RoleRelationTable::getList(array('select' => array('ROLE_ID', 'RELATION')));
                while ($row = $res->fetch()) {
                    $relations[(int)$row['ROLE_ID']][] = $row['RELATION'];
                }
            }
        }
        if ($cache->startDataCache()) {
            $cache->endDataCache($relations);
        }
        return $relations;
    }

    private static function userItemsScript(array $items)
    {
        $items = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        return <<<JS
(function () {
    var ITEMS = {$items};
    var tries = 0;

    function norm(s) { return (s || '').trim(); }

    // მენიუში უკვე არსებული იგივე ბმულის პუნქტი (მაგ. ადმინის დამატებული, მაგრამ ჯგუფის სკრიპტით დამალული)
    function existingItem(menu, link) {
        var links = menu.querySelectorAll('a.menu-item-link');
        for (var i = 0; i < links.length; i++) {
            if (norm(links[i].getAttribute('href')) === link) {
                var li = links[i].closest ? links[i].closest('li') : links[i].parentNode;
                return li || links[i];
            }
        }
        return null;
    }

    function initials(name) {
        var w = name.split(/\s+/);
        return (w[0] ? w[0].charAt(0) : '') + (w[1] ? w[1].charAt(0) : '');
    }

    function build(id, link, name) {
        var li = document.createElement('li');
        li.id = 'bx_left_menu_' + id;
        li.className = 'menu-item-block menu-item-no-icon-state';
        li.style.display = 'flex';
        li.setAttribute('data-status', 'show');
        li.setAttribute('data-id', id);
        li.setAttribute('data-role', 'item');
        li.setAttribute('data-link', link);
        li.setAttribute('data-type', 'self');
        li.setAttribute('data-new-page', 'Y');
        var a = document.createElement('a');
        a.className = 'menu-item-link';
        a.href = link;
        a.target = '_blank';
        var iconBox = document.createElement('span');
        iconBox.className = 'menu-item-icon-box';
        var icon = document.createElement('span');
        icon.className = 'menu-item-icon';
        icon.textContent = initials(name);
        iconBox.appendChild(icon);
        var text = document.createElement('span');
        text.className = 'menu-item-link-text';
        text.setAttribute('data-role', 'item-text');
        text.textContent = name;
        a.appendChild(iconBox);
        a.appendChild(text);
        li.appendChild(a);
        return li;
    }

    // ბოლო ხილული პუნქტი (დამალულ პუნქტებში არ ვსვამთ)
    function lastShownItem(menu) {
        var last = null;
        var items = menu.children;
        for (var i = 0; i < items.length; i++) {
            if (items[i].getAttribute('data-role') === 'item' && items[i].getAttribute('data-status') !== 'hide') { last = items[i]; }
        }
        return last;
    }

    function ensure() {
        var menu = document.querySelector('.menu-items');
        var after = menu ? lastShownItem(menu) : null;
        if (!after) { return false; }
        for (var i = 0; i < ITEMS.length; i++) {
            var id = ITEMS[i][0], link = ITEMS[i][1], name = ITEMS[i][2];
            // ჯგუფების მენიუს სკრიპტი თავისი სიის გარდა ყველაფერს მალავს (display:none) — ჩვენსას ვაჩენთ
            var own = document.getElementById('bx_left_menu_' + id) || existingItem(menu, link);
            if (own) { if (own.style.display === 'none') { own.style.display = 'flex'; } continue; }
            var li = build(id, link, name);
            after.parentNode.insertBefore(li, after.nextSibling);
            after = li;
        }
        return true;
    }

    // სხვა სკრიპტმა შეიძლება მენიუ ჩვენ შემდეგ თავიდან დახატოს ან დამალოს — ცვლილებაზე ვამოწმებთ და ვაბრუნებთ
    var pending = null;
    function watch() {
        if (!window.MutationObserver) { return; }
        new MutationObserver(function () {
            if (pending) { return; }
            pending = setTimeout(function () { pending = null; ensure(); }, 150);
        }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['style'] });
    }

    function run() {
        if (!ensure() && ++tries < 50) { setTimeout(run, 200); return; }
        watch();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(run, 300); });
    } else {
        setTimeout(run, 300);
    }
})();
JS;
    }

    private static function script()
    {
        $items = json_encode(self::ITEMS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        return <<<JS
(function () {
    var ITEMS = {$items};
    // ჯგუფი 69-ის კალენდრების ბლოკის ნიშანი: პირველი ელემენტი თინათინ პეტაშვილის კალენდარია
    var MARKER_ID = 'bx_left_menu_00001';
    var MARKER_LINK = '/company/personal/user/1252/calendar/';
    var tries = 0;

    function norm(s) { return (s || '').trim(); }

    function linkExists(menu, link) {
        var links = menu.querySelectorAll('a.menu-item-link');
        for (var i = 0; i < links.length; i++) {
            if (norm(links[i].getAttribute('href')) === link) { return true; }
        }
        return false;
    }

    function build(id, link, name) {
        var li = document.createElement('li');
        li.id = 'bx_left_menu_' + id;
        li.className = 'menu-item-block menu-item-no-icon-state';
        li.style.display = 'flex';
        li.setAttribute('data-status', 'show');
        li.setAttribute('data-id', id);
        li.setAttribute('data-role', 'item');
        li.setAttribute('data-link', link);
        li.setAttribute('data-type', 'self');
        li.setAttribute('data-new-page', 'Y');
        var a = document.createElement('a');
        a.className = 'menu-item-link';
        a.href = link;
        a.target = '_blank';
        var iconBox = document.createElement('span');
        iconBox.className = 'menu-item-icon-box';
        var icon = document.createElement('span');
        icon.className = 'menu-item-icon';
        icon.textContent = 'ვდ';
        iconBox.appendChild(icon);
        var text = document.createElement('span');
        text.className = 'menu-item-link-text';
        text.setAttribute('data-role', 'item-text');
        text.textContent = name;
        a.appendChild(iconBox);
        a.appendChild(text);
        li.appendChild(a);
        return li;
    }

    function lastCalendarItem(menu) {
        var last = null;
        var items = menu.children;
        for (var i = 0; i < items.length; i++) {
            if (/\/company\/personal\/user\/\d+\/calendar\//.test(items[i].getAttribute('data-link') || '')) { last = items[i]; }
        }
        return last;
    }

    function run() {
        var menu = document.querySelector('.menu-items');
        var marker = document.getElementById(MARKER_ID);
        if (!menu || !marker || norm(marker.getAttribute('data-link')) !== MARKER_LINK) {
            if (++tries < 50) { setTimeout(run, 200); }
            return;
        }
        var after = lastCalendarItem(menu);
        for (var i = 0; i < ITEMS.length; i++) {
            var id = ITEMS[i][0], link = ITEMS[i][1], name = ITEMS[i][2];
            if (document.getElementById('bx_left_menu_' + id) || linkExists(menu, link)) { continue; }
            var li = build(id, link, name);
            if (after && after.parentNode === menu) {
                after.parentNode.insertBefore(li, after.nextSibling);
            } else {
                menu.appendChild(li);
            }
            after = li;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(run, 300); });
    } else {
        setTimeout(run, 300);
    }
})();
JS;
    }
}
