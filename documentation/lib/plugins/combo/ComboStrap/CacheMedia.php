<?php


namespace ComboStrap;

use dokuwiki\Cache\Cache;

/**
 * Class Cache
 * A wrapper around {@link \dokuwiki\Cache\Cache}
 * @package ComboStrap
 */
class CacheMedia
{
    /**
     * Cache
     * values:
     *   * cache
     *   * nocache
     *   * recache
     */
    const CACHE_KEY = 'cache';
    const CACHE_DEFAULT_VALUE = "cache";
    /**
     * buster got the same value
     * that the `rev` attribute (ie mtime)
     * We don't use rev as cache buster because Dokuwiki still thinks
     * that this is an old file and search in the attic
     * as seen in the function {@link mediaFN()}
     */
    const CACHE_BUSTER_KEY = "buster";

    /**
     * @var File
     */
    private $path;
    /**
     * @var Cache
     */
    private $fileCache;
    private $maxAge;


    /**
     * Cache constructor.
     */
    public function __construct(File $path, TagAttributes &$tagAttributes)
    {

        $this->path = $path;

        /**
         * Cache Key Construction
         */
        $cacheKey = $this->path->getFileSystemPath();
        foreach ($tagAttributes->getComponentAttributes() as $name => $value) {

            /**
             * The cache attribute are not part of the key
             * obviously
             */
            if (in_array($name,[
                CacheMedia::CACHE_KEY,
                CacheMedia::CACHE_BUSTER_KEY,
            ])){
                continue;
            }

            /**
             * Normalize name (from w to width)
             */
            $name =  TagAttributes::AttributeNameFromDokuwikiToCombo($name);

            $cacheKey .= "&" . $name . "=" . $value;

        }

        /**
         * Cache Attribute
         */
        $cacheParameter = $tagAttributes->getValue(self::CACHE_KEY, self::CACHE_DEFAULT_VALUE);
        /**
         * Cache transformation
         * From Image cache value (https://www.dokuwiki.org/images#caching)
         * to {@link CacheMedia::setMaxAgeInSec()}
         */
        switch ($cacheParameter) {
            case "nocache":
                $cacheParameter = 0;
                break;
            case "recache":
                global $conf;
                $cacheParameter = $conf['cachetime'];
                break;
            case "cache":
            default:
                $cacheParameter = -1;
                break;
        }
        $this->setMaxAgeInSec($cacheParameter);


        $this->fileCache = new Cache($cacheKey, $this->path->getExtension());

    }

    public static function createFromPath(File $file, $tagAttributes = null)
    {
        if ($tagAttributes == null) {
            $tagAttributes = TagAttributes::createEmpty();
        }
        return new CacheMedia($file, $tagAttributes);
    }


    public function isCacheUsable()
    {
        if ($this->maxAge == 0) {
            return false;
        } else {
            $dependencies = array(
                'files' => [
                    $this->path->getFileSystemPath(),
                    Resources::getComboHome() . "/plugin.info.txt"
                ]
            );
            if ($this->maxAge != null) {
                $dependencies['age'] = $this->maxAge;
            }
            return $this->fileCache->useCache($dependencies);
        }
    }

    public function setMaxAgeInSec($maxAge)
    {

        /**
         * Got the Dokuwiki Rule
         * from
         * https://www.dokuwiki.org/devel:event:fetch_media_status
         */
        if ($maxAge < 0) {
            // cache forever
            $this->maxAge = PHP_INT_MAX;
        } elseif ($maxAge == 0) {
            // never cache
            $this->maxAge = 0;
        } else {
            $this->maxAge = $maxAge;
        }
    }

    public function storeCache($content)
    {
        $this->fileCache->storeCache($content);
    }

    public function getFile()
    {
        return File::createFromPath($this->fileCache->cache);
    }


}
