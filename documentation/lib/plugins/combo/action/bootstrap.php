<?php
/**
 * DokuWiki Plugin Js Action
 *
 */

use ComboStrap\Site;

if (!defined('DOKU_INC')) die();

/**
 * Adding bootstrap if not present
 *
 * This is a little bit silly but make the experience better
 *
 * With the default dokuwiki, they set a color on a:link and a:visited
 * which conflicts immediately
 *
 */
class action_plugin_combo_bootstrap extends DokuWiki_Action_Plugin
{
    const BOOTSTRAP_JAVASCRIPT_BUNDLE_COMBO_ID = "bootstrap-javascript-bundle-combo";
    const BOOTSTRAP_STYLESHEET_COMBO_ID = "bootstrap-stylesheet-combo";


    /**
     * Registers our handler for the MANIFEST_SEND event
     * https://www.dokuwiki.org/devel:event:js_script_list
     * manipulate the list of JavaScripts that will be concatenated
     * @param Doku_Event_Handler $controller
     */
    public function register(Doku_Event_Handler $controller)
    {

        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'handle_bootstrap');


    }

    /**
     * Add Bootstrap html head (css and js if not present)
     * @param Doku_Event $event
     * @param $param
     */
    public function handle_bootstrap(Doku_Event &$event, $param)
    {

        if (Site::isStrapTemplate()) {
            return;
        }

        /**
         * Scanning for bootstrap presence
         */
        $bootstrapFound = false;
        foreach ($event->data as $htmlHeadType => $htmlHeadTagAttributes) {
            switch ($htmlHeadType) {
                case "script":
                    if (isset($htmlHeadTagAttributes["src"])) {
                        $src = $htmlHeadTagAttributes["src"];
                        if (strpos($src, "bootstrap") !== false) {
                            $bootstrapFound = true;
                            break 2;
                        }
                    }
                    break;
                case "link":
                    if (isset($htmlHeadTagAttributes["href"])) {
                        $href = $htmlHeadTagAttributes["href"];
                        if (strpos($href, "bootstrap") !== false) {
                            $bootstrapFound = true;
                            break 2;
                        }
                    }
            }
        }

        if (!$bootstrapFound) {

            // Adding the Css before the CSS of Dokuwiki
            // to avoid conflict :)
            $boostrapStyleSheet = array(
                "type" => "text/css",
                "rel" => "stylesheet",
                "href" => "https://cdn.jsdelivr.net/npm/bootstrap@5.0.1/dist/css/bootstrap.min.css",
                "integrity" => "sha384-+0n0xVW2eSR5OomGNYDnhzAbDsOXxcvSN1TPprVMTNDbiYZCxYbOOl7+AMvyTG2x",
                "crossorigin" => "anonymous",
                "id" => self::BOOTSTRAP_STYLESHEET_COMBO_ID
            );
            array_splice($event->data["link"], 0, 0, [$boostrapStyleSheet]);

            // Adding the JavaScript File
            $event->data["script"][] = array(
                "type" => "text/javascript",
                "src" => "https://cdn.jsdelivr.net/npm/bootstrap@5.0.1/dist/js/bootstrap.bundle.min.js",
                "integrity" => "sha384-gtEjrD/SeCtmISkJkNUaaKMoLD0//ElJ19smozuHV6z3Iehds+3Ulb9Bn9Plx0x4",
                "crossorigin" => "anonymous",
                "id" => self::BOOTSTRAP_JAVASCRIPT_BUNDLE_COMBO_ID
            );
        }


    }


}

