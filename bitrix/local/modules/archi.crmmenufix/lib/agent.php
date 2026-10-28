<?php
// ARCHI — CRM-ის ზედა მენიუს დაჩქარება.
//
// პრობლემა: crm.control_panel ყოველ CRM გვერდზე იძახებს SalesCenter\Driver::hasDeliveryServices().
// ადგილობრივი მიწოდების სერვისი არ გვაქვს, ამიტომ ის ეკითხება Bitrix Marketplace-ს
// (RestManager::getByTag(['delivery','recommended',<zone>])). პასუხი ცარიელია და ბიტრიქსი
// ცარიელ პასუხს ქეშში არ ინახავს → ქსელური მოთხოვნა (~3 წმ, სერვერის DNS-ის გამო) ყოველ გვერდზე.
//
// გამოსავალი: აგენტი ყოველ 30 წუთში ფონურად თავად ეკითხება Marketplace-ს და პასუხს (ცარიელსაც)
// წერს ზუსტად იმ ქეშ-ჩანაწერში, რომელსაც getByTag კითხულობს. მენიუ ქეშიდან იღებს პასუხს.
// ბიტრიქსის ფაილები არ იცვლება; ქეში თუ წაიშლება, ყველაფერი ძველებურად მუშაობს.

namespace Archi\CrmMenuFix;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

class Agent
{
    const AGENT = '\\Archi\\CrmMenuFix\\Agent::run();';
    const INTERVAL = 1800;                          // ქეშის ვადა (TAG_CACHE_TTL) 3600 წმ-ია, ვაახლებთ 2-ჯერ ხშირად
    const CACHE_PATH = '/salescenter/saleshub/tag/'; // RestManager::getByTag-ის ქეშის საქაღალდე
    const DEFAULT_TTL = 3600;

    public static function run()
    {
        try {
            self::refresh();
        } catch (\Throwable $e) {
            error_log('[archi.crmmenufix] ' . $e->getMessage());
        }
        return self::AGENT;
    }

    // ქეშის გასაღები ზუსტად ისე, როგორც RestManager::hasDeliveryMarketplaceApp() → getByTag($tags) ითვლის
    public static function cacheKey()
    {
        if (!Loader::includeModule('salescenter') || !Loader::includeModule('rest')) {
            return null;
        }
        $class = \Bitrix\SalesCenter\Integration\RestManager::class;
        if (!class_exists($class)) {
            return null;
        }
        $manager = $class::getInstance();
        $reflection = new \ReflectionClass($class);
        $tags = array(
            $reflection->getConstant('TAG_DELIVERY'),
            $reflection->getConstant('TAG_DELIVERY_RECOMMENDED'),
            self::zone($manager),
        );
        $ttl = (int)$reflection->getConstant('TAG_CACHE_TTL');
        return array(
            'manager' => $manager,
            'tags' => $tags,
            'id' => md5(serialize(array($tags, false, false))),
            'ttl' => $ttl > 0 ? $ttl : self::DEFAULT_TTL,
        );
    }

    public static function refresh()
    {
        $key = self::cacheKey();
        if (!$key || !$key['manager']->isEnabled()) {
            return;
        }
        // რეალური პასუხი Marketplace-დან (ფონურად, აგენტში — მომხმარებელი არ ელოდება)
        $result = \Bitrix\Rest\Marketplace\Client::getByTag($key['tags']);
        if (!is_array($result)) {
            $result = array('PAGES' => 0, 'CUR_PAGE' => 1);
        }
        $cache = Application::getInstance()->getCache();
        $cache->clean($key['id'], self::CACHE_PATH);
        if ($cache->startDataCache($key['ttl'], $key['id'], self::CACHE_PATH)) {
            $cache->endDataCache($result);
        }
    }

    // მოდულის წაშლისას ჩვენი ჩანაწერი ვშლით — ბიტრიქსი ძველებურად იმუშავებს
    public static function clear()
    {
        $key = self::cacheKey();
        if ($key) {
            Application::getInstance()->getCache()->clean($key['id'], self::CACHE_PATH);
        }
    }

    // RestManager::getZone() private-ია — ვიძახებთ იმავე ფუნქციას; თუ ვერ, ვიმეორებთ მის ლოგიკას
    private static function zone($manager)
    {
        try {
            $method = new \ReflectionMethod($manager, 'getZone');
            $method->setAccessible(true);
            return $method->invoke($manager);
        } catch (\Throwable $e) {
            if (\Bitrix\Main\ModuleManager::isModuleInstalled('bitrix24') && class_exists('CBitrix24')) {
                return \CBitrix24::getPortalZone();
            }
            $row = \Bitrix\Main\Localization\LanguageTable::getList(array(
                'select' => array('ID'),
                'filter' => array('=DEF' => 'Y', '=ACTIVE' => 'Y'),
            ))->fetch();
            return $row ? $row['ID'] : '';
        }
    }
}
