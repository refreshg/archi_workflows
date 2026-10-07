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
//               "IDENTIFICATION_CODE": "..."}}                // ლისტი 231: საიდენტიფიკაციო კოდი (null თუ არ არის)
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

        return array(
            'STATUS' => 'FOUND',
            'ID' => (string)$deal['ID'],
            self::FIELD_DEBT => $deal[self::FIELD_DEBT],
            self::FIELD_DETAIL => $detail,
            'PROJECT' => $projects ? $projects[0] : null,
            'COMPANY_NAME' => $ucc ? $ucc['COMPANY_NAME'] : null,
            'IDENTIFICATION_CODE' => $ucc ? $ucc['CODE'] : null,
        );
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
