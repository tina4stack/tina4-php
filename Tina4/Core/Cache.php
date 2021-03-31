<?php


namespace Tina4;


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
     */
    public function set($keyName, $value, $expires=60): bool
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
     * @tests tina4
     *   assert $this->set("test", "test") !== null, "Could not set cache value"
     *   assert $this->get("test") === "test", "Could not get cache value"
     */
    public function get($keyName)
    {
        $cachedString = $this->cache->getItem($keyName);
        return $cachedString->get();
    }
}