<?php

use ComboStrap\Page;
use ComboStrap\PluginUtility;
use ComboStrap\TplUtility;

if (!defined('DOKU_INC')) die();

/**
 *
 *
 * To delete sidebar (cache) cache when a page was modified in a namespace
 * https://combostrap.com/sideslots
 */
class action_plugin_combo_sidebarscachebursting extends DokuWiki_Action_Plugin
{



    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('IO_WIKIPAGE_WRITE', 'BEFORE', $this, 'cacheBursting', array());
    }

    /**
     * Dokuwiki has already a canonical methodology
     * https://www.dokuwiki.org/canonical
     *
     * @param $event
     */
    function cacheBursting($event)
    {

        global $conf;

        $sidebars = [
            $conf['sidebar']
        ];

        /**
         * @see {@link \ComboStrap\TplConstant::CONF_SIDEKICK}
         */
        $loaded = PluginUtility::loadStrapUtilityTemplateIfPresentAndSameVersion();
        if($loaded){

            $sideKickSlotPageName = TplUtility::getSideKickSlotPageName();
            if (!empty($sideKickSlotPageName)) {
                $sidebars[] = $sideKickSlotPageName;
            }


        }


        /**
         * Delete the cache for the sidebar
         */
        foreach ($sidebars as $sidebarRelativePath) {

            $id = Page::createPageFromNonQualifiedPath($sidebarRelativePath);
            $id->deleteCache();

        }

    }

}
