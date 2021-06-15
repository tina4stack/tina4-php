<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Manages caching with PhpFastCache
 * @package Tina4
 */
class Cache
{
    protected $cache;

    public function __construct()
    {
        global $cache;
        $this->cache = $cache;
    }

    /**
     * Sets a piece of data into cache, returns the cached key for further manipulation
     * @param $keyName
     * @param $value
     * @param int $expires
     * @return bool
     * @throws \Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function set($keyName, $value, $expires = 60): bool
    {
        $cachedString = $this->cache->getItem($keyName);
        $cachedString->set($value)->expiresAfter($expires);
        $this->cache->save($cachedString);
        return true;
    }

    /**
     * Gets data from the cache, returns null if nothing is found
     * @param $keyName
     * @return mixed
     * @throws \Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     * @tests tina4
     *   assert $this->set("test", "test") !== null, "Could not set cache value"
     *   assert $this->get("test") === "test", "Could not get cache value"
     */
    public function get($keyName)
    {
        $cachedString = $this->cache->getItem($keyName);
        $cachedData = $cachedString->get();
        if (empty($cachedData) && $cachedData === null) {
            Debug::message("No cache " . $keyName);
            return null;
        } else {
            return $cachedData;
        }
    }
}
