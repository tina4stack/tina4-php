<?php


use ComboStrap\Identity;
use ComboStrap\DokuPath;
use ComboStrap\LatePublication;
use ComboStrap\LowQualityPage;
use ComboStrap\Page;
use ComboStrap\PageProtection;
use ComboStrap\Publication;
use ComboStrap\StringUtility;

require_once(__DIR__ . '/../ComboStrap/LowQualityPage.php');
require_once(__DIR__ . '/../ComboStrap/PageProtection.php');
require_once(__DIR__ . '/../ComboStrap/DokuPath.php');

/**
 *
 */
class action_plugin_combo_pageprotection extends DokuWiki_Action_Plugin
{


    public function register(Doku_Event_Handler $controller)
    {


        /**
         * https://www.dokuwiki.org/devel:event:pageutils_id_hidepage
         */
        $controller->register_hook('PAGEUTILS_ID_HIDEPAGE', 'BEFORE', $this, 'handleHiddenCheck', array());

        /**
         * https://www.dokuwiki.org/devel:event:auth_acl_check
         */
        $controller->register_hook('AUTH_ACL_CHECK', 'AFTER', $this, 'handleAclCheck', array());

        /**
         * https://www.dokuwiki.org/devel:event:sitemap_generate
         */
        $controller->register_hook('SITEMAP_GENERATE', 'BEFORE', $this, 'handleSiteMapGenerate', array());

        /**
         * https://www.dokuwiki.org/devel:event:search_query_pagelookup
         */
        $controller->register_hook('SEARCH_QUERY_PAGELOOKUP', 'AFTER', $this, 'handleSearchPageLookup', array());

        /**
         * https://www.dokuwiki.org/devel:event:search_query_fullpage
         */
        $controller->register_hook('SEARCH_QUERY_FULLPAGE', 'AFTER', $this, 'handleSearchFullPage', array());

        /**
         * https://www.dokuwiki.org/devel:event:feed_data_process
         */
        $controller->register_hook('FEED_DATA_PROCESS', 'BEFORE', $this, 'handleRssFeed', array());

        /**
         * Add logged in indicator for Javascript
         */
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'handleAnonymousJsIndicator');

        /**
         * Robots meta
         * https://www.dokuwiki.org/devel:event:tpl_metaheader_output
         */
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'handleRobotsMeta', array());


    }

    /**
     * Set page has hidden
     * @param $event
     * @param $param
     */
    function handleHiddenCheck(&$event, $param)
    {

        /**
         * Only for public
         */
        if (Identity::isLoggedIn()) {
            return;
        }

        $id = $event->data['id'];
        if ($id == null) {
            /**
             * Happens in test when rendering
             * with instructions only
             */
            return;
        }
        $page = Page::createPageFromId($id);

        if ($page->isLowQualityPage()) {
            if (LowQualityPage::getLowQualityProtectionMode() == PageProtection::CONF_VALUE_HIDDEN) {
                $event->data['hidden'] = true;
                return;
            }
        }
        if ($page->isLatePublication()) {
            if (Publication::getLatePublicationProtectionMode() == PageProtection::CONF_VALUE_HIDDEN) {
                $event->data['hidden'] = true;
            }

        }

    }

    /**
     *
     * https://www.dokuwiki.org/devel:event:auth_acl_check
     * @param $event
     * @param $param
     */
    function handleAclCheck(&$event, $param)
    {
        /**
         * Only for public
         *
         * Note: user is also
         * to be found at
         * $user = $event->data['user'];
         */
        if (Identity::isLoggedIn()) {
            return;
        }

        /**
         * Are we on a page script
         */
        $imageScript = ["/lib/exe/mediamanager.php", "/lib/exe/detail.php"];
        if (in_array($_SERVER['SCRIPT_NAME'], $imageScript)) {
            // id may be null or end with a star
            // this is not a image
            return;
        }

        $id = $event->data['id'];

        $dokuPath = DokuPath::createUnknownFromId($id);
        if ($dokuPath->isPage()) {

            /**
             * It should be only a page
             * https://www.dokuwiki.org/devel:event:auth_acl_check
             */
            $page = Page::createPageFromId($id);

            if ($page->isLowQualityPage()) {
                if ($this->getConf(LowQualityPage::CONF_LOW_QUALITY_PAGE_PROTECTION_ENABLE, true)) {
                    $securityConf = $this->getConf(LowQualityPage::CONF_LOW_QUALITY_PAGE_PROTECTION_MODE, PageProtection::CONF_VALUE_ACL);
                    if ($securityConf == PageProtection::CONF_VALUE_ACL) {
                        $event->result = AUTH_NONE;
                        return;
                    }
                }
            }
            if ($page->isLatePublication()) {
                if ($this->getConf(Publication::CONF_LATE_PUBLICATION_PROTECTION_ENABLE, true)) {
                    $securityConf = $this->getConf(Publication::CONF_LATE_PUBLICATION_PROTECTION_MODE, PageProtection::CONF_VALUE_ACL);
                    if ($securityConf == PageProtection::CONF_VALUE_ACL) {
                        $event->result = AUTH_NONE;
                        return;
                    }
                }
            }

        }

    }

    function handleSiteMapGenerate(&$event, $param)
    {
        $pageItems = $event->data["items"];
        foreach ($pageItems as $key => $pageItem) {
            $url = $pageItem->url;
            $dokuPath = DokuPath::createFromUrl($url);
            $page = Page::createPageFromId($dokuPath->getId());
            if ($page->isLowQualityPage() && LowQualityPage::isProtectionEnabled()) {

                unset($event->data["items"][$key]);
                continue;

            }
            if ($page->isLatePublication() && Publication::isLatePublicationProtectionEnabled()) {
                unset($event->data["items"][$key]);
            }
        }

    }

    /**
     * @param $event
     * @param $param
     * The autocomplete do a search on page name
     */
    function handleSearchPageLookup(&$event, $param)
    {
        $this->excludePageFromSearch($event);
    }

    /**
     * @param $event
     * @param $param
     * The search page do a search on page name
     */
    function handleSearchFullPage(&$event, $param)
    {

        $this->excludePageFromSearch($event);
    }

    /**
     *
     * @param $event
     * @param $param
     * The Rss
     * https://www.dokuwiki.org/syndication
     * Example
     * https://example.com/feed.php?type=rss2&num=5
     */
    function handleRssFeed(&$event, $param)
    {
        $isLowQualityProtectionEnabled = LowQualityPage::isProtectionEnabled();
        $isLatePublicationProtectionEnabled = Publication::isLatePublicationProtectionEnabled();
        if (!$isLatePublicationProtectionEnabled && !$isLowQualityProtectionEnabled) {
            return;
        }

        $pagesToBeAdded = &$event->data["data"];
        foreach ($pagesToBeAdded as $key => $data) {

            // To prevent an Illegal string offset 'id'
            if(isset($data["id"])) {

                $page = Page::createPageFromId($data["id"]);

                if ($page->isLowQualityPage() && $isLowQualityProtectionEnabled) {
                    $protectionMode = LowQualityPage::getLowQualityProtectionMode();
                    if ($protectionMode != PageProtection::CONF_VALUE_ROBOT) {
                        unset($pagesToBeAdded[$key]);
                    }
                }

                if ($page->isLatePublication() && $isLatePublicationProtectionEnabled) {
                    $protectionMode = Publication::getLatePublicationProtectionMode();
                    if ($protectionMode != PageProtection::CONF_VALUE_ROBOT) {
                        unset($pagesToBeAdded[$key]);
                    }
                }

            }
        }

    }

    /**
     * @param $event
     * @param array $protectionModes
     */
    private
    function excludePageFromSearch(&$event, $protectionModes = [PageProtection::CONF_VALUE_ACL, PageProtection::CONF_VALUE_HIDDEN])
    {

        /**
         * The value is always an array
         * but as we got this error:
         * ```
         * array_keys() expects parameter 1 to be array
         * ```
         * The result is a list of page id
         */
        if (is_array($event->result)) {
            foreach (array_keys($event->result) as $idx) {
                $page = Page::createPageFromId($idx);
                if ($page->isLowQualityPage()) {
                    $securityConf = $this->getConf(LowQualityPage::CONF_LOW_QUALITY_PAGE_PROTECTION_MODE);
                    if (in_array($securityConf, $protectionModes)) {
                        unset($event->result[$idx]);
                        return;
                    }
                }
                if ($page->isLatePublication()) {
                    $securityConf = $this->getConf(Publication::CONF_LATE_PUBLICATION_PROTECTION_MODE);
                    if (in_array($securityConf, [PageProtection::CONF_VALUE_ACL, PageProtection::CONF_VALUE_HIDDEN])) {
                        unset($event->result[$idx]);
                        return;
                    }
                }
            }
        }

    }

    /**
     * @noinspection SpellCheckingInspection
     * Adding an information to know if the user is signed or not
     */
    function handleAnonymousJsIndicator(&$event, $param)
    {

        global $JSINFO;
        if (!Identity::isLoggedIn()) {
            $navigation = Identity::JS_NAVIGATION_ANONYMOUS_VALUE;
        } else {
            $navigation = Identity::JS_NAVIGATION_SIGNED_VALUE;
        }
        $JSINFO[Identity::JS_NAVIGATION_INDICATOR] = $navigation;


    }

    /**
     * Handle the meta robots
     * https://www.dokuwiki.org/devel:event:tpl_metaheader_output
     * @param $event
     * @param $param
     */
    function handleRobotsMeta(&$event, $param)
    {

        global $ID;
        if (empty($ID)) {
            // $_SERVER['SCRIPT_NAME']== "/lib/exe/mediamanager.php"
            // $ID is null
            return;
        }

        $page = Page::createPageFromId($ID);

        /**
         * No management for slot page
         */
        if ($page->isSlot()) {
            return;
        }

        $protected = false;
        $follow = "nofollow";
        if ($page->isLowQualityPage() && LowQualityPage::isProtectionEnabled()) {
            $protected = true;
            if (LowQualityPage::getLowQualityProtectionMode() == PageProtection::CONF_VALUE_ACL) {
                $follow = "nofollow";
            } else {
                $follow = "follow";
            }
        }
        if ($page->isLatePublication() && Publication::isLatePublicationProtectionEnabled()) {
            $protected = true;
            if (Publication::getLatePublicationProtectionMode() == PageProtection::CONF_VALUE_ACL) {
                $follow = "nofollow";
            } else {
                $follow = "follow";
            }
        }
        if ($protected) {
            foreach ($event->data['meta'] as $key => $meta) {
                if (array_key_exists("name", $meta)) {
                    /**
                     * We may have several properties
                     */
                    if ($meta["name"] == "robots") {
                        $event->data['meta'][$key]["content"] = "noindex,$follow";
                    }
                }
            }
        }
    }

}
