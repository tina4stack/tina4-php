<?php

use ComboStrap\PluginUtility;
use ComboStrap\Site;
use ComboStrap\TplUtility;

if (!defined('DOKU_INC')) die();

/**
 *
 */
class  action_plugin_combo_webcode extends DokuWiki_Action_Plugin
{

    const CALL_ID = "webcode";
    const MARKI_PARAM = "marki";


    function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, '_ajax_call');
    }

    /**
     * handle ajax requests
     * @param $event Doku_Event
     *
     * {@link html_show()}
     *
     * https://www.dokuwiki.org/devel:plugin_programming_tips#handle_json_ajax_request
     *
     * CSRF checks are only for logged in users
     * This is public ({@link getSecurityToken()}
     */
    function _ajax_call(&$event)
    {

        if ($event->data !== self::CALL_ID) {
            return;
        }
        //no other ajax call handlers needed
        $event->stopPropagation();
        $event->preventDefault();


        global $INPUT;
        $marki = $INPUT->str(self::MARKI_PARAM);
        $title = $INPUT->str('title') ?: "ComboStrap WebCode - Dokuwiki Renderer";


        header('Content-Type: text/html; charset=utf-8');

        /**
         * Conf
         */
        PluginUtility::setConf(action_plugin_combo_css::CONF_DISABLE_DOKUWIKI_STYLESHEET, true);

        /**
         * Main content happens before the headers
         * to set the headers right
         */
        global $conf;
        $conf["renderer_xhtml"] = "xhtml";
        $mainContent = p_render('xhtml', p_get_instructions($marki), $info);

        /**
         * Html
         */
        $htmlBeforeHeads = '<!DOCTYPE html>' . DOKU_LF;
        $htmlBeforeHeads .= '<html>' . DOKU_LF;
        $htmlBeforeHeads .= '<head>' . DOKU_LF;
        $htmlBeforeHeads .= "  <title>$title</title>" . DOKU_LF;
        // we echo because the tpl function just flush
        echo $htmlBeforeHeads;

        if (Site::isStrapTemplate()) {

            /**
             * The strap header function
             */
            $loaded = PluginUtility::loadStrapUtilityTemplateIfPresentAndSameVersion();
            if($loaded) {
                TplUtility::registerHeaderHandler();
            }
        }

        /**
         * To delete the not needed headers for an export
         * such as manifest, alternate, ...
         */
        global $EVENT_HANDLER;
        $EVENT_HANDLER->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, '_delete_not_needed_headers');

        /**
         * meta headers
         */
        tpl_metaheaders();


        $htmlAfterHeads = '</head>' . DOKU_LF;
        $htmlAfterHeads .= '<body>' . DOKU_LF;
        $htmlAfterHeads .= $mainContent . DOKU_LF;
        $htmlAfterHeads .= '</body>' . DOKU_LF;
        $htmlAfterHeads .= '</html>' . DOKU_LF;
        echo $htmlAfterHeads;
        http_response_code(200);

    }

    /**
     * Dynamically called in the previous function
     * to delete the head
     * @param $event
     */
    public function _delete_not_needed_headers(&$event)
    {
        $data = &$event->data;

        foreach ($data as $tag => &$heads) {
            switch ($tag) {
                case "link":
                    $deletedRel = ["manifest", "search", "start", "alternate", "contents"];
                    foreach ($heads as $id => $headAttributes) {
                        if (isset($headAttributes['rel'])) {
                            $rel = $headAttributes['rel'];
                            if (in_array($rel, $deletedRel)) {
                                unset($heads[$id]);
                            }
                        }
                    }
                    break;
                case "meta":
                    $deletedMeta = ["robots"];
                    foreach ($heads as $id => $headAttributes) {
                        if (isset($headAttributes['name'])) {
                            $rel = $headAttributes['name'];
                            if (in_array($rel, $deletedMeta)) {
                                unset($heads[$id]);
                            }
                        }
                    }
                    break;
                case "script":
                    foreach ($heads as $id => $headAttributes) {
                        if (isset($headAttributes['src'])) {
                            $src = $headAttributes['src'];
                            if (strpos($src, "lib/exe/js.php") !== false) {
                                unset($heads[$id]);
                            }
                        }
                    }
            }
        }
    }


}
