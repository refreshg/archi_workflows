<?php
// Automation template 165 (Deal, Pipeline 0, stage 9) — PHP Code activity, root-ზე "Parallel execution"-მდე.
// ამოწმებს, იყო თუ არა ეს დილი ადრეც სტადია 9-ზე (რეზერვაცია).
// შედეგი: ცვლადი repeat_reserve = "Y" (განმეორებითი) / "N" (პირველი).
// ცვლადი repeat_reserve (String) წინასწარ უნდა შეიქმნას: Template Parameters -> Variables.
// Designer-ში ჩასვით მხოლოდ ქვემოთა ნაწილი (<?php-ის გარეშე).

$root = $this->GetRootActivity();
\Bitrix\Main\Loader::includeModule('crm');

$docId  = $root->GetDocumentId();
$dealId = (int)str_replace('DEAL_', '', $docId[2]);

$deal = CCrmDeal::GetListEx([], ['ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'], false, false, ['ID', 'MOVED_TIME'])->Fetch();
$movedTs = ($deal && $deal['MOVED_TIME']) ? MakeTimeStamp($deal['MOVED_TIME']) : time();

// სტადია 9-ზე ყოფნა მიმდინარე გადასვლამდე (5 წამის მარაგით)
$prev = (int)\Bitrix\Crm\History\Entity\DealStageHistoryTable::getCount([
    '=OWNER_ID'     => $dealId,
    '=STAGE_ID'     => '9',
    '<CREATED_TIME' => \Bitrix\Main\Type\DateTime::createFromTimestamp($movedTs - 5),
]);

$root->SetVariable('repeat_reserve', $prev > 0 ? 'Y' : 'N');
