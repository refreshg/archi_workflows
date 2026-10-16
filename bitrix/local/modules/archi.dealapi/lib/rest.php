<?php
// ARCHI — REST მეთოდი archi.deal.debt (scope: crm, ანუ იმავე webhook-ით იძახება, რითაც crm.deal.list).
//
// მოთხოვნა: POST /rest/<user>/<code>/archi.deal.debt.json
//   {"filter": {"ID": "1277195"}}   ან   {"ID": "1277195"}
//
// პასუხი (მოიძებნა):
//   {"result": {"STATUS": "FOUND", "ID": "1277195",
//               "UF_CRM_1644491469819": "692.03",            // მიმდინარე დავალიანება
//               "UF_CRM_1617787519405": ["არქი ხეივანი B | ..."], // დეტალური ინფორმაცია (კვ. ფასი / ჯამური ფასი ამოღებულია)
//               "PROJECT": "არქი ხეივანი B",
//               "COMPANY_NAME": "შპს ...",                    // ლისტი 231: შპს დასახელება
//               "IDENTIFICATION_CODE": "...",                 // ლისტი 231: საიდენტიფიკაციო კოდი (null თუ არ არის)
//               "DEBT_GEL": "1798.39",                        // დავალიანება ლარში = დავალიანება (USD) × ეროვნული ბანკის კურსი
//               "NBG_USD_RATE": 2.5987,                       // გამოყენებული კურსი (1 USD → GEL)
//               "NBG_RATE_DATE": "2026-10-16"}}               // კურსის თარიღი (დღევანდელი; ბანკი თუ არ პასუხობს — ბოლო ცნობილი)
// პასუხი (ვერ მოიძებნა):
//   {"result": {"STATUS": "NOT_FOUND", "ID": "1277195"}}
//
// კოდის ძებნა: დილის პროექტი (UF_CRM_5E1EECDB0571C) → ლისტი 111-ის ელემენტი იმავე სახელით
// → ლისტი 231-ის ელემენტი, რომლის Project (PROPERTY_1728) ამ ელემენტზე მიუთითებს.
// დილს კითხულობს webhook-ის მომხმარებლის უფლებებით (როგორც crm.deal.list). არაფერს წერს.

namespace Archi\DealApi;

use Bitrix\Main\Loader;

class Rest
{
    const FIELD_PROJECT = 'UF_CRM_5E1EECDB0571C';
    const FIELD_DEBT = 'UF_CRM_1644491469819';
    const FIELD_DETAIL = 'UF_CRM_1617787519405';

    const IBLOCK_PROJECTS = 111;       // Project Detailed info
    const IBLOCK_UCC = 231;            // UCC Projects lists
    const PROP_UCC_PROJECT = 1728;     // Project (ბმა ლისტ 111-ზე)
    const PROP_UCC_COMPANY = 1729;     // შპს დასახელება
    const PROP_UCC_CODE = 1730;        // საიდენტიფიკაციო კოდი

    public static function describe()
    {
        return array(
            'crm' => array(
                'archi.deal.debt' => array(
                    'callback' => array(__CLASS__, 'dealDebt'),
                    'options' => array(),
                ),
            ),
        );
    }

    public static function dealDebt($query, $nav, \CRestServer $server)
    {
        $query = array_change_key_case(is_array($query) ? $query : array(), CASE_UPPER);
        $id = isset($query['ID']) ? $query['ID'] : null;
        if ($id === null && isset($query['FILTER']) && is_array($query['FILTER'])) {
            $filter = array_change_key_case($query['FILTER'], CASE_UPPER);
            $id = isset($filter['ID']) ? $filter['ID'] : null;
        }
        $id = (int)$id;
        if ($id <= 0) {
            throw new \Bitrix\Rest\RestException('ID is required', 'INVALID_ID', \CRestServer::STATUS_WRONG_REQUEST);
        }

        if (!Loader::includeModule('crm') || !Loader::includeModule('iblock')) {
            throw new \Bitrix\Rest\RestException('Module not available', 'INTERNAL_ERROR', \CRestServer::STATUS_INTERNAL);
        }

        $deal = \CCrmDeal::GetListEx(
            array(),
            array('=ID' => $id, 'CHECK_PERMISSIONS' => 'Y'),
            false,
            false,
            array('ID', self::FIELD_PROJECT, self::FIELD_DEBT, self::FIELD_DETAIL)
        )->Fetch();

        if (!$deal) {
            return array('STATUS' => 'NOT_FOUND', 'ID' => (string)$id);
        }

        $detail = $deal[self::FIELD_DETAIL];
        $detail = is_array($detail) ? array_values($detail) : ($detail !== null && $detail !== '' ? array($detail) : array());
        $detail = array_map(array(__CLASS__, 'stripPrices'), $detail);

        $projects = self::projectNames($deal[self::FIELD_PROJECT]);
        if (!$projects && $detail) {
            // პროექტის ველი ცარიელია → დეტალური ინფორმაციის პირველი ნაწილი ("არქი ხეივანი B | ...")
            $projects = array(trim(explode('|', $detail[0])[0]));
        }

        $ucc = null;
        foreach ($projects as $projectName) {
            $ucc = self::findUcc($projectName);
            if ($ucc) {
                break;
            }
        }

        $debtUsd = self::amount($deal[self::FIELD_DEBT]);
        self::ensureAgent();
        $rate = self::usdRate();

        return array(
            'STATUS' => 'FOUND',
            'ID' => (string)$deal['ID'],
            self::FIELD_DEBT => $deal[self::FIELD_DEBT],
            self::FIELD_DETAIL => $detail,
            'PROJECT' => $projects ? $projects[0] : null,
            'COMPANY_NAME' => $ucc ? $ucc['COMPANY_NAME'] : null,
            'IDENTIFICATION_CODE' => $ucc ? $ucc['CODE'] : null,
            'DEBT_GEL' => ($debtUsd !== null && $rate) ? number_format($debtUsd * $rate['RATE'], 2, '.', '') : null,
            'NBG_USD_RATE' => $rate ? $rate['RATE'] : null,
            'NBG_RATE_DATE' => $rate ? $rate['DATE'] : null,
        );
    }

    // "692.03" ან "692.03|USD" → 692.03; ცარიელი → null
    private static function amount($value)
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        $value = trim(explode('|', (string)$value)[0]);
        if ($value === '' || !is_numeric(str_replace(',', '', $value))) {
            return null;
        }
        return (float)str_replace(',', '', $value);
    }

    // ---- ეროვნული ბანკის კურსი (USD → GEL) -------------------------------------------------------------------

    const NBG_URL = 'https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies/ka/json?currencies=USD&date=';
    const OPTION_LAST_RATE = 'nbg_usd_last';

    const AGENT = '\\Archi\\DealApi\\Rest::warmRateAgent();';
    const OPTION_AGENT = 'nbg_agent_registered';

    // აგენტი ყოველ 15 წუთში: თუ დღევანდელი კურსი cache-ში ჯერ არ არის, წინასწარ ჩამოიტანს
    // (სერვერიდან ბანკთან კავშირი ნელია — ასე API-ის გამომძახებელი არ ელოდება)
    public static function warmRateAgent()
    {
        try {
            self::usdRate();
        } catch (\Throwable $e) {
            error_log('[archi.dealapi] warmRateAgent: ' . $e->getMessage());
        }
        return self::AGENT;
    }

    // აგენტს ერთხელ ვარეგისტრირებთ (პირველ გამოძახებაზე), მოდულის ხელახალი ინსტალაციის გარეშე
    private static function ensureAgent()
    {
        try {
            if (\Bitrix\Main\Config\Option::get('archi.dealapi', self::OPTION_AGENT, '') === 'Y') {
                return;
            }
            $exists = \CAgent::GetList(array(), array('NAME' => self::AGENT, 'MODULE_ID' => 'archi.dealapi'))->Fetch();
            if (!$exists) {
                \CAgent::AddAgent(self::AGENT, 'archi.dealapi', 'N', 900);
            }
            \Bitrix\Main\Config\Option::set('archi.dealapi', self::OPTION_AGENT, 'Y');
        } catch (\Throwable $e) {
            error_log('[archi.dealapi] ensureAgent: ' . $e->getMessage());
        }
    }

    // დღევანდელი კურსი (თბილისის დროით). ბანკს ვიძახებთ მხოლოდ მაშინ, როცა ამ დღის კურსი cache-ში არ არის
    // (თარიღის კურსი არ იცვლება). ბანკი თუ არ პასუხობს — ბოლო წარმატებით მიღებული კურსი თავისი თარიღით.
    private static function usdRate()
    {
        $today = (new \DateTime('now', new \DateTimeZone('Asia/Tbilisi')))->format('Y-m-d');
        $cache = \Bitrix\Main\Data\Cache::createInstance();
        if ($cache->initCache(26 * 3600, 'nbg_usd_' . $today, '/archi.dealapi/nbg')) {
            $cached = $cache->getVars();
            if (!empty($cached['RATE'])) {
                return $cached;
            }
        }
        $rate = self::fetchNbg($today);
        if ($rate) {
            if ($cache->startDataCache()) {
                $cache->endDataCache($rate);
            }
            \Bitrix\Main\Config\Option::set('archi.dealapi', self::OPTION_LAST_RATE, json_encode($rate));
            return $rate;
        }
        $last = json_decode(\Bitrix\Main\Config\Option::get('archi.dealapi', self::OPTION_LAST_RATE, ''), true);
        return (is_array($last) && !empty($last['RATE'])) ? $last : null;
    }

    private static function fetchNbg($date)
    {
        try {
            $http = new \Bitrix\Main\Web\HttpClient(array('socketTimeout' => 5, 'streamTimeout' => 5));
            $body = $http->get(self::NBG_URL . $date);
            if ($http->getStatus() !== 200 || !$body) {
                return null;
            }
            $data = json_decode($body, true);
            foreach ((array)(isset($data[0]['currencies']) ? $data[0]['currencies'] : array()) as $c) {
                if (isset($c['code'], $c['rate']) && $c['code'] === 'USD' && (float)$c['rate'] > 0) {
                    $qty = !empty($c['quantity']) ? (float)$c['quantity'] : 1.0;
                    $validFrom = isset($c['validFromDate']) ? substr($c['validFromDate'], 0, 10) : $date;
                    return array('RATE' => round((float)$c['rate'] / $qty, 6), 'DATE' => $validFrom);
                }
            }
        } catch (\Throwable $e) {
            error_log('[archi.dealapi] NBG: ' . $e->getMessage());
        }
        return null;
    }

    // "არქი ხეივანი B | პარკინგი | ბინის №: 38 | კვ.მ. | სართ:-1 | კვ. ფასი: $0.00 | ჯამური ფასი: $9,000.00"
    // → "არქი ხეივანი B | პარკინგი | ბინის №: 38 | კვ.მ. | სართ:-1"
    private static function stripPrices($line)
    {
        $parts = array();
        foreach (explode('|', (string)$line) as $part) {
            $part = trim($part);
            if (mb_strpos($part, 'კვ. ფასი') === 0 || mb_strpos($part, 'ჯამური ფასი') === 0) {
                continue;
            }
            $parts[] = $part;
        }
        return implode(' | ', $parts);
    }

    private static function projectNames($enumIds)
    {
        $enumIds = array_filter(array_map('intval', is_array($enumIds) ? $enumIds : array($enumIds)));
        if (!$enumIds) {
            return array();
        }
        $byId = array();
        $res = \CUserFieldEnum::GetList(array(), array('USER_FIELD_NAME' => self::FIELD_PROJECT));
        while ($row = $res->Fetch()) {
            $byId[(int)$row['ID']] = $row['VALUE'];
        }
        $names = array();
        foreach ($enumIds as $enumId) {
            if (isset($byId[$enumId]) && trim($byId[$enumId]) !== '') {
                $names[] = trim($byId[$enumId]);
            }
        }
        return $names;
    }

    private static function findUcc($projectName)
    {
        if ($projectName === '') {
            return null;
        }
        $project = \CIBlockElement::GetList(
            array(),
            array('IBLOCK_ID' => self::IBLOCK_PROJECTS, '=NAME' => $projectName, 'ACTIVE' => 'Y'),
            false,
            array('nTopCount' => 1),
            array('ID')
        )->Fetch();
        if (!$project) {
            return null;
        }
        $ucc = \CIBlockElement::GetList(
            array(),
            array('IBLOCK_ID' => self::IBLOCK_UCC, 'PROPERTY_' . self::PROP_UCC_PROJECT => (int)$project['ID'], 'ACTIVE' => 'Y'),
            false,
            array('nTopCount' => 1),
            array('ID', 'PROPERTY_' . self::PROP_UCC_COMPANY, 'PROPERTY_' . self::PROP_UCC_CODE)
        )->Fetch();
        if (!$ucc) {
            return null;
        }
        $company = $ucc['PROPERTY_' . self::PROP_UCC_COMPANY . '_VALUE'];
        $code = $ucc['PROPERTY_' . self::PROP_UCC_CODE . '_VALUE'];
        return array(
            'COMPANY_NAME' => ($company !== null && $company !== '') ? (string)$company : null,
            'CODE' => ($code !== null && $code !== '') ? (string)$code : null,
        );
    }
}
