<?php
// AJAX: BX.ajax.runAction('archi:birdsms.sms.send', {data: {dealId, phone, text, country}})
// სტანდარტული ფილტრები: ავტორიზაცია + CSRF (sessid). წვდომა: დილი 40-შია და იუზერს მისი ნახვა შეუძლია.

namespace Archi\BirdSms\Controller;

use Archi\BirdSms\Service;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;

class Sms extends Controller
{
    public function sendAction($dealId, $phone, $text, $country = '')
    {
        global $USER;
        $userId = (int)$USER->GetID();
        if (!Service::userAllowed($userId)) {
            $this->addError(new Error('SMS-ის გაგზავნა ჯერ თქვენთვის ჩართული არ არის.'));
            return null;
        }
        $deal = Service::dealForUser((int)$dealId, $userId);
        if (!$deal) {
            $this->addError(new Error('დილი ვერ მოიძებნა ან წვდომა არ გაქვთ.'));
            return null;
        }
        $phone = Service::normalize($phone);
        if ($phone === '') {
            $this->addError(new Error('ნომერი არასწორია. ჩაწერეთ ქვეყნის კოდით, მაგ. +972501234567'));
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
        if ($text === '' || mb_strlen($text) > Service::MAX_LEN) {
            $this->addError(new Error('ტექსტი ცარიელია ან ' . Service::MAX_LEN . ' სიმბოლოზე გრძელია.'));
            return null;
        }

        $live = Service::isLive();
        $res = $live ? Service::sendViaBird($phone, $text, (int)$deal['ID']) : array('ok' => true, 'id' => 'TEST-' . date('YmdHis'), 'error' => '');
        $status = $res['ok'] ? ($live ? 'sent' : 'test') : 'error';
        $country = mb_substr((string)$country, 0, 64);

        Service::timeline((int)$deal['ID'], $userId, $phone, $country, $text, $status, $res['id'], $res['error']);
        Service::log(array(
            'DEAL_ID' => (int)$deal['ID'],
            'DEAL_TITLE' => $deal['TITLE'],
            'CLIENT_NAME' => Service::clientName($deal),
            'PHONE' => $phone,
            'COUNTRY' => $country,
            'MESSAGE' => $text,
            'USER_ID' => $userId,
            'STATUS' => $status,
            'BIRD_MESSAGE_ID' => $res['id'],
            'ERROR_TEXT' => $res['error'],
            'MODE' => $live ? 'live' : 'test',
        ));

        if (!$res['ok']) {
            $this->addError(new Error('SMS ვერ გაიგზავნა: ' . $res['error']));
            return null;
        }
        return array('messageId' => $res['id'], 'test' => !$live, 'last' => Service::lastSend((int)$deal['ID']));
    }
}
