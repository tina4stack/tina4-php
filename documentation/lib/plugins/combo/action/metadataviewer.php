<?php

use ComboStrap\ArrayUtility;
use ComboStrap\MetadataUtility;
use ComboStrap\PluginUtility;

if (!defined('DOKU_INC')) die();
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');

require_once (__DIR__.'/../class/PluginUtility.php');
require_once (__DIR__.'/../class/ArrayUtility.php');
require_once (__DIR__.'/../class/MetadataUtility.php');

/**
 *
 * To show a message after redirection or rewriting
 *
 *
 *
 */
class action_plugin_combo_metadataviewer extends DokuWiki_Action_Plugin
{


    function __construct()
    {
        // enable direct access to language strings
        // ie $this->lang
        $this->setupLocale();
    }


    function register(Doku_Event_Handler $controller)
    {

        /* This will call the function _displayMetaMessage */
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, '_displayMetaViewer', array());


    }


    /**
     * Main function; dispatches the visual comment actions
     * @param   $event Doku_Event
     * @param $param
     */
    function _displayMetaViewer(&$event, $param)
    {

        if ($this->getConf(MetadataUtility::CONF_ENABLE_WHEN_EDITING) && ($event->data == 'edit' || $event->data == 'preview')) {

            print MetadataUtility::getHtmlMetadataBox($this);

        }

    }


}
