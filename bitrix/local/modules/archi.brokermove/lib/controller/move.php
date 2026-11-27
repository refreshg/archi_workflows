<?php
// AJAX: BX.ajax.runAction('archi:brokermove.move.start', {data: {dealId}})
// სტანდარტული ფილტრები: ავტორიზაცია + CSRF (sessid). წვდომა: დილი Sales ფაიფლაინშია და იუზერს მისი რედაქტირება შეუძლია.
// უშვებს ბიზნეს-პროცეს 1301-ს მიმდინარე მომხმარებლის სახელით და აბრუნებს, გადავიდა თუ არა დილი Broker ფაიფლაინში.

namespace Archi\BrokerMove\Controller;

use Archi\BrokerMove\Handler;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;

class Move extends Controller
{
    public function startAction($dealId)
    {
        global $USER;
        $dealId = (int)$dealId;
        $userId = (int)$USER->GetID();

        $deal = Handler::dealForUser($dealId);
        if (!$deal) {
            $this->addError(new Error('დილი ვერ მოიძებნა, Sales ფაიფლაინში აღარ არის ან რედაქტირების უფლება არ გაქვთ.'));
            return null;
        }
        if (!Loader::includeModule('bizproc')) {
            $this->addError(new Error('ბიზნეს-პროცესების მოდული მიუწვდომელია.'));
            return null;
        }
        $template = \CBPWorkflowTemplateLoader::GetList(array(), array('ID' => Handler::TEMPLATE_ID, 'ACTIVE' => 'Y'),
            false, false, array('ID'))->Fetch();
        if (!$template) {
            $this->addError(new Error('პროცესი ' . Handler::TEMPLATE_ID . ' ვერ მოიძებნა ან გამორთულია.'));
            return null;
        }

        $errors = array();
        $workflowId = \CBPDocument::StartWorkflow(
            Handler::TEMPLATE_ID,
            array('crm', 'CCrmDocumentDeal', 'DEAL_' . $dealId),
            array(\CBPDocument::PARAM_TAGRET_USER => 'user_' . $userId),
            $errors
        );
        if (!$workflowId || $errors) {
            $messages = array();
            foreach ((array)$errors as $e) {
                $messages[] = is_array($e) ? (string)($e['message'] ?? '') : (string)$e;
            }
            error_log('[archi.brokermove] deal ' . $dealId . ' start failed: ' . implode('; ', $messages));
            $this->addError(new Error('პროცესი ვერ გაეშვა' . ($messages ? ': ' . implode('; ', array_filter($messages)) : '.')));
            return null;
        }

        $after = \CCrmDeal::GetListEx(array(), array('ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'), false, false,
            array('ID', 'CATEGORY_ID', 'STAGE_ID'))->Fetch();
        return array(
            'workflowId' => $workflowId,
            'moved' => $after && (int)$after['CATEGORY_ID'] === Handler::BROKER_CATEGORY,
            'categoryId' => $after ? (int)$after['CATEGORY_ID'] : null,
            'stageId' => $after ? (string)$after['STAGE_ID'] : null,
        );
    }
}
