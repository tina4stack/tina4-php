<?php

use ComboStrap\Analytics;


/**
 * Class action_plugin_combo_metatitle
 * Set and manage the meta title
 * The event is triggered in the strap template
 */
class action_plugin_combo_metatitle extends DokuWiki_Action_Plugin
{


    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('TPL_TITLE_OUTPUT', 'BEFORE', $this, 'handleTitle', array());
    }

    function handleTitle(&$event, $param)
    {
        $event->data = self::getTitle();
    }

    static function getTitle(){
        global $ID;
        global $conf;
        if (defined('DOKU_UNITTEST')) {
            $title = TestUtility::getMeta($ID, Analytics::TITLE);
        } else {
            $title = p_get_metadata($ID, Analytics::TITLE);
        }
        if (!empty($title)){

            $pageTitle = $title;

        } else {

            // Home page
            if ($ID == "start") {
                $pageTitle = $conf["title"];
                if ($conf['tagline']) {
                    $pageTitle .= ' - ' . $conf['tagline'];
                }
            } else {
                $pageTitle = tpl_pagetitle($ID, true) . ' ['. $conf["title"]. ']';
            }

        }
        return strip_tags($pageTitle);
    }
}
