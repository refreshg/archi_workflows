<?php
// ARCHI — read-only REST API for partner Nik: three projects only, their units, base price and status.
// Scope „archipartner“: a webhook created with only this scope sees these two methods and nothing else in the CRM.
//
//   POST /rest/<user>/<code>/archi.partner.project.list.json   {}
//   POST /rest/<user>/<code>/archi.partner.unit.list.json      {"project_id": 3, "status": ["available"], "after_id": 0, "limit": 200}
//
// User, 2026-11-20: expose ONLY Trump Tower, King Tamar and Sioni Lake, strictly, built directly on their
// catalog sections (not on list 123). Everything else is invisible: other ids → 404, no filter → only these.
// Data: catalog iblock 26 (section 102 „ბინები“ → one section per building/block). Price = catalog BASE price (USD).
// Sold units never carry a price. Nothing is written. Partner documentation: docs/partner-api.md.

namespace Archi\PartnerApi;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Rest\RestException;

class Rest
{
    const SCOPE = 'archipartner';

    const IBLOCK_CATALOG = 26;
    const PRICE_GROUP = 1;            // BASE

    // The only projects the API exposes. project id => name + blocks (block id = catalog section id => block name).
    const PROJECTS = array(
        1 => array('name' => 'Trump Tower', 'blocks' => array(596 => 'Trump Tower')),
        2 => array('name' => 'King Tamar', 'blocks' => array(570 => 'King Tamar')),
        3 => array('name' => 'Sioni Lake', 'blocks' => array(571 => 'A', 555 => 'B', 586 => 'C', 582 => 'E')),
    );

    // catalog (unit) properties
    const U_FLOOR = 376, U_NUMBER = 377, U_AREA_INSIDE = 379, U_AREA_SUMMER = 380, U_AREA_TOTAL = 381, U_SQM_PRICE = 382,
        U_TYPE = 383, U_ENTRANCE = 384, U_ROOMS = 385, U_STATUS = 429, U_VIEW = 452, U_VIEW_EN = 1024, U_VIEW_RU = 1025;

    const STATUS_CODES = array(811 => 'available', 819 => 'reserved', 810 => 'sold', 833 => 'not_in_sale', 1324 => 'no_price');

    const LIMIT_DEFAULT = 200;
    const LIMIT_MAX = 500;
    const CACHE_TTL = 300;

    public static function describe()
    {
        return array(
            self::SCOPE => array(
                'archi.partner.project.list' => array('callback' => array(__CLASS__, 'projectList'), 'options' => array()),
                'archi.partner.unit.list' => array('callback' => array(__CLASS__, 'unitList'), 'options' => array()),
            ),
        );
    }

    // ---------------------------------------------------------------------------------------------
    // archi.partner.project.list
    // ---------------------------------------------------------------------------------------------
    public static function projectList($query, $nav, \CRestServer $server)
    {
        $cache = Cache::createInstance();
        if ($cache->initCache(self::CACHE_TTL, 'project_list_three_v1', '/archi.partnerapi')) {
            return $cache->getVars();
        }
        $counts = self::unitCounts(array_keys(self::sectionMap()));
        $out = array();
        foreach (self::PROJECTS as $pid => $p) {
            $total = self::emptyCounts();
            $blocks = array();
            foreach ($p['blocks'] as $sid => $name) {
                $c = $counts[$sid] ?? self::emptyCounts();
                foreach ($c as $k => $n) $total[$k] += $n;
                $blocks[] = array('id' => $sid, 'name' => $name, 'units' => $c);
            }
            $out[] = array('id' => $pid, 'name' => $p['name'], 'units' => $total, 'blocks' => $blocks);
        }
        // not 'total': Bitrix REST lifts a 'total' key out of result
        $result = array('projects' => $out, 'count' => count($out), 'generated_at' => date('c'));
        if ($cache->startDataCache()) $cache->endDataCache($result);
        return $result;
    }

    // ---------------------------------------------------------------------------------------------
    // archi.partner.unit.list
    // ---------------------------------------------------------------------------------------------
    public static function unitList($query, $nav, \CRestServer $server)
    {
        $q = array_change_key_case(is_array($query) ? $query : array(), CASE_LOWER);
        $map = self::sectionMap();

        // which catalog sections — never anything outside PROJECTS
        if (isset($q['block_id']) && $q['block_id'] !== '') {
            $id = self::positiveInt($q['block_id'], 'block_id');
            if (!isset($map[$id])) throw new RestException('Unknown block_id ' . $id, 'NOT_FOUND', \CRestServer::STATUS_NOT_FOUND);
            $sections = array($id);
        } elseif (isset($q['project_id']) && $q['project_id'] !== '') {
            $id = self::positiveInt($q['project_id'], 'project_id');
            if (!isset(self::PROJECTS[$id])) throw new RestException('Unknown project_id ' . $id, 'NOT_FOUND', \CRestServer::STATUS_NOT_FOUND);
            $sections = array_keys(self::PROJECTS[$id]['blocks']);
        } else {
            $sections = array_keys($map);
        }

        $statusEnums = null;
        if (isset($q['status']) && $q['status'] !== '' && $q['status'] !== array()) {
            $wanted = is_array($q['status']) ? $q['status'] : explode(',', (string)$q['status']);
            $codes = array_flip(self::STATUS_CODES);
            $statusEnums = array();
            foreach ($wanted as $s) {
                $s = strtolower(trim((string)$s));
                if (!isset($codes[$s])) {
                    throw new RestException('Unknown status "' . $s . '". Allowed: ' . implode(', ', self::STATUS_CODES), 'INVALID_STATUS', \CRestServer::STATUS_WRONG_REQUEST);
                }
                $statusEnums[] = (int)$codes[$s];
            }
        }
        $type = isset($q['type']) && $q['type'] !== '' ? strtolower(trim((string)$q['type'])) : null;
        if ($type !== null && !in_array($type, array('apartment', 'parking', 'commercial', 'office', 'other'), true)) {
            throw new RestException('Unknown type "' . $type . '". Allowed: apartment, parking, commercial, office, other', 'INVALID_TYPE', \CRestServer::STATUS_WRONG_REQUEST);
        }
        $after = isset($q['after_id']) && $q['after_id'] !== '' ? max(0, (int)$q['after_id']) : 0;
        $limit = isset($q['limit']) && $q['limit'] !== '' ? (int)$q['limit'] : self::LIMIT_DEFAULT;
        $limit = max(1, min(self::LIMIT_MAX, $limit));

        $sql = 'SELECT e.ID, e.NAME, e.IBLOCK_SECTION_ID, e.TIMESTAMP_X FROM b_iblock_element e';
        if ($statusEnums !== null) {
            $sql .= ' JOIN b_iblock_element_property st ON st.IBLOCK_ELEMENT_ID = e.ID AND st.IBLOCK_PROPERTY_ID = ' . self::U_STATUS
                . ' AND st.VALUE_ENUM IN (' . implode(',', $statusEnums) . ')';
        }
        if ($type !== null) {
            $sql .= ' LEFT JOIN b_iblock_element_property tp ON tp.IBLOCK_ELEMENT_ID = e.ID AND tp.IBLOCK_PROPERTY_ID = ' . self::U_TYPE;
        }
        $sql .= ' WHERE e.IBLOCK_ID = ' . self::IBLOCK_CATALOG . " AND e.ACTIVE = 'Y'"
            . ' AND e.IBLOCK_SECTION_ID IN (' . implode(',', array_map('intval', $sections)) . ')'
            . ' AND e.ID > ' . $after;
        if ($type !== null) $sql .= ' AND ' . self::typeSql('tp.VALUE', $type);
        $sql .= ' ORDER BY e.ID LIMIT ' . ($limit + 1);

        $rows = array();
        $res = Application::getConnection()->query($sql);
        while ($r = $res->fetch()) $rows[(int)$r['ID']] = $r;
        $hasMore = count($rows) > $limit;
        if ($hasMore) $rows = array_slice($rows, 0, $limit, true);

        $ids = array_keys($rows);
        $props = self::unitProps($ids);
        $prices = self::prices($ids);

        $units = array();
        foreach ($rows as $id => $r) {
            $p = $props[$id] ?? array();
            $sid = (int)$r['IBLOCK_SECTION_ID'];
            $where = $map[$sid];
            $statusEnum = isset($p[self::U_STATUS]) ? (int)$p[self::U_STATUS]['enum'] : 0;
            $status = self::STATUS_CODES[$statusEnum] ?? 'unknown';
            $sold = $status === 'sold';
            $price = $prices[$id] ?? null;
            $sqm = self::money($p[self::U_SQM_PRICE]['v'] ?? null);
            $typeLabel = $p[self::U_TYPE]['v'] ?? null;
            $updated = $r['TIMESTAMP_X'] instanceof \Bitrix\Main\Type\DateTime ? $r['TIMESTAMP_X'] : null;
            if ($price && $price['ts'] && (!$updated || $price['ts']->getTimestamp() > $updated->getTimestamp())) $updated = $price['ts'];

            $units[] = array(
                'id' => $id,
                'number' => (string)($p[self::U_NUMBER]['v'] ?? $r['NAME']),
                'project_id' => $where['project_id'],
                'project_name' => $where['project_name'],
                'block_id' => $sid,
                'block_name' => $where['block_name'],
                'type' => self::typeCode($typeLabel),
                'type_label' => $typeLabel,
                'floor' => self::num($p[self::U_FLOOR]['v'] ?? null),
                'rooms' => self::num($p[self::U_ROOMS]['v'] ?? null),
                'area_total_m2' => self::num($p[self::U_AREA_TOTAL]['v'] ?? null),
                'area_inside_m2' => self::num($p[self::U_AREA_INSIDE]['v'] ?? null),
                'area_summer_m2' => self::num($p[self::U_AREA_SUMMER]['v'] ?? null),
                'entrance' => $p[self::U_ENTRANCE]['v'] ?? null,
                'view' => array('ka' => $p[self::U_VIEW]['v'] ?? null, 'en' => $p[self::U_VIEW_EN]['v'] ?? null, 'ru' => $p[self::U_VIEW_RU]['v'] ?? null),
                'status' => $status,
                'price_usd' => (!$sold && $price && $price['price'] > 0) ? $price['price'] : null,
                'price_per_m2_usd' => (!$sold && $sqm > 0) ? $sqm : null,
                'updated_at' => $updated ? $updated->format('c') : null,
            );
        }

        return array('units' => $units, 'count' => count($units), 'next_after_id' => $hasMore ? end($ids) : null);
    }

    // ---------------------------------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------------------------------

    // catalog section id => project / block names
    private static function sectionMap()
    {
        $map = array();
        foreach (self::PROJECTS as $pid => $p) {
            foreach ($p['blocks'] as $sid => $name) {
                $map[$sid] = array('project_id' => $pid, 'project_name' => $p['name'], 'block_name' => $name);
            }
        }
        return $map;
    }

    private static function emptyCounts()
    {
        return array('total' => 0, 'available' => 0, 'reserved' => 0, 'sold' => 0, 'not_in_sale' => 0, 'no_price' => 0, 'unknown' => 0);
    }

    private static function unitCounts($sections)
    {
        $out = array();
        if (!$sections) return $out;
        $res = Application::getConnection()->query('SELECT e.IBLOCK_SECTION_ID s, st.VALUE_ENUM en, COUNT(*) c FROM b_iblock_element e'
            . ' LEFT JOIN b_iblock_element_property st ON st.IBLOCK_ELEMENT_ID = e.ID AND st.IBLOCK_PROPERTY_ID = ' . self::U_STATUS
            . ' WHERE e.IBLOCK_ID = ' . self::IBLOCK_CATALOG . " AND e.ACTIVE = 'Y' AND e.IBLOCK_SECTION_ID IN (" . implode(',', array_map('intval', $sections)) . ')'
            . ' GROUP BY e.IBLOCK_SECTION_ID, st.VALUE_ENUM');
        while ($r = $res->fetch()) {
            $s = (int)$r['s'];
            if (!isset($out[$s])) $out[$s] = self::emptyCounts();
            $code = self::STATUS_CODES[(int)$r['en']] ?? 'unknown';
            $out[$s][$code] += (int)$r['c'];
            $out[$s]['total'] += (int)$r['c'];
        }
        return $out;
    }

    private static function unitProps($ids)
    {
        $out = array();
        if (!$ids) return $out;
        $pids = array(self::U_FLOOR, self::U_NUMBER, self::U_AREA_INSIDE, self::U_AREA_SUMMER, self::U_AREA_TOTAL, self::U_SQM_PRICE,
            self::U_TYPE, self::U_ENTRANCE, self::U_ROOMS, self::U_STATUS, self::U_VIEW, self::U_VIEW_EN, self::U_VIEW_RU);
        $res = Application::getConnection()->query('SELECT IBLOCK_ELEMENT_ID, IBLOCK_PROPERTY_ID, VALUE, VALUE_ENUM FROM b_iblock_element_property'
            . ' WHERE IBLOCK_ELEMENT_ID IN (' . implode(',', array_map('intval', $ids)) . ') AND IBLOCK_PROPERTY_ID IN (' . implode(',', $pids) . ')');
        while ($r = $res->fetch()) {
            $v = $r['VALUE'];
            $out[(int)$r['IBLOCK_ELEMENT_ID']][(int)$r['IBLOCK_PROPERTY_ID']] = array('v' => ($v === '' ? null : $v), 'enum' => $r['VALUE_ENUM']);
        }
        return $out;
    }

    private static function prices($ids)
    {
        $out = array();
        if (!$ids) return $out;
        $res = Application::getConnection()->query('SELECT PRODUCT_ID, PRICE, CURRENCY, TIMESTAMP_X FROM b_catalog_price WHERE CATALOG_GROUP_ID = ' . self::PRICE_GROUP
            . ' AND PRODUCT_ID IN (' . implode(',', array_map('intval', $ids)) . ')');
        while ($r = $res->fetch()) {
            $out[(int)$r['PRODUCT_ID']] = array(
                'price' => round((float)$r['PRICE'], 2),
                'ts' => $r['TIMESTAMP_X'] instanceof \Bitrix\Main\Type\DateTime ? $r['TIMESTAMP_X'] : null,
            );
        }
        return $out;
    }

    private static function typeCode($label)
    {
        $l = mb_strtolower(trim((string)$label));
        if ($l === 'ბინა') return 'apartment';
        if (mb_strpos($l, 'პარკინგ') !== false) return 'parking';
        if ($l === 'კომერციული') return 'commercial';
        if (mb_strpos($l, 'საოფისე') !== false) return 'office';
        return 'other';
    }

    private static function typeSql($col, $type)
    {
        switch ($type) {
            case 'apartment': return $col . " = 'ბინა'";
            case 'parking': return $col . " LIKE '%პარკინგ%'";
            case 'commercial': return $col . " = 'კომერციული'";
            case 'office': return $col . " LIKE 'საოფისე%'";
            default: return '(' . $col . " IS NULL OR (" . $col . " <> 'ბინა' AND " . $col . " NOT LIKE '%პარკინგ%' AND " . $col . " <> 'კომერციული' AND " . $col . " NOT LIKE 'საოფისე%'))";
        }
    }

    private static function money($value)
    {
        if ($value === null || $value === '') return null;
        $n = (float)str_replace(array(',', ' '), '', explode('|', (string)$value)[0]);
        return $n > 0 ? round($n, 2) : null;
    }

    private static function num($value)
    {
        if ($value === null || $value === '' || !is_numeric(str_replace(',', '.', (string)$value))) return null;
        $n = (float)str_replace(',', '.', (string)$value);
        return floor($n) == $n ? (int)$n : $n;
    }

    private static function positiveInt($value, $name)
    {
        if (!preg_match('/^\d+$/', (string)$value) || (int)$value <= 0) {
            throw new RestException($name . ' must be a positive integer', 'INVALID_' . strtoupper($name), \CRestServer::STATUS_WRONG_REQUEST);
        }
        return (int)$value;
    }
}
