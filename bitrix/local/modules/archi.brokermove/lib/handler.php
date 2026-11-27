<?php
// ARCHI — ღილაკი „Broker გადატანა“ Sales ფაიფლაინის (0) დილის ბარათზე (2026-11-27).
//
// ბარათის ზედა პანელში („პროცესები“ ღილაკის გვერდით, იქვე სადაც SMS ღილაკია) ჩნდება ღილაკი „Broker გადატანა“.
// დაჭერისას ეკითხება დადასტურებას; „კი“-ზე AJAX-ით (archi:brokermove.move.start) ეშვება ბიზნეს-პროცესი 1301
// („Inter>Broikers>GEO“), რომელიც დილს International Brokers ფაიფლაინში (55) გადაიტანს.
// რომელი ეტაპიდან გადაიტანს — წყვეტს თვითონ პროცესი 1301 (ამ მოდულში ეტაპები ჩაწერილი არ არის);
// ღილაკი შედეგს ამოწმებს და ეუბნება მომხმარებელს, გადავიდა თუ არა.
// ღილაკს ხედავს ის, ვისაც დილის რედაქტირების უფლება აქვს. სხვა ფაიფლაინებს და სხვის კოდს არ ვეხებით.

namespace Archi\BrokerMove;

use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;

class Handler
{
    const SALES_CATEGORY = 0;
    const TEMPLATE_ID = 1301;
    const BROKER_CATEGORY = 55;

    // დილი, თუ ის Sales ფაიფლაინშია და მიმდინარე მომხმარებელს მისი რედაქტირება შეუძლია; სხვა შემთხვევაში null
    public static function dealForUser($dealId)
    {
        $dealId = (int)$dealId;
        if ($dealId <= 0 || !Loader::includeModule('crm')) {
            return null;
        }
        $deal = \CCrmDeal::GetListEx(array(), array('ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'), false, false,
            array('ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID'))->Fetch();
        if (!$deal || (int)$deal['CATEGORY_ID'] !== self::SALES_CATEGORY) {
            return null;
        }
        if (!\CCrmDeal::CheckUpdatePermission($dealId, \CCrmPerms::GetCurrentUserPermissions(), self::SALES_CATEGORY)) {
            return null;
        }
        return $deal;
    }

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
            if (!preg_match('#^/crm/deal/details/(\d+)/#', $page, $m)) {
                return;
            }
            $deal = self::dealForUser((int)$m[1]);
            if (!$deal) {
                return;
            }
            \Bitrix\Main\UI\Extension::load(array('ui.dialogs.messagebox', 'ui.notification'));
            $cfg = json_encode(array('dealId' => (int)$deal['ID']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            Asset::getInstance()->addString('<script>window.ARCHI_BROKERMOVE=' . $cfg . ';' . self::js() . '</script>');
        } catch (\Throwable $e) {
            error_log('[archi.brokermove] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    private static function js()
    {
        return <<<'JS'
(function () {
    var CFG = window.ARCHI_BROKERMOVE; if (!CFG) { return; }
    var BTN_ID = 'archi-brokermove-btn', busy = false;
    var ICON = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="#3eb3e3" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex:none"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>';
    var PILL = 'background:#f5fcff;border:1px solid #3eb3e3;border-radius:25px;color:#2a2a2a;font-size:14px;line-height:20px;font-weight:600;font-family:inherit;padding:8px 20px;height:38px;box-sizing:border-box;cursor:pointer;display:inline-flex;align-items:center;gap:6px;margin:0;white-space:nowrap;transition:background .2s;';

    function notify(t) { if (window.BX && BX.UI && BX.UI.Notification) { BX.UI.Notification.Center.notify({content: t, autoHideDelay: 7000}); } else { alert(t); } }

    // იგივე ხაზი, რასაც სხვა ARCHI ღილაკები (SMS) იყენებს: „პროცესები“ ღილაკის გვერდით; თუ ის ბლოკი არ არის — სათაურის პანელის ქვემოთ
    function actionBar() {
        var c = document.getElementById('archi-deal-actions');
        var w = document.getElementById('buttonerWrapper');
        if (!c) { c = document.createElement('div'); c.id = 'archi-deal-actions'; }
        if (w) {
            if (c.parentNode !== w) {
                w.style.display = 'flex'; w.style.flexWrap = 'wrap'; w.style.alignItems = 'center';
                for (var i = 1; i < w.children.length; i++) { if (w.children[i] !== c) { w.children[i].style.flexBasis = '100%'; } }
                c.style.cssText = 'display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:0;';
                w.insertBefore(c, w.children[1] || null);
            }
            return c;
        }
        if (c.parentNode) { return c; }
        var rb = document.querySelector('.ui-toolbar-right-buttons');
        var tb = rb && rb.closest ? (rb.closest('.ui-toolbar') || rb.parentNode) : null;
        if (!tb || !tb.parentNode) { return null; }
        c.style.cssText = 'display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:-2px 0 14px;';
        tb.parentNode.insertBefore(c, tb.nextSibling);
        return c;
    }

    function start() {
        if (busy) { return; }
        busy = true;
        var btn = document.getElementById(BTN_ID); if (btn) { btn.style.opacity = '.55'; btn.style.pointerEvents = 'none'; }
        var done = function () { busy = false; if (btn) { btn.style.opacity = ''; btn.style.pointerEvents = ''; } };
        BX.ajax.runAction('archi:brokermove.move.start', {data: {dealId: CFG.dealId}}).then(function (r) {
            if (r.data && r.data.moved) {
                notify('დილი გადავიდა Broker ფაიფლაინში.');
                setTimeout(function () { window.location.reload(); }, 900);
            } else {
                done();
                notify('პროცესი გაეშვა, მაგრამ დილი არ გადასულა: ამ ეტაპიდან გადატანა პროცესში გათვალისწინებული არ არის.');
            }
        }, function (r) {
            done();
            notify((r && r.errors && r.errors[0] && r.errors[0].message) || 'შეცდომა, სცადეთ თავიდან.');
        });
    }

    function ask() {
        var text = 'დილი გადავიდეს Broker ფაიფლაინში?';
        if (window.BX && BX.UI && BX.UI.Dialogs && BX.UI.Dialogs.MessageBox) {
            BX.UI.Dialogs.MessageBox.confirm(text, 'Broker გადატანა',
                function (box) { box.close(); start(); }, 'კი, გადაიტანე',
                function (box) { box.close(); }, 'გაუქმება');
        } else if (window.confirm(text)) { start(); }
    }

    function ensure() {
        var bar = actionBar(); if (!bar) { return false; }
        if (!document.getElementById(BTN_ID)) {
            var btn = document.createElement('button');
            btn.id = BTN_ID; btn.type = 'button'; btn.style.cssText = PILL;
            btn.onmouseenter = function () { btn.style.background = '#e3f6ff'; }; btn.onmouseleave = function () { btn.style.background = '#f5fcff'; };
            btn.innerHTML = ICON + '<span>Broker გადატანა</span>'; btn.title = 'დილის გადატანა Broker ფაიფლაინში';
            btn.addEventListener('click', function (e) { e.preventDefault(); ask(); });
            bar.appendChild(btn);
        }
        return true;
    }
    var tries = 0;
    function run() {
        if (!ensure() && ++tries < 50) { setTimeout(run, 200); return; }
        if (window.MutationObserver) {
            var pending = null;
            new MutationObserver(function () { if (pending) { return; } pending = setTimeout(function () { pending = null; ensure(); }, 250); })
                .observe(document.body, {childList: true, subtree: true});
        }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { setTimeout(run, 300); }); } else { setTimeout(run, 300); }
})();
JS;
    }
}
