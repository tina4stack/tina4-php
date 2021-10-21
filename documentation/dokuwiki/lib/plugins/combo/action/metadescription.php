<?php

use ComboStrap\LogUtility;
use ComboStrap\Page;


/**
 * Take the metadata description
 *
 */


class action_plugin_combo_metadescription extends DokuWiki_Action_Plugin
{

    const DESCRIPTION_META_KEY = 'description';
    const DESCRIPTION_PROPERTY = 'og:description';

    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'description_modification', array());
    }

    /**
     * Add a meta-data description
     * @param $event
     * @param $param
     */
    function description_modification(&$event, $param)
    {

        global $ID;
        if (empty($ID)) {
            return;  // Admin call for instance
        }

        /**
         * Description
         * https://www.dokuwiki.org/devel:metadata
         */
        $page = Page::createPageFromId($ID);

        $description = $page->getDescriptionOrElseDokuWiki();
        if (empty($description)) {
            $this->sendDestInfo($ID);
            return;
        }

        // Add it to the meta
        $event->data['meta'][] = array("name" => self::DESCRIPTION_META_KEY, "content" => $description);
        $event->data['meta'][] = array("property" => self::DESCRIPTION_PROPERTY, "content" => $description);


    }

    /**
     * Just send a test info
     * @param $ID
     */
    public function sendDestInfo($ID)
    {
        if (defined('DOKU_UNITTEST')) {
            // When you make a admin test call, the page ID = start and there is no meta
            // When there is only an icon, there is also no meta
            global $INPUT;
            $showActions = ["show", ""]; // Empty for the test
            if (in_array($INPUT->str("do"), $showActions)) {
                LogUtility::msg("Page ($ID): The description should never be null when rendering the page", LogUtility::LVL_MSG_INFO);
            }
        }
    }


}
