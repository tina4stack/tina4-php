<?php

use Combostrap\AnalyticsMenuItem;
use ComboStrap\Identity;
use ComboStrap\LogUtility;
use ComboStrap\Page;
use ComboStrap\Sqlite;

/**
 * Copyright (c) 2021. ComboStrap, Inc. and its affiliates. All Rights Reserved.
 *
 * This source code is licensed under the GPL license found in the
 * COPYING  file in the root directory of this source tree.
 *
 * @license  GPL 3 (https://www.gnu.org/licenses/gpl-3.0.en.html)
 * @author   ComboStrap <support@combostrap.com>
 *
 */

require_once(__DIR__ . '/../class/' . 'Analytics.php');
require_once(__DIR__ . '/../class/' . 'AnalyticsMenuItem.php');

/**
 * Class action_plugin_combo_analytics
 * Update the analytics data
 */
class action_plugin_combo_analytics extends DokuWiki_Action_Plugin
{

    /**
     * @var array
     */
    protected $linksBeforeByPage = array();

    public function register(Doku_Event_Handler $controller)
    {

        /**
         * Analytics to refresh because they have lost or gain a backlinks
         * are done via Sqlite table (The INDEXER_TASKS_RUN gives a way to
         * manipulate this queue)
         *
         * There is no need to do it at page write
         * https://www.dokuwiki.org/devel:event:io_wikipage_write
         * because after the page is written, the page is shown and trigger the index tasks run
         *
         * We do it after because if there is an error
         * We will not stop the Dokuwiki Processing
         */
        $controller->register_hook('INDEXER_TASKS_RUN', 'AFTER', $this, 'handle_refresh_analytics', array());

        /**
         * Add a icon in the page tools menu
         * https://www.dokuwiki.org/devel:event:menu_items_assembly
         */
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'handle_rail_bar');

        /**
         * Check the internal link that have been
         * added or deleted to update the backlinks statistics
         * if a link has been added or deleted
         */
        $controller->register_hook('PARSER_METADATA_RENDER', 'AFTER', $this, 'handle_meta_renderer_after', array());
        $controller->register_hook('PARSER_METADATA_RENDER', 'BEFORE', $this, 'handle_meta_renderer_before', array());

    }

    public function handle_refresh_analytics(Doku_Event $event, $param)
    {

        /**
         * Check that the actual page has analytics data
         * (if there is a cache, it's pretty quick)
         */
        global $ID;
        if ($ID == null) {
            $id = $event->data['page'];
        } else {
            $id = $ID;
        }
        $page = Page::createPageFromId($id);
        if ($page->shouldAnalyticsProcessOccurs()) {
            $page->processAnalytics();
        }

        /**
         * Process the analytics to refresh
         */
        $this->analyticsBatchBackgroundRefresh();

    }

    public function handle_rail_bar(Doku_Event $event, $param)
    {

        if (!Identity::isWriter()) {
            return;
        }

        /**
         * The `view` property defines the menu that is currently built
         * https://www.dokuwiki.org/devel:menus
         * If this is not the page menu, return
         */
        if ($event->data['view'] != 'page') return;

        global $INFO;
        if (!$INFO['exists']) {
            return;
        }
        array_splice($event->data['items'], -1, 0, array(new AnalyticsMenuItem()));

    }

    private function analyticsBatchBackgroundRefresh()
    {
        $sqlite = Sqlite::getSqlite();
        $res = $sqlite->query("SELECT ID FROM ANALYTICS_TO_REFRESH");
        if (!$res) {
            LogUtility::msg("There was a problem during the select: {$sqlite->getAdapter()->getDb()->errorInfo()}");
        }
        $rows = $sqlite->res2arr($res, true);
        $sqlite->res_close($res);

        /**
         * In case of a start or if there is a recursive bug
         * We don't want to take all the resources
         */
        $maxRefresh = 10; // by default, there is 5 pages in a default dokuwiki installation in the wiki namespace
        $maxRefreshLow = 2;
        $pagesToRefresh = sizeof($rows);
        if ($pagesToRefresh > $maxRefresh) {
            LogUtility::msg("There is {$pagesToRefresh} pages to refresh in the queue (table `ANALYTICS_TO_REFRESH`). This is more than {$maxRefresh} pages. Batch background Analytics refresh was reduced to {$maxRefreshLow} pages to not hit the computer resources.", LogUtility::LVL_MSG_ERROR, "analytics");
            $maxRefresh = $maxRefreshLow;
        }
        $refreshCounter = 0;
        foreach ($rows as $row) {
            $page = Page::createPageFromId($row['ID']);
            $page->processAnalytics();
            $refreshCounter++;
            if ($refreshCounter >= $maxRefresh) {
                break;
            }
        }

    }

    /**
     * Generate the statistics for the internal link added or deleted
     *
     * @param Doku_Event $event
     * @param $param
     */
    public function handle_meta_renderer_before(Doku_Event $event, $param)
    {

        $pageId = $event->data['page'];
        $page = Page::createPageFromId($pageId);
        $links = $page->getInternalLinksFromMeta();
        if ($links !== null) {
            $this->linksBeforeByPage[$pageId] = $links;
        } else {
            $this->linksBeforeByPage[$pageId] = array();
        }


    }

    /**
     * Save the links before metadata render
     * @param Doku_Event $event
     * @param $param
     */
    public function handle_meta_renderer_after(Doku_Event $event, $param)
    {

        $pageId = $event->data['page'];
        $linksAfter = $event->data['current']['relation']['references'];
        if ($linksAfter == null) {
            $linksAfter = array();
        }
        $linksBefore = $this->linksBeforeByPage[$pageId];
        unset($this->linksBeforeByPage[$pageId]);
        $addedLinks = array();
        foreach ($linksAfter as $linkAfter => $exist) {
            if (array_key_exists($linkAfter, $linksBefore)) {
                unset($linksBefore[$linkAfter]);
            } else {
                $addedLinks[] = $linkAfter;
            }
        }

        /**
         * Process to update the backlinks
         */
        $linksChanged = array_fill_keys($addedLinks,"added");
        foreach ($linksBefore as $deletedLink => $deletedLinkPageExists) {
            $linksChanged[$deletedLink] = 'deleted';
        }
        foreach ($linksChanged as  $changedLink => $status) {
            /**
             * We delete the cache
             * We don't update the analytics
             * because we want speed
             */
            $addedPage = Page::createPageFromId($changedLink);
            $reason = "The backlink {$changedLink} from the page {$pageId} was {$status}";
            $addedPage->deleteCacheAndAskAnalyticsRefresh($reason);

        }

    }


}



