<?php
// ARCHI — „დაჯარიმება“ ღილაკი Technical Ticketing (ფაიფლაინი 23) დილის ბარათზე.
//
// ღილაკი ბარათის ზედა მარჯვენა პანელში ჩნდება და ბიტრიქსის სტანდარტული ფანჯრით უშვებს ბიზნეს-პროცესს 1296
// („Tech Ticket Penalty“) — ფანჯარა თვითონ ითხოვს პროცესის პარამეტრებს (დაჯარიმდა, File).
// დილის ხელმოწერილ იდენტიფიკატორს ვიღებთ იმავე გვერდზე არსებული „Workflows“ ღილაკის კონფიგურაციიდან.
// სხვა არაფერს ვეხებით. Uninstall აბრუნებს ყველაფერს.

namespace Archi\TicketFine;

use Bitrix\Main\Page\Asset;

class Handler
{
    const CATEGORY_ID = 23;
    const TEMPLATE_ID = 1296;
    const LABEL = 'დაჯარიმება';

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
            error_log('[archi.ticketfine] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    private static function script($dealId)
    {
        $cfg = json_encode(array(
            'dealId' => (int)$dealId,
            'templateId' => self::TEMPLATE_ID,
            'label' => self::LABEL,
        ), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

        return <<<JS
(function () {
    var CFG = {$cfg};
    var BTN_ID = 'archi-ticketfine-btn';
    var tries = 0;

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

    function start() {
        var ids = signedIds();
        if (!ids) {
            alert('ვერ მოიძებნა დილის მონაცემები — განაახლეთ გვერდი (F5).');
            return;
        }
        var run = function () {
            BX.Bizproc.Workflow.Starter.singleStart({
                templateId: CFG.templateId,
                signedDocumentType: ids.type,
                signedDocumentId: ids.id
            }, function () {
                if (BX.UI && BX.UI.Notification) {
                    BX.UI.Notification.Center.notify({content: 'დაჯარიმების პროცესი გაეშვა', autoHideDelay: 4000});
                }
            });
        };
        if (BX.Bizproc && BX.Bizproc.Workflow && BX.Bizproc.Workflow.Starter) {
            run();
        } else if (BX.Runtime && BX.Runtime.loadExtension) {
            BX.Runtime.loadExtension('bizproc.workflow.starter').then(run);
        }
    }

    function ensure() {
        if (document.getElementById(BTN_ID)) { return true; }
        var bar = document.querySelector('.ui-toolbar-right-buttons');
        if (!bar) { return false; }
        var btn = document.createElement('button');
        btn.id = BTN_ID;
        btn.type = 'button';
        btn.className = 'ui-btn ui-btn-danger ui-btn-md';
        btn.textContent = CFG.label;
        btn.addEventListener('click', function (e) { e.preventDefault(); start(); });
        bar.insertBefore(btn, bar.firstChild);
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
