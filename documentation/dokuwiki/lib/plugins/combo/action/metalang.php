<?php

use ComboStrap\DokuPath;
use ComboStrap\Page;


/**
 * Change the lang of the page if present
 */
class action_plugin_combo_metalang extends DokuWiki_Action_Plugin
{

    public function register(Doku_Event_Handler $controller)
    {

        /**
         * https://www.dokuwiki.org/devel:event:init_lang_load
         */
        $controller->register_hook('INIT_LANG_LOAD', 'BEFORE', $this, 'load_lang', array());


    }

    public function load_lang(Doku_Event $event, $param)
    {
        /**
         * On the test setup of Dokuwiki
         * this event is send without any context
         * data
         */
        global $_REQUEST;
        if(isset($_REQUEST["id"])) {
            $initialLang = $event->data;
            $page = Page::createRequestedPageFromEnvironment();
            if($page==null){
                $page = Page::createPageFromId($_REQUEST["id"]);
                if($page==null){
                    return;
                }
            }
            $pageLang = $page->getLang();
            global $conf;
            if ($initialLang != $pageLang) {
                $conf['lang'] = $pageLang;
                $event->data = $pageLang;
            }
        }

    }


}



