<?php
// ARCHI — Sales API გარე სისტემისთვის (2026-11-27). საკუთარი scope „archisales“: ამ scope-ის webhook-ით CRM-ის
// სტანდარტული მეთოდები (crm.deal.list და სხვ.) არ იძახება. მხოლოდ Sales ფაიფლაინი (0).
//
// 1) archi.sales.deal.stage — დილის ეტაპის შეცვლა.
//    მოთხოვნა: {"ID": 1500000, "STAGE_ID": "8"}
//    მუშაობს ისე, როგორც თანამშრომლის ხელით გადაყვანა (იგივე გზა, რაც crm.deal.update): ეშვება ბიზნეს-პროცესები
//    და რობოტები (მათ შორის 227 — პროდუქტის სტატუსები, ჯავშნის პროცესი, შეტყობინებები).
//    ნებადართული ეტაპები — მხოლოდ „რეზერვაციის“ ჩათვლით (ALLOWED_STAGES). დილი თვითონაც ამ ეტაპებიდან ერთ-ერთზე
//    უნდა იდგეს: შემდგომ ეტაპებზე (20, 21, WON) ან დახურულ ეტაპზე მყოფ დილს სერვისი არ ეხება.
//    პასუხში STAGE_ID არის რეალური ეტაპი ავტომატიზაციის შემდეგ (მაგ. თუ ბინა ვერ დაიჯავშნა და პროცესმა უკან დააბრუნა).
//
// 2) archi.sales.product.status — დილზე მიბმული პროდუქტის (ბინის) დაჯავშნა / გათავისუფლება.
//    მოთხოვნა: {"ID": 1500000, "PRODUCT_ID": 3352223, "STATUS": "reserved"}   // ან "free"
//    წესები და ჩასაწერი ველები ზუსტად იგივეა, რაც ბიზნეს-პროცეს 227-ში („product statuses on stages“):
//      reserved — შეიძლება, თუ ბინა თავისუფალია ან უკვე ამ დილზეა დაჯავშნილი (გაყიდული / სხვაზე დაჯავშნილი /
//                 Not In Sale / No Price → უარი). იწერება: სტატუსი 819, რიგი, ჯავშანი, მყიდველი, მენეჯერი, ტიპი, თარიღი.
//      free     — შეიძლება მხოლოდ მაშინ, თუ ჯავშანი ამ დილს ეკუთვნის. სტატუსი 811, ჯავშნის ველები სუფთავდება.
//    ყურადღება: 227 დილის ყოველ ცვლილებაზე ეტაპის მიხედვით თავიდან ასწორებს სტატუსს (ეტაპი 9/20/21 → დაჯავშნილი,
//    უფრო ადრეული → თავისუფალი), ამიტომ ამ მეთოდით დასმული სტატუსი ძალაში რჩება მხოლოდ მანამ, სანამ ეტაპს შეესაბამება.
//
// ყველა პასუხი HTTP 200-ია, შედეგი result.STATUS-შია (OK ან შეცდომის კოდი). არაფერს ბლოკავს სხვაგან; Uninstall
// აბრუნებს ყველაფერს.

namespace Archi\SalesApi;

use Bitrix\Main\Loader;

class Rest
{
    const SCOPE = 'archisales';
    const SALES_CATEGORY = 0;

    // ეტაპები, რომლებზეც (და რომლებიდანაც) გარე სისტემას დილის გადაყვანა შეუძლია — „რეზერვაციის“ ჩათვლით
    const ALLOWED_STAGES = array(
        'NEW' => 'გასანაწილებელი',
        'PREPAYMENT_INVOICE' => 'ახალი',
        '7' => 'ვერ დავუკავშირდი',
        '8' => 'დაინტერესებული',
        '9' => 'რეზერვაცია/პირობების შეთანხმება',
    );

    const IBLOCK_PRODUCTS = 26;
    const P_STATUS = 429;       // სტატუსი (სია)
    const P_QUEUE = 438;        // რიგი
    const P_BOOKING = 439;      // ჯავშანი: |contact=manager?deal
    const P_BUYER = 440;        // buyer name
    const P_MANAGER = 441;      // Sales Manager
    const P_BOOK_TYPE = 478;    // ჯავშნის ტიპი
    const P_BOOK_DATE = 479;    // ჯავშნის თარიღი

    const ST_SOLD = 810;
    const ST_FREE = 811;
    const ST_RESERVED = 819;
    const ST_NOT_IN_SALE = 833;
    const ST_NO_PRICE = 1324;

    const STATUS_CODES = array(
        self::ST_SOLD => 'sold',
        self::ST_FREE => 'free',
        self::ST_RESERVED => 'reserved',
        self::ST_NOT_IN_SALE => 'not_in_sale',
        self::ST_NO_PRICE => 'no_price',
    );

    const FIELD_PAID_BOOKING = 'UF_1613052920026';   // „2083“ = ფასიანი ჯავშანი

    public static function describe()
    {
        return array(
            self::SCOPE => array(
                'archi.sales.deal.stage' => array(
                    'callback' => array(__CLASS__, 'dealStage'),
                    'options' => array(),
                ),
                'archi.sales.product.status' => array(
                    'callback' => array(__CLASS__, 'productStatus'),
                    'options' => array(),
                ),
                // კატალოგის კითხვა (lib/catalog.php): ყველა პროექტი „ბინები“-ს ქვეშ, ერთეულები, სტატუსი, ფასი
                'archi.sales.project.list' => array(
                    'callback' => array(__NAMESPACE__ . '\\Catalog', 'projectList'),
                    'options' => array(),
                ),
                'archi.sales.unit.list' => array(
                    'callback' => array(__NAMESPACE__ . '\\Catalog', 'unitList'),
                    'options' => array(),
                ),
            ),
        );
    }

    // ---------------------------------------------------------------------------------------------------------

    private static function param($query, $name)
    {
        $query = array_change_key_case(is_array($query) ? $query : array(), CASE_UPPER);
        if (isset($query[$name]) && is_scalar($query[$name])) {
            return trim((string)$query[$name]);
        }
        if (isset($query['FIELDS']) && is_array($query['FIELDS'])) {
            $fields = array_change_key_case($query['FIELDS'], CASE_UPPER);
            if (isset($fields[$name]) && is_scalar($fields[$name])) {
                return trim((string)$fields[$name]);
            }
        }
        return null;
    }

    private static function positiveInt($raw)
    {
        return ($raw !== null && $raw !== '' && ctype_digit($raw) && (int)$raw > 0) ? (int)$raw : null;
    }

    private static function deal($id, array $extra = array())
    {
        return \CCrmDeal::GetListEx(array(), array('ID' => $id, 'CHECK_PERMISSIONS' => 'N'), false, false,
            array_merge(array('ID', 'CATEGORY_ID', 'STAGE_ID', 'CONTACT_ID', 'ASSIGNED_BY_ID'), $extra))->Fetch();
    }

    private static function userId()
    {
        global $USER;
        return is_object($USER) ? (int)$USER->GetID() : 0;
    }

    private static function failed($method, \Throwable $e)
    {
        error_log('[archi.salesapi] ' . $method . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return array('STATUS' => 'ERROR', 'MESSAGE' => 'Internal error');
    }

    // ---------------------------------------------------------------------------------------------------------
    // 1) archi.sales.deal.stage

    public static function dealStage($query, $nav = 0, $server = null)
    {
        try {
            $sentId = self::param($query, 'ID');
            $id = self::positiveInt($sentId);
            if ($id === null) {
                return array('STATUS' => 'INVALID_ID', 'ID' => $sentId);
            }
            $stage = self::param($query, 'STAGE_ID');
            if ($stage === null || $stage === '') {
                return array('STATUS' => 'INVALID_STAGE', 'ID' => (string)$id, 'STAGE_ID' => $stage,
                    'ALLOWED_STAGES' => self::ALLOWED_STAGES);
            }
            $stage = strtoupper($stage);
            if (!isset(self::ALLOWED_STAGES[$stage])) {
                return array('STATUS' => 'STAGE_NOT_ALLOWED', 'ID' => (string)$id, 'STAGE_ID' => $stage,
                    'ALLOWED_STAGES' => self::ALLOWED_STAGES);
            }
            if (!Loader::includeModule('crm')) {
                return array('STATUS' => 'ERROR', 'MESSAGE' => 'CRM module is not available');
            }

            $deal = self::deal($id);
            if (!$deal || (int)$deal['CATEGORY_ID'] !== self::SALES_CATEGORY) {
                return array('STATUS' => 'NOT_FOUND', 'ID' => (string)$id);
            }
            $current = (string)$deal['STAGE_ID'];
            if (!isset(self::ALLOWED_STAGES[$current])) {
                // დილი უკვე „რეზერვაციის“ შემდეგაა ან დახურულია — გარე სისტემა მას ვეღარ ცვლის
                return array('STATUS' => 'DEAL_LOCKED', 'ID' => (string)$id, 'CURRENT_STAGE_ID' => $current);
            }
            if ($current === $stage) {
                return array('STATUS' => 'OK', 'ID' => (string)$id, 'CHANGED' => false,
                    'PREVIOUS_STAGE_ID' => $current, 'STAGE_ID' => $current, 'STAGE_NAME' => self::ALLOWED_STAGES[$current]);
            }

            // იგივე გზა, რასაც crm.deal.update გადის: Update → ბიზნეს-პროცესები → რობოტები
            $fields = array('STAGE_ID' => $stage);
            $entity = new \CCrmDeal(false);
            $ok = $entity->Update($id, $fields, true, true, array('REGISTER_SONET_EVENT' => true));
            if ($ok !== true) {
                return array('STATUS' => 'UPDATE_FAILED', 'ID' => (string)$id, 'CURRENT_STAGE_ID' => $current,
                    'MESSAGE' => trim(strip_tags((string)$entity->LAST_ERROR)));
            }
            $bpErrors = array();
            \CCrmBizProcHelper::AutoStartWorkflows(\CCrmOwnerType::Deal, $id, \CCrmBizProcEventType::Edit, $bpErrors);
            $starter = new \Bitrix\Crm\Automation\Starter(\CCrmOwnerType::Deal, $id);
            $starter->setContextToRest()->setUserId(self::userId());
            $starter->runOnUpdate($fields, array('ID' => $id, 'STAGE_ID' => $current));

            // ავტომატიზაციამ შეიძლება ეტაპი შეცვალოს (მაგ. ბინა ვერ დაიჯავშნა → უკან) — ვაბრუნებთ რეალურს
            $after = self::deal($id);
            $final = $after ? (string)$after['STAGE_ID'] : $stage;
            $result = array('STATUS' => 'OK', 'ID' => (string)$id, 'CHANGED' => true,
                'PREVIOUS_STAGE_ID' => $current, 'REQUESTED_STAGE_ID' => $stage, 'STAGE_ID' => $final,
                'STAGE_NAME' => isset(self::ALLOWED_STAGES[$final]) ? self::ALLOWED_STAGES[$final] : null,
                'STAGE_KEPT' => $final === $stage);
            if ($stage === '9' || $current === '9') {
                $result['PRODUCTS'] = self::dealProducts($id);
            }
            return $result;
        } catch (\Throwable $e) {
            return self::failed('deal.stage', $e);
        }
    }

    // ---------------------------------------------------------------------------------------------------------
    // 2) archi.sales.product.status

    private static function props($productId)
    {
        $out = array();
        foreach (array(self::P_STATUS, self::P_QUEUE, self::P_BOOKING) as $pid) {
            $out[$pid] = '';
            $res = \CIBlockElement::GetProperty(self::IBLOCK_PRODUCTS, $productId, 'sort', 'asc', array('ID' => $pid));
            if ($row = $res->Fetch()) {
                $out[$pid] = (string)$row['VALUE'];
            }
        }
        return $out;
    }

    private static function statusCode($enumId)
    {
        $enumId = (int)$enumId;
        // 227-ის წესი: ცარიელი / უცნობი სტატუსი თავისუფლად ითვლება
        return isset(self::STATUS_CODES[$enumId]) ? self::STATUS_CODES[$enumId] : 'free';
    }

    private static function bookedDealId($booking)
    {
        $parts = explode('?', (string)$booking);
        return isset($parts[1]) ? (int)$parts[1] : 0;
    }

    private static function dealProducts($dealId)
    {
        $out = array();
        Loader::includeModule('iblock');
        foreach ((array)\CCrmDeal::LoadProductRows($dealId) as $row) {
            $pid = (int)$row['PRODUCT_ID'];
            if ($pid <= 0) {
                continue;
            }
            $p = self::props($pid);
            $out[] = array('PRODUCT_ID' => (string)$pid, 'NAME' => (string)$row['PRODUCT_NAME'],
                'PRODUCT_STATUS' => self::statusCode($p[self::P_STATUS]),
                'RESERVED_BY_THIS_DEAL' => self::bookedDealId($p[self::P_BOOKING]) === (int)$dealId);
        }
        return $out;
    }

    public static function productStatus($query, $nav = 0, $server = null)
    {
        try {
            $sentId = self::param($query, 'ID');
            $id = self::positiveInt($sentId);
            if ($id === null) {
                return array('STATUS' => 'INVALID_ID', 'ID' => $sentId);
            }
            $sentProduct = self::param($query, 'PRODUCT_ID');
            $productId = self::positiveInt($sentProduct);
            if ($productId === null) {
                return array('STATUS' => 'INVALID_PRODUCT_ID', 'ID' => (string)$id, 'PRODUCT_ID' => $sentProduct);
            }
            $want = mb_strtolower((string)self::param($query, 'STATUS'));
            $aliases = array('reserved' => 'reserved', 'დაჯავშნილი' => 'reserved', (string)self::ST_RESERVED => 'reserved',
                'free' => 'free', 'თავისუფალი' => 'free', (string)self::ST_FREE => 'free');
            if (!isset($aliases[$want])) {
                return array('STATUS' => 'INVALID_STATUS', 'ID' => (string)$id, 'PRODUCT_ID' => (string)$productId,
                    'ALLOWED' => array('reserved', 'free'));
            }
            $want = $aliases[$want];
            if (!Loader::includeModule('crm') || !Loader::includeModule('iblock')) {
                return array('STATUS' => 'ERROR', 'MESSAGE' => 'CRM module is not available');
            }

            $deal = self::deal($id, array(self::FIELD_PAID_BOOKING));
            if (!$deal || (int)$deal['CATEGORY_ID'] !== self::SALES_CATEGORY) {
                return array('STATUS' => 'NOT_FOUND', 'ID' => (string)$id);
            }
            if (!isset(self::ALLOWED_STAGES[(string)$deal['STAGE_ID']])) {
                // „რეზერვაციის“ შემდგომ ან დახურულ ეტაპზე მყოფი დილის პროდუქტებს გარე სისტემა არ ეხება
                return array('STATUS' => 'DEAL_LOCKED', 'ID' => (string)$id, 'CURRENT_STAGE_ID' => (string)$deal['STAGE_ID']);
            }
            $attached = false;
            foreach ((array)\CCrmDeal::LoadProductRows($id) as $row) {
                if ((int)$row['PRODUCT_ID'] === $productId) {
                    $attached = true;
                    break;
                }
            }
            $element = $attached ? \CIBlockElement::GetList(array(), array('IBLOCK_ID' => self::IBLOCK_PRODUCTS, 'ID' => $productId,
                'CHECK_PERMISSIONS' => 'N'), false, false, array('ID', 'NAME'))->Fetch() : false;
            if (!$attached || !$element) {
                return array('STATUS' => 'PRODUCT_NOT_ON_DEAL', 'ID' => (string)$id, 'PRODUCT_ID' => (string)$productId);
            }

            $p = self::props($productId);
            $before = self::statusCode($p[self::P_STATUS]);
            $mine = self::bookedDealId($p[self::P_BOOKING]) === $id;
            $base = array('ID' => (string)$id, 'PRODUCT_ID' => (string)$productId, 'NAME' => (string)$element['NAME'],
                'PREVIOUS_PRODUCT_STATUS' => $before);
            $bookStr = '|' . $deal['CONTACT_ID'] . '=' . $deal['ASSIGNED_BY_ID'] . '?' . $deal['ID'];

            if ($want === 'reserved') {
                if (!$mine && $before !== 'free') {
                    // გაყიდული, სხვა დილზე დაჯავშნილი, Not In Sale, No Price
                    return array('STATUS' => 'PRODUCT_NOT_AVAILABLE') + $base + array('PRODUCT_STATUS' => $before);
                }
                $queue = (string)$p[self::P_QUEUE];
                if (!in_array(substr($bookStr, 1), explode('|', $queue), true)) {
                    $queue .= $bookStr;
                }
                $contact = (int)$deal['CONTACT_ID'] > 0
                    ? \CCrmContact::GetListEx(array(), array('ID' => (int)$deal['CONTACT_ID'], 'CHECK_PERMISSIONS' => 'N'), false, false, array('ID', 'FULL_NAME'))->Fetch()
                    : false;
                $manager = \CUser::GetByID((int)$deal['ASSIGNED_BY_ID'])->Fetch();
                \CIBlockElement::SetPropertyValuesEx($productId, self::IBLOCK_PRODUCTS, array(
                    self::P_STATUS => self::ST_RESERVED,
                    self::P_QUEUE => $queue,
                    self::P_BOOKING => $bookStr,
                    self::P_BUYER => ($contact ? $contact['FULL_NAME'] : '') . '|' . $deal['CONTACT_ID'],
                    self::P_MANAGER => ($manager ? trim($manager['NAME'] . ' ' . $manager['LAST_NAME']) : '') . '|' . $deal['ASSIGNED_BY_ID'],
                    self::P_BOOK_TYPE => (string)$deal[self::FIELD_PAID_BOOKING] === '2083' ? 'ფასიანი' : 'უფასო',
                    self::P_BOOK_DATE => date('m/d/Y'),
                ));
            } else {
                if ($before === 'free' && !$mine) {
                    return array('STATUS' => 'OK') + $base + array('PRODUCT_STATUS' => 'free', 'CHANGED' => false,
                        'DEAL_STAGE_ID' => (string)$deal['STAGE_ID']);
                }
                if (!$mine || $before !== 'reserved') {
                    // სხვა დილის ჯავშანი, ან გაყიდული / Not In Sale / No Price — არ ვეხებით
                    return array('STATUS' => 'NOT_RESERVED_BY_DEAL') + $base + array('PRODUCT_STATUS' => $before);
                }
                \CIBlockElement::SetPropertyValuesEx($productId, self::IBLOCK_PRODUCTS, array(
                    self::P_STATUS => self::ST_FREE,
                    self::P_BOOKING => '',
                    self::P_BUYER => '',
                    self::P_MANAGER => '',
                    self::P_BOOK_TYPE => '',
                    self::P_BOOK_DATE => '',
                ));
            }
            \CIBlock::clearIblockTagCache(self::IBLOCK_PRODUCTS);

            $after = self::props($productId);
            return array('STATUS' => 'OK') + $base + array('PRODUCT_STATUS' => self::statusCode($after[self::P_STATUS]),
                'CHANGED' => $before !== self::statusCode($after[self::P_STATUS]),
                'DEAL_STAGE_ID' => (string)$deal['STAGE_ID']);
        } catch (\Throwable $e) {
            return self::failed('product.status', $e);
        }
    }
}
