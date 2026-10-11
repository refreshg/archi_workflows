<?php
// ARCHI — მარცხენა მენიუს დამატებითი კალენდრები ჰოთ ლიდის გუნდისთვის (ჯგუფი 69, „call center“).
//
// არსებულ (ხელით დაწერილ) მენიუს სკრიპტს არ ვეხებით. ის ჯგუფი 69-ს უხატავს გამყიდველების კალენდრებს.
// ეს მოდული მხოლოდ ჯგუფი 69-ის წევრებს გვერდზე უმატებს პატარა სკრიპტს, რომელიც ელოდება იმ ბლოკის
// დახატვას და მის ბოლოს (მარიამ ხარატიშვილის შემდეგ) იმავე სტილით ამატებს ქვემოთ ჩამოთვლილ კალენდრებს.
// თუ ბმული უკვე არის მენიუში, მეორედ აღარ ემატება. Uninstall აბრუნებს ყველაფერს.

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
            if (!in_array(self::GROUP_ID, $groups, true)) {
                return;
            }
            Asset::getInstance()->addString('<script>' . self::script() . '</script>');
        } catch (\Throwable $e) {
            error_log('[archi.leftmenu] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
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
