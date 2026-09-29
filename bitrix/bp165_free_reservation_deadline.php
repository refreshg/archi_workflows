<?php
// Automation template 165 (Deal, Pipeline 0, stage 9) — PHP Code activity.
// უფასო რეზერვის ვადა: 1 სამუშაო დღე. შაბ/კვი და სია 201-ის დღეები არ ითვლება.
//   სამუშაო დღეს  -> შემდეგ სამუშაო დღეს, იმავე საათზე
//   არასამუშაოზე -> მეორე სამუშაო დღეს 10:00
// შედეგი იწერება Reserved_24H (UF_CRM_1676469049376) ველში.
// Designer-ში ჩასვით მხოლოდ ქვემოთა ნაწილი (<?php-ის გარეშე).

$root = $this->GetRootActivity();
\Bitrix\Main\Loader::includeModule('iblock');

// მხოლოდ უფასო ჯავშანი (UF_1613052920026: 2084 = უფასო, 2083 = ფასიანი)
$docId  = $root->GetDocumentId();
$dealId = (int)str_replace('DEAL_', '', $docId[2]);
$deal = CCrmDeal::GetListEx([], ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'], false, false, ['ID', 'UF_1613052920026'])->Fetch();
if (!$deal || (int)$deal['UF_1613052920026'] !== 2084) {
    return;
}

$holidays = [];
$res = CIBlockElement::GetList([], ['IBLOCK_ID' => 201, 'ACTIVE' => 'Y'], false, false, ['ID', 'PROPERTY_1510']);
while ($row = $res->Fetch()) {
    $v = trim((string)$row['PROPERTY_1510_VALUE']);
    if ($v === '') continue;
    $ts = preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? strtotime($v) : MakeTimeStamp($v);
    if ($ts) $holidays[date('Y-m-d', $ts)] = true;
}
$isWork = function ($ts) use ($holidays) {
    return date('N', $ts) < 6 && !isset($holidays[date('Y-m-d', $ts)]);
};
$nextWork = function ($ts) use ($isWork) {
    do { $ts = strtotime('+1 day', $ts); } while (!$isWork($ts));
    return $ts;
};

$now = time();
if ($isWork($now)) {
    $deadline = $nextWork($now);
} else {
    $d = $nextWork($nextWork($now));
    $deadline = mktime(10, 0, 0, date('n', $d), date('j', $d), date('Y', $d));
}

CBPRuntime::GetRuntime()->GetService('DocumentService')->UpdateDocument(
    $root->GetDocumentId(),
    ['UF_CRM_1676469049376' => ConvertTimeStamp($deadline, 'FULL')]
);
