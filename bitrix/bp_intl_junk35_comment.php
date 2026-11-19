<?php
// BP „International: 35 დღე → No response 35+ days“ — PHP Code „კომენტარი“ (2026-11-19).
// ეტაპის შეცვლის შემდეგ დილის ტაიმლაინში წერს, რატომ გადავიდა დილი junk-ში.
// Designer-ში ჩასვით <?php-ის გარეშე.

$root = $this->GetRootActivity();
try {
    \Bitrix\Main\Loader::includeModule('crm');
    $doc = $root->GetDocumentId();
    $id = (int)str_replace('DEAL_', '', $doc[2]);
    $deal = CCrmDeal::GetListEx(array(), array('ID' => $id, 'CHECK_PERMISSIONS' => 'N'), false, false, array('ID', 'DATE_CREATE'))->Fetch();
    $created = $deal ? FormatDate('d.m.Y', MakeTimeStamp($deal['DATE_CREATE'])) : '';
    $text = "⏱ დილი ავტომატურად გადავიდა ეტაპზე „No response 35+ days“.\n"
        . "მიზეზი: შექმნიდან (" . $created . ") გავიდა 35 კალენდარული დღე და დილი ისევ მიმდინარე ეტაპზე იყო.\n"
        . "წინა ეტაპი: " . $root->GetVariable('prev_stage');
    $cid = \Bitrix\Crm\Timeline\CommentEntry::create(array(
        'TEXT' => $text,
        'AUTHOR_ID' => 1,
        'SETTINGS' => array('HAS_FILES' => 'N'),
        'BINDINGS' => array(array('ENTITY_TYPE_ID' => \CCrmOwnerType::Deal, 'ENTITY_ID' => $id)),
    ));
    if ($cid > 0) {
        \Bitrix\Crm\Timeline\CommentController::getInstance()->onCreate($cid, array(
            'ENTITY_TYPE_ID' => \CCrmOwnerType::Deal, 'ENTITY_ID' => $id, 'AUTHOR_ID' => 1,
        ));
    }
} catch (\Throwable $e) {
    $this->WriteToTrackingService('[junk35] კომენტარი ვერ დაემატა: ' . $e->getMessage());
}
