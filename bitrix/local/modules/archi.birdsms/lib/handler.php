<?php
// ARCHI — „SMS“ ღილაკი International Lead Generation (ფაიფლაინი 40) დილის ბარათზე.
//
// ბარათის ზედა მარჯვენა პანელში ჩნდება ღილაკი „SMS“ და (თუ უკვე გაიგზავნა) ნიშანი „SMS გაგზავნილია · თარიღი“.
// ღილაკი ხსნის ფანჯარას: ნომერი (ქვეყნის კოდით, რედაქტირებადი), შაბლონის ტექსტი (რედაქტირებადი, ≤130),
// განმეორების გაფრთხილება. გაგზავნა — AJAX archi:birdsms.sms.send (lib/controller/sms.php).
// სხვა გვერდებს/ფაიფლაინებს არ ვეხებით.

namespace Archi\BirdSms;

use Bitrix\Main\Page\Asset;

class Handler
{
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
            $userId = (int)$USER->GetID();
            if (!Service::userAllowed($userId)) {
                return;
            }
            $deal = Service::dealForUser((int)$m[1], $userId);
            if (!$deal) {
                return;
            }
            $cfg = array(
                'dealId' => (int)$deal['ID'],
                'dealTitle' => (string)$deal['TITLE'],
                'client' => Service::clientName($deal),
                'phones' => Service::phones($deal),
                'template' => Service::template(),
                'maxLen' => Service::MAX_LEN,
                'live' => Service::isLive(),
                'last' => Service::lastSend((int)$deal['ID']),
            );
            $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            Asset::getInstance()->addString('<style>' . self::css() . '</style>');
            Asset::getInstance()->addString('<script>window.ARCHI_BIRDSMS=' . $json . ';' . self::js() . '</script>');
        } catch (\Throwable $e) {
            error_log('[archi.birdsms] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    private static function css()
    {
        return <<<'CSS'
.abs-btn{display:inline-flex!important;align-items:center;gap:6px;margin-right:8px!important}
.abs-btn svg{width:16px;height:16px;flex:none}
.abs-badge{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 12px;margin-right:8px;border-radius:15px;
  background:#e8f7ee;color:#1f7a45;font:600 12px/1 var(--ui-font-family-primary,"Open Sans",Arial,sans-serif);white-space:nowrap;align-self:center}
.abs-badge.abs-test{background:#fff4dc;color:#8a5a00}
.abs-badge svg{width:14px;height:14px}
.abs-wrap{width:460px;max-width:calc(100vw - 80px);font-family:var(--ui-font-family-primary,"Open Sans",Arial,sans-serif);color:#333}
.abs-head{display:flex;align-items:center;gap:12px;padding-bottom:14px;border-bottom:1px solid #edeef0;margin-bottom:14px}
.abs-ava{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#2fc6f6,#1e8ae6);color:#fff;
  display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;flex:none}
.abs-name{font-size:15px;font-weight:600;line-height:1.3}
.abs-sub{font-size:12px;color:#828b95;margin-top:2px}
.abs-chip{margin-left:auto;padding:4px 10px;border-radius:11px;font-size:11px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;flex:none}
.abs-chip.test{background:#fff4dc;color:#8a5a00}
.abs-chip.live{background:#e8f7ee;color:#1f7a45}
.abs-warn{display:flex;gap:10px;padding:10px 12px;border-radius:8px;background:#fff8e6;border:1px solid #ffe3a3;color:#7a5200;font-size:13px;line-height:1.45;margin-bottom:14px}
.abs-warn b{font-weight:700}
.abs-label{display:block;font-size:12px;font-weight:600;color:#525c69;margin:0 0 6px}
.abs-phone{display:flex;align-items:center;border:1px solid #c6cdd3;border-radius:8px;overflow:hidden;background:#fff;transition:border-color .15s,box-shadow .15s}
.abs-phone:focus-within{border-color:#2fc6f6;box-shadow:0 0 0 3px rgba(47,198,246,.18)}
.abs-phone.bad{border-color:#ff5752;box-shadow:0 0 0 3px rgba(255,87,82,.15)}
.abs-iso{flex:none;margin-left:8px;min-width:30px;height:22px;padding:0 6px;border-radius:4px;background:#eef2f4;color:#525c69;
  font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center}
.abs-phone input{flex:1;border:0;outline:0;padding:10px;font-size:15px;letter-spacing:.3px;background:transparent;min-width:0}
.abs-country{flex:none;padding:0 12px;color:#828b95;font-size:12px}
.abs-phones{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.abs-pick{border:1px solid #dfe3e6;background:#f5f7f8;border-radius:12px;padding:3px 10px;font-size:12px;cursor:pointer;color:#525c69}
.abs-pick.on{border-color:#2fc6f6;background:#e5f9ff;color:#0b66c3}
.abs-err{color:#ff5752;font-size:12px;margin-top:6px;min-height:0}
.abs-gap{height:14px}
.abs-text{width:100%;box-sizing:border-box;min-height:96px;resize:vertical;border:1px solid #c6cdd3;border-radius:8px;padding:10px;font:14px/1.5 inherit;outline:0;transition:border-color .15s,box-shadow .15s}
.abs-text:focus{border-color:#2fc6f6;box-shadow:0 0 0 3px rgba(47,198,246,.18)}
.abs-meta{display:flex;justify-content:space-between;align-items:center;margin-top:6px;font-size:12px;color:#828b95}
.abs-reset{color:#2067b0;cursor:pointer;border-bottom:1px dashed #2067b0}
.abs-count.over{color:#ff5752;font-weight:700}
.abs-preview{margin-top:14px;padding:12px;border-radius:12px;background:#f1f3f5}
.abs-bubble{max-width:85%;padding:9px 12px;border-radius:14px 14px 14px 4px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.08);font-size:13px;line-height:1.45;word-wrap:break-word}
.abs-from{font-size:11px;color:#828b95;margin-bottom:6px}
CSS;
    }

    private static function js()
    {
        return <<<'JS'
(function () {
    var CFG = window.ARCHI_BIRDSMS; if (!CFG) { return; }
    var BTN_ID = 'archi-birdsms-btn', BADGE_ID = 'archi-birdsms-badge';
    var ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 9h8M8 13h5"/></svg>';
    var CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
    // ქვეყნის კოდები: კოდი → [ISO, სახელი]
    var CC = {'995':['GE','Georgia'],'972':['IL','Israel'],'49':['DE','Germany'],'48':['PL','Poland'],'1':['US','USA / Canada'],'7':['RU','Russia / Kazakhstan'],
        '971':['AE','UAE'],'966':['SA','Saudi Arabia'],'961':['LB','Lebanon'],'965':['KW','Kuwait'],'974':['QA','Qatar'],'973':['BH','Bahrain'],'968':['OM','Oman'],
        '962':['JO','Jordan'],'964':['IQ','Iraq'],'963':['SY','Syria'],'20':['EG','Egypt'],'90':['TR','Turkey'],'98':['IR','Iran'],'44':['GB','United Kingdom'],
        '33':['FR','France'],'39':['IT','Italy'],'34':['ES','Spain'],'41':['CH','Switzerland'],'43':['AT','Austria'],'31':['NL','Netherlands'],'32':['BE','Belgium'],
        '30':['GR','Greece'],'357':['CY','Cyprus'],'380':['UA','Ukraine'],'374':['AM','Armenia'],'994':['AZ','Azerbaijan'],'375':['BY','Belarus'],'373':['MD','Moldova'],
        '370':['LT','Lithuania'],'371':['LV','Latvia'],'372':['EE','Estonia'],'420':['CZ','Czechia'],'421':['SK','Slovakia'],'36':['HU','Hungary'],'40':['RO','Romania'],
        '359':['BG','Bulgaria'],'381':['RS','Serbia'],'385':['HR','Croatia'],'46':['SE','Sweden'],'47':['NO','Norway'],'45':['DK','Denmark'],'358':['FI','Finland'],
        '353':['IE','Ireland'],'351':['PT','Portugal'],'352':['LU','Luxembourg'],'61':['AU','Australia'],'86':['CN','China'],'91':['IN','India'],'212':['MA','Morocco'],
        '216':['TN','Tunisia'],'213':['DZ','Algeria'],'998':['UZ','Uzbekistan'],'996':['KG','Kyrgyzstan'],'992':['TJ','Tajikistan'],'993':['TM','Turkmenistan'],'55':['BR','Brazil'],'52':['MX','Mexico']};
    var GSM = /^[@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&'()*+,\-.\/0-9:;<=>?¡A-ZÄÖÑÜ§¿a-zäöñüà^{}\\\[~\]|€]*$/;

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function norm(p) { p = String(p || '').replace(/[^\d+]/g, ''); if (p.indexOf('00') === 0) { p = '+' + p.slice(2); } if (p && p[0] !== '+') { p = '+' + p; } return p; }
    function valid(p) { return /^\+\d{7,15}$/.test(p); }
    function country(p) { var d = p.replace(/\D/g, ''); for (var n = 3; n >= 1; n--) { if (CC[d.slice(0, n)]) { return CC[d.slice(0, n)]; } } return null; }
    function initials(s) { var w = String(s || '').trim().split(/\s+/); return ((w[0] || '?')[0] + (w[1] ? w[1][0] : '')).toUpperCase(); }
    function notify(t) { if (BX.UI && BX.UI.Notification) { BX.UI.Notification.Center.notify({content: t, autoHideDelay: 5000}); } else { alert(t); } }
    function confirmBox(text, ok) {
        if (BX.UI && BX.UI.Dialogs && BX.UI.Dialogs.MessageBox) {
            BX.UI.Dialogs.MessageBox.confirm(text, 'განმეორებით გაგზავნა', function (box) { box.close(); ok(); }, 'მაინც გაგზავნა', function (box) { box.close(); }, 'გაუქმება');
        } else if (window.confirm(text)) { ok(); }
    }

    function renderBadge() {
        var bar = document.querySelector('.ui-toolbar-right-buttons'); if (!bar) { return; }
        var b = document.getElementById(BADGE_ID);
        if (!CFG.last) { if (b) { b.remove(); } return; }
        if (!b) { b = document.createElement('span'); b.id = BADGE_ID; var btn = document.getElementById(BTN_ID); bar.insertBefore(b, btn ? btn.nextSibling : bar.firstChild); }
        b.className = 'abs-badge' + (CFG.last.test ? ' abs-test' : '');
        b.innerHTML = CHECK + 'SMS გაგზავნილია · ' + esc(CFG.last.date) + (CFG.last.test ? ' (ტესტი)' : '');
        b.title = (CFG.last.user ? CFG.last.user + ' · ' : '') + (CFG.last.phone || '');
    }

    function openPopup() {
        var phones = CFG.phones || [], cur = phones[0] || '';
        var picks = phones.length > 1 ? '<div class="abs-phones">' + phones.map(function (p, i) { return '<span class="abs-pick' + (i ? '' : ' on') + '" data-p="' + esc(p) + '">' + esc(p) + '</span>'; }).join('') + '</div>' : '';
        var warn = CFG.last ? '<div class="abs-warn"><span>⚠️</span><span>ამ დილზე SMS უკვე გაიგზავნა <b>' + esc(CFG.last.date) + '</b>' +
            (CFG.last.user ? ' — ' + esc(CFG.last.user) : '') + (CFG.last.phone ? ', ' + esc(CFG.last.phone) : '') + (CFG.last.test ? ' (ტესტი)' : '') + '.</span></div>' : '';
        var html = '<div class="abs-wrap">' +
            '<div class="abs-head"><div class="abs-ava">' + esc(initials(CFG.client)) + '</div><div><div class="abs-name">' + esc(CFG.client) + '</div>' +
            '<div class="abs-sub">დილი #' + CFG.dealId + ' · International Lead Generation</div></div>' +
            '<span class="abs-chip ' + (CFG.live ? 'live">Bird SMS' : 'test">ტესტ-რეჟიმი') + '</span></div>' + warn +
            '<label class="abs-label">ნომერი</label>' +
            '<div class="abs-phone" id="abs-phone"><span class="abs-iso" id="abs-iso">—</span><input id="abs-num" type="tel" autocomplete="off" value="' + esc(cur) + '" placeholder="+972501234567"><span class="abs-country" id="abs-country"></span></div>' +
            picks + '<div class="abs-err" id="abs-err"></div><div class="abs-gap"></div>' +
            '<label class="abs-label">ტექსტი</label>' +
            '<textarea class="abs-text" id="abs-text" maxlength="' + CFG.maxLen + '">' + esc(CFG.template) + '</textarea>' +
            '<div class="abs-meta"><span class="abs-reset" id="abs-reset">შაბლონის აღდგენა</span><span class="abs-count" id="abs-count"></span></div>' +
            '<div class="abs-preview"><div class="abs-from">ასე დაინახავს კლიენტი</div><div class="abs-bubble" id="abs-bubble"></div></div>' +
            (CFG.live ? '' : '<div class="abs-err" style="color:#8a5a00;margin-top:10px">ტესტ-რეჟიმი: SMS რეალურად არ გაიგზავნება, მხოლოდ ჩაიწერება ლოგში.</div>') +
            '</div>';

        var popup = new BX.PopupWindow('archi-birdsms-popup-' + Date.now(), null, {
            content: html, titleBar: 'SMS გაგზავნა', closeIcon: true, closeByEsc: true, overlay: {opacity: 40}, draggable: true, autoHide: false, zIndex: 1100,
            events: {onPopupClose: function () { popup.destroy(); }},
            buttons: [
                new BX.PopupWindowCustomButton({text: 'გაგზავნა', className: 'ui-btn ui-btn-success ui-btn-md', id: 'abs-send', events: {click: function () { submit(this); }}}),
                new BX.PopupWindowCustomButton({text: 'გაუქმება', className: 'ui-btn ui-btn-link ui-btn-md', events: {click: function () { popup.close(); }}})
            ]
        });
        popup.show();

        var $ = function (id) { return document.getElementById(id); };
        var num = $('abs-num'), txt = $('abs-text');
        function upd() {
            var p = norm(num.value), c = country(p);
            $('abs-iso').textContent = c ? c[0] : '—';
            $('abs-country').textContent = c ? c[1] : (valid(p) ? 'უცნობი ქვეყანა' : '');
            $('abs-phone').classList.toggle('bad', !!num.value && !valid(p));
            $('abs-err').textContent = num.value && !valid(p) ? 'ნომერი ჩაწერეთ ქვეყნის კოდით, მაგ. +972501234567' : '';
            var t = txt.value, len = t.length, gsm = GSM.test(t), per = gsm ? (len > 160 ? 153 : 160) : (len > 70 ? 67 : 70), parts = Math.max(1, Math.ceil(len / per));
            var cnt = $('abs-count'); cnt.textContent = len + ' / ' + CFG.maxLen + ' · ' + parts + ' SMS' + (gsm ? '' : ' (Unicode)');
            cnt.classList.toggle('over', len > CFG.maxLen || len === 0);
            $('abs-bubble').textContent = t || '…';
            Array.prototype.forEach.call(document.querySelectorAll('.abs-pick'), function (el) { el.classList.toggle('on', el.getAttribute('data-p') === p); });
        }
        num.addEventListener('input', upd); txt.addEventListener('input', upd);
        $('abs-reset').addEventListener('click', function () { txt.value = CFG.template; upd(); });
        Array.prototype.forEach.call(document.querySelectorAll('.abs-pick'), function (el) { el.addEventListener('click', function () { num.value = el.getAttribute('data-p'); upd(); }); });
        upd(); setTimeout(function () { txt.focus(); }, 50);

        function submit(btn) {
            var p = norm(num.value), t = txt.value.trim(), c = country(p);
            if (!valid(p)) { upd(); num.focus(); return; }
            if (!t || t.length > CFG.maxLen) { upd(); txt.focus(); return; }
            var go = function () {
                var el = btn.buttonNode || document.getElementById('abs-send'); if (el) { el.classList.add('ui-btn-wait'); }
                BX.ajax.runAction('archi:birdsms.sms.send', {data: {dealId: CFG.dealId, phone: p, text: t, country: c ? c[1] : ''}}).then(function (r) {
                    CFG.last = r.data.last; renderBadge(); popup.close();
                    notify(r.data.test ? 'ტესტ-რეჟიმი: SMS არ გაგზავნილა, ჩაიწერა ლოგში' : 'SMS გაიგზავნა: ' + p);
                }, function (r) {
                    if (el) { el.classList.remove('ui-btn-wait'); }
                    $('abs-err').textContent = (r && r.errors && r.errors[0] && r.errors[0].message) || 'შეცდომა, სცადეთ თავიდან';
                });
            };
            if (CFG.last) {
                confirmBox('ამ დილზე SMS უკვე გაიგზავნა ' + CFG.last.date + (CFG.last.user ? ' (' + CFG.last.user + ')' : '') + '. მაინც გავაგზავნო?', go);
            } else { go(); }
        }
    }

    function ensure() {
        var bar = document.querySelector('.ui-toolbar-right-buttons'); if (!bar) { return false; }
        if (!document.getElementById(BTN_ID)) {
            var btn = document.createElement('button');
            btn.id = BTN_ID; btn.type = 'button'; btn.className = 'ui-btn ui-btn-light-border ui-btn-md abs-btn';
            btn.innerHTML = ICON + '<span>SMS</span>'; btn.title = 'SMS გაგზავნა კლიენტთან';
            btn.addEventListener('click', function (e) { e.preventDefault(); openPopup(); });
            bar.insertBefore(btn, bar.firstChild);
        }
        if (!document.getElementById(BADGE_ID)) { renderBadge(); }
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
