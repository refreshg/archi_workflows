<?php
// ARCHI — განვადების გრაფიკის PDF სერვერზე, ზუსტად ისეთი, როგორსაც ბრაუზერის დამატება „Archi – გრაფიკის PDF“
// (archi_calc_print) აკეთებს /crm/deal/calculator.php-ის გვერდიდან.
//
// დამატება გვერდიდან კითხულობს ველებს (წარწერა → მნიშვნელობა), შენიშვნას და ცხრილს და jsPDF-ით აწყობს PDF-ს.
// აქ იგივე „payload“ იწყობა სერვერზე იმავე წყაროებიდან, საიდანაც კალკულატორი ავსებს გვერდს:
//   - ლისტი 104 (DATA JSON: grafType, graf, ველების მნიშვნელობები ველის id-ით);
//   - ლისტი 108-ის ბოლო ჩანაწერი, თუ "dafaruli" = YES (დაფარული გრაფიკი — კალკულატორი მაშინ მას აჩვენებს);
//   - დილის OPPORTUNITY („ბინის ღირებულება“);
//   - წარწერები და ველების რიგი — თავად calculator.php-დან და მისი lang/calculator.php-დან (მხოლოდ კითხვა),
//     ასე კალკულატორში ახალი ველი PDF-შიც ავტომატურად გამოჩნდება.
// PDF-ს აწყობს pdf/render.js (Node) — დამატების pdf.js, jsPDF და შრიფტები უცვლელად.
// არაფერს წერს: არც ბაზაში, არც დისკზე.

namespace Archi\DealApi;

class SchedulePdf
{
    const CALCULATOR = '/crm/deal/calculator.php';
    const CALCULATOR_LANG = '/crm/deal/lang/calculator.php';
    const LANG = 'ka';
    const NODE_BIN = '/usr/bin/node';
    const NODE_TIMEOUT = 60;           // წამი

    const IBLOCK_REGISTRATION = 104;   // გრაფიკის რეგისტრაციის მონაცემები (DEAL, DATA)
    const IBLOCK_ARCHIVE = 108;        // დაარქივებული გრაფიკები (DEAL, DATA)

    const HEADER_COLOR = array(0, 107, 79); // #grafiki thead { background-color: #006B4F }

    // გრაფიკის ტიპი → [ველების ბლოკის id calculator.php-ში, ტიპის წარწერის გასაღები, ცხრილის სათაურის ვარიანტი]
    private static $types = array(
        'graf_uprocento' => array('inputs_uprocento', 'ZERO_INTEREST', 1),
        'graf_ertetapiani' => array('inputs_ertetapiani', 'ONE_STAGE', 2),
        'graf_gareshe_30_70' => array('inputs_gareshe_30_70', 'TWO_STAGE', 3),
        'graf_uprocento_martivi' => array('inputs_manually', 'ZERO_PLUS', 3),
        'graf_sametapiani' => array('inputs_sametapiani', 'THREE_STAGE', 3),
    );

    // დილის გრაფიკის PDF. დილის უფლებები უკვე შემოწმებულია გამომძახებლის მიერ.
    // დაბრუნება: ['STATUS' => 'FOUND', 'FILE_NAME', 'PDF_BASE64'] | ['STATUS' => 'NO_SCHEDULE'] | ['STATUS' => 'ERROR']
    public static function build($dealId, $opportunity)
    {
        $payload = self::payload((int)$dealId, $opportunity);
        if (!$payload) {
            return array('STATUS' => 'NO_SCHEDULE');
        }
        $now = new \DateTime('now', new \DateTimeZone('Asia/Tbilisi'));
        $pdf = self::render($payload, $now);
        if ($pdf === null) {
            return array('STATUS' => 'ERROR');
        }
        return array(
            'STATUS' => 'FOUND',
            'FILE_NAME' => 'grafiki_' . (int)$dealId . '_' . $now->format('Y-m-d') . '.pdf',
            'PDF_BASE64' => $pdf,
        );
    }

    // ---- payload (იგივე ფორმა, რასაც დამატების scrape.js აბრუნებს) ---------------------------------------------

    public static function payload($dealId, $opportunity)
    {
        $lang = self::lang();
        $page = self::page($lang);
        $archived = self::lastArchived($dealId);
        $finished = is_array($archived) && isset($archived['dafaruli']) && $archived['dafaruli'] === 'YES';
        $data = $finished ? null : self::registration($dealId);
        if (!$finished && !is_array($data)) {
            return null;
        }

        $fields = array();
        $fields[] = array(
            'label' => self::norm(self::t($lang, 'ASSET_VALUE')),
            'value' => number_format((float)$opportunity, 2, '.', ',') . ' $',
            'kind' => 'input', 'fromPlaceholder' => false, 'disabled' => true,
        );

        $type = (!$finished && isset($data['grafType']) && isset(self::$types[$data['grafType']])) ? $data['grafType'] : null;
        $graphType = self::norm(self::t($lang, $type ? self::$types[$type][1] : 'CHOOSE'));
        $fields[] = array(
            'label' => self::norm(self::t($lang, 'SCHEDULE_TYPE')),
            'value' => $graphType,
            'kind' => 'select', 'fromPlaceholder' => false, 'disabled' => false,
        );

        if ($type && isset($page[self::$types[$type][0]])) {
            foreach ($page[self::$types[$type][0]] as $input) {
                $value = $input['value'];
                // fillRegistrationData(): DATA-ს გასაღები = ველის id (income* გამოტოვებულია)
                if (array_key_exists($input['id'], $data) && strpos($input['id'], 'income') !== 0 && is_scalar($data[$input['id']])) {
                    $value = (string)$data[$input['id']];
                }
                $value = self::norm($value);
                $fromPlaceholder = false;
                if ($value === '' && $input['placeholder'] !== '') {
                    $value = $input['placeholder'];
                    $fromPlaceholder = true;
                }
                $fields[] = array(
                    'label' => $input['label'], 'value' => $value, 'kind' => 'input',
                    'fromPlaceholder' => $fromPlaceholder, 'disabled' => $input['disabled'],
                );
            }
        }

        $note = self::norm(strip_tags(preg_replace('#<br\s*/?>#i', ' ', self::t($lang, 'NOTE'))));
        $notes = $note !== '' ? array(array('text' => $note, 'isFooterNote' => false, 'visible' => true)) : array();

        return array(
            'v' => 1,
            'dealId' => (string)$dealId,
            'title' => '',
            'sourceUrl' => '',
            'capturedAt' => (int)round(microtime(true) * 1000),
            'graphType' => $graphType,
            'fields' => $fields,
            'notes' => $notes,
            'table' => $finished ? self::finishedTable($lang, $archived) : self::table($lang, $data, self::$types[$type ?: 'graf_sametapiani'][2]),
        );
    }

    // calculator.php fillRegistrationData(): graf = "n|date|...|=n|date|...|=" — ბოლო ცარიელი სტრიქონი და ბოლო უჯრა არ ჩანს
    private static function table($lang, $data, $headerVariant)
    {
        $header = array('#', self::t($lang, 'DATE'), self::t($lang, 'PRINCIPAL'));
        if ($headerVariant === 1) {
            $header = array_merge($header, array(self::t($lang, 'PAYMENT'), self::t($lang, 'BALANCE')));
        } elseif ($headerVariant === 2) {
            $header = array_merge($header, array(self::t($lang, 'INTEREST'), self::t($lang, 'PAYMENT'), self::t($lang, 'BALANCE')));
        } else {
            $header = array_merge($header, array(self::t($lang, 'INTEREST'), self::t($lang, 'PAYMENT'), self::t($lang, 'STAGE_BALANCE'), self::t($lang, 'TOTAL_BALANCE')));
        }
        $rows = array();
        $lines = explode('=', isset($data['graf']) ? (string)$data['graf'] : '');
        for ($j = 0; $j < count($lines) - 1; $j++) {
            $cells = explode('|', $lines[$j]);
            $rows[] = array_map(array(__CLASS__, 'norm'), array_slice($cells, 0, count($cells) - 1));
        }
        return array('header' => array_map(array(__CLASS__, 'norm'), $header), 'rows' => $rows, 'headerColor' => self::HEADER_COLOR);
    }

    // calculator.php finishedGraf(): დაფარული გრაფიკი, ჩანს 5 სვეტი (ბალანსის სვეტები დამალულია)
    private static function finishedTable($lang, $archived)
    {
        $header = array('#', self::t($lang, 'DATE'), self::t($lang, 'PRINCIPAL'), self::t($lang, 'INTEREST'), self::t($lang, 'PAYMENT'));
        $rows = array();
        $starter = 0;
        foreach (explode('=', isset($archived['graf']) ? (string)$archived['graf'] : '') as $index => $line) {
            $cells = explode('|', $line);
            if (count($cells) > 2) {
                $row = array((string)$index);
                for ($j = $starter; $j < count($cells) - 1; $j++) {
                    $row[] = self::norm($cells[$j]);
                }
                $rows[] = $row;
            }
            $starter = 1;
        }
        return array('header' => array_map(array(__CLASS__, 'norm'), $header), 'rows' => $rows, 'headerColor' => self::HEADER_COLOR);
    }

    // ---- წყაროები ------------------------------------------------------------------------------------------------

    private static function registration($dealId)
    {
        $row = \CIBlockElement::GetList(
            array(),
            array('IBLOCK_ID' => self::IBLOCK_REGISTRATION, 'PROPERTY_DEAL' => $dealId, 'CHECK_PERMISSIONS' => 'N'),
            false,
            array('nTopCount' => 1),
            array('ID', 'PROPERTY_DATA')
        )->Fetch();
        return $row ? self::decode($row['PROPERTY_DATA_VALUE']) : null;
    }

    // calculator.php getLastArchivedGraf(): ლისტი 108-ის იგივე მოთხოვნა (დალაგების გარეშე) და მისი ბოლო ჩანაწერი
    private static function lastArchived($dealId)
    {
        $last = null;
        $res = \CIBlockElement::GetList(
            array(),
            array('IBLOCK_ID' => self::IBLOCK_ARCHIVE, 'PROPERTY_DEAL' => $dealId, 'CHECK_PERMISSIONS' => 'N'),
            false,
            false,
            array('ID', 'PROPERTY_DATA')
        );
        while ($row = $res->Fetch()) {
            $last = $row;
        }
        return $last ? self::decode($last['PROPERTY_DATA_VALUE']) : null;
    }

    private static function decode($value)
    {
        if (is_array($value)) {
            $value = isset($value['TEXT']) ? $value['TEXT'] : reset($value);
        }
        $json = json_decode(str_replace('&quot;', '"', (string)$value), true);
        return is_array($json) ? $json : null;
    }

    private static function lang()
    {
        static $lang = null;
        if ($lang === null) {
            $CALC_LANG = null;
            include $_SERVER['DOCUMENT_ROOT'] . self::CALCULATOR_LANG; // მხოლოდ $CALC_LANG მასივს განსაზღვრავს
            if (!is_array($CALC_LANG) && isset($GLOBALS['CALC_LANG'])) {
                $CALC_LANG = $GLOBALS['CALC_LANG'];
            }
            $lang = (is_array($CALC_LANG) && isset($CALC_LANG[self::LANG])) ? $CALC_LANG[self::LANG] : array();
        }
        return $lang;
    }

    private static function t($lang, $key)
    {
        return isset($lang[$key]) ? (string)$lang[$key] : '';
    }

    // calculator.php-ის ველების ბლოკები: [ბლოკის id => [[id, label, value, placeholder, disabled], ...]] — წარწერა
    // (<p class="table-cell-head-text">) და მის შემდეგ პირველი <input> (checkbox-ები/გადამრთველები PDF-ში არ შედის)
    private static function page($lang)
    {
        static $page = null;
        if ($page !== null) {
            return $page;
        }
        $page = array();
        $src = (string)@file_get_contents($_SERVER['DOCUMENT_ROOT'] . self::CALCULATOR);
        // PHP ჩანართი "echo $CALC_LANG[$lang]['KEY']" → ტექსტი; დანარჩენი PHP ჩანართები ქრება
        $src = preg_replace_callback(
            '/<\?(?:php)?\s*echo\s+\$CALC_LANG\[\$lang\]\[["\'](\w+)["\']\]\s*;?\s*\?>/',
            function ($m) use ($lang) { return htmlspecialchars(isset($lang[$m[1]]) ? $lang[$m[1]] : '', ENT_QUOTES); },
            $src
        );
        $src = preg_replace('/<\?(?:php)?.*?\?>/s', '', $src);

        foreach (self::$types as $type) {
            $start = strpos($src, '<div id="' . $type[0] . '"');
            if ($start === false) {
                continue;
            }
            $end = strlen($src);
            foreach (array('<div id="inputs_', '<div id="draftImgs"', '<div class="calitcbtn-block"') as $stop) {
                $p = strpos($src, $stop, $start + 10);
                if ($p !== false && $p < $end) {
                    $end = $p;
                }
            }
            $block = substr($src, $start, $end - $start);
            $inputs = array();
            $parts = preg_split('/<p class="table-cell-head-text">/', $block);
            array_shift($parts);
            foreach ($parts as $part) {
                $labelEnd = strpos($part, '</p>');
                if ($labelEnd === false) {
                    continue;
                }
                $label = self::norm(html_entity_decode(strip_tags(substr($part, 0, $labelEnd)), ENT_QUOTES, 'UTF-8'));
                if (!preg_match('/<input\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/', substr($part, $labelEnd), $tag)) {
                    continue;
                }
                $attr = self::attributes($tag[0]);
                $kind = isset($attr['type']) ? strtolower($attr['type']) : 'text';
                if (in_array($kind, array('checkbox', 'radio', 'hidden', 'button', 'submit'), true) || empty($attr['id']) || $label === '') {
                    continue;
                }
                $inputs[] = array(
                    'id' => $attr['id'],
                    'label' => $label,
                    'value' => isset($attr['value']) ? $attr['value'] : '',
                    'placeholder' => self::norm(isset($attr['placeholder']) ? $attr['placeholder'] : ''),
                    'disabled' => array_key_exists('disabled', $attr),
                );
            }
            $page[$type[0]] = $inputs;
        }
        return $page;
    }

    private static function attributes($tag)
    {
        $attr = array();
        preg_match_all('/([\w-]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', substr($tag, 6), $m, PREG_SET_ORDER);
        foreach ($m as $a) {
            $value = isset($a[2]) && $a[2] !== '' ? $a[2] : (isset($a[3]) && $a[3] !== '' ? $a[3] : (isset($a[4]) ? $a[4] : ''));
            $attr[strtolower($a[1])] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        }
        return $attr;
    }

    public static function norm($s)
    {
        return trim(preg_replace('/\s+/u', ' ', (string)$s));
    }

    // ---- Node: pdf/render.js ------------------------------------------------------------------------------------

    private static function render(array $payload, \DateTime $now)
    {
        $script = dirname(__DIR__) . '/pdf/render.js';
        $input = json_encode(array('payload' => $payload, 'now' => $now->getTimestamp() * 1000), JSON_UNESCAPED_UNICODE);
        $env = array('TZ' => 'Asia/Tbilisi', 'PATH' => '/usr/bin:/bin');
        $proc = @proc_open(
            escapeshellarg(self::NODE_BIN) . ' ' . escapeshellarg($script),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            null,
            $env
        );
        if (!is_resource($proc)) {
            error_log('[archi.dealapi] schedule pdf: cannot start node');
            return null;
        }
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $deadline = microtime(true) + self::NODE_TIMEOUT;
        while (true) {
            $out .= (string)stream_get_contents($pipes[1]);
            $err .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $out .= (string)stream_get_contents($pipes[1]);
                $err .= (string)stream_get_contents($pipes[2]);
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc);
                error_log('[archi.dealapi] schedule pdf: node timeout');
                break;
            }
            usleep(20000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $exit = (isset($status['exitcode']) && $status['exitcode'] >= 0) ? $status['exitcode'] : $exit;
        $out = trim($out);
        $bin = base64_decode($out, true);
        if ($exit !== 0 || $bin === false || strncmp($bin, '%PDF', 4) !== 0) {
            error_log('[archi.dealapi] schedule pdf: node exit ' . $exit . ': ' . substr($err, 0, 500));
            return null;
        }
        return $out;
    }
}
