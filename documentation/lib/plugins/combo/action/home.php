<?php

if (!defined('DOKU_INC')) die();
require_once(__DIR__ . '/../ComboStrap/PluginUtility.php');
require_once(__DIR__ . '/../ComboStrap/LinkUtility.php');

/**
 * Set the home of the web site documentation
 */
class action_plugin_combo_home extends DokuWiki_Action_Plugin
{

    /**
     * @param Doku_Event_Handler $controller
     */
    function register(Doku_Event_Handler $controller)
    {

        $controller->register_hook('DOKUWIKI_STARTED', 'BEFORE', $this, 'set_home', array());
        $controller->register_hook('MEDIAMANAGER_STARTED', 'BEFORE', $this, 'set_home', array());
        $controller->register_hook('DETAIL_STARTED', 'BEFORE', $this, 'set_home', array());
    }

    /**
     *
     * @param Doku_Event $event
     * @param $params
     * The path are initialized in {@link init_paths}
     */
    function set_home(Doku_Event $event, $params)
    {
        // See also: https://www.dokuwiki.org/devel:preload
//        global $conf;
//        $conf['mediadir']="D:/dokuwiki/website/media";
//        $conf['datadir']="D:/dokuwiki/website/pages";
    }


}
