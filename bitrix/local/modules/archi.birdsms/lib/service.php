<?php
// ARCHI — SMS (Bird): პარამეტრები, წვდომა, დილის მონაცემები, ლოგი და Bird API.
//
// პარამეტრები ინახება მოდულის ოფციებში (b_option, მოდული archi.birdsms) — არა კოდში/რეპოში:
//   mode         test | live        test = Bird-ს არ ვუკავშირდებით, ლოგში STATUS=test
//   test_users   "1,5"              ცარიელი = ყველა, ვისაც 40 ფაიფლაინის დილზე წვდომა აქვს
//   template     შაბლონის ტექსტი
//   api_key      Bird key (bk_eu1_…), sender — გამგზავნი (ნომერი ან დადასტურებული sender ID), category (service)

namespace Archi\BirdSms;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;

class Service
{
    const MODULE_ID = 'archi.birdsms';
    const CATEGORY_ID = 40;
    const PHONE_UF = 'UF_CRM_5E5E926786D76';
    const MAX_LEN = 130;
    const DEFAULT_TEMPLATE = 'Archi: Thank you for contacting us! We tried to call you. For more information, please message us on WhatsApp: wa.me/995577602601';

    public static function option($name, $default = '')
    {
        return (string)Option::get(self::MODULE_ID, $name, $default);
    }

    public static function isLive()
    {
        return self::option('mode', 'test') === 'live';
    }

    public static function template()
    {
        $t = trim(self::option('template', ''));
        return $t !== '' ? $t : self::DEFAULT_TEMPLATE;
    }

    // ტესტის ეტაპზე ღილაკი/გაგზავნა მხოლოდ ამ იუზერებს
    public static function userAllowed($userId)
    {
        $list = array_filter(array_map('intval', explode(',', self::option('test_users', '1'))));
        return !$list || in_array((int)$userId, $list, true);
    }

    // დილი 40-შია და იუზერს მისი ნახვა შეუძლია
    public static function dealForUser($dealId, $userId)
    {
        $dealId = (int)$dealId;
        if ($dealId <= 0 || !\Bitrix\Main\Loader::includeModule('crm')) {
            return null;
        }
        $deal = \CCrmDeal::GetListEx(array(), array('ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'), false, false,
            array('ID', 'TITLE', 'CATEGORY_ID', 'CONTACT_ID', self::PHONE_UF))->Fetch();
        if (!$deal || (int)$deal['CATEGORY_ID'] !== self::CATEGORY_ID) {
            return null;
        }
        $perms = \CCrmPerms::GetUserPermissions((int)$userId);
        if (!\CCrmDeal::CheckReadPermission($dealId, $perms, self::CATEGORY_ID)) {
            return null;
        }
        return $deal;
    }

    public static function clientName(array $deal)
    {
        $cid = (int)$deal['CONTACT_ID'];
        if ($cid > 0) {
            $c = \CCrmContact::GetListEx(array(), array('ID' => $cid, 'CHECK_PERMISSIONS' => 'N'), false, false, array('ID', 'NAME', 'LAST_NAME'))->Fetch();
            if ($c) {
                $n = trim($c['NAME'] . ' ' . $c['LAST_NAME']);
                if ($n !== '') {
                    return $n;
                }
            }
        }
        return (string)$deal['TITLE'];
    }

    // ნომრები: კონტაქტის ტელეფონები + დილის ტელეფონის ველი, უნიკალური, ნორმალიზებული
    public static function phones(array $deal)
    {
        $out = array();
        $cid = (int)$deal['CONTACT_ID'];
        if ($cid > 0) {
            $r = \CCrmFieldMulti::GetList(array('ID' => 'ASC'), array('ENTITY_ID' => 'CONTACT', 'ELEMENT_ID' => $cid, 'TYPE_ID' => 'PHONE'));
            while ($p = $r->Fetch()) {
                $out[] = self::normalize($p['VALUE']);
            }
        }
        $out[] = self::normalize((string)$deal[self::PHONE_UF]);
        return array_values(array_unique(array_filter($out)));
    }

    public static function normalize($phone)
    {
        $p = preg_replace('/[^\d+]/', '', (string)$phone);
        if (strpos($p, '00') === 0) {
            $p = '+' . substr($p, 2);
        }
        if ($p !== '' && $p[0] !== '+') {
            $p = '+' . $p;
        }
        return preg_match('/^\+\d{7,15}$/', $p) ? $p : '';
    }

    public static function lastSend($dealId)
    {
        $row = Application::getConnection()->query(
            "SELECT ID, CREATED, USER_ID, PHONE, STATUS, MODE FROM b_archi_birdsms_log
             WHERE DEAL_ID = " . (int)$dealId . " AND STATUS IN ('sent','test') ORDER BY ID DESC LIMIT 1"
        )->fetch();
        if (!$row) {
            return null;
        }
        $u = \CUser::GetByID((int)$row['USER_ID'])->Fetch();
        $created = $row['CREATED'] instanceof DateTime ? $row['CREATED'] : new DateTime((string)$row['CREATED'], 'Y-m-d H:i:s');
        return array(
            'date' => $created->format('d.m.Y H:i'),
            'user' => $u ? trim($u['NAME'] . ' ' . $u['LAST_NAME']) : '',
            'phone' => $row['PHONE'],
            'test' => $row['MODE'] === 'test',
        );
    }

    public static function log(array $f)
    {
        $c = Application::getConnection();
        $h = $c->getSqlHelper();
        $c->query("INSERT INTO b_archi_birdsms_log
            (CREATED, DEAL_ID, DEAL_TITLE, CLIENT_NAME, PHONE, COUNTRY, MESSAGE, USER_ID, STATUS, BIRD_MESSAGE_ID, ERROR_TEXT, MODE)
            VALUES (NOW(), " . (int)$f['DEAL_ID'] . ", '" . $h->forSql(mb_substr((string)$f['DEAL_TITLE'], 0, 255)) . "',
            '" . $h->forSql(mb_substr((string)$f['CLIENT_NAME'], 0, 255)) . "', '" . $h->forSql($f['PHONE']) . "',
            '" . $h->forSql(mb_substr((string)$f['COUNTRY'], 0, 64)) . "', '" . $h->forSql($f['MESSAGE']) . "', " . (int)$f['USER_ID'] . ",
            '" . $h->forSql($f['STATUS']) . "', '" . $h->forSql((string)$f['BIRD_MESSAGE_ID']) . "',
            '" . $h->forSql((string)$f['ERROR_TEXT']) . "', '" . $h->forSql($f['MODE']) . "')");
        return (int)$c->getInsertedId();
    }

    // დილის ტაიმლაინში კომენტარი (ავტორი = გამგზავნი მენეჯერი); ბარათზე მაშინვე ჩნდება (pull)
    public static function timeline($dealId, $userId, $phone, $country, $text, $status, $birdId, $error)
    {
        try {
            if ($status === 'sent') {
                $head = '[b]SMS გაიგზავნა[/b]';
            } elseif ($status === 'test') {
                $head = '[b]SMS (ტესტ-რეჟიმი — რეალურად არ გაგზავნილა)[/b]';
            } else {
                $head = '[b]SMS ვერ გაიგზავნა[/b]';
            }
            $lines = array($head . ' → ' . $phone . ($country !== '' ? ' (' . $country . ')' : ''), '[i]' . $text . '[/i]');
            if ($status === 'sent' && $birdId !== '') {
                $lines[] = 'Bird ID: ' . $birdId;
            }
            if ($status === 'error' && $error !== '') {
                $lines[] = 'შეცდომა: ' . $error;
            }
            $id = \Bitrix\Crm\Timeline\CommentEntry::create(array(
                'TEXT' => implode("\n", $lines),
                'AUTHOR_ID' => (int)$userId,
                'SETTINGS' => array('HAS_FILES' => 'N'),
                'BINDINGS' => array(array('ENTITY_TYPE_ID' => \CCrmOwnerType::Deal, 'ENTITY_ID' => (int)$dealId)),
            ));
            if ($id > 0) {
                \Bitrix\Crm\Timeline\CommentController::getInstance()->onCreate($id, array(
                    'ENTITY_TYPE_ID' => \CCrmOwnerType::Deal,
                    'ENTITY_ID' => (int)$dealId,
                    'AUTHOR_ID' => (int)$userId,
                ));
            }
            return (int)$id;
        } catch (\Throwable $e) {
            // ტაიმლაინის შეცდომამ გაგზავნა არ უნდა გააფუჭოს
            error_log('[archi.birdsms] timeline: ' . $e->getMessage());
            return 0;
        }
    }

    // Bird (ახალი პლატფორმა): POST https://{region}.platform.bird.com/v1/sms/messages, Authorization: Bearer bk_{region}_…
    // workspace-ს key განსაზღვრავს. ოფციები: api_key, sender (from: Bird-ში საკუთარი ნომერი ან დადასტურებული sender ID),
    // category (service). პასუხი 202 {"id":"sms_…","status":"accepted"} → ['ok'=>bool, 'id'=>string, 'error'=>string]
    public static function sendViaBird($phone, $text, $dealId = 0)
    {
        $key = self::option('api_key');
        $from = self::option('sender');
        if ($key === '' || $from === '') {
            return array('ok' => false, 'id' => '', 'error' => 'Bird-ის პარამეტრები (API key / გამგზავნი) შევსებული არ არის');
        }
        $region = preg_match('/^bk_([a-z]+\d+)_/', $key, $m) ? $m[1] : 'eu1';
        $http = new HttpClient(array('socketTimeout' => 10, 'streamTimeout' => 15));
        $http->setHeader('Authorization', 'Bearer ' . $key);
        $http->setHeader('Content-Type', 'application/json');
        $http->setHeader('Accept', 'application/json');
        $http->setHeader('Idempotency-Key', 'archi-' . (int)$dealId . '-' . bin2hex(random_bytes(8)));
        $body = Json::encode(array(
            'to' => $phone,
            'from' => $from,
            'text' => $text,
            'category' => self::option('category', 'service'),
            'reference' => 'deal-' . (int)$dealId,
            'metadata' => array('deal_id' => (int)$dealId),
        ));
        $res = $http->post('https://' . $region . '.platform.bird.com/v1/sms/messages', $body);
        $status = (int)$http->getStatus();
        $data = array();
        try {
            $data = Json::decode((string)$res);
        } catch (\Throwable $e) {
        }
        if ($status >= 200 && $status < 300 && !empty($data['id'])) {
            return array('ok' => true, 'id' => (string)$data['id'], 'error' => '');
        }
        $e = is_array($data['error'] ?? null) ? $data['error'] : array();
        $msg = trim(($e['name'] ?? '') . ' ' . ($e['message'] ?? ''));
        if ($msg === '') {
            $msg = $res ? mb_substr((string)$res, 0, 300) : implode(';', (array)$http->getError());
        }
        return array('ok' => false, 'id' => '', 'error' => 'HTTP ' . $status . ': ' . mb_substr($msg, 0, 500));
    }
}
