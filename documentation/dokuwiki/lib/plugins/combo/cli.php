<?php
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
if (!defined('DOKU_INC')) die();

use ComboStrap\Analytics;
use ComboStrap\FsWikiUtility;
use ComboStrap\Page;
use ComboStrap\Sqlite;
use splitbrain\phpcli\Options;

/**
 * All dependency are loaded in plugin utility
 */
require_once(__DIR__ . '/ComboStrap/PluginUtility.php');

/**
 * The memory of the server 128 is not enough
 */
ini_set('memory_limit', '256M');

/**
 * Class cli_plugin_combo
 *
 * This is a cli:
 * https://www.dokuwiki.org/devel:cli_plugins#example
 *
 * Usage:
 *
 * ```
 * docker exec -ti $(CONTAINER) /bin/bash
 * ./bin/plugin.php combo -c
 * ```
 * or via the IDE
 *
 *
 * Example:
 * https://www.dokuwiki.org/tips:grapher
 *
 */
class cli_plugin_combo extends DokuWiki_CLI_Plugin
{
    const ANALYTICS = "analytics";
    const SYNC = "sync";

    /**
     * register options and arguments
     * @param Options $options
     */
    protected function setup(Options $options)
    {
        $options->setHelp(
            "Manage the analytics database\n\n" .
            "analytics\n" .
            "sync"
        );
        $options->registerOption('version', 'print version', 'v');
        $options->registerCommand(self::ANALYTICS, "Update the analytics data");
        $options->registerOption(
            'namespaces',
            "If no namespace is given, the root namespace is assumed.",
            'n',
            true
        );
        $options->registerOption(
            'output',
            "Optional, where to store the analytical data as csv eg. a filename.",
            'o', 'file');
        $options->registerOption(
            'cache',
            "Optional, returns from the cache if set",
            'c', false);
        $options->registerOption(
            'dry',
            "Optional, dry-run",
            'd', false);
        $options->registerCommand(self::SYNC, "Sync the database");

    }

    /**
     * The main entry
     * @param Options $options
     */
    protected function main(Options $options)
    {

        $namespaces = array_map('cleanID', $options->getArgs());
        if (!count($namespaces)) $namespaces = array(''); //import from top

        $cache = $options->getOpt('cache', false);
        $depth = $options->getOpt('depth', 0);
        $cmd = $options->getCmd();
        if ($cmd == "") {
            $cmd = self::ANALYTICS;
        }
        switch ($cmd) {
            case self::ANALYTICS:
                $output = $options->getOpt('output', '');
                //if ($output == '-') $output = 'php://stdout';
                $this->updateAnalyticsData($namespaces, $output, $cache, $depth);
                break;
            case self::SYNC:
                $this->syncPages();
                break;
            default:
                throw new \RuntimeException("Combo: Command unknown (" . $cmd . ")");
        }


    }

    /**
     * @param array $namespaces
     * @param $output
     * @param bool $cache
     * @param int $depth recursion depth. 0 for unlimited
     */
    private function updateAnalyticsData($namespaces = array(), $output = null, $cache = false, $depth = 0)
    {

        $fileHandle = null;
        if (!empty($output)) {
            $fileHandle = @fopen($output, 'w');
            if (!$fileHandle) $this->fatal("Failed to open $output");
        }

        /**
         * Run as admin to overcome the fact that
         * anonymous user cannot see all links and backlinks
         */
        global $USERINFO;
        $USERINFO['grps'] = array('admin');
        global $INPUT;
        $INPUT->server->set('REMOTE_USER', "cli");

        $pages = FsWikiUtility::getPages($namespaces, $depth);


        if (!empty($fileHandle)) {
            $header = array(
                'id',
                'backlinks',
                'broken_links',
                'changes',
                'chars',
                'external_links',
                'external_medias',
                'h1',
                'h2',
                'h3',
                'h4',
                'h5',
                'internal_links',
                'internal_medias',
                'words',
                'score'
            );
            fwrite($fileHandle, implode(",", $header) . PHP_EOL);
        }
        $pageCounter = 0;
        $totalNumberOfPages = sizeof($pages);
        while ($page = array_shift($pages)) {
            $id = $page['id'];

            $pageCounter++;
            echo "Processing the page {$id} ($pageCounter / $totalNumberOfPages)\n";

            $data = Analytics::processAndGetDataAsArray($id, $cache);
            if (!empty($fileHandle)) {
                $statistics = $data[Analytics::STATISTICS];
                $row = array(
                    'id' => $id,
                    'backlinks' => $statistics[Analytics::INTERNAL_BACKLINKS_COUNT],
                    'broken_links' => $statistics[Analytics::INTERNAL_LINKS_BROKEN_COUNT],
                    'changes' => $statistics[Analytics::EDITS_COUNT],
                    'chars' => $statistics[Analytics::CHARS_COUNT],
                    'external_links' => $statistics[Analytics::EXTERNAL_LINKS_COUNT],
                    'external_medias' => $statistics[Analytics::EXTERNAL_MEDIAS_COUNT],
                    Analytics::H1 => $statistics[Analytics::HEADERS_COUNT][Analytics::H1],
                    'h2' => $statistics[Analytics::HEADERS_COUNT]['h2'],
                    'h3' => $statistics[Analytics::HEADERS_COUNT]['h3'],
                    'h4' => $statistics[Analytics::HEADERS_COUNT]['h4'],
                    'h5' => $statistics[Analytics::HEADERS_COUNT]['h5'],
                    'internal_links' => $statistics[Analytics::INTERNAL_LINKS_COUNT],
                    'internal_medias' => $statistics[Analytics::INTERNAL_MEDIAS_COUNT],
                    'words' => $statistics[Analytics::WORD_COUNT],
                    'low' => $data[Analytics::QUALITY]['low']
                );
                fwrite($fileHandle, implode(",", $row) . PHP_EOL);
            }
        }
        if (!empty($fileHandle)) {
            fclose($fileHandle);
        }

    }



    private function syncPages()
    {
        $sqlite = Sqlite::getSqlite();
        $res = $sqlite->query("select ID from pages");
        if (!$res) {
            throw new \RuntimeException("An exception has occurred with the alias selection query");
        }
        $res2arr = $sqlite->res2arr($res);
        $sqlite->res_close($res);
        foreach ($res2arr as $row) {
            $id = $row['ID'];
            if (!page_exists($id)) {
                echo 'Page does not exist on the file system. Deleted from the database (' . $id . ")\n";
                Page::createPageFromId($id)->deleteInDb();
            }
        }


    }
}
