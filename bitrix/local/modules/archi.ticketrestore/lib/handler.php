<?php
// ARCHI — „თიქეთის აღდგენა“: დახურული თიქეთის (Tickets, ფაიფლაინი 9) დაბრუნება სამუშაოდ.
//
// ღილაკი ჩანს მხოლოდ: Tickets დილის ბარათზე, სტადიაზე „დასრულება“ (C9:3) ან „კლიენტთან უკუკავშირი“ (C9:WON),
// და მხოლოდ იმ თანამშრომელს, ვინც ამ თიქეთის „Task Manager.“ ველშია.
// დაჭერა → ფანჯარა სავალდებულო მიზეზით → სერვერი (Controller\Ticket::restoreAction) ჩაწერს მიზეზს, სტატუსს
// „არასწორად დახურული თიქეთი“, დააბრუნებს სტადიას „პროცესში“, დაამატებს კომენტარს და გაუშვებს მეილის BP 1298-ს.
// Uninstall აბრუნებს ყველაფერს.

namespace Archi\TicketRestore;

use Bitrix\Main\Page\Asset;

class Handler
{
    const CATEGORY_ID = 9;
    const CLOSED_STAGES = array('C9:3', 'C9:WON');
    const TARGET_STAGE = 'C9:PREPARATION';
    const FIELD_TASK_MANAGER = 'UF_CRM_1733921713'; // Task Manager.
    const FIELD_REASON = 'UF_CRM_1627303426966';    // თიქეთის დაბრუნების მიზეზი
    const FIELD_STATUS = 'UF_CRM_1577014160745';    // აღდგენის სტატუსი
    const STATUS_RESTORED = 186;                    // „არასწორად დახურული თიქეთი“
    const MAIL_TEMPLATE_ID = 1298;                  // „Tichet დაბრუნების მეილი“

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
            $dealId = (int)$m[1];
            if (self::check($dealId, (int)$USER->GetID()) !== true) {
                return;
            }
            Asset::getInstance()->addString('<script>' . self::script($dealId) . '</script>');
        } catch (\Throwable $e) {
            error_log('[archi.ticketrestore] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    // true — თუ ამ იუზერს ამ დილის აღდგენა შეუძლია; სხვა შემთხვევაში — მიზეზის ტექსტი.
    public static function check($dealId, $userId)
    {
        if ($dealId <= 0 || $userId <= 0 || !\Bitrix\Main\Loader::includeModule('crm')) {
            return 'თიქეთი ვერ მოიძებნა';
        }
        $deal = \CCrmDeal::GetListEx(
            array(),
            array('ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'),
            false,
            false,
            array('ID', 'CATEGORY_ID', 'STAGE_ID', self::FIELD_TASK_MANAGER)
        )->Fetch();
        if (!$deal || (int)$deal['CATEGORY_ID'] !== self::CATEGORY_ID) {
            return 'ეს არ არის Tickets ფაიფლაინის თიქეთი';
        }
        if (!in_array($deal['STAGE_ID'], self::CLOSED_STAGES, true)) {
            return 'თიქეთი დახურული არ არის';
        }
        if ((int)$deal[self::FIELD_TASK_MANAGER] !== (int)$userId) {
            return 'აღდგენა შეუძლია მხოლოდ ამ თიქეთის Task Manager-ს';
        }
        return true;
    }

    private static function script($dealId)
    {
        $cfg = json_encode(array('dealId' => (int)$dealId), JSON_HEX_TAG);

        return <<<JS
(function () {
    var CFG = {$cfg};
    var BTN_ID = 'archi-ticketrestore-btn';
    var tries = 0;

    function openDialog() {
        if (document.getElementById('archi-ticketrestore-dlg')) { return; }
        var wrap = document.createElement('div');
        wrap.id = 'archi-ticketrestore-dlg';
        wrap.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center';
        wrap.innerHTML =
            '<div style="background:#fff;border-radius:12px;padding:24px;width:480px;max-width:92vw;box-shadow:0 10px 40px rgba(0,0,0,.25);font-family:inherit">' +
            '<div style="font-size:18px;font-weight:600;margin-bottom:6px">თიქეთის აღდგენა</div>' +
            '<div style="font-size:13px;color:#6a737f;margin-bottom:14px">თიქეთი დაბრუნდება სტადიაზე „პროცესში“ სტატუსით „არასწორად დახურული თიქეთი“.</div>' +
            '<label style="font-size:13px;font-weight:600">თიქეთის დაბრუნების მიზეზი <span style="color:#e53935">*</span></label>' +
            '<textarea id="archi-ticketrestore-reason" rows="4" style="width:100%;box-sizing:border-box;margin-top:6px;padding:8px;border:1px solid #c6cdd3;border-radius:6px;font:inherit;resize:vertical"></textarea>' +
            '<div id="archi-ticketrestore-err" style="color:#e53935;font-size:13px;min-height:18px;margin-top:6px"></div>' +
            '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:10px">' +
            '<button type="button" id="archi-ticketrestore-cancel" class="ui-btn ui-btn-light-border">გაუქმება</button>' +
            '<button type="button" id="archi-ticketrestore-ok" class="ui-btn ui-btn-primary">აღდგენა</button>' +
            '</div></div>';
        document.body.appendChild(wrap);

        var reason = document.getElementById('archi-ticketrestore-reason');
        var err = document.getElementById('archi-ticketrestore-err');
        var ok = document.getElementById('archi-ticketrestore-ok');
        reason.focus();

        function close() { wrap.parentNode && wrap.parentNode.removeChild(wrap); }
        document.getElementById('archi-ticketrestore-cancel').addEventListener('click', close);

        ok.addEventListener('click', function () {
            var text = reason.value.trim();
            if (!text) { err.textContent = 'მიზეზის მითითება სავალდებულოა'; reason.focus(); return; }
            ok.disabled = true;
            ok.classList.add('ui-btn-wait');
            err.textContent = '';
            BX.ajax.runAction('archi:ticketrestore.ticket.restore', {data: {dealId: CFG.dealId, reason: text}})
                .then(function () {
                    close();
                    window.location.reload();
                })
                .catch(function (response) {
                    var msg = (response && response.errors && response.errors[0] && response.errors[0].message) || 'შეცდომა — სცადეთ თავიდან';
                    err.textContent = msg;
                    ok.disabled = false;
                    ok.classList.remove('ui-btn-wait');
                });
        });
    }

    function ensure() {
        if (document.getElementById(BTN_ID)) { return true; }
        var bar = document.querySelector('.ui-toolbar-right-buttons');
        if (!bar) { return false; }
        var btn = document.createElement('button');
        btn.id = BTN_ID;
        btn.type = 'button';
        btn.className = 'ui-btn ui-btn-primary ui-btn-md';
        btn.textContent = 'თიქეთის აღდგენა';
        btn.addEventListener('click', function (e) { e.preventDefault(); openDialog(); });
        bar.insertBefore(btn, bar.firstChild);
        return true;
    }

    function run() {
        if (!ensure() && ++tries < 50) { setTimeout(run, 200); return; }
        if (window.MutationObserver) {
            var pending = null;
            new MutationObserver(function () {
                if (pending) { return; }
                pending = setTimeout(function () { pending = null; ensure(); }, 200);
            }).observe(document.body, {childList: true, subtree: true});
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
