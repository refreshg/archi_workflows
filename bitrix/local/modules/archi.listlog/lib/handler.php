<?php
// ARCHI — სია 111-ის (Project Detailed info) ცვლილებების ჟურნალი სია 234-ში („პროექტების ცვლილებების ჟურნალი“).
//
// ყოველ ცვლილებაზე (ფორმა, ბიზნეს-პროცესი, REST, აგენტი) ვიღებთ ელემენტის ველების „სურათს“ შეცვლამდე და შემდეგ,
// ვადარებთ და თითო შეცვლილ ველზე ვწერთ ერთ ჩანაწერს: ვინ, როდის, რომელი ველი, რა იყო → რა გახდა, წყარო.
// ასევე ვწერთ ელემენტის შექმნას და წაშლას.
//
// შენახვას არ ვაფერხებთ: შედარება იაფია, ხოლო მნიშვნელობების ფორმატირება და ჩაწერა ხდება პასუხის გაგზავნის შემდეგ
// (addBackgroundJob). ნებისმიერი ჩვენი შეცდომა ჩუმად ილოგება error_log-ში და სიის შენახვას არ აჩერებს.
// სხვის კოდს არ ვეხებით — მხოლოდ iblock მოდულის მოვლენები. Uninstall აბრუნებს ყველაფერს.

namespace Archi\ListLog;

use Bitrix\Main\Application;

class Handler
{
    const SOURCE_IBLOCK_ID = 111;
    const LOG_IBLOCK_ID = 234;

    // ტექნიკური ველები, რომლებსაც არ ვწერთ
    const SKIP_PROPERTY_CODES = array('SECTIONID');

    private static $iblockOf = array();
    private static $snap = array();
    private static $depth = array();

    // ---- მოვლენები -------------------------------------------------------------------------------------------

    public static function onBeforeUpdate(&$arFields)
    {
        $dbgIblock = isset($arFields['IBLOCK_ID']) ? (int)$arFields['IBLOCK_ID'] : 0;
        if ($dbgIblock === self::SOURCE_IBLOCK_ID || $dbgIblock === 0) {
            self::debug('event OnBeforeIBlockElementUpdate iblock=' . $dbgIblock . ' #' . (isset($arFields['ID']) ? $arFields['ID'] : '?'));
        }
        self::safe(function () use ($arFields) {
            self::begin(isset($arFields['ID']) ? (int)$arFields['ID'] : 0, isset($arFields['IBLOCK_ID']) ? (int)$arFields['IBLOCK_ID'] : 0);
        });
        return true;
    }

    public static function onAfterUpdate(&$arFields)
    {
        if (isset($arFields['IBLOCK_ID']) && (int)$arFields['IBLOCK_ID'] === self::SOURCE_IBLOCK_ID) {
            self::debug('event OnAfterIBlockElementUpdate #' . $arFields['ID'] . ' result=' . var_export(!empty($arFields['RESULT']), true));
        }
        self::safe(function () use ($arFields) {
            self::end(isset($arFields['ID']) ? (int)$arFields['ID'] : 0, !empty($arFields['RESULT']), 'შეცვლა');
        });
    }

    public static function onBeforeSetProps($elementId, $iblockId)
    {
        self::safe(function () use ($elementId, $iblockId) {
            self::begin((int)$elementId, (int)$iblockId);
        });
    }

    public static function onAfterSetProps($elementId, $iblockId)
    {
        self::safe(function () use ($elementId) {
            self::end((int)$elementId, true, 'შეცვლა');
        });
    }

    public static function onAfterAdd(&$arFields)
    {
        self::safe(function () use ($arFields) {
            $id = isset($arFields['ID']) ? (int)$arFields['ID'] : 0;
            if ($id <= 0 || empty($arFields['RESULT']) || (int)$arFields['IBLOCK_ID'] !== self::SOURCE_IBLOCK_ID) {
                return;
            }
            $new = self::loadState($id);
            if ($new) {
                self::queue($id, self::emptyLike($new), $new, 'შექმნა', self::context());
            }
        });
    }

    public static function onBeforeDelete($id)
    {
        self::safe(function () use ($id) {
            $id = (int)$id;
            if (self::iblockOf($id, 0) !== self::SOURCE_IBLOCK_ID) {
                return;
            }
            $old = self::loadState($id);
            if ($old) {
                $ctx = self::context();
                $ctx['deleted'] = true;
                self::queue($id, $old, self::emptyLike($old), 'წაშლა', $ctx);
            }
        });
        return true;
    }

    // ---- სურათი და შედარება ----------------------------------------------------------------------------------

    private static function begin($id, $iblockId)
    {
        if ($id <= 0 || self::iblockOf($id, $iblockId) !== self::SOURCE_IBLOCK_ID) {
            return;
        }
        if (!isset(self::$depth[$id])) {
            self::$depth[$id] = 0;
            self::$snap[$id] = array('state' => self::loadState($id), 'ctx' => self::context());
        }
        self::$depth[$id]++;
        self::debug('begin #' . $id . ' depth=' . self::$depth[$id] . ' state=' . (self::$snap[$id]['state'] ? count(self::$snap[$id]['state']) : 'null'));
    }

    // დროებითი დიაგნოსტიკა (DEBUG=false-ით ითიშება)
    const DEBUG = false;

    private static function debug($message)
    {
        if (self::DEBUG) {
            @file_put_contents(
                $_SERVER['DOCUMENT_ROOT'] . '/upload/archi_listlog_debug.log',
                date('H:i:s') . ' ' . $message . "\n",
                FILE_APPEND
            );
        }
    }

    private static function end($id, $ok, $action)
    {
        if ($id <= 0 || !isset(self::$depth[$id])) {
            return;
        }
        self::$depth[$id]--;
        self::debug('end #' . $id . ' ok=' . ($ok ? 1 : 0) . ' depth=' . self::$depth[$id]);
        if (self::$depth[$id] > 0) {
            return; // Update შიგნით SetPropertyValues-იც იძახება — ვწერთ მხოლოდ გარე დასრულებაზე
        }
        $snap = self::$snap[$id];
        unset(self::$depth[$id], self::$snap[$id]);
        if (!$ok || empty($snap['state'])) {
            return;
        }
        $new = self::loadState($id);
        if ($new) {
            self::queue($id, $snap['state'], $new, $action, $snap['ctx']);
        }
    }

    private static function iblockOf($id, $iblockId)
    {
        if ($iblockId > 0) {
            return self::$iblockOf[$id] = $iblockId;
        }
        if (!isset(self::$iblockOf[$id])) {
            self::$iblockOf[$id] = (int)\CIBlockElement::GetIBlockByID($id);
        }
        return self::$iblockOf[$id];
    }

    // ელემენტის ველები + ყველა property: [key => ['name', 'type', 'link', 'values' => [...]]]
    private static function loadState($id)
    {
        $el = \CIBlockElement::GetList(
            array(),
            array('ID' => $id, 'IBLOCK_ID' => self::SOURCE_IBLOCK_ID, 'CHECK_PERMISSIONS' => 'N'),
            false,
            false,
            array('ID', 'NAME', 'ACTIVE', 'SORT', 'IBLOCK_SECTION_ID', 'PREVIEW_TEXT', 'DETAIL_TEXT')
        )->Fetch();
        if (!$el) {
            return null;
        }
        $state = array();
        $fieldNames = array(
            'NAME' => 'Name', 'ACTIVE' => 'აქტიური', 'SORT' => 'Sort',
            'IBLOCK_SECTION_ID' => 'სექცია', 'PREVIEW_TEXT' => 'Preview text', 'DETAIL_TEXT' => 'Detail text',
        );
        foreach ($fieldNames as $code => $name) {
            $v = isset($el[$code]) ? (string)$el[$code] : '';
            $state[$code] = array('name' => $name, 'type' => 'FIELD', 'link' => 0, 'values' => $v === '' ? array() : array($v));
        }
        $res = \CIBlockElement::GetProperty(self::SOURCE_IBLOCK_ID, $id, array('sort' => 'asc', 'id' => 'asc'), array());
        while ($p = $res->Fetch()) {
            if (in_array((string)$p['CODE'], self::SKIP_PROPERTY_CODES, true)) {
                continue;
            }
            $key = 'PROPERTY_' . $p['ID'];
            if (!isset($state[$key])) {
                $type = $p['PROPERTY_TYPE'] . ($p['USER_TYPE'] ? ':' . $p['USER_TYPE'] : '');
                $state[$key] = array('name' => $p['NAME'], 'type' => $type, 'link' => (int)$p['LINK_IBLOCK_ID'], 'values' => array());
            }
            $v = $p['PROPERTY_TYPE'] === 'L' ? $p['VALUE_ENUM'] : $p['VALUE'];
            if (is_array($v)) {
                $v = isset($v['TEXT']) ? $v['TEXT'] : json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            if ($v !== null && $v !== false && (string)$v !== '') {
                $state[$key]['values'][] = (string)$v;
            }
        }
        return $state;
    }

    private static function emptyLike(array $state)
    {
        foreach ($state as $k => $f) {
            $state[$k]['values'] = array();
        }
        return $state;
    }

    private static function queue($id, array $old, array $new, $action, array $ctx)
    {
        $changes = array();
        foreach ($new + $old as $key => $f) {
            $a = isset($old[$key]) ? $old[$key]['values'] : array();
            $b = isset($new[$key]) ? $new[$key]['values'] : array();
            $sa = $a;
            $sb = $b;
            sort($sa);
            sort($sb);
            if ($sa === $sb) {
                continue;
            }
            $meta = isset($new[$key]) ? $new[$key] : $old[$key];
            $changes[] = array('code' => $key, 'name' => $meta['name'], 'type' => $meta['type'], 'link' => $meta['link'], 'old' => $a, 'new' => $b);
        }
        self::debug('queue #' . $id . ' changes=' . count($changes));
        if (!$changes) {
            return;
        }
        $projectName = '';
        foreach (array($new, $old) as $s) {
            if (!empty($s['NAME']['values'])) {
                $projectName = $s['NAME']['values'][0];
                break;
            }
        }
        // პირდაპირ ვწერთ: სიის ფორმა შენახვის შემდეგ გადამისამართებით სრულდება და background job შეიძლება არ შესრულდეს
        self::write($id, $projectName, $action, $changes, $ctx);
    }

    // ---- ჩაწერა ----------------------------------------------------------------------------------------------

    public static function write($id, $projectName, $action, array $changes, array $ctx)
    {
        self::safe(function () use ($id, $projectName, $action, $changes, $ctx) {
            if (!\CModule::IncludeModule('iblock')) {
                return;
            }
            $el = new \CIBlockElement();
            foreach ($changes as $c) {
                $props = array(
                    'PROJECT' => empty($ctx['deleted']) ? $id : '',
                    'PROJECT_ID' => $id,
                    'ACTION' => $action,
                    'FIELD_NAME' => $c['name'],
                    'FIELD_CODE' => $c['code'],
                    'OLD_VALUE' => self::cut(self::format($c, $c['old'])),
                    'NEW_VALUE' => self::cut(self::format($c, $c['new'])),
                    'CHANGED_BY' => $ctx['user'] > 0 ? $ctx['user'] : '',
                    'CHANGED_AT' => $ctx['time'],
                    'SOURCE' => $ctx['source'],
                    'REQUEST_URI' => self::cut($ctx['uri'], 250),
                );
                $name = self::cut(($projectName !== '' ? $projectName : '#' . $id) . ': ' . $c['name'], 250);
                $newId = $el->Add(array('IBLOCK_ID' => self::LOG_IBLOCK_ID, 'NAME' => $name, 'ACTIVE' => 'Y', 'PROPERTY_VALUES' => $props), false, false, false);
                self::debug('write add -> ' . var_export($newId, true) . ' ' . strip_tags((string)$el->LAST_ERROR));
                if (!$newId) {
                    error_log('[archi.listlog] add failed: ' . $el->LAST_ERROR);
                    self::logError('ჩანაწერი ვერ დაემატა: ' . strip_tags($el->LAST_ERROR));
                }
            }
        });
    }

    private static function format(array $c, array $values)
    {
        if (!$values) {
            return '(ცარიელი)';
        }
        $out = array();
        foreach ($values as $v) {
            $out[] = self::formatOne($c['type'], $c['link'], $v);
        }
        return implode(', ', $out);
    }

    private static function formatOne($type, $link, $v)
    {
        try {
            if ($type === 'S:employee' && (int)$v > 0) {
                $u = \CUser::GetByID((int)$v)->Fetch();
                return $u ? trim($u['NAME'] . ' ' . $u['LAST_NAME']) . ' [' . (int)$v . ']' : $v;
            }
            if (($type === 'E' || strpos($type, 'E:') === 0) && (int)$v > 0) {
                $e = \CIBlockElement::GetList(array(), array('ID' => (int)$v, 'CHECK_PERMISSIONS' => 'N'), false, false, array('ID', 'NAME'))->Fetch();
                return $e ? $e['NAME'] . ' [' . (int)$v . ']' : $v;
            }
            if ($type === 'F' && (int)$v > 0) {
                $f = \CFile::GetFileArray((int)$v);
                return $f ? $f['ORIGINAL_NAME'] . ' [ფაილი ' . (int)$v . ']' : $v;
            }
            if ($type === 'S:ECrm' && \CModule::IncludeModule('crm')) {
                $parts = explode('_', (string)$v, 2);
                if (count($parts) === 2) {
                    $typeId = \CCrmOwnerTypeAbbr::ResolveTypeID($parts[0]);
                    $caption = $typeId ? \CCrmOwnerType::GetCaption($typeId, (int)$parts[1], false) : '';
                    return ($caption !== '' ? $caption . ' ' : '') . '[' . $v . ']';
                }
            }
        } catch (\Throwable $e) {
        }
        return (string)$v;
    }

    // ---- დამხმარე -------------------------------------------------------------------------------------------

    private static function context()
    {
        global $USER;
        $user = (is_object($USER) && $USER->IsAuthorized()) ? (int)$USER->GetID() : 0;
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        $source = 'ხელით';
        if (PHP_SAPI === 'cli' || (defined('BX_CRONTAB') && BX_CRONTAB === true)) {
            $source = 'აგენტი / cron';
        } elseif (strpos($uri, '/rest/') === 0) {
            $source = 'REST';
        } else {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                $class = isset($frame['class']) ? ltrim($frame['class'], '\\') : '';
                if ($class === 'CRestServer') {
                    $source = 'REST';
                    break;
                }
                if ($class !== '' && (strpos($class, 'CBP') === 0 || stripos($class, 'Bitrix\\Bizproc') === 0 || $class === 'CAgent')) {
                    $source = $class === 'CAgent' ? 'აგენტი / cron' : 'ბიზნეს-პროცესი';
                    break;
                }
            }
        }
        return array('user' => $user, 'time' => ConvertTimeStamp(time(), 'FULL'), 'source' => $source, 'uri' => $uri);
    }

    private static function cut($s, $max = 2000)
    {
        $s = (string)$s;
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    private static function safe(callable $fn)
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            error_log('[archi.listlog] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            self::debug('EXCEPTION ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            self::logError($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    // შეცდომა ჟურნალშივე ჩანდეს, რომ დიაგნოსტიკა სერვერის ლოგის გარეშე შეიძლებოდეს
    private static function logError($message)
    {
        try {
            if (!\CModule::IncludeModule('iblock')) {
                return;
            }
            $el = new \CIBlockElement();
            $el->Add(array(
                'IBLOCK_ID' => self::LOG_IBLOCK_ID,
                'NAME' => 'შეცდომა (archi.listlog)',
                'ACTIVE' => 'Y',
                'PROPERTY_VALUES' => array(
                    'ACTION' => 'შეცდომა',
                    'NEW_VALUE' => self::cut($message),
                    'CHANGED_AT' => ConvertTimeStamp(time(), 'FULL'),
                    'REQUEST_URI' => self::cut(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', 250),
                ),
            ), false, false, false);
        } catch (\Throwable $e) {
        }
    }
}
