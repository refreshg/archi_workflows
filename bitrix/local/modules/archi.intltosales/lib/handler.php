<?php
// ARCHI — „Sales-ში გადატანა“ ღილაკი International Lead Generation (ფაიფლაინი 40) დილის ბარათზე.
//
// ღილაკი ბარათის ზედა მარჯვენა პანელში ჩნდება, დასტურის შემდეგ უშვებს ბიზნეს-პროცესს 1300
// („Inter > Sales > Geo“ — დილი გადადის Sales-ის ფაიფლაინში) და ბარათს ანახლებს.
// დილის ხელმოწერილ იდენტიფიკატორს ვიღებთ იმავე გვერდზე არსებული „Workflows“ ღილაკის კონფიგურაციიდან.
// 1.3.0: ღილაკები „პროცესები“ ღილაკის ხაზშია, იმავე სტილით.
// 1.2.0: ღილაკები სათაურის პანელის ქვემოთ ცალკე ხაზშია (პანელი აღარ იჭრება).
// 1.1.0: მეორე ღილაკი „ბროკერებში გადატანა“ — BP 1301 („Inter>Broikers>GEO“).
// სხვა არაფერს ვეხებით. Uninstall აბრუნებს ყველაფერს.

namespace Archi\IntlToSales;

use Bitrix\Main\Page\Asset;

class Handler
{
    const CATEGORY_ID = 40;
    // [ღილაკის id, BP შაბლონი, წარწერა, დასტურის კითხვა, შეტყობინება, სტილი]
    const BUTTONS = array(
        array('archi-intltosales-btn', 1300, 'Sales-ში გადატანა', 'დილი გადავიდეს Sales-ის ფაიფლაინში?', 'დილი გადადის Sales-ში…', 'ui-btn-primary'),
        array('archi-intltobrokers-btn', 1301, 'ბროკერებში გადატანა', 'დილი გადავიდეს ბროკერების ფაიფლაინში?', 'დილი გადადის ბროკერებში…', 'ui-btn-primary'),
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
            $page = (string)\Bitrix\Main\Context::getCurrent()->getRequest()->getRequestedPage();
            if (!preg_match('#^/crm/deal/details/(\d+)/#', $page, $m)) {
                return;
            }
            $dealId = (int)$m[1];
            if ($dealId <= 0 || !\Bitrix\Main\Loader::includeModule('crm')) {
                return;
            }
            $deal = \CCrmDeal::GetListEx(array(), array('ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'), false, false, array('ID', 'CATEGORY_ID'))->Fetch();
            if (!$deal || (int)$deal['CATEGORY_ID'] !== self::CATEGORY_ID) {
                return;
            }
            Asset::getInstance()->addString('<script>' . self::script($dealId) . '</script>');
        } catch (\Throwable $e) {
            error_log('[archi.intltosales] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    private static function script($dealId)
    {
        $cfg = json_encode(array(
            'dealId' => (int)$dealId,
            'buttons' => self::BUTTONS,
        ), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

        return <<<JS
(function () {
    var CFG = {$cfg};
    var tries = 0;

    // ჩვენი ღილაკების ხაზი: „პროცესები“ ღილაკის (#buttonerWrapper) გვერდით, იმავე სტილით; თუ ის ბლოკი არ არის — სათაურის პანელის ქვემოთ
    var PILL = 'background:#f5fcff;border:1px solid #3eb3e3;border-radius:25px;color:#2a2a2a;font-size:14px;line-height:20px;font-weight:600;font-family:inherit;padding:8px 20px;height:38px;box-sizing:border-box;cursor:pointer;display:inline-flex;align-items:center;gap:6px;margin:0;white-space:nowrap;transition:background .2s;';
    function actionBar() {
        var c = document.getElementById('archi-deal-actions');
        var w = document.getElementById('buttonerWrapper');
        if (!c) {
            c = document.createElement('div');
            c.id = 'archi-deal-actions';
        }
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

    // „Workflows“ ღილაკის კონფიგურაცია გვერდზე: {"signedDocumentType":"…","signedDocumentId":"…DEAL_<id>…"}
    function signedIds() {
        var scripts = document.getElementsByTagName('script');
        var re = /"signedDocumentType":"([^"]+)","signedDocumentId":"([^"]+)"/;
        for (var i = 0; i < scripts.length; i++) {
            var m = re.exec(scripts[i].text || '');
            if (!m) { continue; }
            var type = JSON.parse('"' + m[1] + '"');
            var id = JSON.parse('"' + m[2] + '"');
            if (id.indexOf('DEAL_' + CFG.dealId + '"') !== -1) {
                return {type: type, id: id};
            }
        }
        return null;
    }

    function start(b) {
        var ids = signedIds();
        if (!ids) {
            alert('ვერ მოიძებნა დილის მონაცემები — განაახლეთ გვერდი (F5).');
            return;
        }
        var run = function () {
            BX.Bizproc.Workflow.Starter.singleStart({
                templateId: b[1],
                signedDocumentType: ids.type,
                signedDocumentId: ids.id
            }, function () {
                if (BX.UI && BX.UI.Notification) {
                    BX.UI.Notification.Center.notify({content: b[4], autoHideDelay: 4000});
                }
                setTimeout(function () { window.location.reload(); }, 2500);
            });
        };
        if (BX.Bizproc && BX.Bizproc.Workflow && BX.Bizproc.Workflow.Starter) {
            run();
        } else if (BX.Runtime && BX.Runtime.loadExtension) {
            BX.Runtime.loadExtension('bizproc.workflow.starter').then(run);
        }
    }

    function ensure() {
        var bar = actionBar();
        if (!bar) { return false; }
        for (var i = 0; i < CFG.buttons.length; i++) {
            (function (b) {
                if (document.getElementById(b[0])) { return; }
                var btn = document.createElement('button');
                btn.id = b[0];
                btn.type = 'button';
                btn.style.cssText = PILL;
                btn.onmouseenter = function () { btn.style.background = '#e3f6ff'; };
                btn.onmouseleave = function () { btn.style.background = '#f5fcff'; };
                btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#3eb3e3" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg><span></span>';
                btn.lastChild.textContent = b[2];
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (BX.UI && BX.UI.Dialogs && BX.UI.Dialogs.MessageBox) {
                        BX.UI.Dialogs.MessageBox.confirm(b[3], function (box) { box.close(); start(b); }, 'გადატანა');
                    } else if (window.confirm(b[3])) { start(b); }
                });
                bar.appendChild(btn);
            })(CFG.buttons[i]);
        }
        return true;
    }

    function run() {
        if (!ensure() && ++tries < 50) { setTimeout(run, 200); return; }
        // პანელი შეიძლება თავიდან დაიხატოს — ღილაკს ვაბრუნებთ
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
