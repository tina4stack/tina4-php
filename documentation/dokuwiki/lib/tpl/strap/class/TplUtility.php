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

use Doku_Event;
use dokuwiki\Extension\Event;
use dokuwiki\Menu\PageMenu;
use dokuwiki\Menu\SiteMenu;
use dokuwiki\Menu\UserMenu;
use dokuwiki\plugin\config\core\Configuration;
use dokuwiki\plugin\config\core\Writer;
use Exception;


/**
 * Class TplUtility
 * @package ComboStrap
 * Utility class
 */
class TplUtility
{

    /**
     * Constant for the function {@link msg()}
     * -1 = error, 0 = info, 1 = success, 2 = notify
     */
    const LVL_MSG_ERROR = -1;
    const LVL_MSG_INFO = 0;
    const LVL_MSG_SUCCESS = 1;
    const LVL_MSG_WARNING = 2;
    const LVL_MSG_DEBUG = 3;
    const TEMPLATE_NAME = 'strap';


    const CONF_HEADER_SLOT_PAGE_NAME = "headerSlotPageName";

    const CONF_FOOTER_SLOT_PAGE_NAME = "footerSlotPageName";


    /**
     * @deprecated for  {@link TplUtility::CONF_BOOTSTRAP_VERSION_STYLESHEET}
     */
    const CONF_BOOTSTRAP_VERSION = "bootstrapVersion";
    /**
     * @deprecated for  {@link TplUtility::CONF_BOOTSTRAP_VERSION_STYLESHEET}
     */
    const CONF_BOOTSTRAP_STYLESHEET = "bootstrapStylesheet";

    /**
     * Stylesheet and Boostrap should have the same version
     * This conf is a mix between the version and the stylesheet
     *
     * majorVersion.0.0 - stylesheetname
     */
    const CONF_BOOTSTRAP_VERSION_STYLESHEET = "bootstrapVersionStylesheet";
    /**
     * The separator in {@link TplUtility::CONF_BOOTSTRAP_VERSION_STYLESHEET}
     */
    const BOOTSTRAP_VERSION_STYLESHEET_SEPARATOR = " - ";
    const DEFAULT_BOOTSTRAP_VERSION_STYLESHEET = "5.0.1" . self::BOOTSTRAP_VERSION_STYLESHEET_SEPARATOR . "bootstrap";

    /**
     * Jquery UI
     */
    const CONF_JQUERY_DOKU = 'jQueryDoku';
    const CONF_REM_SIZE = "remSize";
    const CONF_GRID_COLUMNS = "gridColumns";
    const CONF_USE_CDN = "useCDN";

    const CONF_PRELOAD_CSS = "preloadCss"; // preload all css ?
    const BS_4_BOOTSTRAP_VERSION_STYLESHEET = "4.5.0 - bootstrap";

    const CONF_SIDEKICK_OLD = "sidekickbar";
    const CONF_SIDEKICK_SLOT_PAGE_NAME = "sidekickSlotPageName";
    const CONF_SLOT_HEADER_PAGE_NAME_VALUE = "slot_header";

    /**
     * @deprecated see {@link TplUtility::CONF_HEADER_SLOT_PAGE_NAME}
     */
    const CONF_HEADER_OLD = "headerbar";
    /**
     * @deprecated
     */
    const CONF_HEADER_OLD_VALUE = TplUtility::CONF_HEADER_OLD;
    /**
     * @deprecated see {@link TplUtility::CONF_FOOTER_SLOT_PAGE_NAME}
     */
    const CONF_FOOTER_OLD = "footerbar";

    /**
     * Disable the javascript of Dokuwiki
     * if public
     * https://combostrap.com/frontend/optimization
     */
    const CONF_DISABLE_BACKEND_JAVASCRIPT = "disableBackendJavascript";

    /**
     * A parameter switch to allows the update
     * of conf in test
     */
    const COMBO_TEST_UPDATE = "combo_update_conf";

    /**
     * Do we show the rail bar for anonymous user
     */
    const CONF_PRIVATE_RAIL_BAR = "privateRailbar";

    /**
     * When do we toggle from offcanvas to fixed railbar
     */
    const CONF_BREAKPOINT_RAIL_BAR = "breakpointRailbar";

    /**
     * Breakpoint naming
     */
    const BREAKPOINT_EXTRA_SMALL_NAME = "extra-small";
    const BREAKPOINT_SMALL_NAME = "small";
    const BREAKPOINT_MEDIUM_NAME = "medium";
    const BREAKPOINT_LARGE_NAME = "large";
    const BREAKPOINT_EXTRA_LARGE_NAME = "extra-large";
    const BREAKPOINT_EXTRA_EXTRA_LARGE_NAME = "extra-extra-large";
    const BREAKPOINT_NEVER_NAME = "never";

    /**
     * @var array|null
     */
    private static $TEMPLATE_INFO = null;


    /**
     * Print the breadcrumbs trace with Bootstrap class
     *
     * @param string $sep Separator between entries
     * @return bool
     * @author Nicolas GERARD
     *
     *
     */
    static function renderTrailBreadcrumb($sep = '�')
    {

        global $conf;
        global $lang;

        //check if enabled
        if (!$conf['breadcrumbs']) return false;

        $crumbs = breadcrumbs(); //setup crumb trace

        echo '<nav id="breadcrumb" aria-label="breadcrumb" class="my-3 d-print-none">' . PHP_EOL;

        $i = 0;
        // Try to get the template custom breadcrumb
        // $breadCrumb = tpl_getLang('breadcrumb');
        // if ($breadCrumb == '') {
        //    // If not present for the language, get the default one
        //    $breadCrumb = $lang['breadcrumb'];
        // }

        // echo '<span id="breadCrumbTitle" ">' . $breadCrumb . ':   </span>' . PHP_EOL;
        echo '<ol class="breadcrumb py-1 px-2" style="background-color:unset">' . PHP_EOL;
        print '<li class="pr-2" style="display:flex;font-weight: 200">' . $lang['breadcrumb'] . '</li>';

        foreach ($crumbs as $id => $name) {
            $i++;

            if ($i == 0) {
                print '<li class="breadcrumb-item active">';
            } else {
                print '<li class="breadcrumb-item">';
            }
            if ($name == "start") {
                $name = "Home";
            }
            tpl_link(wl($id), hsc($name), 'title="' . $name . '"');

            print '</li>' . PHP_EOL;

        }
        echo '</ol>' . PHP_EOL;
        echo '</nav>' . PHP_EOL;
        return true;
    }

    /**
     * @param string $text add a comment into the HTML page
     */
    private static function addAsHtmlComment($text)
    {
        print_r('<!-- TplUtility Comment: ' . hsc($text) . '-->');
    }

    private static function getApexDomainUrl()
    {
        return self::getTemplateInfo()["url"];
    }

    private static function getTemplateInfo()
    {
        if (self::$TEMPLATE_INFO == null) {
            self::$TEMPLATE_INFO = confToHash(__DIR__ . '/../template.info.txt');
        }
        return self::$TEMPLATE_INFO;
    }

    public static function getFullQualifyVersion()
    {
        return "v" . self::getTemplateInfo()['version'] . " (" . self::getTemplateInfo()['date'] . ")";
    }


    private static function getStrapUrl()
    {
        return self::getTemplateInfo()["strap"];
    }


    public static function registerHeaderHandler()
    {
        /**
         * In test, we may test for 4 and for 5
         * on the same test, making two request
         * This two requests will register the event two times
         * To avoid that we use a global variable
         */
        global $COMBO_STRAP_METAHEADER_HOOK_ALREADY_REGISTERED;
        if ($COMBO_STRAP_METAHEADER_HOOK_ALREADY_REGISTERED !== true) {
            global $EVENT_HANDLER;
            $method = array('\Combostrap\TplUtility', 'handleBootstrapMetaHeaders');
            /**
             * A call to a method is via an array and the hook declare a string
             * @noinspection PhpParamsInspection
             */
            $EVENT_HANDLER->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', null, $method);
            $COMBO_STRAP_METAHEADER_HOOK_ALREADY_REGISTERED = true;
        }

    }

    /**
     * Add the preloaded CSS resources
     * at the end
     */
    public static function addPreloadedResources()
    {
        // For the preload if any
        global $preloadedCss;
        //
        // Note: Adding this css in an animationFrame
        // such as https://github.com/jakearchibald/svgomg/blob/master/src/index.html#L183
        // would be difficult to test
        if (isset($preloadedCss)) {
            foreach ($preloadedCss as $link) {
                $htmlLink = '<link rel="stylesheet" href="' . $link['href'] . '" ';
                if ($link['crossorigin'] != "") {
                    $htmlLink .= ' crossorigin="' . $link['crossorigin'] . '" ';
                }
                if (!empty($link['class'])) {
                    $htmlLink .= ' class="' . $link['class'] . '" ';
                }
                // No integrity here
                $htmlLink .= '>';
                ptln($htmlLink);
            }
            /**
             * Reset
             * Needed in test when we start two requests
             */
            $preloadedCss = [];
        }

    }

    /**
     * @param $linkData - an array of link style sheet data
     * @return array - the array with the preload attributes
     */
    private static function toPreloadCss($linkData)
    {
        /**
         * Save the stylesheet to load it at the end
         */
        global $preloadedCss;
        $preloadedCss[] = $linkData;

        /**
         * Modify the actual tag data
         * Change the loading mechanism to preload
         */
        $linkData['rel'] = 'preload';
        $linkData['as'] = 'style';
        return $linkData;
    }

    public static function getBootStrapVersion()
    {
        $bootstrapStyleSheetVersion = tpl_getConf(TplUtility::CONF_BOOTSTRAP_VERSION_STYLESHEET, TplUtility::DEFAULT_BOOTSTRAP_VERSION_STYLESHEET);
        $bootstrapStyleSheetArray = explode(self::BOOTSTRAP_VERSION_STYLESHEET_SEPARATOR, $bootstrapStyleSheetVersion);
        return $bootstrapStyleSheetArray[0];
    }

    public static function getStyleSheetConf()
    {
        $bootstrapStyleSheetVersion = tpl_getConf(TplUtility::CONF_BOOTSTRAP_VERSION_STYLESHEET, TplUtility::DEFAULT_BOOTSTRAP_VERSION_STYLESHEET);
        $bootstrapStyleSheetArray = explode(self::BOOTSTRAP_VERSION_STYLESHEET_SEPARATOR, $bootstrapStyleSheetVersion);
        return $bootstrapStyleSheetArray[1];
    }

    /**
     * Return the XHMTL for the bar or null if not found
     *
     * An adaptation from {@link tpl_include_page()}
     * to make the cache namespace
     *
     * @param $barName
     * @return string|null
     *
     *
     * Note: Even if there is no sidebar
     * the rendering may output
     * debug information in the form of
     * an HTML comment
     */
    public static function renderSlot($barName)
    {

        if (class_exists("ComboStrap\Page")) {
            $page = new Page($barName);
            return $page->render();
        } else {
            TplUtility::msg("The combo plugin is not installed, sidebars automatic bursting will not work", self::LVL_MSG_INFO, "sidebars");
            return tpl_include_page($barName, 0, 1);
        }

    }

    private static function getBootStrapMajorVersion()
    {
        return self::getBootStrapVersion()[0];
    }


    public static function getSideKickSlotPageName()
    {

        return TplUtility::migrateSlotConfAndGetValue(
            TplUtility::CONF_SIDEKICK_SLOT_PAGE_NAME,
            "slot_sidekick",
            TplUtility::CONF_SIDEKICK_OLD,
            "sidekickbar",
            "sidekick_slot"
        );
    }

    public static function getHeaderSlotPageName()
    {

        return TplUtility::migrateSlotConfAndGetValue(
            TplUtility::CONF_HEADER_SLOT_PAGE_NAME,
            TplUtility::CONF_SLOT_HEADER_PAGE_NAME_VALUE,
            TplUtility::CONF_HEADER_OLD,
            TplUtility::CONF_HEADER_OLD_VALUE,
            "header_slot"
        );

    }

    public static function getFooterSlotPageName()
    {
        return self::migrateSlotConfAndGetValue(
            TplUtility::CONF_FOOTER_SLOT_PAGE_NAME,
            "slot_footer",
            TplUtility::CONF_FOOTER_OLD,
            "footerbar",
            "footer_slot"
        );
    }

    /**
     * @param string $key the key configuration
     * @param string $value the value
     * @return bool
     */
    public static function updateConfiguration($key, $value)
    {

        /**
         * Hack to avoid updating during {@link \TestRequest}
         * when not asked
         * Because the test request environment is wiped out only on the class level,
         * the class / test function needs to specifically say that it's open
         * to the modification of the configuration
         */
        global $_REQUEST;
        if (defined('DOKU_UNITTEST') && !isset($_REQUEST[self::COMBO_TEST_UPDATE])) {

            /**
             * This hack resolves two problems
             *
             * First one
             * this is a test request
             * the local.php file has a the `DOKU_TMP_DATA`
             * constant in the file and updating the file
             * with this method will then update the value of savedir to DOKU_TMP_DATA
             * we get then the error
             * The datadir ('pages') at DOKU_TMP_DATA/pages is not found
             *
             *
             * Second one
             * if in a php test unit, we send a php request two times
             * the headers have been already send and the
             * {@link msg()} function will send them
             * causing the {@link TplUtility::outputBufferShouldBeEmpty() output buffer check} to fail
             */
            global $MSG_shown;
            if (isset($MSG_shown) || headers_sent()) {
                return false;
            } else {
                return true;
            }

        }


        $configuration = new Configuration();
        $settings = $configuration->getSettings();

        $key = "tpl____strap____" . $key;
        if (isset($settings[$key])) {
            $setting = &$settings[$key];
            $setting->update($value);
            /**
             * We cannot update the setting
             * via the configuration object
             * We are taking another pass
             */

            $writer = new Writer();
            if (!$writer->isLocked()) {
                try {
                    $writer->save($settings);
                    return true;
                } catch (Exception $e) {
                    TplUtility::msg("An error occurred while trying to save automatically the configuration ($key) to the value ($value). Error: " . $e->getMessage());
                    return false;
                }
            } else {
                TplUtility::msg("The configuration file was locked. The upgrade configuration ($key) value could not be not changed to ($value)");
                return false;
            }

        } else {

            /**
             * When we run test,
             * strap is not always the active template
             * and therefore the configurations are not loaded
             */
            global $conf;
            if ($conf['template'] == TplUtility::TEMPLATE_NAME) {
                TplUtility::msg("The configuration ($key) is unknown and was therefore not change to ($value)");
            }
        }

        return false;


    }

    /**
     * Helper to migrate from bar to slot
     * @return mixed|string
     */
    public static function migrateSlotConfAndGetValue($newConf, $newDefaultValue, $oldConf, $oldDefaultValue, $canonical)
    {

        $name = tpl_getConf($newConf, null);
        if ($name == null) {
            $name = tpl_getConf($oldConf, null);
        }
        if ($name == null) {

            $foundOldName = false;
            if (page_exists($oldConf)) {
                $foundOldName = true;
            }

            if (!$foundOldName) {
                global $conf;
                $startPageName = $conf["start"];
                $startPagePath = wikiFN($startPageName);
                $directory = dirname($startPagePath);


                $childrenDirectories = glob($directory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
                foreach ($childrenDirectories as $childrenDirectory) {
                    $directoryName = pathinfo($childrenDirectory)['filename'];
                    $dokuFilePath = $directoryName . ":" . $oldDefaultValue;
                    if (page_exists($dokuFilePath)) {
                        $foundOldName = true;
                        break;
                    }
                }
            }

            if ($foundOldName) {
                $name = $oldDefaultValue;
            } else {
                $name = $newDefaultValue;
            }
            $updated = TplUtility::updateConfiguration($newConf, $name);
            if ($updated) {
                TplUtility::msg("The <a href=\"https://combostrap.com/$canonical\">$newConf</a> configuration was set with the value <mark>$name</mark>", self::LVL_MSG_INFO, $canonical);
            }
        }
        return $name;
    }

    /**
     * Output buffer checks
     *
     * It should be null before printing otherwise
     * you may get a text before the HTML header
     * and it mess up the whole page
     */
    public static function outputBufferShouldBeEmpty()
    {
        $length = ob_get_length();
        if ($length > 0) {
            $ob = ob_get_contents();
            ob_clean();
            /**
             * If you got this problem check that this is not a character before a  `<?php` declaration
             */
            TplUtility::msg("A plugin has send text before the creation of the page. Because it will mess the rendering, we have deleted it. The content was: (" . $ob . ")", TplUtility::LVL_MSG_ERROR, "strap");
        }
    }


    /**
     * @return string
     * Railbar items can add snippet in the head
     * And should then be could before the HTML output
     *
     * In Google Material Design, they call it a
     * navigational drawer
     * https://material.io/components/navigation-drawer
     */
    public static function getRailBar()
    {

        if (tpl_getConf(TplUtility::CONF_PRIVATE_RAIL_BAR) === 1 && empty($_SERVER['REMOTE_USER'])) {
            return "";
        }
        $breakpoint = tpl_getConf(TplUtility::CONF_BREAKPOINT_RAIL_BAR, TplUtility::BREAKPOINT_LARGE_NAME);

        $bootstrapBreakpoint = "";
        switch($breakpoint){
            case TplUtility::BREAKPOINT_EXTRA_SMALL_NAME:
                $bootstrapBreakpoint = "xs";
                break;
            case TplUtility::BREAKPOINT_SMALL_NAME:
                $bootstrapBreakpoint = "sm";
                break;
            case TplUtility::BREAKPOINT_MEDIUM_NAME:
                $bootstrapBreakpoint = "md";
                break;
            case TplUtility::BREAKPOINT_LARGE_NAME:
                $bootstrapBreakpoint = "lg";
                break;
            case TplUtility::BREAKPOINT_EXTRA_LARGE_NAME:
                $bootstrapBreakpoint = "xl";
                break;
            case TplUtility::BREAKPOINT_EXTRA_EXTRA_LARGE_NAME:
                $bootstrapBreakpoint = "xxl";
                break;
        }

        $classOffCanvas="";
        $classFixed="";
        if(!empty($bootstrapBreakpoint)){
            $classOffCanvas="class=\"d-$bootstrapBreakpoint-none\"";
            $classFixed="class=\"d-none d-$bootstrapBreakpoint-flex\"";
        }

        $railBarListItems = TplUtility::getRailBarListItems();
        $railBarOffCanvas = <<<EOF
<div id="railbar-offcanvas-wrapper" $classOffCanvas>
    <button id="railbar-offcanvas-open" class="btn" type="button" data-bs-toggle="offcanvas"
            data-bs-target="#railbar-offcanvas" aria-controls="railbar-offcanvas">
    </button>

    <div id="railbar-offcanvas" class="offcanvas offcanvas-end" tabindex="-1"
         aria-labelledby="offcanvas-label"
         style="visibility: hidden;" aria-hidden="true">
         <h5 class="d-none" id="offcanvas-label">Railbar</h5>
        <!-- Pseudo relative element  https://stackoverflow.com/questions/6040005/relatively-position-an-element-without-it-taking-up-space-in-document-flow -->
        <div style="position: relative; width: 0; height: 0">
            <button id="railbar-offcanvas-close" class="btn" type="button" data-bs-dismiss="offcanvas"
                    aria-label="Close">
            </button>
        </div>
        <div id="railbar-offcanvas-body" class="offcanvas-body" style="align-items: center;display: flex;">
            $railBarListItems
        </div>
    </div>
</div>
EOF;

        if($breakpoint!=TplUtility::BREAKPOINT_NEVER_NAME) {
            $railBarFixed = <<<EOF
<div id="railbar-fixed" style="z-index: 1030;" $classFixed>
    <div class="tools">
        $railBarListItems
    </div>
</div>
EOF;
            return <<<EOF
$railBarOffCanvas
$railBarFixed
EOF;
        } else {

            return $railBarOffCanvas;

        }


    }

    /**
     *
     * https://material.io/components/navigation-rail|Navigation rail
     * @return string - the ul part of the railbar
     */
    public
    static function getRailBarListItems()
    {
        $liUserTools = (new UserMenu())->getListItems('action');
        $liPageTools = (new PageMenu())->getListItems();
        $liSiteTools = (new SiteMenu())->getListItems('action');
        // FYI: The below code outputs all menu in mobile (in another HTML layout)
        // echo (new \dokuwiki\Menu\MobileMenu())->getDropdown($lang['tools']);
        return <<<EOF
<ul class="railbar">
    <li><a href="#" style="height: 19px;line-height: 17px;text-align: left;font-weight:bold"><span>User</span><svg style="height:19px"></svg></a></li>
    $liUserTools
    <li><a href="#" style="height: 19px;line-height: 17px;text-align: left;font-weight:bold"><span>Page</span><svg style="height:19px"></svg></a></li>
    $liPageTools
    <li><a href="#" style="height: 19px;line-height: 17px;text-align: left;font-weight:bold"><span>Website</span><svg style="height:19px"></svg></a></li>
    $liSiteTools
</ul>
EOF;

    }

    /**
     * Hierarchical breadcrumbs
     *
     * This will return the Hierarchical breadcrumbs.
     *
     * Config:
     *    - $conf['youarehere'] must be true
     *    - add $lang['youarehere'] if $printPrefix is true
     *
     * @param bool $printPrefix print or not the $lang['youarehere']
     * @return string
     */
    function renderHierarchicalBreadcrumb($printPrefix = false)
    {

        global $conf;
        global $lang;

        // check if enabled
        if (!$conf['youarehere']) return;

        // print intermediate namespace links
        $htmlOutput = '<ol class="breadcrumb">' . PHP_EOL;

        // Print the home page
        $htmlOutput .= '<li>' . PHP_EOL;
        if ($printPrefix) {
            $htmlOutput .= $lang['youarehere'] . ' ';
        }
        $page = $conf['start'];
        $htmlOutput .= tpl_link(wl($page), '<span class="glyphicon glyphicon-home" aria-hidden="true"></span>', 'title="' . tpl_pagetitle($page, true) . '"', $return = true);
        $htmlOutput .= '</li>' . PHP_EOL;

        // Print the parts if there is more than one
        global $ID;
        $idParts = explode(':', $ID);
        if (count($idParts) > 1) {

            // Print the parts without the last one ($count -1)
            $page = "";
            for ($i = 0; $i < count($idParts) - 1; $i++) {

                $page .= $idParts[$i] . ':';

                // Skip home page of the namespace
                // if ($page == $conf['start']) continue;

                // The last part is the active one
//            if ($i == $count) {
//                $htmlOutput .= '<li class="active">';
//            } else {
//                $htmlOutput .= '<li>';
//            }

                $htmlOutput .= '<li>';
                // html_wikilink because the page has the form pagename: and not pagename:pagename
                $htmlOutput .= html_wikilink($page);
                $htmlOutput .= '</li>' . PHP_EOL;

            }
        }

        // Skipping Wiki Global Root Home Page
//    resolve_pageid('', $page, $exists);
//    if(isset($page) && $page == $idPart.$idParts[$i]) {
//        echo '</ol>'.PHP_EOL;
//        return true;
//    }
//    // skipping for namespace index
//    $page = $idPart.$idParts[$i];
//    if($page == $conf['start']) {
//        echo '</ol>'.PHP_EOL;
//        return true;
//    }

        // print current page
//    print '<li>';
//    tpl_link(wl($page), tpl_pagetitle($page,true), 'title="' . $page . '"');
        $htmlOutput .= '</li>' . PHP_EOL;
        // close the breadcrumb
        $htmlOutput .= '</ol>' . PHP_EOL;
        return $htmlOutput;

    }


    /*
     * Function return the page name from an id
     * @author Nicolas GERARD
     *
     * @param string $sep Separator between entries
     * @return bool
     */

    function getPageTitle($id)
    {

        // page names
        $name = noNSorNS($id);
        if (useHeading('navigation')) {
            // get page title
            $title = p_get_first_heading($id, METADATA_RENDER_USING_SIMPLE_CACHE);
            if ($title) {
                $name = $title;
            }
        }
        return $name;

    }

    function renderSearchForm($ajax = true, $autocomplete = true)
    {
        global $lang;
        global $ACT;
        global $QUERY;

        // don't print the search form if search action has been disabled
        if (!actionOK('search')) return false;

        print '<form id="navBarSearch" action="' . wl() . '" accept-charset="utf-8" class="search form-inline my-lg-0" id="dw__search" method="get" role="search">';
        print '<input type="hidden" name="do" value="search" />';
        print '<label class="sr-only" for="search">Search Term</label>';
        print '<input type="text" ';
        if ($ACT == 'search') print 'value="' . htmlspecialchars($QUERY) . '" ';
        print 'placeholder="' . $lang['btn_search'] . '..." ';
        if (!$autocomplete) print 'autocomplete="off" ';
        print 'id="qsearch__in" accesskey="f" name="id" class="edit form-control" title="[F]" />';
//    print '<button type="submit" title="'.$lang['btn_search'].'">'.$lang['btn_search'].'</button>';
        if ($ajax) print '<div id="qsearch__out" class="ajax_qsearch JSpopup"></div>';
        print '</form>';
        return true;
    }

    /**
     * This is a fork of tpl_actionlink where I have added the class parameters
     *
     * Like the action buttons but links
     *
     * @param string $type action command
     * @param string $pre prefix of link
     * @param string $suf suffix of link
     * @param string $inner innerHML of link
     * @param bool $return if true it returns html, otherwise prints
     * @param string $class the class to be added
     * @return bool|string html or false if no data, true if printed
     * @see    tpl_get_action
     *
     * @author Adrian Lang <mail@adrianlang.de>
     */
    function renderActionLink($type, $class = '', $pre = '', $suf = '', $inner = '', $return = false)
    {
        global $lang;
        $data = tpl_get_action($type);
        if ($data === false) {
            return false;
        } elseif (!is_array($data)) {
            $out = sprintf($data, 'link');
        } else {
            /**
             * @var string $accesskey
             * @var string $id
             * @var string $method
             * @var bool $nofollow
             * @var array $params
             * @var string $replacement
             */
            extract($data);
            if (strpos($id, '#') === 0) {
                $linktarget = $id;
            } else {
                $linktarget = wl($id, $params);
            }
            $caption = $lang['btn_' . $type];
            if (strpos($caption, '%s')) {
                $caption = sprintf($caption, $replacement);
            }
            $akey = $addTitle = '';
            if ($accesskey) {
                $akey = 'accesskey="' . $accesskey . '" ';
                $addTitle = ' [' . strtoupper($accesskey) . ']';
            }
            $rel = $nofollow ? 'rel="nofollow" ' : '';
            $out = $pre . tpl_link(
                    $linktarget, (($inner) ? $inner : $caption),
                    'class="nav-link action ' . $type . ' ' . $class . '" ' .
                    $akey . $rel .
                    'title="' . hsc($caption) . $addTitle . '"', true
                ) . $suf;
        }
        if ($return) return $out;
        echo $out;
        return true;
    }


    /**
     * @return array
     * Return the headers needed by this template
     *
     * @throws Exception
     */
    static function getBootstrapMetaHeaders()
    {

        // The version
        $bootstrapVersion = TplUtility::getBootStrapVersion();
        if ($bootstrapVersion === false) {
            /**
             * Strap may be called for test
             * by combo
             * In this case, the conf may not be reloaded
             */
            self::reloadConf();
            $bootstrapVersion = TplUtility::getBootStrapVersion();
            if ($bootstrapVersion === false) {
                throw new Exception("Bootstrap version should not be false");
            }
        }
        $scriptsMeta = self::buildBootstrapMetas($bootstrapVersion);

        // if cdn
        $useCdn = tpl_getConf(self::CONF_USE_CDN);


        // Build the returned Js script array
        $jsScripts = array();
        foreach ($scriptsMeta as $key => $script) {
            $path_parts = pathinfo($script["file"]);
            $extension = $path_parts['extension'];
            if ($extension === "js") {
                $src = DOKU_BASE . "lib/tpl/strap/bootstrap/$bootstrapVersion/" . $script["file"];
                if ($useCdn) {
                    if (isset($script["url"])) {
                        $src = $script["url"];
                    }
                }
                $jsScripts[$key] =
                    array(
                        'src' => $src,
                        'defer' => null
                    );
                if (isset($script['integrity'])) {
                    $jsScripts[$key]['integrity'] = $script['integrity'];
                    $jsScripts[$key]['crossorigin'] = 'anonymous';
                }
            }
        }

        $css = array();
        $cssScript = $scriptsMeta['css'];
        $href = DOKU_BASE . "lib/tpl/strap/bootstrap/$bootstrapVersion/" . $cssScript["file"];
        if ($useCdn) {
            if (isset($script["url"])) {
                $href = $script["url"];
            }
        }
        $css['css'] =
            array(
                'href' => $href,
                'rel' => "stylesheet"
            );
        if (isset($script['integrity'])) {
            $css['css']['integrity'] = $script['integrity'];
            $css['css']['crossorigin'] = 'anonymous';
        }


        return array(
            'script' => $jsScripts,
            'link' => $css
        );


    }

    /**
     * @return array - A list of all available stylesheets
     * This function is used to build the configuration as a list of files
     */
    static function getStylesheetsForMetadataConfiguration()
    {
        $cssVersionsMetas = self::getStyleSheetsFromJsonFileAsArray();
        $listVersionStylesheetMeta = array();
        foreach ($cssVersionsMetas as $bootstrapVersion => $cssVersionMeta) {
            foreach ($cssVersionMeta as $fileName => $values) {
                $listVersionStylesheetMeta[] = $bootstrapVersion . TplUtility::BOOTSTRAP_VERSION_STYLESHEET_SEPARATOR . $fileName;
            }
        }
        return $listVersionStylesheetMeta;
    }

    /**
     *
     * @param $version - return only the selected version if set
     * @return array - an array of the meta JSON custom files
     */
    static function getStyleSheetsFromJsonFileAsArray($version = null)
    {

        $jsonAsArray = true;
        $stylesheetsFile = __DIR__ . '/../bootstrap/bootstrapStylesheet.json';
        $styleSheets = json_decode(file_get_contents($stylesheetsFile), $jsonAsArray);
        if ($styleSheets == null) {
            self::msg("Unable to read the file {$stylesheetsFile} as json");
        }


        $localStyleSheetsFile = __DIR__ . '/../bootstrap/bootstrapLocal.json';
        if (file_exists($localStyleSheetsFile)) {
            $localStyleSheets = json_decode(file_get_contents($localStyleSheetsFile), $jsonAsArray);
            if ($localStyleSheets == null) {
                self::msg("Unable to read the file {$localStyleSheets} as json");
            }
            foreach ($styleSheets as $bootstrapVersion => &$stylesheetsFiles) {
                if (isset($localStyleSheets[$bootstrapVersion])) {
                    $stylesheetsFiles = array_merge($stylesheetsFiles, $localStyleSheets[$bootstrapVersion]);
                }
            }
        }

        if (isset($version)) {
            if (!isset($styleSheets[$version])) {
                self::msg("The bootstrap version ($version) could not be found in the custom CSS file ($stylesheetsFile, or $localStyleSheetsFile)");
            } else {
                $styleSheets = $styleSheets[$version];
            }
        }

        /**
         * Select Rtl or Ltr
         * Stylesheet name may have another level
         * with direction property of the language
         *
         * Bootstrap needs another stylesheet
         * See https://getbootstrap.com/docs/5.0/getting-started/rtl/
         */
        global $lang;
        $direction = $lang["direction"];
        if (empty($direction)) {
            $direction = "ltr";
        }
        $directedStyleSheets = [];
        foreach ($styleSheets as $name => $styleSheetDefinition) {
            if (isset($styleSheetDefinition[$direction])) {
                $directedStyleSheets[$name] = $styleSheetDefinition[$direction];
            } else {
                $directedStyleSheets[$name] = $styleSheetDefinition;
            }
        }

        return $directedStyleSheets;
    }

    /**
     *
     * Build from all Bootstrap JSON meta files only one array
     * @param $version
     * @return array
     *
     */
    static function buildBootstrapMetas($version)
    {

        $jsonAsArray = true;
        $bootstrapJsonFile = __DIR__ . '/../bootstrap/bootstrapJavascript.json';
        $bootstrapMetas = json_decode(file_get_contents($bootstrapJsonFile), $jsonAsArray);
        // Decodage problem
        if ($bootstrapMetas == null) {
            self::msg("Unable to read the file {$bootstrapJsonFile} as json");
            return array();
        }
        if (!isset($bootstrapMetas[$version])) {
            self::msg("The bootstrap version ($version) could not be found in the file $bootstrapJsonFile");
            return array();
        }
        $bootstrapMetas = $bootstrapMetas[$version];


        // Css
        $bootstrapCssFile = TplUtility::getStyleSheetConf();
        $bootstrapCustomMetas = self::getStyleSheetsFromJsonFileAsArray($version);

        if (!isset($bootstrapCustomMetas[$bootstrapCssFile])) {
            self::msg("The bootstrap custom file ($bootstrapCssFile) could not be found in the custom CSS files for the version ($version)");
        } else {
            $bootstrapMetas['css'] = $bootstrapCustomMetas[$bootstrapCssFile];
        }


        return $bootstrapMetas;
    }

    /**
     * @param Doku_Event $event
     * @param $param
     * Function that handle the META HEADER event
     *   * It will add the Bootstrap Js and CSS
     *   * Make all script and resources defer
     * @throws Exception
     */
    static function handleBootstrapMetaHeaders(Doku_Event &$event, $param)
    {

        $debug = tpl_getConf('debug');
        if ($debug) {
            self::addAsHtmlComment('Request: ' . json_encode($_REQUEST));
        }


        $newHeaderTypes = array();
        $bootstrapHeaders = self::getBootstrapMetaHeaders();
        $eventHeaderTypes = $event->data;
        foreach ($eventHeaderTypes as $headerType => $headerData) {
            switch ($headerType) {

                case "link":
                    // index, rss, manifest, search, alternate, stylesheet
                    // delete edit
                    $bootstrapCss = $bootstrapHeaders[$headerType]['css'];
                    $headerData[] = $bootstrapCss;

                    // preload all CSS is an heresy as it creates a FOUC (Flash of non-styled element)
                    // but we known it now and this is
                    $cssPreloadConf = tpl_getConf(self::CONF_PRELOAD_CSS);
                    $newLinkData = array();
                    foreach ($headerData as $linkData) {
                        switch ($linkData['rel']) {
                            case 'edit':
                                break;
                            case 'stylesheet':
                                /**
                                 * Preloading default to the configuration
                                 */
                                $preload = $cssPreloadConf;
                                /**
                                 * Preload can be set at the array level with the critical attribute
                                 * If the preload attribute is present
                                 * We get that for instance for css animation style sheet
                                 * that are not needed for rendering
                                 */
                                if (isset($linkData["critical"])) {
                                    $critical = $linkData["critical"];
                                    $preload = !(filter_var($critical, FILTER_VALIDATE_BOOLEAN));
                                    unset($linkData["critical"]);
                                }
                                if ($preload) {
                                    $newLinkData[] = TplUtility::toPreloadCss($linkData);
                                } else {
                                    $newLinkData[] = $linkData;
                                }
                                break;
                            default:
                                $newLinkData[] = $linkData;
                                break;
                        }
                    }

                    $newHeaderTypes[$headerType] = $newLinkData;
                    break;

                case "script":

                    /**
                     * Do we delete the dokuwiki javascript ?
                     */
                    $scriptToDeletes = [];
                    if (empty($_SERVER['REMOTE_USER']) && tpl_getConf(TplUtility::CONF_DISABLE_BACKEND_JAVASCRIPT, 0)) {
                        $scriptToDeletes = [
                            //'JSINFO', Don't delete Jsinfo !! It contains metadata information (that is used to get context)
                            'js.php'
                        ];
                        if (TplUtility::getBootStrapMajorVersion() == "5") {
                            // bs 5 does not depends on jquery
                            $scriptToDeletes[] = "jquery.php";
                        }
                    }

                    /**
                     * The new script array
                     */
                    $newScriptData = array();
                    // A variable to hold the Jquery scripts
                    // jquery-migrate, jquery, jquery-ui ou jquery.php
                    // see https://www.dokuwiki.org/config:jquerycdn
                    $jqueryDokuScripts = array();
                    foreach ($headerData as $scriptData) {

                        foreach ($scriptToDeletes as $scriptToDelete) {
                            if (isset($scriptData["_data"]) && !empty($scriptData["_data"])) {
                                $haystack = $scriptData["_data"];
                            } else {
                                $haystack = $scriptData["src"];
                            }
                            if (preg_match("/$scriptToDelete/i", $haystack)) {
                                continue 2;
                            }
                        }

                        $critical = false;
                        if (isset($scriptData["critical"])) {
                            $critical = $scriptData["critical"];
                            unset($scriptData["critical"]);
                        }

                        // defer is only for external resource
                        // if this is not, this is illegal
                        if (isset($scriptData["src"])) {
                            if (!$critical) {
                                $scriptData['defer'] = null;
                            }
                        }

                        if (isset($scriptData["type"])) {
                            $type = strtolower($scriptData["type"]);
                            if ($type == "text/javascript") {
                                unset($scriptData["type"]);
                            }
                        }

                        // The charset attribute on the script element is obsolete.
                        if (isset($scriptData["charset"])) {
                            unset($scriptData["charset"]);
                        }

                        // Jquery ?
                        $jqueryFound = false;
                        // script may also be just an online script without the src attribute
                        if (array_key_exists('src', $scriptData)) {
                            $jqueryFound = strpos($scriptData['src'], 'jquery');
                        }
                        if ($jqueryFound === false) {
                            $newScriptData[] = $scriptData;
                        } else {
                            $jqueryDokuScripts[] = $scriptData;
                        }

                    }

                    // Add Jquery at the beginning
                    $boostrapMajorVersion = TplUtility::getBootStrapMajorVersion();
                    if ($boostrapMajorVersion == "4") {
                        if (
                            empty($_SERVER['REMOTE_USER'])
                            && tpl_getConf(self::CONF_JQUERY_DOKU) == 0
                        ) {
                            // We take the Jquery of Bootstrap
                            $newScriptData = array_merge($bootstrapHeaders[$headerType], $newScriptData);
                        } else {
                            // Logged in
                            // We take the Jqueries of doku and we add Bootstrap
                            $newScriptData = array_merge($jqueryDokuScripts, $newScriptData); // js
                            // We had popper of Bootstrap
                            $newScriptData[] = $bootstrapHeaders[$headerType]['popper'];
                            // We had the js of Bootstrap
                            $newScriptData[] = $bootstrapHeaders[$headerType]['js'];
                        }
                    } else {

                        // There is no JQuery in 5
                        // We had the js of Bootstrap (bundle with popper)
                        // Add Jquery before the js.php
                        $newScriptData = array_merge($jqueryDokuScripts, $newScriptData); // js
                        // Then add at the top of the top (first of the first) bootstrap
                        // Why ? Because Jquery should be last to be able to see the missing icon
                        // https://stackoverflow.com/questions/17367736/jquery-ui-dialog-missing-close-icon
                        $newScriptData = array_merge([$bootstrapHeaders[$headerType]['js']], $newScriptData);

                    }


                    $newHeaderTypes[$headerType] = $newScriptData;
                    break;
                case "meta":
                    $newHeaderData = array();
                    foreach ($headerData as $metaData) {
                        // Content should never be null
                        // Name may change
                        // https://www.w3.org/TR/html4/struct/global.html#edef-META
                        if (!key_exists("content", $metaData)) {
                            $message = "Strap - The head meta (" . print_r($metaData, true) . ") does not have a content property";
                            msg($message, -1, "", "", MSG_ADMINS_ONLY);
                            if (defined('DOKU_UNITTEST')
                            ) {
                                throw new \RuntimeException($message);
                            }
                        } else {
                            $content = $metaData["content"];
                            if (empty($content)) {
                                $messageEmpty = "Strap - the below head meta has an empty content property (" . print_r($metaData, true) . ")";
                                msg($messageEmpty, -1, "", "", MSG_ADMINS_ONLY);
                                if (defined('DOKU_UNITTEST')
                                ) {
                                    throw new \RuntimeException($messageEmpty);
                                }
                            } else {
                                $newHeaderData[] = $metaData;
                            }
                        }
                    }
                    $newHeaderTypes[$headerType] = $newHeaderData;
                    break;
                case "noscript": // https://github.com/ComboStrap/dokuwiki-plugin-gtm/blob/master/action.php#L32
                case "style":
                    $newHeaderTypes[$headerType] = $headerData;
                    break;
                default:
                    $message = "Strap - The header type ($headerType) is unknown and was not controlled.";
                    $newHeaderTypes[$headerType] = $headerData;
                    msg($message, -1, "", "", MSG_ADMINS_ONLY);
                    if (defined('DOKU_UNITTEST')
                    ) {
                        throw new \RuntimeException($message);
                    }
            }
        }

        if ($debug) {
            self::addAsHtmlComment('Script Header : ' . json_encode($newHeaderTypes['script']));
        }
        $event->data = $newHeaderTypes;


    }

    /**
     * Returns the icon link as created by https://realfavicongenerator.net/
     *
     *
     *
     * @return string
     */
    static function renderFaviconMetaLinks()
    {

        $return = '';

        // FavIcon.ico
        $possibleLocation = array(':wiki:favicon.ico', ':favicon.ico', 'images/favicon.ico');
        $return .= '<link rel="shortcut icon" href="' . tpl_getMediaFile($possibleLocation, $fallback = true) . '" />' . NL;

        // Icon Png
        $possibleLocation = array(':wiki:favicon-32x32.png', ':favicon-32x32.png', 'images/favicon-32x32.png');
        $return .= '<link rel="icon" type="image/png" sizes="32x32" href="' . tpl_getMediaFile($possibleLocation, $fallback = true) . '"/>';

        $possibleLocation = array(':wiki:favicon-16x16.png', ':favicon-16x16.png', 'images/favicon-16x16.png');
        $return .= '<link rel="icon" type="image/png" sizes="16x16" href="' . tpl_getMediaFile($possibleLocation, $fallback = true) . '"/>';

        // Apple touch icon
        $possibleLocation = array(':wiki:apple-touch-icon.png', ':apple-touch-icon.png', 'images/apple-touch-icon.png');
        $return .= '<link rel="apple-touch-icon" href="' . tpl_getMediaFile($possibleLocation, $fallback = true) . '" />' . NL;

        return $return;

    }

    static function renderPageTitle()
    {

        global $conf;
        global $ID;
        $title = tpl_pagetitle($ID, true) . ' [' . $conf["title"] . ']';
        // trigger event here
        Event::createAndTrigger('TPL_TITLE_OUTPUT', $title, '\ComboStrap\TplUtility::callBackPageTitle', true);
        return true;

    }

    /**
     * Print the title that we get back from the event TPL_TITLE_OUTPUT
     * triggered by the function {@link tpl_strap_title()}
     * @param $title
     */
    static function callBackPageTitle($title)
    {
        echo $title;
    }

    /**
     *
     * Set a template conf value
     *
     * To set a template configuration, you need to first load them
     * and there is no set function in template.php
     *
     * @param $confName - the configuration name
     * @param $confValue - the configuration value
     */
    static function setConf($confName, $confValue)
    {

        /**
         * Env variable
         */
        global $conf;
        $template = $conf['template'];

        if ($template != "strap") {
            throw new \RuntimeException("This is not the strap template, in test, active it in setup");
        }

        /**
         *  Make sure to load the configuration first by calling getConf
         */
        $actualValue = tpl_getConf($confName);
        if ($actualValue === false) {

            self::reloadConf();

            // Check that the conf was loaded
            if (tpl_getConf($confName) === false) {
                throw new \RuntimeException("The configuration (" . $confName . ") returns no value or has no default");
            }
        }

        $conf['tpl'][$template][$confName] = $confValue;

    }

    /**
     * When running multiple test, the function {@link tpl_getConf()}
     * does not reload the configuration twice
     */
    static function reloadConf()
    {
        /**
         * Env variable
         */
        global $conf;
        $template = $conf['template'];

        $tconf = tpl_loadConfig();
        if ($tconf !== false) {
            foreach ($tconf as $key => $value) {
                if (isset($conf['tpl'][$template][$key])) continue;
                $conf['tpl'][$template][$key] = $value;
            }
        }
    }


    /**
     * Send a message to a manager and log it
     * Fail if in test
     * @param string $message
     * @param int $level - the level see LVL constant
     * @param string $canonical - the canonical
     */
    static function msg($message, $level = self::LVL_MSG_ERROR, $canonical = "strap")
    {
        $strapUrl = self::getStrapUrl();
        $prefix = "<a href=\"$strapUrl\">Strap</a>";
        $prefix = '<a href="https://combostrap.com/' . $canonical . '">' . ucfirst($canonical) . '</a>';

        $htmlMsg = $prefix . " - " . $message;
        if ($level != self::LVL_MSG_DEBUG) {
            msg($htmlMsg, $level, '', '', MSG_MANAGERS_ONLY);
        }
        /**
         * Print to a log file
         * Note: {@link dbg()} dbg print to the web page
         */
        $prefix = 'strap';
        if ($canonical != null) {
            $prefix .= ' - ' . $canonical;
        }
        $msg = $prefix . ' - ' . $message;
        dbglog($msg);
        if (defined('DOKU_UNITTEST') && ($level == self::LVL_MSG_WARNING || $level == self::LVL_MSG_ERROR)) {
            throw new \RuntimeException($msg);
        }
    }

    /**
     * @param bool $prependTOC
     * @return false|string - Adapted from {@link tpl_content()} to return the HTML
     */
    static function tpl_content($prependTOC = true)
    {
        global $ACT;
        global $INFO;
        $INFO['prependTOC'] = $prependTOC;

        ob_start();
        Event::createAndTrigger('TPL_ACT_RENDER', $ACT, 'tpl_content_core');
        $html_output = ob_get_clean();

        /**
         * The action null does nothing.
         * See {@link Event::trigger()}
         */
        Event::createAndTrigger('TPL_CONTENT_DISPLAY', $html_output, null);

        return $html_output;
    }

    static function getPageHeader()
    {

        $navBarPageName = TplUtility::getHeaderSlotPageName();
        if ($id = page_findnearest($navBarPageName)) {

            $header = tpl_include_page($id, 0, false);

        } else {

            $domain = self::getApexDomainUrl();
            $header = '<div class="container p-3" style="text-align: center;position:relative;z-index:100">Welcome to the <a href="' . $domain . '/">Strap template</a>.<br/>
            If you don\'t known the <a href="https://combostrap.com/">ComboStrap</a>, it\'s recommended to follow the <a href="' . $domain . '/getting_started">Getting Started Guide</a>.<br/>
            Otherwise, to create a menu bar in the header, create a page with the id (' . html_wikilink(':' . $navBarPageName) . ') and the <a href="' . $domain . '/menubar">menubar component</a>.
            </div>';

        }
        // No header on print
        return "<div class=\"d-print-none\">$header</div>";

    }

    static function getFooter()
    {
        $domain = self::getApexDomainUrl();

        $footerPageName = TplUtility::getFooterSlotPageName();
        if ($id = page_findnearest($footerPageName)) {
            $footer = tpl_include_page($id, 0, false);
        } else {
            $footer = '<div class="container p-3" style="text-align: center">Welcome to the <a href="' . $domain . '/strap">Strap template</a>. To get started, create a page with the id ' . html_wikilink(':' . $footerPageName) . ' to create a footer.</div>';
        }

        // No footer on print
        return "<div class=\"d-print-none\">$footer</div>";
    }

    static function getPoweredBy()
    {

        $domain = self::getApexDomainUrl();
        $version = self::getFullQualifyVersion();
        $poweredBy = "<div class=\"mx-auto\" style=\"width: 300px;text-align: center;margin-bottom: 1rem\">";
        $poweredBy .= "  <small><i>Powered by <a href=\"$domain\" title=\"ComboStrap " . $version . "\" style=\"color:#495057\">ComboStrap</a></i></small>";
        $poweredBy .= '</div>';
        return $poweredBy;
    }


}


