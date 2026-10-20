<?php
// ARCHI — /bizproc/userprocesses/ („Workflows“) გვერდზე Previous / Next ღილაკების აღდგენა.
//
// ბიტრიქსის bizproc მოდული ჯამურ რაოდენობად მიმდინარე გვერდის ჩანაწერებს აბრუნებს (მაგ. „სულ: 20“),
// ამიტომ ცხრილი ფიქრობს, რომ მხოლოდ ერთი გვერდია და ისრებს თიშავს. სერვერზე ?page=N კი მუშაობს.
// ეს მოდული მხოლოდ ამ ერთ გვერდზე უმატებს პატარა სკრიპტს, რომელიც ისრებს ?page=N-1 / ?page=N+1 ბმულებად აქცევს.
// ბირთვს და სხვა გვერდებს არ ეხება. Uninstall აბრუნებს ყველაფერს.

namespace Archi\BpNav;

use Bitrix\Main\Page\Asset;

class Handler
{
    const PAGE_PREFIX = '/bizproc/userprocesses/';

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
            $page = (string)\Bitrix\Main\Context::getCurrent()->getRequest()->getRequestedPage();
            if (strpos($page, self::PAGE_PREFIX) !== 0) {
                return;
            }
            Asset::getInstance()->addString('<script>' . self::script() . '</script>');
        } catch (\Throwable $e) {
            error_log('[archi.bpnav] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    private static function script()
    {
        return <<<'JS'
(function () {
    var GRID = 'bizproc_user_processes_v2';

    function currentPage() {
        var m = /[?&]page=(?:page-)?(\d+)/.exec(window.location.search);
        var n = m ? parseInt(m[1], 10) : 1;
        return n > 0 ? n : 1;
    }

    function urlFor(n) {
        var u = new URL(window.location.href);
        if (n <= 1) { u.searchParams.delete('page'); } else { u.searchParams.set('page', String(n)); }
        return u.toString();
    }

    function pageSize() {
        var el = document.getElementById(GRID + '_grid_page_size');
        var v = el ? parseInt(el.getAttribute('data-value'), 10) : 0;
        return v > 0 ? v : 0;
    }

    // ცხრილის რეალური სტრიქონები (შაბლონის/ცარიელი სტრიქონის გარეშე)
    function rowsCount() {
        var table = document.getElementById(GRID + '_table');
        if (!table) { return -1; }
        var rows = table.querySelectorAll('tr.main-grid-row-body[data-id]');
        var n = 0;
        for (var i = 0; i < rows.length; i++) {
            var id = rows[i].getAttribute('data-id') || '';
            if (id.indexOf('template') === 0 || rows[i].classList.contains('main-grid-not-count')) { continue; }
            n++;
        }
        return n;
    }

    // გათიშული ისარი (span) -> ჩვეულებრივი ბმული (a), იგივე კლასებით
    function toLink(el, href) {
        if (el.getAttribute('data-archi-bpnav') === href) { return; }
        var a = document.createElement('a');
        a.className = el.className;
        a.textContent = el.textContent;
        a.href = href;
        a.setAttribute('data-archi-bpnav', href);
        a.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            window.location.href = href;
        });
        el.parentNode.replaceChild(a, el);
    }

    function apply() {
        var grid = document.getElementById(GRID);
        if (!grid) { return false; }
        var box = grid.querySelector('.main-ui-pagination');
        if (!box) { return false; }
        var page = currentPage();

        var active = box.querySelector('.main-ui-pagination-active');
        if (active && active.textContent !== String(page)) { active.textContent = String(page); }

        var prev = box.querySelector('.main-ui-pagination-prev');
        if (prev && page > 1) { toLink(prev, urlFor(page - 1)); }

        var size = pageSize();
        var next = box.querySelector('.main-ui-pagination-next');
        // შემდეგი გვერდი ჩანს მხოლოდ მაშინ, თუ ეს გვერდი სავსეა
        if (next && size > 0 && rowsCount() >= size) { toLink(next, urlFor(page + 1)); }
        return true;
    }

    var pending = null;
    function watch() {
        var grid = document.getElementById(GRID);
        if (!grid || !window.MutationObserver) { return; }
        new MutationObserver(function () {
            if (pending) { return; }
            pending = setTimeout(function () { pending = null; apply(); }, 150);
        }).observe(grid, { childList: true, subtree: true });
    }

    // ფილტრის შეცვლისას პირველ გვერდზე ვბრუნდებით, რომ ცარიელი გვერდი არ დარჩეს
    function onFilterApply() {
        if (currentPage() > 1) { window.location.href = urlFor(1); }
    }

    var tries = 0;
    function run() {
        if (!apply() && ++tries < 50) { setTimeout(run, 200); return; }
        watch();
        if (window.BX && BX.addCustomEvent) { BX.addCustomEvent('BX.Main.Filter:apply', onFilterApply); }
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
