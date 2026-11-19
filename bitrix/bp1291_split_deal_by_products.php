<?php
// Automation template 1291 (Deal, Pipeline 40 International, stage C40:UC_LNA3LV „Decision Pending“) — PHP Code activity.
// 2026-11-19. თუ დილს 1-ზე მეტი პროდუქტი აქვს, დილი იშლება:
//   - ორიგინალს რჩება პირველი პროდუქტი (ისტორია, ზარები, ჩატი, საქმეები ორიგინალში რჩება);
//   - ყოველ დანარჩენ პროდუქტზე იქმნება ახალი დილი იმავე ინფორმაციით (ველები, კონტაქტები, კომპანია,
//     პასუხისმგებელი, წყარო, UTM) და ერთი შესაბამისი პროდუქტით, ეტაპზე Decision Pending;
//   - სათაური: „<ორიგინალის სათაური> · <პროდუქტის სახელი>“ (ორიგინალსაც ემატება თავისი პროდუქტი);
//   - პროდუქტიდან მომდინარე ველები (პროექტი, ბინის ნომერი, სართული, ფართი, კვ.ფასი) ყოველ დილზე
//     მისივე პროდუქტიდან ივსება; ჯავშნის ველები (rezerv_list_id, Reserved_Date1) არ კოპირდება;
//   - ორივე მხარეს ტაიმლაინში იწერება კომენტარი ბმულებით.
// ახალი დილები იქმნება CCrmDeal::Add-ით, რომელიც BP-ებს/რობოტებს არ უშვებს — RUN ON CREATE (1009)-ის
// „[SALES] მერჯი 189“ ასლებს ისევ ერთ დილად ვერ გააერთიანებს.
// უსაფრთხოება: ჯერ იქმნება ასლები, მხოლოდ წარმატებით გადატანილი პროდუქტები იშლება ორიგინალიდან —
// შეცდომისას პროდუქტი არ იკარგება. ყველაფერი try/catch-შია, შეცდომა პროცესს არ აჩერებს (tracking-ში იწერება).
// Designer-ში ჩასვით <?php-ის გარეშე.

$self = $this;
$log = function ($m) use ($self) { $self->WriteToTrackingService('[1291 split] ' . $m); };

try {
    \Bitrix\Main\Loader::includeModule('crm');
    \Bitrix\Main\Loader::includeModule('iblock');

    $docId = $this->GetDocumentId();
    $dealId = (int)str_replace('DEAL_', '', $docId[2]);
    $rows = CCrmDeal::LoadProductRows($dealId);
    if (!is_array($rows) || count($rows) < 2) {
        $log('deal ' . $dealId . ': პროდუქტი ' . (is_array($rows) ? count($rows) : 0) . ' — დაშლა არ სჭირდება');
        return;
    }

    $deal = CCrmDeal::GetListEx(array(), array('ID' => $dealId, 'CHECK_PERMISSIONS' => 'N'), false, false, array('*', 'UF_*'))->Fetch();
    if (!$deal || (int)$deal['CATEGORY_ID'] !== 40) {
        $log('deal ' . $dealId . ': არ არის pipeline 40 — გამოტოვება');
        return;
    }

    // --- product-derived fields -------------------------------------------------------------
    $PRODUCT_UF = array(
        'UF_CRM_5E1EECDB0571C',  // პროექტი (enum, VALUE = პროდუქტის P427)
        'UF_CRM_1574325609282',  // ბინის ნომერი (P377)
        'UF_CRM_1574325620519',  // სართული (P376)
        'UF_1616144092203',      // ჯამური ფართი (მიბმული პროდუქტი) (P381)
        'UF_CRM_1574325830678',  // კვ.ფასი (P382, money)
        'UF_CRM_1641915753073',  // kvm (average)
        'UF_CRM_1639420120942',  // kvm fasi (aver)
    );
    // per-product lists: one entry per attached product, in product-row order
    $LIST_UF = array(
        'UF_1613715017601',      // ტიპი (მიბმული პროდუქტი) — enum (ბინა/პარკინგი/…), multiple
        'UF_CRM_1617787519405',  // მიბმული პროდუქტი — "პროექტი | ბინის №: 11 | კვ.მ. … | …", multiple
        'UF_CRM_1709540295458',  // პროდუქტი / რაოდენობა — multiple
    );
    $SKIP_UF = array(
        'UF_CRM_1720778078400',  // rezerv_list_id (სია 176)
        'UF_CRM_1676468387863',  // Reserved_Date1
    );

    global $USER_FIELD_MANAGER;
    $ufMeta = $USER_FIELD_MANAGER->GetUserFields('CRM_DEAL', 0, 'en');
    $projectUfId = isset($ufMeta['UF_CRM_5E1EECDB0571C']) ? (int)$ufMeta['UF_CRM_5E1EECDB0571C']['ID'] : 0;

    $productFields = function ($productId) use ($projectUfId, $log) {
        $out = array();
        try {
            $p = CIBlockElement::GetList(array(), array('ID' => (int)$productId), false, false,
                array('ID', 'NAME', 'IBLOCK_SECTION_ID', 'PROPERTY_377', 'PROPERTY_376', 'PROPERTY_381', 'PROPERTY_382', 'PROPERTY_427'))->Fetch();
            if (!$p) return $out;
            if ((string)$p['PROPERTY_377_VALUE'] !== '') $out['UF_CRM_1574325609282'] = $p['PROPERTY_377_VALUE'];
            if ((string)$p['PROPERTY_376_VALUE'] !== '') $out['UF_CRM_1574325620519'] = $p['PROPERTY_376_VALUE'];
            if ((string)$p['PROPERTY_381_VALUE'] !== '') $out['UF_1616144092203'] = $p['PROPERTY_381_VALUE'];
            $sqm = trim((string)$p['PROPERTY_382_VALUE']);
            if ($sqm !== '') $out['UF_CRM_1574325830678'] = strpos($sqm, '|') === false ? $sqm . '|USD' : $sqm;   // money field
            $project = trim((string)$p['PROPERTY_427_VALUE']);
            if ($project === '' && $p['IBLOCK_SECTION_ID']) {
                $s = CIBlockSection::GetByID($p['IBLOCK_SECTION_ID'])->Fetch();
                $project = $s ? trim((string)$s['NAME']) : '';
            }
            if ($project !== '' && $projectUfId) {
                $en = CUserFieldEnum::GetList(array(), array('USER_FIELD_ID' => $projectUfId));
                while ($e = $en->Fetch()) {
                    if (mb_strtolower(trim($e['VALUE'])) === mb_strtolower($project)) {
                        $out['UF_CRM_5E1EECDB0571C'] = array((int)$e['ID']);
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $log('product ' . $productId . ' ველები ვერ წავიკითხე: ' . $e->getMessage());
        }
        return $out;
    };

    // Values of the per-product list fields for product row $i: the entry that names the same
    // apartment number ("№: 12 |"), otherwise the entry at the same position when the list has one
    // entry per product. A list that cannot be matched is left empty rather than wrong.
    $rowCount = count($rows);
    $listFields = function ($i, $aptNo) use ($deal, $LIST_UF, $rowCount) {
        $out = array();
        foreach ($LIST_UF as $k) {
            $vals = isset($deal[$k]) && is_array($deal[$k]) ? array_values($deal[$k]) : array();
            if (!$vals) continue;
            $pick = null;
            if ($aptNo !== '' && !is_numeric($vals[0])) {
                foreach ($vals as $v) {
                    if (preg_match('/№:\s*' . preg_quote($aptNo, '/') . '\s*\|/u', (string)$v)) { $pick = $v; break; }
                }
            }
            if ($pick === null && count($vals) === $rowCount) $pick = $vals[$i];
            if ($pick !== null) $out[$k] = array($pick);
        }
        return $out;
    };
    $aptOf = function ($pf, $row) {
        return isset($pf['UF_CRM_1574325609282']) ? (string)$pf['UF_CRM_1574325609282'] : (string)$row['PRODUCT_NAME'];
    };

    $cleanRow = function ($row) {
        // a row belongs to one deal: drop its ids and any warehouse reservation
        foreach (array('ID', 'OWNER_ID', 'OWNER_TYPE', 'XML_ID', 'RESERVE_ID', 'DATE_RESERVE_END', 'RESERVE_QUANTITY') as $k) unset($row[$k]);
        return $row;
    };

    $comment = function ($id, $text, $authorId) use ($log) {
        try {
            $cid = \Bitrix\Crm\Timeline\CommentEntry::create(array(
                'TEXT' => $text,
                'AUTHOR_ID' => (int)$authorId,
                'SETTINGS' => array('HAS_FILES' => 'N'),
                'BINDINGS' => array(array('ENTITY_TYPE_ID' => \CCrmOwnerType::Deal, 'ENTITY_ID' => (int)$id)),
            ));
            if ($cid > 0) {
                \Bitrix\Crm\Timeline\CommentController::getInstance()->onCreate($cid, array(
                    'ENTITY_TYPE_ID' => \CCrmOwnerType::Deal, 'ENTITY_ID' => (int)$id, 'AUTHOR_ID' => (int)$authorId,
                ));
            }
        } catch (\Throwable $e) {
            $log('კომენტარი ვერ დაემატა დილს ' . $id . ': ' . $e->getMessage());
        }
    };

    // --- base fields for the copies ---------------------------------------------------------
    $baseTitle = (string)$deal['TITLE'];
    $base = array(
        'CATEGORY_ID' => 40,
        'STAGE_ID' => 'C40:UC_LNA3LV',
        'ASSIGNED_BY_ID' => (int)$deal['ASSIGNED_BY_ID'],
        'OPENED' => $deal['OPENED'],
        'CURRENCY_ID' => $deal['CURRENCY_ID'],
    );
    foreach (array('TYPE_ID', 'COMPANY_ID', 'SOURCE_ID', 'SOURCE_DESCRIPTION', 'COMMENTS', 'ADDITIONAL_INFO',
                   'BEGINDATE', 'CLOSEDATE', 'PROBABILITY', 'ORIGINATOR_ID') as $k) {
        if (isset($deal[$k]) && $deal[$k] !== '' && $deal[$k] !== null) $base[$k] = $deal[$k];
    }
    $contactIds = \Bitrix\Crm\Binding\DealContactTable::getDealContactIDs($dealId);
    if ($contactIds) $base['CONTACT_IDS'] = $contactIds;
    try {
        $utm = \Bitrix\Crm\UtmTable::getCodeValues(\CCrmOwnerType::Deal, $dealId);
        foreach ((array)$utm as $k => $v) if ((string)$v !== '') $base[$k] = $v;
    } catch (\Throwable $e) { /* UTM is optional */ }

    foreach ($deal as $k => $v) {
        if (strpos($k, 'UF_') !== 0) continue;
        if (in_array($k, $PRODUCT_UF, true) || in_array($k, $SKIP_UF, true) || in_array($k, $LIST_UF, true)) continue;
        if (!isset($ufMeta[$k]) || $ufMeta[$k]['USER_TYPE_ID'] === 'file') continue;   // files are not shared between deals
        if ($v === null || $v === '' || $v === false || (is_array($v) && !$v)) continue;
        $base[$k] = $v;
    }

    // --- create one deal per extra product ----------------------------------------------------
    $entity = new CCrmDeal(false);
    $created = array();          // newDealId => product name
    $keep = array($cleanRow($rows[0]));
    for ($i = 1; $i < count($rows); $i++) {
        $row = $rows[$i];
        try {
            $fields = $base;
            $fields['TITLE'] = $baseTitle . ' · ' . $row['PRODUCT_NAME'];
            $pf = $productFields($row['PRODUCT_ID']);
            $fields = array_merge($fields, $pf, $listFields($i, $aptOf($pf, $row)));
            $newId = (int)$entity->Add($fields, true, array(
                'DISABLE_USER_FIELD_CHECK' => true,
                'DISABLE_REQUIRED_USER_FIELD_CHECK' => true,
                'CURRENT_USER' => (int)$deal['ASSIGNED_BY_ID'],
            ));
            if ($newId <= 0) {
                $log('ახალი დილი ვერ შეიქმნა (' . $row['PRODUCT_NAME'] . '): ' . $entity->LAST_ERROR);
                $keep[] = $cleanRow($row);
                continue;
            }
            if (!CCrmDeal::SaveProductRows($newId, array($cleanRow($row)), false, true, true)) {
                $log('დილ ' . $newId . '-ზე პროდუქტი ვერ მიება (' . $row['PRODUCT_NAME'] . ') — ორიგინალში რჩება');
                $keep[] = $cleanRow($row);
            }
            $created[$newId] = $row['PRODUCT_NAME'];
            // RUN ON CREATE does not run for copies: start the 35-day junk timer (BP 1310) directly
            try {
                $wfErrors = array();
                CBPDocument::StartWorkflow(1310, array('crm', 'CCrmDocumentDeal', 'DEAL_' . $newId), array(), $wfErrors);
                if ($wfErrors) $log('BP 1310 დილზე ' . $newId . ': ' . json_encode($wfErrors, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                $log('BP 1310 დილზე ' . $newId . ' ვერ გაეშვა: ' . $e->getMessage());
            }
            $log('შეიქმნა დილი ' . $newId . ' პროდუქტით ' . $row['PRODUCT_NAME'] . ' (' . $row['PRODUCT_ID'] . ')');
        } catch (\Throwable $e) {
            $log('პროდუქტი ' . $row['PRODUCT_NAME'] . ': ' . $e->getMessage() . ' — ორიგინალში რჩება');
            $keep[] = $cleanRow($row);
        }
    }

    if (!$created) {
        $log('deal ' . $dealId . ': ვერც ერთი ასლი ვერ შეიქმნა, ორიგინალი უცვლელია');
        return;
    }

    // --- original keeps its first product (plus anything that failed to move) ----------------
    if (!CCrmDeal::SaveProductRows($dealId, $keep, false, true, true)) {
        $log('ორიგინალის პროდუქტები ვერ განახლდა — შეამოწმეთ ხელით (ასლები უკვე შეიქმნა: ' . implode(', ', array_keys($created)) . ')');
    }
    $upd = $productFields($rows[0]['PRODUCT_ID']);
    if (count($keep) === 1) {
        // only its first product is left: lists keep just that product's entry
        $upd = array_merge($upd, $listFields(0, $aptOf($upd, $rows[0])));
    }
    if (count($keep) === 1 && strpos($baseTitle, ' · ' . $rows[0]['PRODUCT_NAME']) === false) {
        $upd['TITLE'] = $baseTitle . ' · ' . $rows[0]['PRODUCT_NAME'];
    }
    if ($upd) {
        $u = $entity->Update($dealId, $upd, true, true, array(
            'DISABLE_USER_FIELD_CHECK' => true, 'DISABLE_REQUIRED_USER_FIELD_CHECK' => true, 'REGISTER_SONET_EVENT' => false,
        ));
        if (!$u) $log('ორიგინალის ველები ვერ განახლდა: ' . $entity->LAST_ERROR);
    }

    // --- timeline links ----------------------------------------------------------------------
    $author = (int)$deal['ASSIGNED_BY_ID'];
    $links = array();
    foreach ($created as $id => $name) {
        $links[] = '[URL=/crm/deal/details/' . $id . '/]#' . $id . '[/URL] — ' . $name;
        $comment($id, 'დილი შეიქმნა დილის [URL=/crm/deal/details/' . $dealId . '/]#' . $dealId . '[/URL] დაშლით (პროდუქტი: ' . $name . '). ისტორია, ზარები და ჩატი ორიგინალ დილშია.', $author);
    }
    $comment($dealId, "დილი დაიშალა პროდუქტების მიხედვით. ამ დილში დარჩა: " . $rows[0]['PRODUCT_NAME'] . "\nახალი დილები:\n" . implode("\n", $links), $author);
    $log('deal ' . $dealId . ' დაიშალა: ' . count($created) . ' ახალი დილი (' . implode(', ', array_keys($created)) . ')');
} catch (\Throwable $e) {
    $log('შეცდომა: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
