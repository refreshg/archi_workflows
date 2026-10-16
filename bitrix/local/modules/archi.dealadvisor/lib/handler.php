<?php
// ARCHI — „მოითხოვა კონკრეტული მენეჯერი“: პასუხისმგებელი უნდა იყოს პერსონალური მრჩეველი (ჯგუფი 17 salemanager).
//
// წესი (მხოლოდ ახალი დილის შექმნისას, ფაიფლაინები: Sales 0, Hot Leads 35):
//   თუ „მოთხოვნა“ (UF_CRM_5F2A7F2C0F9C9) = „მოითხოვა კონკრეტული მენეჯერი“ (1305) და პასუხისმგებელი
//   ჯგუფ 17-ში არ არის — დილი არ ინახება და ფორმაში ჩანს შეცდომა.
//
// არ ვეხებით: დილის რედაქტირებას, REST/Make ინტეგრაციებს, ბიზნეს-პროცესებს/რობოტებს, აგენტებს, იმპორტს,
// სხვა ფაიფლაინებს. სხვის კოდს არ ვცვლით — მხოლოდ ერთი handler (crm:OnBeforeCrmDealAdd). Uninstall აბრუნებს ყველაფერს.

namespace Archi\DealAdvisor;

class Handler
{
    const CATEGORY_IDS = array(0, 35);
    const REQUEST_FIELD = 'UF_CRM_5F2A7F2C0F9C9';
    const REQUEST_SPECIFIC_MANAGER = 1305;
    const MANAGER_GROUP_ID = 17;

    // ტესტის რეჟიმი: თუ სია არაა ცარიელი, წესი მოქმედებს მხოლოდ ამ იუზერებზე (ვინც დილს ქმნის)
    const TEST_ONLY_USERS = array();

    const MESSAGE = 'მოთხოვნა: „მოითხოვა კონკრეტული მენეჯერი“ — პასუხისმგებლად აირჩიეთ პერსონალური მრჩეველი (salemanager).';

    public static function onBeforeDealAdd(&$arFields)
    {
        try {
            if (!self::isManualCreate()) {
                return true;
            }
            $categoryId = isset($arFields['CATEGORY_ID']) ? (int)$arFields['CATEGORY_ID'] : 0;
            if (!in_array($categoryId, self::CATEGORY_IDS, true)) {
                return true;
            }
            if (!self::isSpecificManagerRequest(isset($arFields[self::REQUEST_FIELD]) ? $arFields[self::REQUEST_FIELD] : null)) {
                return true;
            }

            global $USER;
            $currentUserId = (int)$USER->GetID();
            if (self::TEST_ONLY_USERS && !in_array($currentUserId, self::TEST_ONLY_USERS, true)) {
                return true;
            }

            // პასუხისმგებელი თუ ფორმიდან არ მოვიდა, Bitrix შემქმნელს სვამს
            $assignedId = !empty($arFields['ASSIGNED_BY_ID']) ? (int)$arFields['ASSIGNED_BY_ID'] : $currentUserId;
            if ($assignedId > 0 && in_array(self::MANAGER_GROUP_ID, array_map('intval', \CUser::GetUserGroup($assignedId)), true)) {
                return true;
            }

            $arFields['RESULT_MESSAGE'] = self::MESSAGE;
            global $APPLICATION;
            if (is_object($APPLICATION)) {
                $APPLICATION->ThrowException(self::MESSAGE);
            }
            return false;
        } catch (\Throwable $e) {
            // ჩვენი შეცდომის გამო დილის შექმნა არასდროს უნდა გაჩერდეს
            error_log('[archi.dealadvisor] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return true;
        }
    }

    private static function isSpecificManagerRequest($value)
    {
        foreach ((array)$value as $v) {
            if ((int)$v === self::REQUEST_SPECIFIC_MANAGER) {
                return true;
            }
        }
        return false;
    }

    // მხოლოდ თანამშრომლის მიერ ინტერფეისიდან შექმნა: არა REST/Make, არა აგენტი/cron, არა იმპორტი, არა ბიზნეს-პროცესი
    private static function isManualCreate()
    {
        if (PHP_SAPI === 'cli' || (defined('BX_CRONTAB') && BX_CRONTAB === true)) {
            return false;
        }
        global $USER;
        if (!is_object($USER) || !$USER->IsAuthorized()) {
            return false;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        if (strpos($uri, '/rest/') === 0 || stripos($uri, 'import') !== false) {
            return false;
        }
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = isset($frame['class']) ? ltrim($frame['class'], '\\') : '';
            // ბიზნეს-პროცესები/რობოტები (CBP*, Bitrix\Bizproc\*, CCrmDocument*), REST სერვერი, აგენტები
            if ($class !== '' && (strpos($class, 'CBP') === 0
                || stripos($class, 'Bitrix\\Bizproc') === 0
                || strpos($class, 'CCrmDocument') === 0
                || stripos($class, 'Bitrix\\Crm\\Automation') === 0
                || $class === 'CRestServer'
                || $class === 'CAgent')) {
                return false;
            }
        }
        return true;
    }
}
