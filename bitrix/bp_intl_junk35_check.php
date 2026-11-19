<?php
// BP „International: 35 დღე → No response 35+ days“ — PHP Code „შემოწმება“ (2026-11-19).
// ეშვება RUN ON CREATE (1009) International შტოდან და დილის შექმნიდან 35 კალენდარული დღის პაუზის შემდეგ.
// junk35=1 მხოლოდ თუ დილი ისევ pipeline 40-შია და მიმდინარე (in progress, semantic P) ეტაპზეა.
// დახურულ დილებს (Won, Future Opportunity, Unqualified, No response, Broker Junk) არ ვეხებით.
// შეცდომისას junk35=0 (დილი არ გადადის), შეცდომა იწერება tracking-ში.
// Designer-ში ჩასვით <?php-ის გარეშე.

$root = $this->GetRootActivity();
$root->SetVariable('junk35', 0);
try {
    \Bitrix\Main\Loader::includeModule('crm');
    $doc = $root->GetDocumentId();
    $id = (int)str_replace('DEAL_', '', $doc[2]);
    $deal = CCrmDeal::GetListEx(array(), array('ID' => $id, 'CHECK_PERMISSIONS' => 'N'), false, false,
        array('ID', 'CATEGORY_ID', 'STAGE_ID', 'STAGE_SEMANTIC_ID', 'DATE_CREATE'))->Fetch();
    if (!$deal) {
        $this->WriteToTrackingService('[junk35] დილი ვერ მოიძებნა');
        return;
    }
    $stages = \Bitrix\Crm\Category\DealCategory::getStageList(40);
    $stageName = isset($stages[$deal['STAGE_ID']]) ? $stages[$deal['STAGE_ID']] : $deal['STAGE_ID'];
    if ((int)$deal['CATEGORY_ID'] === 40 && $deal['STAGE_SEMANTIC_ID'] === 'P') {
        $root->SetVariable('junk35', 1);
        $root->SetVariable('prev_stage', $stageName);
        $this->WriteToTrackingService('[junk35] შექმნიდან 35 დღე, ეტაპი „' . $stageName . '“ -> No response 35+ days');
    } else {
        $this->WriteToTrackingService('[junk35] არ გადადის: pipeline ' . $deal['CATEGORY_ID'] . ', ეტაპი „' . $stageName . '“ (' . $deal['STAGE_SEMANTIC_ID'] . ')');
    }
} catch (\Throwable $e) {
    $this->WriteToTrackingService('[junk35] შეცდომა: ' . $e->getMessage());
}
