<?php
// ARCHI — International ლიდის ნიშანი სათაურში.
//
// წესი: International ლიდი (UF_CRM_1700569256804 შეიცავს „For International“ ან UF_CRM_1728302897 შეიცავს
// „archiproperties.com“), რომელიც Sales-ში (0) ან Hot Leads-ში (35) დგას, სათაურის თავში იღებს ნიშანს:
//   ⭐GE  — თუ ტელეფონი (UF_CRM_5E5E926786D76) +995-ით იწყება
//   ⭐INT — სხვა შემთხვევაში
// სათაურს BP/რობოტები („HOT LEAD …“) რამდენჯერმე წერენ თავიდან, ამიტომ ნიშანი ემატება ყოველ ჩაწერაზე,
// თუ სათაურში უკვე არ არის. International-ის პაიპლაინში (40) ნიშანი არ ემატება.
// ძველ დილებს არ ვეხებით: მხოლოდ START-ის შემდეგ შექმნილი დილები.
//
// მხოლოდ ერთი handler (crm:OnBeforeCrmDealUpdate), სხვის კოდს არ ვცვლით. Uninstall აბრუნებს ყველაფერს.

namespace Archi\IntlMark;

class Handler
{
    const START = '2026-11-12 11:45:00';
    const CATEGORY_IDS = array(0, 35);
    const CAMPAIGN_FIELD = 'UF_CRM_1700569256804';
    const URL_FIELD = 'UF_CRM_1728302897';
    const PHONE_FIELD = 'UF_CRM_5E5E926786D76';
    const MARK_GE = '⭐GE';
    const MARK_INT = '⭐INT';

    public static function onBeforeUpdate(&$arFields)
    {
        try {
            $id = isset($arFields['ID']) ? (int)$arFields['ID'] : 0;
            if ($id <= 0) {
                return true;
            }

            $row = self::load($id);
            if (!$row || $row['DATE_CREATE'] < self::START) {
                return true;
            }

            $categoryId = (int)(array_key_exists('CATEGORY_ID', $arFields) ? $arFields['CATEGORY_ID'] : $row['CATEGORY_ID']);
            if (!in_array($categoryId, self::CATEGORY_IDS, true)) {
                return true;
            }

            $campaign = self::value($arFields, $row, self::CAMPAIGN_FIELD);
            $url = self::value($arFields, $row, self::URL_FIELD);
            if (mb_stripos($campaign, 'For International') === false && mb_stripos($url, 'archiproperties.com') === false) {
                return true;
            }

            $title = (string)(array_key_exists('TITLE', $arFields) ? $arFields['TITLE'] : $row['TITLE']);
            if (mb_strpos($title, self::MARK_GE) !== false || mb_strpos($title, self::MARK_INT) !== false) {
                return true;
            }

            $phone = self::value($arFields, $row, self::PHONE_FIELD);
            $mark = preg_match('/^\s*\+?\s*(00)?\s*995/', $phone) ? self::MARK_GE : self::MARK_INT;
            $arFields['TITLE'] = $mark . ' ' . $title;
            return true;
        } catch (\Throwable $e) {
            // ჩვენი შეცდომის გამო დილის შენახვა არასდროს უნდა გაჩერდეს
            error_log('[archi.intlmark] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return true;
        }
    }

    private static function value(array $arFields, array $row, $code)
    {
        $v = array_key_exists($code, $arFields) ? $arFields[$code] : $row[$code];
        return is_array($v) ? implode(' ', $v) : (string)$v;
    }

    private static function load($id)
    {
        $sql = 'SELECT d.CATEGORY_ID, d.TITLE, d.DATE_CREATE, u.' . self::CAMPAIGN_FIELD . ', u.' . self::URL_FIELD . ', u.' . self::PHONE_FIELD
            . ' FROM b_crm_deal d LEFT JOIN b_uts_crm_deal u ON u.VALUE_ID = d.ID WHERE d.ID = ' . (int)$id;
        $row = \Bitrix\Main\Application::getConnection()->query($sql)->fetchRaw();
        if (!$row) {
            return null;
        }
        $row['DATE_CREATE'] = (string)$row['DATE_CREATE'];
        return $row;
    }
}
