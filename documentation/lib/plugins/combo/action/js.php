<?php
/**
 * DokuWiki Plugin Js Action
 *
 */

if (!defined('DOKU_INC')) die();

/**
 * Suppress the Javascript of Dokuwiki not needed on a page show
 */
class action_plugin_combo_js extends DokuWiki_Action_Plugin
{

    // Because the javascript is a second request call
    // we don't known if we are in the admin or public interface
    // we add them to the js href the below query key
    const ACCESS_PROPERTY_KEY = 'wcacc';
    // For the public (no user, no admin)
    const ACCESS_PROPERTY_VALUE_PUBLIC = 'public';

    /**
     * Registers our handler for the MANIFEST_SEND event
     * https://www.dokuwiki.org/devel:event:js_script_list
     * manipulate the list of JavaScripts that will be concatenated
     * @param Doku_Event_Handler $controller
     */
    public function register(Doku_Event_Handler $controller)
    {

        // To add the act query string to tweak the cache at the end. ie http://localhost:81/lib/exe/js.php?t=strap&tseed=3cf44e7&act=show
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'handle_header');

        // To get only the script that we need
        $controller->register_hook('JS_SCRIPT_LIST', 'AFTER', $this, 'handle_js');

    }

    /**
     * Add a query string to the js.php link when it's a SHOW action
     * @param Doku_Event $event
     */
    public function handle_header(Doku_Event &$event, $param)
    {

        // If there is no user
        if (empty($_SERVER['REMOTE_USER'])) {
            $scripts = &$event->data['script'];
            foreach ($scripts as &$script) {
                $pos = strpos($script['src'], 'js.php');
                if ($pos !== false) {
                    $script['src'] = $script['src'] . '&' . self::ACCESS_PROPERTY_KEY . '=' . self::ACCESS_PROPERTY_VALUE_PUBLIC . '';
                }
            }
        }


    }

    /**
     * This is to handle the HTTP call
     * /lib/exe/js.php?t=strap&tseed=2eb19bd2d8991a9bb11366d787d4225e&wcacc=public
     * @param Doku_Event $event
     * @param            $param
     */
    public function handle_js(Doku_Event &$event, $param)
    {

        /**
         * The directory separator is hard written
         * in the list {@link js_out()}
         * We then use it here also as hardcoded
         */
        $directorySeparatorInDokuwikiList = "/";

        // It was added by the TPL_METAHEADER_OUTPUT handler (ie function handle_header)
        $access = $_GET[self::ACCESS_PROPERTY_KEY];
        if ($access == self::ACCESS_PROPERTY_VALUE_PUBLIC) {

            // The directory path for the internal dokuwiki script
            $dokuScriptPath = $directorySeparatorInDokuwikiList . 'lib' . $directorySeparatorInDokuwikiList . 'scripts' . $directorySeparatorInDokuwikiList;
            // The directory path for the plugin script (we need to keep them)
            $dokuPluginPath = $directorySeparatorInDokuwikiList . 'lib' . $directorySeparatorInDokuwikiList . 'plugins' . $directorySeparatorInDokuwikiList;

            // Script that we want on the show page
            $showPageScripts =
                [
                    0 => $dokuScriptPath . "qsearch.js", // for the search bar
                    1 => $dokuScriptPath . "script.js",
                    2 => $dokuScriptPath . "hotkeys.js",
                    3 => $dokuScriptPath . "locktimer.js", // Use in js.php - dw_locktimer
                    4 => $dokuScriptPath . "helpers.js", // substr_replace use in qsearch.php
                    5 => 'conf' . $directorySeparatorInDokuwikiList . 'userscript.js',
                    6 => $dokuScriptPath . "cookie.js", // plugin may depend on this library such as styling (lib/plugins/styling/script.js)
                    7 => $dokuScriptPath . "jquery/jquery.cookie.js" // cookie.js depends on it
                ];

            $scriptsToKeep = array();

            foreach ($event->data as $scriptPath) {
                $posPluginPath = strpos($scriptPath, $dokuPluginPath);
                if ($posPluginPath !== false) {
                    // This is a plugin script, we keep it
                    $scriptsToKeep[] = $scriptPath;
                } else {

                    foreach ($showPageScripts as $showPageScript) {

                        $posShowPage = strpos($scriptPath, $showPageScript);
                        if ($posShowPage !== false) {
                            // This is a script needed on the show page
                            $scriptsToKeep[] = $scriptPath;
                        }
                    }
                }
            }

            $event->data = $scriptsToKeep;

        }
    }

}

