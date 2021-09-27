<?php

use ComboStrap\CacheManager;
use ComboStrap\Iso8601Date;
use ComboStrap\PluginUtility;

require_once(__DIR__ . '/../ComboStrap/PluginUtility.php');

/**
 * Can we use the parser cache
 */
class action_plugin_combo_cache extends DokuWiki_Action_Plugin
{
    const COMBO_CACHE_PREFIX = "combo:cache:";

    /**
     * @param Doku_Event_Handler $controller
     */
    function register(Doku_Event_Handler $controller)
    {

        /**
         * Log the cache usage and also
         */
        $controller->register_hook('PARSER_CACHE_USE', 'AFTER', $this, 'logCacheUsage', array());

        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'purgeIfNeeded', array());

        /**
         * To add the cache result in the header
         */
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'addMeta', array());

        /**
         * To reset the cache manager
         * between two run in the test
         */
        $controller->register_hook('DOKUWIKI_DONE', 'BEFORE', $this, 'close', array());

    }

    /**
     *
     * @param Doku_Event $event
     * @param $params
     */
    function logCacheUsage(Doku_Event $event, $params)
    {

        /**
         * To log the cache used by bar
         * @var \dokuwiki\Cache\CacheParser $data
         */
        $data = $event->data;
        $result = $event->result;
        $pageId = $data->page;
        $cacheManager = PluginUtility::getCacheManager();
        $cacheManager->addSlot($pageId, $result, $data);


    }

    /**
     *
     * @param Doku_Event $event
     * @param $params
     */
    function purgeIfNeeded(Doku_Event $event, $params)
    {

        /**
         * No cache for all mode
         * (ie xhtml, instruction)
         */
        $data = &$event->data;
        $pageId = $data->page;
        /**
         * Because of the recursive nature of rendering
         * inside dokuwiki, we just handle the first
         * rendering for a request.
         *
         * The first will be purged, the other one not
         * because they can use the first one
         */
        if (!PluginUtility::getCacheManager()->isCacheLogPresent($pageId, $data->mode)) {
            $expirationStringDate = p_get_metadata($pageId, CacheManager::DATE_CACHE_EXPIRATION_META_KEY, METADATA_DONT_RENDER);
            if ($expirationStringDate !== null) {

                $expirationDate = Iso8601Date::create($expirationStringDate)->getDateTime();
                $actualDate = new DateTime();
                if ($expirationDate < $actualDate) {
                    /**
                     * As seen in {@link Cache::makeDefaultCacheDecision()}
                     * We request a purge
                     */
                    $data->depends["purge"] = true;
                }
            }
        }


    }

    /**
     * Add HTML meta to be able to debug
     * @param Doku_Event $event
     * @param $params
     */
    function addMeta(Doku_Event $event, $params)
    {

        $cacheManager = PluginUtility::getCacheManager();
        $slots = $cacheManager->getCacheSlotResults();
        foreach ($slots as $slotId => $modes) {

            $cachedMode = [];
            foreach ($modes as $mode => $values) {
                if ($values[CacheManager::RESULT_STATUS] === true) {
                    $metaContentData = $mode;
                    if(!PluginUtility::isTest()){
                        /**
                         * @var DateTime $dateModified
                         */
                        $dateModified = $values[CacheManager::DATE_MODIFIED];
                        $metaContentData .= ":". $dateModified->format('Y-m-d\TH:i:s');
                    }
                    $cachedMode[] = $metaContentData;
                }
            }

            if (sizeof($cachedMode) === 0) {
                $value = "nocache";
            } else {
                sort($cachedMode);
                $value = implode(",", $cachedMode);
            }

            // Add cache information into the head meta
            // to test
            $event->data["meta"][] = array("name" => self::COMBO_CACHE_PREFIX . $slotId, "content" => hsc($value));
        }

    }

    function close(Doku_Event $event, $params)
    {
        CacheManager::close();
    }


}
