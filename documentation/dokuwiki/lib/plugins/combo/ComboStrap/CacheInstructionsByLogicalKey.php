<?php


namespace ComboStrap;

use dokuwiki\Cache\CacheInstructions;

/**
 * Class BarCache
 * @package ComboStrap
 *
 * See {@link CacheByLogicalKey} for explanation
 *
 * Adapted from {@link CacheInstructions}
 * because the storage is an array and the storage process is cached in the code function
 * {@link CacheInstructionsByLogicalKey::retrieveCache()} and
 * {@link CacheInstructionsByLogicalKey::storeCache()}
 */
class CacheInstructionsByLogicalKey extends CacheByLogicalKey
{
    public $pageObject;

    public $mode;

    /**
     * BarCache constructor.
     *
     * @param $pageObject - logical id
     */
    public function __construct($pageObject)
    {
        $this->pageObject = $pageObject;
        $this->mode = "i";

        parent::__construct($pageObject, $this->mode);

    }

    /**
     * retrieve the cached data
     *
     * @param bool $clean true to clean line endings, false to leave line endings alone
     * @return  array          cache contents
     */
    public function retrieveCache($clean = true)
    {
        $contents = io_readFile($this->getCacheFile(), false);
        return !empty($contents) ? unserialize($contents) : array();
    }

    /**
     * cache $instructions
     *
     * @param array $instructions the instruction to be cached
     * @return  bool                  true on success, false otherwise
     */
    public function storeCache($instructions)
    {
        if ($this->_nocache) {
            return false;
        }

        return io_saveFile($this->getCacheFile(), serialize($instructions));
    }


}
