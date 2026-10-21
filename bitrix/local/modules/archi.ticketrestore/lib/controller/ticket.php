<?php
// AJAX: BX.ajax.runAction('archi:ticketrestore.ticket.restore', {data: {dealId, reason}})
// ავტორიზაცია და CSRF (sessid) — Controller-ის სტანდარტული ფილტრებით.

namespace Archi\TicketRestore\Controller;

use Archi\TicketRestore\Handler;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;

class Ticket extends Controller
{
    public function restoreAction($dealId, $reason)
    {
        $dealId = (int)$dealId;
        $reason = trim((string)$reason);
        $userId = (int)$this->getCurrentUser()->getId();

        if ($reason === '') {
            $this->addError(new Error('მიზეზის მითითება სავალდებულოა'));
            return null;
        }
        $check = Handler::check($dealId, $userId);
        if ($check !== true) {
            $this->addError(new Error($check));
            return null;
        }

        // ერთი ჩვეულებრივი შენახვა — ისე, როგორც ბარათიდან: რობოტები და პროცესები ჩვეულებრივად ეშვება.
        $factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory(\CCrmOwnerType::Deal);
        $item = $factory ? $factory->getItem($dealId) : null;
        if (!$item) {
            $this->addError(new Error('თიქეთი ვერ მოიძებნა'));
            return null;
        }
        $item->set(Handler::FIELD_REASON, $reason);
        $item->set(Handler::FIELD_STATUS, Handler::STATUS_RESTORED);
        $item->setStageId(Handler::TARGET_STAGE);
        $result = $factory->getUpdateOperation($item)->launch();
        if (!$result->isSuccess()) {
            foreach ($result->getErrors() as $error) {
                $this->addError(new Error($error->getMessage()));
            }
            return null;
        }

        $name = trim($this->getCurrentUser()->getFirstName() . ' ' . $this->getCurrentUser()->getLastName());
        \Bitrix\Crm\Timeline\CommentEntry::create(array(
            'TEXT' => "❗ თიქეთი აღდგენილია დახურვის შემდეგ — სტატუსი: არასწორად დახურული თიქეთი\n"
                . "აღადგინა: [USER=" . $userId . "]" . $name . "[/USER]\n"
                . "მიზეზი: " . $reason,
            'AUTHOR_ID' => $userId,
            'BINDINGS' => array(array('ENTITY_TYPE_ID' => \CCrmOwnerType::Deal, 'ENTITY_ID' => $dealId)),
        ));

        // მეილი ticket@archi.ge-დან (BP 1298); შეცდომა აღდგენას არ აჩერებს.
        if (Loader::includeModule('bizproc')) {
            $errors = array();
            \CBPDocument::StartWorkflow(
                Handler::MAIL_TEMPLATE_ID,
                array('crm', 'CCrmDocumentDeal', 'DEAL_' . $dealId),
                array(),
                $errors
            );
            if ($errors) {
                error_log('[archi.ticketrestore] mail BP: ' . json_encode($errors, JSON_UNESCAPED_UNICODE));
            }
        }

        return array('dealId' => $dealId);
    }
}
