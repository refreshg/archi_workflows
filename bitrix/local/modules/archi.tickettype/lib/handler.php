<?php
// ARCHI — „მომართვის ტიპი“ თიქეთებზე (ფაიფლაინი Tickets, 9). 2026-11-25.
//
// ველები (დილის UF): UF_CRM_TK_REQ_TYPE „მომართვის ტიპი“, UF_CRM_TK_TYPE_WHY „მომართვის ტიპის შეცვლის მიზეზი“.
// „მომართვის ტიპი“ შექმნის ეტაპზე („შემოსული მოთხოვნა“) სავალდებულოა Bitrix-ის სტანდარტული პარამეტრით.
// ეს მოდული ამატებს იმას, რაც სტანდარტულად არ არის:
//   1) ბარათიდან ახალი თიქეთი ტიპის გარეშე ვერ შეიქმნება არც სხვა ეტაპზე (მაგ. პირდაპირ „ახალი ბილეთი“);
//   2) უკვე შევსებული ტიპის შეცვლისას (გადაკვალიფიცირება) სავალდებულოა მიზეზი იმავე შენახვაში;
//      ცვლილება იწერება დილის ტაიმლაინში (ვინ, რიდან რაზე, მიზეზი), მიზეზის ველი შემდეგ სუფთავდება.
// წესები მოქმედებს მხოლოდ ბარათის ფორმიდან შენახვაზე (crm.deal.details/ajax.php) — REST, იმპორტი,
// ბიზნეს-პროცესები და აგენტები არ იბლოკება. შეცდომისას არაფერს ბლოკავს (fail open), წერს error_log-ში.
// სხვა ფაიფლაინებს და სხვის კოდს არ ვეხებით. Uninstall აბრუნებს ყველაფერს (ველები რჩება).

namespace Archi\TicketType;

class Handler
{
    const CATEGORY_ID = 9;
    const F_TYPE = 'UF_CRM_TK_REQ_TYPE';
    const F_WHY = 'UF_CRM_TK_TYPE_WHY';

    private static $pending = array();   // dealId => [old, new, reason]

    private static function fromCard()
    {
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        return strpos($script, '/crm.deal.details/ajax.php') !== false;
    }

    private static function typeNames()
    {
        static $names = null;
        if ($names === null) {
            $names = array();
            $field = \CUserTypeEntity::GetList(array(), array('ENTITY_ID' => 'CRM_DEAL', 'FIELD_NAME' => self::F_TYPE))->Fetch();
            if ($field) {
                $res = \CUserFieldEnum::GetList(array(), array('USER_FIELD_ID' => $field['ID']));
                while ($e = $res->Fetch()) {
                    $names[(int)$e['ID']] = $e['VALUE'];
                }
            }
        }
        return $names;
    }

    private static function fail(&$fields, $message)
    {
        global $APPLICATION;
        $fields['RESULT_MESSAGE'] = $message;
        if (is_object($APPLICATION)) {
            $APPLICATION->ThrowException($message);
        }
        return false;
    }

    public static function onBeforeAdd(&$fields)
    {
        try {
            if (!self::fromCard() || (int)($fields['CATEGORY_ID'] ?? -1) !== self::CATEGORY_ID) {
                return true;
            }
            if ((int)($fields[self::F_TYPE] ?? 0) <= 0) {
                return self::fail($fields, 'აირჩიეთ „მომართვის ტიპი“ — თიქეთის გაფორმებისას სავალდებულოა.');
            }
        } catch (\Throwable $e) {
            error_log('[archi.tickettype] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
        return true;
    }

    public static function onBeforeUpdate(&$fields)
    {
        try {
            $id = (int)($fields['ID'] ?? 0);
            if ($id <= 0 || !array_key_exists(self::F_TYPE, $fields) || !self::fromCard()) {
                return true;
            }
            $deal = \CCrmDeal::GetListEx(array(), array('ID' => $id, 'CHECK_PERMISSIONS' => 'N'), false, false,
                array('ID', 'CATEGORY_ID', self::F_TYPE))->Fetch();
            if (!$deal || (int)$deal['CATEGORY_ID'] !== self::CATEGORY_ID) {
                return true;
            }
            $old = (int)$deal[self::F_TYPE];
            $new = (int)$fields[self::F_TYPE];
            if ($old <= 0 || $old === $new) {
                return true;    // first fill or no change: no reason needed
            }
            $reason = trim((string)($fields[self::F_WHY] ?? ''));
            if ($reason === '') {
                return self::fail($fields, '„მომართვის ტიპის“ შეცვლისას შეავსეთ ველი „მომართვის ტიპის შეცვლის მიზეზი“ და შეინახეთ ერთად.');
            }
            self::$pending[$id] = array($old, $new, $reason);
        } catch (\Throwable $e) {
            error_log('[archi.tickettype] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
        return true;
    }

    public static function onAfterUpdate(&$fields)
    {
        try {
            $id = (int)($fields['ID'] ?? 0);
            if ($id <= 0 || !isset(self::$pending[$id])) {
                return;
            }
            list($old, $new, $reason) = self::$pending[$id];
            unset(self::$pending[$id]);

            global $USER, $USER_FIELD_MANAGER;
            $userId = is_object($USER) ? (int)$USER->GetID() : 0;
            $names = self::typeNames();
            $text = "მომართვის ტიპი შეიცვალა: " . ($names[$old] ?? $old) . " → " . ($new > 0 ? ($names[$new] ?? $new) : '(ცარიელი)')
                . "\nმიზეზი: " . $reason;

            $cid = \Bitrix\Crm\Timeline\CommentEntry::create(array(
                'TEXT' => $text,
                'AUTHOR_ID' => $userId > 0 ? $userId : 1,
                'SETTINGS' => array('HAS_FILES' => 'N'),
                'BINDINGS' => array(array('ENTITY_TYPE_ID' => \CCrmOwnerType::Deal, 'ENTITY_ID' => $id)),
            ));
            if ($cid > 0) {
                \Bitrix\Crm\Timeline\CommentController::getInstance()->onCreate($cid, array(
                    'ENTITY_TYPE_ID' => \CCrmOwnerType::Deal, 'ENTITY_ID' => $id, 'AUTHOR_ID' => $userId > 0 ? $userId : 1,
                ));
            }
            // the reason is kept in the timeline; clear the field so the next change needs a new one
            $USER_FIELD_MANAGER->Update('CRM_DEAL', $id, array(self::F_WHY => ''));
        } catch (\Throwable $e) {
            error_log('[archi.tickettype] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }
}
