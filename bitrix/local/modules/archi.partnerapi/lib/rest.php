<?php
// ARCHI — read-only REST API for partners: projects, blocks, units (availability, base price, status).
// Scope „archipartner“: a webhook created with only this scope sees these two methods and nothing else in the CRM.
//
//   POST /rest/<user>/<code>/archi.partner.project.list.json   {}
//   POST /rest/<user>/<code>/archi.partner.unit.list.json      {"project_id": 2217206, "status": ["available"], "after_id": 0, "limit": 200}
//
// Data sources (read only, nothing is written):
//   - list 123 „Livingstone Building Types“: projects (no CONNECT_LIVING) and their blocks (CONNECT_LIVING = parent project);
//   - catalog iblock 26, section 102 „ბინები“: units; a block's PROJECT_ID (P1022) is the catalog section that holds its units.
//     Catalog sections that no list-123 element points to are not exposed (user, 2026-11-19).
//   - price: catalog BASE price (USD). Sold units never carry a price (user, 2026-11-19).
// Documentation for the partner: docs/partner-api.md in the module folder.

namespace Archi\PartnerApi;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Rest\RestException;

class Rest
{
    const SCOPE = 'archipartner';

    const IBLOCK_PROJECTS = 123;
    const IBLOCK_CATALOG = 26;
    const PRICE_GROUP = 1;            // BASE

    // list 123 properties
    const P_KIND = 1019, P_NAME_RU = 1020, P_NAME_EN = 1021, P_SECTION = 1022, P_PARENT = 1026, P_BROKER = 1751,
        P_ADDRESS = 1329, P_ADDRESS_EN = 1361, P_ADDRESS_RU = 1362, P_CITY = 1337, P_CITY_EN = 1363, P_CITY_RU = 1364,
        P_DISTRICT = 1338, P_DISTRICT_EN = 1365, P_DISTRICT_RU = 1366, P_STATUS = 1339, P_END_DATE = 1334;

    // catalog (unit) properties
    const U_FLOOR = 376, U_NUMBER = 377, U_AREA_INSIDE = 379, U_AREA_SUMMER = 380, U_AREA_TOTAL = 381, U_SQM_PRICE = 382,
        U_TYPE = 383, U_ENTRANCE = 384, U_ROOMS = 385, U_STATUS = 429, U_VIEW = 452, U_VIEW_EN = 1024, U_VIEW_RU = 1025;

    const STATUS_CODES = array(811 => 'available', 819 => 'reserved', 810 => 'sold', 833 => 'not_in_sale', 1324 => 'no_price');
    const PROJECT_STATUS = array(1429 => 'ongoing', 1428 => 'completed', 1430 => 'new');

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
        if ($cache->initCache(self::CACHE_TTL, 'project_list_v3', '/archi.partnerapi')) {
            return $cache->getVars();
        }
        $tree = self::tree();
        $counts = self::unitCounts(array_keys($tree['sections']));

        $out = array();
        foreach ($tree['projects'] as $p) {
            $item = self::describeElement($p);
            $item['units'] = $p['section'] ? ($counts[$p['section']] ?? self::emptyCounts()) : null;
            $item['blocks'] = array();
            foreach ($p['children'] as $b) {
                $block = self::describeElement($b);
                $block['units'] = $b['section'] ? ($counts[$b['section']] ?? self::emptyCounts()) : null;
                $item['blocks'][] = $block;
            }
            $out[] = $item;
        }
        $result = array('projects' => $out, 'count' => count($out), 'generated_at' => date('c'));   // not 'total': Bitrix REST lifts 'total' out of result
        if ($cache->startDataCache()) {
            $cache->endDataCache($result);
        }
        return $result;
    }

    // ---------------------------------------------------------------------------------------------
    // archi.partner.unit.list
    // ---------------------------------------------------------------------------------------------
    public static function unitList($query, $nav, \CRestServer $server)
    {
        $q = array_change_key_case(is_array($query) ? $query : array(), CASE_LOWER);
        $tree = self::tree();

        // which catalog sections
        if (!empty($q['block_id'])) {
            $id = self::positiveInt($q['block_id'], 'block_id');
            $el = $tree['all'][$id] ?? null;
            if (!$el) throw new RestException('Unknown block_id ' . $id, 'NOT_FOUND', \CRestServer::STATUS_NOT_FOUND);
            $sections = $el['section'] ? array($el['section']) : array();
        } elseif (!empty($q['project_id'])) {
            $id = self::positiveInt($q['project_id'], 'project_id');
            $el = $tree['all'][$id] ?? null;
            if (!$el) throw new RestException('Unknown project_id ' . $id, 'NOT_FOUND', \CRestServer::STATUS_NOT_FOUND);
            $sections = array();
            if ($el['section']) $sections[] = $el['section'];
            foreach ($el['children'] as $c) if ($c['section']) $sections[] = $c['section'];
        } else {
            $sections = array_keys($tree['sections']);
        }

        $statusEnums = null;
        if (isset($q['status']) && $q['status'] !== '' && $q['status'] !== array()) {
            $wanted = is_array($q['status']) ? $q['status'] : explode(',', (string)$q['status']);
            $map = array_flip(self::STATUS_CODES);
            $statusEnums = array();
            foreach ($wanted as $s) {
                $s = strtolower(trim((string)$s));
                if (!isset($map[$s])) {
                    throw new RestException('Unknown status "' . $s . '". Allowed: ' . implode(', ', self::STATUS_CODES), 'INVALID_STATUS', \CRestServer::STATUS_WRONG_REQUEST);
                }
                $statusEnums[] = (int)$map[$s];
            }
        }
        $type = isset($q['type']) && $q['type'] !== '' ? strtolower(trim((string)$q['type'])) : null;
        if ($type !== null && !in_array($type, array('apartment', 'parking', 'commercial', 'office', 'other'), true)) {
            throw new RestException('Unknown type "' . $type . '". Allowed: apartment, parking, commercial, office, other', 'INVALID_TYPE', \CRestServer::STATUS_WRONG_REQUEST);
        }
        $after = isset($q['after_id']) && $q['after_id'] !== '' ? max(0, (int)$q['after_id']) : 0;
        $limit = isset($q['limit']) && $q['limit'] !== '' ? (int)$q['limit'] : self::LIMIT_DEFAULT;
        $limit = max(1, min(self::LIMIT_MAX, $limit));

        if (!$sections) {
            return array('units' => array(), 'count' => 0, 'next_after_id' => null);
        }

        $db = Application::getConnection();
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
        if ($type !== null) {
            $sql .= ' AND ' . self::typeSql('tp.VALUE', $type);
        }
        $sql .= ' ORDER BY e.ID LIMIT ' . ($limit + 1);

        $rows = array();
        $res = $db->query($sql);
        while ($r = $res->fetch()) $rows[(int)$r['ID']] = $r;
        $hasMore = count($rows) > $limit;
        if ($hasMore) $rows = array_slice($rows, 0, $limit, true);

        $ids = array_keys($rows);
        $props = self::unitProps($ids);
        $prices = self::prices($ids);

        $units = array();
        foreach ($rows as $id => $r) {
            $p = $props[$id] ?? array();
            $section = (int)$r['IBLOCK_SECTION_ID'];
            $block = $tree['sections'][$section] ?? null;
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
                'project_id' => $block ? $block['project_id'] : null,
                'block_id' => $block ? $block['id'] : null,
                'block_name' => $block ? $block['name'] : null,
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

        return array(
            'units' => $units,
            'count' => count($units),
            'next_after_id' => $hasMore ? end($ids) : null,
        );
    }

    // ---------------------------------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------------------------------

    // list 123 → projects with their blocks; section id → block
    private static function tree()
    {
        static $tree = null;
        if ($tree !== null) return $tree;
        $cache = Cache::createInstance();
        if ($cache->initCache(self::CACHE_TTL, 'tree_v2', '/archi.partnerapi')) {
            return $tree = $cache->getVars();
        }
        $db = Application::getConnection();
        $all = array();
        $res = $db->query('SELECT ID, NAME FROM b_iblock_element WHERE IBLOCK_ID = ' . self::IBLOCK_PROJECTS . " AND ACTIVE = 'Y' ORDER BY SORT, ID");
        while ($r = $res->fetch()) {
            $all[(int)$r['ID']] = array('id' => (int)$r['ID'], 'name' => $r['NAME'], 'p' => array(), 'children' => array());
        }
        if ($all) {
            $res = $db->query('SELECT p.IBLOCK_ELEMENT_ID, p.IBLOCK_PROPERTY_ID, p.VALUE, p.VALUE_ENUM FROM b_iblock_element_property p'
                . ' WHERE p.IBLOCK_ELEMENT_ID IN (' . implode(',', array_keys($all)) . ')');
            while ($r = $res->fetch()) {
                $all[(int)$r['IBLOCK_ELEMENT_ID']]['p'][(int)$r['IBLOCK_PROPERTY_ID']] = array('v' => $r['VALUE'], 'enum' => $r['VALUE_ENUM']);
            }
        }
        $projects = array();
        foreach (array_keys($all) as $id) {
            $all[$id]['section'] = (int)($all[$id]['p'][self::P_SECTION]['v'] ?? 0);
            $all[$id]['parent'] = (int)($all[$id]['p'][self::P_PARENT]['v'] ?? 0);
        }
        // TYPE (P1019): Complex = project with blocks, Building = block of a complex,
        // IndependentBuilding = stand-alone project whose CONNECT_LIVING points to itself
        foreach ($all as $id => $el) {
            if (!$el['parent'] || $el['parent'] === $id || !isset($all[$el['parent']])) $projects[$id] = true;
        }
        // blocks are leaves (one level under a project): plain copies, no references (the tree is cached/serialized)
        foreach ($all as $id => $el) {
            if (!isset($projects[$id])) $all[$el['parent']]['children'][] = $el;
        }
        $sections = array();
        foreach ($all as $id => $el) {
            if ($el['section']) {
                $projectId = isset($projects[$id]) ? $id : $el['parent'];
                $sections[$el['section']] = array('id' => $id, 'name' => $el['name'], 'project_id' => $projectId);
            }
        }
        $list = array();
        foreach (array_keys($projects) as $id) $list[$id] = $all[$id];
        $tree = array('all' => $all, 'projects' => $list, 'sections' => $sections);
        if ($cache->startDataCache()) $cache->endDataCache($tree);
        return $tree;
    }

    private static function describeElement($el)
    {
        $p = $el['p'];
        $v = function ($pid) use ($p) { return isset($p[$pid]) && $p[$pid]['v'] !== '' ? $p[$pid]['v'] : null; };
        $statusEnum = isset($p[self::P_STATUS]) ? (int)$p[self::P_STATUS]['enum'] : 0;
        $end = $v(self::P_END_DATE);
        $kind = array(2217204 => 'complex', 2217205 => 'independent_building', 2217203 => 'building');
        return array(
            'id' => $el['id'],
            'kind' => $kind[(int)$v(self::P_KIND)] ?? null,
            'name' => array('ka' => $el['name'], 'en' => $v(self::P_NAME_EN), 'ru' => $v(self::P_NAME_RU)),
            'city' => array('ka' => $v(self::P_CITY), 'en' => $v(self::P_CITY_EN), 'ru' => $v(self::P_CITY_RU)),
            'district' => array('ka' => $v(self::P_DISTRICT), 'en' => $v(self::P_DISTRICT_EN), 'ru' => $v(self::P_DISTRICT_RU)),
            'address' => array('ka' => $v(self::P_ADDRESS), 'en' => $v(self::P_ADDRESS_EN), 'ru' => $v(self::P_ADDRESS_RU)),
            'status' => self::PROJECT_STATUS[$statusEnum] ?? null,
            'completion_date' => $end ? date('Y-m-d', MakeTimeStamp($end)) : null,
            'show_on_broker_site' => isset($p[self::P_BROKER]) ? ((int)$p[self::P_BROKER]['enum'] === 2493) : null,
        );
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
                'currency' => $r['CURRENCY'],
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
