<?php
// /feedback/restored/?deal=<თიქეთის ID> — აღდგენილი (ხელახლა დახურული) თიქეთის კითხვარი.
// ბმულს კლიენტს SMS-ით უგზავნის BP 258 (C9:WON, სტატუსი „არასწორად დახურული თიქეთი“).
// პასუხი ინახება Ticket FeedBack (11)-ში ახალ დილად და ეშვება BP 238 (როგორც ძველ /feedback/-ზე).
// ძველ /feedback/index.php-ს არ ვეხებით; წვდომა (საჯარო) მემკვიდრეობით მოდის /feedback/.access.php-დან.

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

CModule::IncludeModule('crm');
CModule::IncludeModule('bizproc');

const RF_TICKET_CATEGORY = 9;
const RF_FIELD_STATUS = 'UF_CRM_1577014160745';     // აღდგენის სტატუსი
const RF_STATUS_RESTORED = 186;                     // არასწორად დახურული თიქეთი
const RF_FIELD_TICKET_ID = 'UF_CRM_1654254176';     // შეფასების დილზე: თიქეთის ID
const RF_FIELD_RESOLVED = 'UF_CRM_1639550332785';   // მოგვარდა სრულად (აღდგ.) — კი/არა
const RF_FIELD_RATING_RESOLUTION = 'UF_CRM_1719833627'; // კმაყოფილება მოგვარებით 1–5
const RF_FIELD_RATING_COMMUNICATION = 'UF_CRM_1719833577'; // კომუნიკაცია 1–5
const RF_FIELD_COMMENT = 'UF_CRM_1648025983038';
const RF_FEEDBACK_BP = 238;

function rfGetTicket($id)
{
    $res = CCrmDeal::GetListEx(
        array(),
        array('ID' => $id, 'CHECK_PERMISSIONS' => 'N'),
        false,
        false,
        array('ID', 'CATEGORY_ID', 'ASSIGNED_BY_ID', 'UF_CRM_1610362285', 'UF_CRM_1599505987',
            'UF_CRM_1733921713', 'UF_CRM_1606765944288', RF_FIELD_STATUS)
    );
    return $res ? $res->Fetch() : false;
}

// აღდგენილის კითხვარი ერთ თიქეთზე ერთხელ ივსება (ძველი კითხვარი ცალკე რჩება).
function rfFindFeedback($ticketId)
{
    $res = CCrmDeal::GetListEx(
        array('ID' => 'DESC'),
        array('CATEGORY_ID' => 11, '=' . RF_FIELD_TICKET_ID => $ticketId, '=' . RF_FIELD_STATUS => RF_STATUS_RESTORED,
            'CHECK_PERMISSIONS' => 'N'),
        false,
        array('nTopCount' => 1),
        array('ID')
    );
    $row = $res ? $res->Fetch() : false;
    return $row ? (int)$row['ID'] : 0;
}

$ticketId = isset($_GET['deal']) ? (int)$_GET['deal'] : 0;
$ticket = $ticketId > 0 ? rfGetTicket($ticketId) : false;
$valid = $ticket && (int)$ticket['CATEGORY_ID'] === RF_TICKET_CATEGORY && !empty($ticket[RF_FIELD_STATUS]);
$alreadySent = $valid && rfFindFeedback($ticketId) > 0;
$formError = '';

if ($valid && !$alreadySent && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $resolved = isset($_POST['resolved']) ? (string)$_POST['resolved'] : '';
    $ratingResolution = isset($_POST['rating_resolution']) ? (int)$_POST['rating_resolution'] : 0;
    $ratingCommunication = isset($_POST['rating_communication']) ? (int)$_POST['rating_communication'] : 0;
    $comment = trim(isset($_POST['comment']) ? (string)$_POST['comment'] : '');

    if (!in_array($resolved, array('yes', 'no'), true)
        || $ratingResolution < 1 || $ratingResolution > 5
        || $ratingCommunication < 1 || $ratingCommunication > 5
        || $comment === '') {
        $formError = 'გთხოვთ, უპასუხოთ ყველა კითხვას.';
    } else {
        $deal = new CCrmDeal(false);
        $fields = array(
            'TITLE' => 'აღდგენილი თიქეთის კითხვარი #' . $ticketId,
            'CATEGORY_ID' => 11,
            'STAGE_ID' => 'C11:NEW',
            RF_FIELD_TICKET_ID => $ticketId,
            RF_FIELD_STATUS => RF_STATUS_RESTORED,
            RF_FIELD_RESOLVED => $resolved === 'yes' ? 1 : 0,
            RF_FIELD_RATING_RESOLUTION => $ratingResolution,
            RF_FIELD_RATING_COMMUNICATION => $ratingCommunication,
            RF_FIELD_COMMENT => mb_substr($comment, 0, 2000),
            'ASSIGNED_BY_ID' => $ticket['ASSIGNED_BY_ID'],
            'UF_CRM_1610362285' => $ticket['UF_CRM_1610362285'],
            'UF_CRM_1599505987' => $ticket['UF_CRM_1599505987'],
            'UF_CRM_1733921713' => $ticket['UF_CRM_1733921713'],
            'UF_CRM_1606765944288' => $ticket['UF_CRM_1606765944288'],
        );
        $newId = (int)$deal->Add($fields, true, array('CURRENT_USER' => 1, 'DISABLE_USER_FIELD_CHECK' => true,
            'REGISTER_SONET_EVENT' => true, 'IS_RESTORATION' => true));

        if ($newId > 0) {
            $errors = array();
            CBPDocument::StartWorkflow(RF_FEEDBACK_BP, array('crm', 'CCrmDocumentDeal', 'DEAL_' . $newId),
                array('TargetUser' => 'user_1'), $errors);
            if ($errors) {
                error_log('[feedback/restored] BP ' . RF_FEEDBACK_BP . ': ' . json_encode($errors, JSON_UNESCAPED_UNICODE));
            }
            \Bitrix\Crm\Timeline\CommentEntry::create(array(
                'TEXT' => 'აღდგენილი თიქეთის კითხვარი შეავსო კლიენტმა — შეფასება #' . $newId
                    . "\nმოგვარდა სრულად: " . ($resolved === 'yes' ? 'დიახ' : 'არა')
                    . "\nკმაყოფილება მოგვარებით: " . $ratingResolution
                    . "\nკომუნიკაცია განახლებისას: " . $ratingCommunication,
                'AUTHOR_ID' => 1,
                'BINDINGS' => array(array('ENTITY_TYPE_ID' => CCrmOwnerType::Deal, 'ENTITY_ID' => $ticketId)),
            ));
            $alreadySent = true;
        } else {
            error_log('[feedback/restored] add failed for ticket ' . $ticketId . ': ' . $deal->LAST_ERROR);
            $formError = 'შენახვა ვერ მოხერხდა. გთხოვთ, სცადოთ თავიდან.';
        }
    }
}

$state = !$valid ? 'invalid' : ($alreadySent ? 'sent' : 'form');
?><!DOCTYPE html>
<html lang="ka">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ARCHI — შეფასება</title>
<style>
    * { padding: 0; margin: 0; box-sizing: border-box; }
    @font-face {
        font-family: 'ArchiMxed';
        src: url('https://crm.archi.ge/crm/deal/web_form/font/Archi_Mxed-Bold.otf') format('opentype');
        font-weight: bold;
    }
    body, h2, button, input, textarea, span, p { font-family: 'ArchiMxed', sans-serif; }
    body { background: #F6F7F9; }
    .container { width: 95%; margin: 0 auto; padding: 0 5%; }
    .top-block {
        display: flex; align-items: center; background-color: #EAF1F7; padding: 50px 20px;
        border-bottom-left-radius: 30px; border-bottom-right-radius: 30px; position: relative;
    }
    .title { font-size: 24px; color: #000; }
    .logo { height: 35px; margin: 20px; }
    .form-block { margin-top: 55px; margin-bottom: 40px; }

    .lang-switcher { position: absolute; top: 18px; right: 20px; display: flex; align-items: center; gap: 8px; }
    .lang-btn {
        display: flex; align-items: center; gap: 5px; padding: 5px 10px 5px 6px; border: 2px solid transparent;
        border-radius: 8px; background: transparent; cursor: pointer; font-size: 14px; color: #444;
        transition: border-color 0.2s, background 0.2s;
    }
    .lang-btn:hover { background: rgba(23, 102, 177, 0.08); }
    .lang-btn.active { border-color: #1766B1; background: rgba(23, 102, 177, 0.10); color: #1766B1; font-weight: bold; }

    .inner { display: flex; justify-content: center; background-color: #EAF1F7; border-radius: 30px; padding: 50px 150px 100px 150px; }
    .row { width: 100%; }
    .form-controls-block { background: #F6F7F9; border-radius: 30px; padding: 40px; }
    .rating-block { margin-bottom: 28px; }
    .rating-label { font-size: 16px; color: #333; margin-bottom: 14px; display: block; }
    .rating-boxes { display: flex; align-items: flex-start; gap: 8px; flex-wrap: wrap; }
    .rating-box-item { display: flex; flex-direction: column; align-items: center; }
    .rating-box-item input { display: none; }
    .rating-box-item label {
        min-width: 64px; height: 64px; padding: 0 14px; background: #EAF1F7; border: 2px solid transparent;
        border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 26px;
        font-weight: 700; color: #1766B1; cursor: pointer; transition: background 0.2s, border-color 0.2s, transform 0.15s;
    }
    .rating-box-item.wide label { font-size: 20px; min-width: 110px; }
    .rating-box-item label:hover { background: #d0e3f5; transform: scale(1.07); }
    .rating-box-item input:checked + label { background: #1766B1; color: #fff; border-color: #1766B1; }
    .rating-box-item input[value="no"]:checked + label { background: #D2127F; border-color: #D2127F; }
    .rating-box-caption { font-size: 13px; color: #666; margin-top: 6px; text-align: center; width: 64px; }
    .comment { padding: 12px; border: 1px solid #1766B1; border-radius: 10px; width: 100%; font-size: 15px; resize: vertical; min-height: 90px; }
    .btn-block { display: flex; justify-content: flex-end; padding-top: 30px; }
    .submit-btn { border: none; font-size: 17px; background: #1766B1; color: #fff; border-radius: 5px; padding: 8px 24px; cursor: pointer; }
    .submit-btn:disabled { opacity: 0.4; cursor: not-allowed; }
    .form-error { color: #D2127F; margin-bottom: 20px; }

    .message-block { display: flex; justify-content: center; padding: 50px 20px; }
    .success-logo-block { display: flex; justify-content: center; }
    .success-logo-block svg { width: 30%; max-width: 160px; }
    .modal-title-text { font-size: 30px; text-align: center; color: #1766B1; margin-top: 40px; }

    @media (max-width: 1080px) {
        .inner { padding: 50px 60px 80px 60px; }
    }
    @media (max-width: 640px) {
        .container { width: 100%; padding: 0 16px; }
        .top-block { flex-direction: column; align-items: flex-start; padding: 60px 16px 30px 16px; }
        .title { font-size: 20px; }
        .logo { margin: 0 0 16px 0; }
        .form-block { margin-top: 24px; }
        .inner { padding: 16px; border-radius: 20px; }
        .form-controls-block { padding: 20px; border-radius: 20px; }
        .rating-box-item label { min-width: 48px; height: 48px; font-size: 20px; padding: 0 10px; }
        .rating-box-item.wide label { min-width: 90px; font-size: 17px; }
        .rating-box-caption { width: 48px; font-size: 11px; }
        .submit-btn { width: 100%; padding: 12px; }
        .modal-title-text { font-size: 22px; }
    }
</style>
</head>
<body>

<div class="container">
    <div class="top-block">
        <div class="lang-switcher">
            <button type="button" class="lang-btn active" id="btn-ka" onclick="setLang('ka')">
                <svg width="22" height="16" viewBox="0 0 22 16" xmlns="http://www.w3.org/2000/svg" style="border-radius:3px;display:block;">
                    <rect width="22" height="16" fill="#fff"/>
                    <rect x="9" y="0" width="4" height="16" fill="#E31D2B"/>
                    <rect x="0" y="6" width="22" height="4" fill="#E31D2B"/>
                    <rect x="1" y="1" width="1.8" height="4.5" fill="#E31D2B"/>
                    <rect x="0.2" y="1.8" width="4.5" height="1.8" fill="#E31D2B"/>
                    <rect x="16.2" y="1" width="1.8" height="4.5" fill="#E31D2B"/>
                    <rect x="15.4" y="1.8" width="4.5" height="1.8" fill="#E31D2B"/>
                    <rect x="1" y="10.5" width="1.8" height="4.5" fill="#E31D2B"/>
                    <rect x="0.2" y="12.4" width="4.5" height="1.8" fill="#E31D2B"/>
                    <rect x="16.2" y="10.5" width="1.8" height="4.5" fill="#E31D2B"/>
                    <rect x="15.4" y="12.4" width="4.5" height="1.8" fill="#E31D2B"/>
                </svg>
                ქართული
            </button>
            <button type="button" class="lang-btn" id="btn-en" onclick="setLang('en')">
                <svg width="22" height="16" viewBox="0 0 22 16" xmlns="http://www.w3.org/2000/svg" style="border-radius:3px;display:block;">
                    <rect width="22" height="16" fill="#012169"/>
                    <path d="M0,0 L22,16 M22,0 L0,16" stroke="#fff" stroke-width="3.2"/>
                    <path d="M0,0 L22,16 M22,0 L0,16" stroke="#C8102E" stroke-width="1.8"/>
                    <path d="M11,0 V16 M0,8 H22" stroke="#fff" stroke-width="5"/>
                    <path d="M11,0 V16 M0,8 H22" stroke="#C8102E" stroke-width="3"/>
                </svg>
                English
            </button>
        </div>
        <div class="logo-block">
            <img src="https://crm.archi.ge/crm/deal/web_form/images/newLogo.png" alt="ARCHI" class="logo">
        </div>
        <h2 class="title"
            data-ka="თქვენს საკითხზე მუშაობა განახლდა და დასრულდა — გთხოვთ, შეაფასოთ მიღებული მომსახურება"
            data-en="Work on your request was resumed and completed — please rate the service you received">
            თქვენს საკითხზე მუშაობა განახლდა და დასრულდა — გთხოვთ, შეაფასოთ მიღებული მომსახურება
        </h2>
    </div>

<?php if ($state === 'form'): ?>
    <div class="form-block">
        <form method="post" id="myForm">
            <div class="inner">
                <div class="row">
                    <div class="form-controls-block">
<?php if ($formError !== ''): ?>
                        <p class="form-error"><?= htmlspecialcharsbx($formError) ?></p>
<?php endif; ?>

                        <div class="rating-block">
                            <span class="rating-label"
                                data-ka="1. მოგვარდა თუ არა თქვენი საკითხი სრულად?"
                                data-en="1. Has your issue been fully resolved?">
                                1. მოგვარდა თუ არა თქვენი საკითხი სრულად?
                            </span>
                            <div class="rating-boxes">
                                <div class="rating-box-item wide">
                                    <input type="radio" name="resolved" id="res-yes" value="yes">
                                    <label for="res-yes" data-ka="დიახ" data-en="Yes">დიახ</label>
                                </div>
                                <div class="rating-box-item wide">
                                    <input type="radio" name="resolved" id="res-no" value="no">
                                    <label for="res-no" data-ka="არა" data-en="No">არა</label>
                                </div>
                            </div>
                        </div>

<?php
$scales = array(
    array('rating_resolution', 'rr',
        '2. რამდენად კმაყოფილი ხართ საკითხის საბოლოო მოგვარებით?',
        '2. How satisfied are you with the final resolution of your issue?'),
    array('rating_communication', 'rc',
        '3. როგორ შეაფასებდით ჩვენთან კომუნიკაციას საკითხის განახლებისას?',
        '3. How would you rate our communication while your request was reopened?'),
);
foreach ($scales as $s):
?>
                        <div class="rating-block">
                            <span class="rating-label" data-ka="<?= $s[2] ?>" data-en="<?= $s[3] ?>"><?= $s[2] ?></span>
                            <div class="rating-boxes">
<?php for ($i = 1; $i <= 5; $i++): ?>
                                <div class="rating-box-item">
                                    <input type="radio" name="<?= $s[0] ?>" id="<?= $s[1] . '-' . $i ?>" value="<?= $i ?>">
                                    <label for="<?= $s[1] . '-' . $i ?>"><?= $i ?></label>
<?php if ($i === 1): ?>
                                    <span class="rating-box-caption" data-ka="ძალიან ცუდი" data-en="Very bad">ძალიან ცუდი</span>
<?php elseif ($i === 5): ?>
                                    <span class="rating-box-caption" data-ka="ძალიან კარგი" data-en="Very good">ძალიან კარგი</span>
<?php endif; ?>
                                </div>
<?php endfor; ?>
                            </div>
                        </div>
<?php endforeach; ?>

                        <div class="rating-block">
                            <span class="rating-label"
                                data-ka="4. კომენტარი"
                                data-en="4. Comment">
                                4. კომენტარი
                            </span>
                            <textarea name="comment" id="comment" class="comment" maxlength="2000"
                                data-placeholder-ka="კომენტარი" data-placeholder-en="Comment"
                                placeholder="კომენტარი"></textarea>
                        </div>

                        <div class="btn-block">
                            <button type="submit" class="submit-btn" id="submit-btn" disabled
                                data-ka="გაგზავნა" data-en="Submit">გაგზავნა</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
<?php elseif ($state === 'sent'): ?>
    <div class="message-block">
        <div>
            <div class="success-logo-block">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 50">
                    <circle style="fill:#1766B1;" cx="25" cy="25" r="25"/>
                    <polyline style="fill:none;stroke:#FFFFFF;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;stroke-miterlimit:10;" points="38,15 22,33 12,25"/>
                </svg>
            </div>
            <p class="modal-title-text"
                data-ka="მადლობა! თქვენი აზრი ჩვენთვის მნიშვნელოვანია!"
                data-en="Thank you! Your opinion matters to us!">
                მადლობა! თქვენი აზრი ჩვენთვის მნიშვნელოვანია!
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="message-block">
        <p class="modal-title-text"
            data-ka="ბმული არასწორია ან ვადაგასულია."
            data-en="This link is invalid or has expired.">
            ბმული არასწორია ან ვადაგასულია.
        </p>
    </div>
<?php endif; ?>
</div>

<script>
    function checkFormValidity() {
        var btn = document.getElementById('submit-btn');
        if (!btn) return;
        var ok = !!document.querySelector('input[name="resolved"]:checked')
            && !!document.querySelector('input[name="rating_resolution"]:checked')
            && !!document.querySelector('input[name="rating_communication"]:checked')
            && document.getElementById('comment').value.trim() !== '';
        btn.disabled = !ok;
    }
    var form = document.getElementById('myForm');
    if (form) {
        form.addEventListener('change', checkFormValidity);
        form.addEventListener('input', checkFormValidity);
        form.addEventListener('submit', function () { document.getElementById('submit-btn').disabled = true; });
        checkFormValidity();
    }

    function setLang(lang) {
        document.documentElement.lang = lang;
        document.querySelectorAll('[data-ka][data-en]').forEach(function (el) {
            el.textContent = el.getAttribute('data-' + lang);
        });
        var c = document.getElementById('comment');
        if (c) c.placeholder = c.getAttribute('data-placeholder-' + lang);
        document.getElementById('btn-ka').classList.toggle('active', lang === 'ka');
        document.getElementById('btn-en').classList.toggle('active', lang === 'en');
    }
</script>
</body>
</html>
<?php require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php'); ?>
