<?php



/**
 * Class action_plugin_combo_metatitle
 * Set and manage the meta title
 * The event is triggered in the strap template
 */
class action_plugin_combo_metatitle extends DokuWiki_Action_Plugin
{

    const TITLE_META_KEY = "title";

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
            $title = TestUtility::getMeta($ID, self::TITLE_META_KEY);
        } else {
            $title = p_get_metadata($ID, self::TITLE_META_KEY);
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
