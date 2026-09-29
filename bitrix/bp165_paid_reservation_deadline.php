<?php
// Automation template 165 (Deal, Pipeline 0, stage 9) — PHP Code activity, ფასიანი ჯავშანი.
// ეშვება ბუღალტერიის დადასტურების (Approve -> Yes) შემდეგ.
// ვადა: 10 სამუშაო დღე დადასტურებიდან. შაბ/კვი და სია 201-ის დღეები არ ითვლება.
//   სამუშაო დღეს  -> მე-10 სამუშაო დღეს, იმავე საათზე
//   არასამუშაოზე -> ათვლა იწყება შემდეგი სამუშაო დღის 10:00-დან
// შედეგი: Reserved_Date1 (UF_CRM_1676468387863) + სია 176 "ჯავშნის ამოწურვის თარიღი" (_databolo).
// Designer-ში ჩასვით მხოლოდ ქვემოთა ნაწილი (<?php-ის გარეშე).

$root = $this->GetRootActivity();
\Bitrix\Main\Loader::includeModule('iblock');
\Bitrix\Main\Loader::includeModule('crm');

$workDays = 10;

$docId  = $root->GetDocumentId();
$dealId = (int)str_replace('DEAL_', '', $docId[2]);
$deal = CCrmDeal::GetListEx([], ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'], false, false, ['ID', 'UF_CRM_1720778078400'])->Fetch();

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

$start = time();
if (!$isWork($start)) {
    $d = $nextWork($start);
    $start = mktime(10, 0, 0, date('n', $d), date('j', $d), date('Y', $d));
}
$deadline = $start;
for ($i = 0; $i < $workDays; $i++) {
    $deadline = $nextWork($deadline);
}
$deadlineStr = ConvertTimeStamp($deadline, 'FULL');

CBPRuntime::GetRuntime()->GetService('DocumentService')->UpdateDocument(
    $docId,
    ['UF_CRM_1676468387863' => $deadlineStr]   // Reserved_Date1
);

// სია 176-ის ჩანაწერი (ID ინახება დილის UF_CRM_1720778078400-ში)
$elementId = $deal ? (int)$deal['UF_CRM_1720778078400'] : 0;
if ($elementId > 0) {
    CIBlockElement::SetPropertyValuesEx($elementId, 176, ['_databolo' => $deadlineStr]);
}
