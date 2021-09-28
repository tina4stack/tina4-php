<?php
/**
 * Copyright (c) 2020. ComboStrap, Inc. and its affiliates. All Rights Reserved.
 *
 * This source code is licensed under the GPL license found in the
 * COPYING  file in the root directory of this source tree.
 *
 * @license  GPL 3 (https://www.gnu.org/licenses/gpl-3.0.en.html)
 * @author   ComboStrap <support@combostrap.com>
 *
 */

namespace ComboStrap;

require_once(__DIR__ . '/PluginUtility.php');

class LogUtility
{

    /**
     * Constant for the function {@link msg()}
     * -1 = error, 0 = info, 1 = success, 2 = notify
     * (Not even in order of importance)
     */
    const LVL_MSG_ERROR = 4; //-1;
    const LVL_MSG_WARNING = 3; //2;
    const LVL_MSG_SUCCESS = 2; //1;
    const LVL_MSG_INFO = 1; //0;
    const LVL_MSG_DEBUG = 0; //3;


    /**
     * Id level to name
     */
    const LVL_NAME = array(
        0 => "debug",
        1 => "info",
        3 => "warning",
        2 => "success",
        4 => "error"
    );

    /**
     * Id level to name
     * {@link msg()} constant
     */
    const LVL_TO_MSG_LEVEL = array(
        0 => 3,
        1 => 0,
        2 => 1,
        3 => 2,
        4 => -1
    );


    const LOGLEVEL_URI_QUERY_PROPERTY = "loglevel";

    /**
     * Send a message to a manager and log it
     * Fail if in test
     * @param string $message
     * @param int $level - the level see LVL constant
     * @param string $canonical - the canonical
     */
    public static function msg($message, $level = self::LVL_MSG_ERROR, $canonical = "support")
    {

        /**
         * Log to frontend
         */
        self::log2FrontEnd($message, $level, $canonical, true);

        /**
         * Log level passed for a page (only for file used)
         * to not allow an attacker to see all errors in frontend
         */
        global $INPUT;
        $loglevelProp = $INPUT->str(self::LOGLEVEL_URI_QUERY_PROPERTY, null);
        if (!empty($loglevelProp)) {
            $level = $loglevelProp;
        }
        self::log2file($message, $level, $canonical);

        /**
         * If test, we throw an error
         */
        if (defined('DOKU_UNITTEST')
            && ($level >= self::LVL_MSG_WARNING)
        ) {
            throw new \RuntimeException(PluginUtility::$PLUGIN_NAME . " - " . $message);
        }
    }

    /**
     * Print log to a  file
     *
     * Adapted from {@link dbglog}
     * Note: {@link dbg()} dbg print to the web page
     *
     * @param string $msg
     * @param int $logLevel
     * @param null $canonical
     */
    static function log2file($msg, $logLevel = self::LVL_MSG_INFO, $canonical = null)
    {

        if (defined('DOKU_UNITTEST') || $logLevel >= self::LVL_MSG_WARNING) {

            $prefix = PluginUtility::$PLUGIN_NAME;
            if (!empty($canonical)) {
                $prefix .= ' - ' . $canonical;
            }
            $msg = $prefix . ' - ' . $msg;

            global $INPUT;
            global $conf;

            $id = PluginUtility::getPageId();

            $file = $conf['cachedir'] . '/debug.log';
            $fh = fopen($file, 'a');
            if ($fh) {
                $sep = " - ";
                fwrite($fh, date('c') . $sep . self::LVL_NAME[$logLevel] . $sep . $msg . $sep . $INPUT->server->str('REMOTE_ADDR') . $sep . $id . "\n");
                fclose($fh);
            }
        }

    }

    /**
     * @param $message
     * @param $level
     * @param $canonical
     * @param bool $withIconURL
     */
    public static function log2FrontEnd($message, $level, $canonical, $withIconURL = true)
    {
        /**
         * If we are not in the console
         * and not in test
         * we test that the message comes in the front end
         * (example {@link \plugin_combo_frontmatter_test}
         */
        $isCLI = (php_sapi_name() == 'cli');
        $print = true;
        if ($isCLI) {
            if (!defined('DOKU_UNITTEST')) {
                $print = false;
            }
        }
        if ($print) {
            $htmlMsg = PluginUtility::getUrl("", PluginUtility::$PLUGIN_NAME, $withIconURL);
            if ($canonical != null) {
                $htmlMsg = PluginUtility::getUrl($canonical, ucfirst(str_replace(":", " ", $canonical)));
            }

            /**
             * Adding page - context information
             * We are not creating the page
             * direction from {@link Page::createRequestedPageFromEnvironment()}
             * because it throws an error message when the environment
             * is not good, creating a recursive call.
             */
            $id = PluginUtility::getPageId();
            if ($id!=null) {
                $page = Page::createPageFromId($id);
                if ($page != null) {
                    $htmlMsg .= " - " . $page->getAnchorLink();
                }
            }

            /**
             *
             */
            $htmlMsg .= " - " . $message;
            if ($level > self::LVL_MSG_DEBUG) {
                $dokuWikiLevel = self::LVL_TO_MSG_LEVEL[$level];
                msg($htmlMsg, $dokuWikiLevel, '', '', MSG_USERS_ONLY);
            }
        }
    }

    /**
     * Log a message to the browser console
     * @param $message
     */
    public static function log2BrowserConsole($message)
    {
        // TODO
    }
}
