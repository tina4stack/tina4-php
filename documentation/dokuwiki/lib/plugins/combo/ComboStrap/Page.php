<?php

namespace ComboStrap;


use action_plugin_combo_qualitymessage;
use DateTime;
use dokuwiki\Cache\CacheInstructions;
use dokuwiki\Cache\CacheRenderer;
use dokuwiki\Extension\SyntaxPlugin;
use renderer_plugin_combo_analytics;
use RuntimeException;


/**
 * Page
 */
require_once(__DIR__ . '/PluginUtility.php');

/**
 *
 * Class Page
 * @package ComboStrap
 *
 * This is just a wrapper around a file with the mime Dokuwiki
 * that has a doku path (ie with the `:` separator)
 */
class Page extends DokuPath
{
    const CANONICAL_PROPERTY = 'canonical';
    const TITLE_META_PROPERTY = 'title';

    const CONF_DISABLE_FIRST_IMAGE_AS_PAGE_IMAGE = "disableFirstImageAsPageImage";

    const FIRST_IMAGE_META_RELATION = "firstimage";

    /**
     * An indicator in the meta
     * that set a boolean to true or false
     * to categorize a page as low quality
     * It can be set manually via the {@link \syntax_plugin_combo_frontmatter front matter}
     * otherwise the {@link \renderer_plugin_combo_analytics}
     * will do it
     */
    const LOW_QUALITY_PAGE_INDICATOR = 'low_quality_page';

    /**
     * The default page type
     */
    const CONF_DEFAULT_PAGE_TYPE = "defaultPageType";
    const WEBSITE_TYPE = "website";
    const ARTICLE_TYPE = "article";
    const ORGANIZATION_TYPE = "organization";
    const NEWS_TYPE = "news";
    const BLOG_TYPE = "blog";
    const NAME_PROPERTY = "name";
    const DESCRIPTION_PROPERTY = "description";
    const TYPE_META_PROPERTY = "type";

    /**
     * The scope is the namespace used to store the cache
     *
     * It can be set by a component via the {@link p_set_metadata()}
     * in a {@link SyntaxPlugin::handle()} function
     *
     * This is mostly used on side slots to
     * have several output of a list {@link \syntax_plugin_combo_pageexplorer navigation pane}
     * for different namespace (ie there is one cache by namespace)
     *
     * The special value current means the namespace of the requested page
     */
    const SCOPE_KEY = "scope";
    /**
     * The special scope value current means the namespace of the requested page
     * The real scope value is then calculated before retrieving the cache
     */
    const SCOPE_CURRENT_VALUE = "current";


    const CURRENT_METADATA = "current";
    const PERSISTENT_METADATA = "persistent";
    const PAGE_TYPE_META_PROPERTY = "type";
    const IMAGE_META_PROPERTY = 'image';
    const COUNTRY_META_PROPERTY = "country";
    const LANG_META_PROPERTY = "lang";


    private $canonical;


    /**
     * @var array|array[]
     */
    private $metadatas;
    /**
     * @var string|null - the description (the origin is in the $descriptionOrigin)
     */
    private $description;
    /**
     * @var string - the dokuwiki
     */
    private $descriptionOrigin;


    /**
     * @var bool Indicator to say if this is a sidebar (or sidekick bar)
     */
    private $isSideSlot = false;

    /**
     * The id requested (ie the main page)
     * The page may be a slot
     * @var string
     */
    private $requestedId;

    /**
     * Page constructor.
     * @param $absolutePath - the qualified path (may be not relative)
     *
     */
    public function __construct($absolutePath)
    {

        /**
         * Bars have a logical reasoning (ie such as a virtual, alias)
         * They are logically located in the same namespace
         * but the file may be located on the parent
         *
         * This block of code is processing this case
         */
        global $conf;
        $sidebars = array($conf['sidebar']);
        $strapTemplateName = 'strap';
        if ($conf['template'] === $strapTemplateName) {
            $sidebars[] = $conf['tpl'][$strapTemplateName]['sidekickbar'];
        }
        $lastPathPart = DokuPath::getLastPart($absolutePath);
        if (in_array($lastPathPart, $sidebars)) {

            $this->isSideSlot = true;

            /**
             * Find the first physical file
             * Don't use ACL otherwise the ACL protection event 'AUTH_ACL_CHECK' will kick in
             * and we got then a recursive problem
             * with the {@link \action_plugin_combo_pageprotection}
             */
            $useAcl = false;
            $id = page_findnearest($lastPathPart, $useAcl);
            if ($id !== false) {
                $absolutePath = DokuPath::PATH_SEPARATOR . $id;
            }

        }

        global $ID;
        $this->requestedId = $ID;

        parent::__construct($absolutePath, DokuPath::PAGE_TYPE);

    }

    public static function createPageFromCurrentId()
    {
        global $ID;
        return self::createPageFromId($ID);
    }

    public static function createPageFromId($id)
    {
        return new Page(DokuPath::PATH_SEPARATOR . $id);
    }

    public static function createPageFromNonQualifiedPath($pathOrId)
    {
        global $ID;
        $qualifiedId = $pathOrId;
        resolve_pageid(getNS($ID), $qualifiedId, $exists);
        /**
         * Root correction
         * yeah no root functionality in the {@link resolve_pageid resolution}
         * meaning that we get an empty string
         * they correct it in the link creation {@link wl()}
         */
        if ($qualifiedId === '') {
            global $conf;
            $qualifiedId = $conf['start'];
        }
        return Page::createPageFromId($qualifiedId);

    }

    /**
     * @return Page - the requested page
     */
    public static function createPageFromRequestedPage()
    {
        $mainPageId = FsWikiUtility::getMainPageId();
        return self::createPageFromId($mainPageId);
    }


    /**
     * @var string the logical id is used with slots.
     *
     * A slot may exist in several node of the file system tree
     * but they can be rendered for a page in a lowest level
     * listing the page of the current namespace
     *
     * The slot is physically stored in one place but is equivalent
     * physically to the same slot in all sub-node.
     *
     * This logical id does take into account this aspect.
     *
     * This is used also to store the HTML output in the cache
     * If this is not a slot the logical id is the {@link DokuPath::getId()}
     */
    public function getLogicalId()
    {
        /**
         * Delete the first separator
         */
        return substr($this->getLogicalPath(), 1);
    }

    public function getLogicalPath()
    {

        /**
         * Set the logical id
         * When no $ID is set (for instance, test),
         * the logical id is the id
         *
         * The logical id depends on the namespace attribute of the {@link \syntax_plugin_combo_pageexplorer}
         * stored in the `scope` metadata.
         */
        $scopePath = $this->getScope();
        if ($scopePath !== null) {

            if ($scopePath == Page::SCOPE_CURRENT_VALUE) {
                $requestPage = Page::createRequestedPageFromEnvironment();
                $scopePath = $requestPage->getNamespacePath();
            }

            if ($scopePath !== ":") {
                return $scopePath . DokuPath::PATH_SEPARATOR . $this->getName();
            } else {
                return DokuPath::PATH_SEPARATOR . $this->getName();
            }


        } else {

            return $this->getAbsolutePath();

        }


    }


    /**
     *
     *
     * Dokuwiki Methodology taken from {@link tpl_metaheaders()}
     * @return string - the Dokuwiki URL
     */
    public
    function getUrl()
    {
        if ($this->isHomePage()) {
            $url = DOKU_URL;
        } else {
            $url = wl($this->getId(), '', true, '&');
        }
        return $url;
    }

    public
    static function createRequestedPageFromEnvironment()
    {
        $pageId = PluginUtility::getPageId();
        if ($pageId != null) {
            return Page::createPageFromId($pageId);
        } else {
            LogUtility::msg("We were unable to determine the page from the variables environment", LogUtility::LVL_MSG_ERROR);
            return null;
        }
    }


    /**
     * Does the page is known in the pages table
     * @return array
     */
    function getRow()
    {

        $sqlite = Sqlite::getSqlite();
        $res = $sqlite->query("SELECT * FROM pages where id = ?", $this->getId());
        if (!$res) {
            throw new RuntimeException("An exception has occurred with the select pages query");
        }
        $res2arr = $sqlite->res2row($res);
        $sqlite->res_close($res);
        return $res2arr;


    }

    /**
     * Delete Page
     */
    function deleteInDb()
    {

        $res = Sqlite::getSqlite()->query('delete from pages where id = ?', $this->getId());
        if (!$res) {
            LogUtility::msg("Something went wrong when deleting a page");
        }

    }


    /**
     * Does the page is known in the pages table
     * @return int
     */
    function existInDb()
    {
        $sqlite = Sqlite::getSqlite();
        $res = $sqlite->query("SELECT count(*) FROM pages where id = ?", $this->getId());
        $count = $sqlite->res2single($res);
        $sqlite->res_close($res);
        return $count;

    }

    /**
     * Exist in FS
     * @return bool
     * @deprecated use {@link DokuPath::exists()} instead
     */
    function existInFs()
    {
        return $this->exists();
    }

    private
    function persistPageAlias($canonical, $alias)
    {

        $row = array(
            "CANONICAL" => $canonical,
            "ALIAS" => $alias
        );

        // Page has change of location
        // Creation of an alias
        $sqlite = Sqlite::getSqlite();
        $res = $sqlite->query("select count(*) from pages_alias where CANONICAL = ? and ALIAS = ?", $row);
        if (!$res) {
            throw new RuntimeException("An exception has occurred with the alia selection query");
        }
        $aliasInDb = $sqlite->res2single($res);
        $sqlite->res_close($res);
        if ($aliasInDb == 0) {

            $res = $sqlite->storeEntry('pages_alias', $row);
            if (!$res) {
                LogUtility::msg("There was a problem during pages_alias insertion");
            }
        }

    }


    static function createPageFromQualifiedPath($qualifiedPath)
    {
        return new Page($qualifiedPath);
    }

    /**
     * @param $canonical
     * @return Page - an id of an existing page
     */
    static function createPageFromCanonical($canonical)
    {

        // Canonical
        $sqlite = Sqlite::getSqlite();
        $res = $sqlite->query("select * from pages where CANONICAL = ? ", $canonical);
        if (!$res) {
            LogUtility::msg("An exception has occurred with the pages selection query");
        }
        $res2arr = $sqlite->res2arr($res);
        $sqlite->res_close($res);
        foreach ($res2arr as $row) {
            $id = $row['ID'];
            return self::createPageFromId($id)->setCanonical($canonical);
        }


        // If the function comes here, it means that the page id was not found in the pages table
        // Alias ?
        // Canonical
        $res = $sqlite->query("select p.ID from pages p, PAGES_ALIAS pa where p.CANONICAL = pa.CANONICAL and pa.ALIAS = ? ", $canonical);
        if (!$res) {
            throw new RuntimeException("An exception has occurred with the alias selection query");
        }
        $res2arr = $sqlite->res2arr($res);
        $sqlite->res_close($res);
        foreach ($res2arr as $row) {
            $id = $row['ID'];
            return self::createPageFromId($id)
                ->setCanonical($canonical);
        }

        return self::createPageFromId($canonical);

    }

    /**
     * Persist a page in the database
     */
    function processAndPersistInDb()
    {

        $canonical = p_get_metadata($this->getId(), "canonical");
        if ($canonical != "") {

            // Do we have a page attached to this canonical
            $sqlite = Sqlite::getSqlite();
            $res = $sqlite->query("select ID from pages where CANONICAL = ?", $canonical);
            if (!$res) {
                LogUtility::msg("An exception has occurred with the search id from canonical");
            }
            $idInDb = $sqlite->res2single($res);
            $sqlite->res_close($res);
            if ($idInDb && $idInDb != $this->getId()) {
                // If the page does not exist anymore we delete it
                if (!page_exists($idInDb)) {
                    $res = $sqlite->query("delete from pages where ID = ?", $idInDb);
                    if (!$res) {
                        LogUtility::msg("An exception has occurred during the deletion of the page");
                    }
                    $sqlite->res_close($res);

                } else {
                    LogUtility::msg("The page ($this) and the page ($idInDb) have the same canonical ($canonical)", LogUtility::LVL_MSG_ERROR, "url:manager");
                    /**
                     * Check if the error may come from the auto-canonical
                     * (Never ever save generated data)
                     */
                    $canonicalLastNamesCount = PluginUtility::getConfValue(\action_plugin_combo_metacanonical::CANONICAL_LAST_NAMES_COUNT_CONF);
                    if ($canonicalLastNamesCount > 0) {
                        $this->unsetMetadata(Page::CANONICAL_PROPERTY);
                        Page::createPageFromQualifiedPath($idInDb)->unsetMetadata(Page::CANONICAL_PROPERTY);
                    }
                }
                $this->persistPageAlias($canonical, $idInDb);
            }

            // Do we have a canonical on this page
            $res = $sqlite->query("select canonical from pages where ID = ?", $this->getId());
            if (!$res) {
                LogUtility::msg("An exception has occurred with the query");
            }
            $canonicalInDb = $sqlite->res2single($res);
            $sqlite->res_close($res);

            $row = array(
                "CANONICAL" => $canonical,
                "ID" => $this->getId()
            );
            if ($canonicalInDb && $canonicalInDb != $canonical) {

                // Persist alias
                $this->persistPageAlias($canonical, $this->getId());

                // Update
                $statement = 'update pages set canonical = ? where id = ?';
                $res = $sqlite->query($statement, $row);
                if (!$res) {
                    LogUtility::msg("There was a problem during page update");
                }
                $sqlite->res_close($res);

            } else {

                if ($canonicalInDb == false) {
                    $res = $sqlite->storeEntry('pages', $row);
                    if (!$res) {
                        LogUtility::msg("There was a problem during pages insertion");
                    }
                    $sqlite->res_close($res);
                }

            }


        }
        return $this;
    }

    private
    function setCanonical($canonical)
    {
        $this->canonical = $canonical;
        return $this;
    }


    public
    function isSlot()
    {
        global $conf;
        $barsName = array($conf['sidebar']);
        $strapTemplateName = 'strap';
        if ($conf['template'] === $strapTemplateName) {
            $loaded = PluginUtility::loadStrapUtilityTemplateIfPresentAndSameVersion();
            if ($loaded) {
                $barsName[] = TplUtility::getHeaderSlotPageName();
                $barsName[] = TplUtility::getFooterSlotPageName();
                $barsName[] = TplUtility::getSideKickSlotPageName();
            }
        }
        return in_array($this->getName(), $barsName);
    }

    public
    function isStrapSideSlot()
    {

        return $this->isSideSlot && Site::isStrapTemplate();

    }


    public
    function isStartPage()
    {
        global $conf;
        return $this->getName() == $conf['start'];
    }

    /**
     * Return a canonical if set
     * otherwise derive it from the id
     * by taking the last two parts
     *
     * @return string
     */
    public
    function getCanonical()
    {
        if (empty($this->canonical)) {

            $this->canonical = $this->getPersistentMetadata(Page::CANONICAL_PROPERTY);

            /**
             * The last part of the id as canonical
             */
            // How many last parts are taken into account in the canonical processing (2 by default)
            $canonicalLastNamesCount = PluginUtility::getConfValue(\action_plugin_combo_metacanonical::CANONICAL_LAST_NAMES_COUNT_CONF);
            if (empty($this->canonical) && $canonicalLastNamesCount > 0) {
                /**
                 * Takes the last names part
                 */
                $namesOriginal = $this->getNames();
                /**
                 * Delete the identical names at the end
                 * To resolve this problem
                 * The page (viz:viz) and the page (data:viz:viz) have the same canonical.
                 * The page (viz:viz) will get the canonical viz
                 * The page (data:viz) will get the canonical  data:viz
                 */
                $i = sizeof($namesOriginal) - 1;
                $names = $namesOriginal;
                while ($namesOriginal[$i] == $namesOriginal[$i - 1]) {
                    unset($names[$i]);
                    $i--;
                    if ($i <= 0) {
                        break;
                    }
                }
                /**
                 * Minimal length check
                 */
                $namesLength = sizeof($names);
                if ($namesLength > $canonicalLastNamesCount) {
                    $names = array_slice($names, $namesLength - $canonicalLastNamesCount);
                }
                /**
                 * If this is a start page, delete the name
                 * ie javascript:start will become javascript
                 */
                if ($this->isStartPage()) {
                    $names = array_slice($names, 0, $namesLength - 1);
                }
                $this->canonical = implode(":", $names);
                p_set_metadata($this->getId(), array(Page::CANONICAL_PROPERTY => $this->canonical));
            }

        }
        return $this->canonical;
    }

    /**
     * @return array|null the analytics array or null if not in db
     */
    public
    function getAnalyticsFromDb()
    {
        $sqlite = Sqlite::getSqlite();
        if ($sqlite == null) {
            return array();
        }
        $res = $sqlite->query("select ANALYTICS from pages where ID = ? ", $this->getId());
        if (!$res) {
            LogUtility::msg("An exception has occurred with the pages selection query");
        }
        $jsonString = trim($sqlite->res2single($res));
        $sqlite->res_close($res);
        if (!empty($jsonString)) {
            return json_decode($jsonString, true);
        } else {
            return null;
        }

    }

    /**
     * Return the metadata stored in the file system
     * @return array|array[]
     */
    public
    function getMetadatas()
    {

        /**
         * Read / not {@link p_get_metadata()}
         * because it can trigger a rendering of the meta again)
         *
         * This is not a {@link Page::renderMetadata()}
         */
        if ($this->metadatas == null) {
            $this->metadatas = p_read_metadata($this->getId());
        }
        return $this->metadatas;

    }

    /**
     *
     * @return mixed the internal links or null
     */
    public
    function getInternalLinksFromMeta()
    {
        $metadata = $this->getMetadatas();
        if (key_exists(self::CURRENT_METADATA, $metadata)) {
            $current = $metadata[self::CURRENT_METADATA];
            if (key_exists('relation', $current)) {
                $relation = $current['relation'];
                if (is_array($relation)) {
                    if (key_exists('references', $relation)) {
                        return $relation['references'];
                    }
                }
            }
        }
        return null;
    }

    public
    function saveAnalytics(array $analytics)
    {

        $sqlite = Sqlite::getSqlite();
        if ($sqlite != null) {
            /**
             * Sqlite Plugin installed
             */

            $json = json_encode($analytics, JSON_PRETTY_PRINT);
            /**
             * Same data as {@link Page::getMetadataStandard()}
             */
            $entry = array(
                'CANONICAL' => $this->getCanonical(),
                'ANALYTICS' => $json,
                'PATH'=>$this->getAbsolutePath(),
                'NAME'=>$this->getName(),
                'TITLE'=>$this->getTitleNotEmpty(),
                'H1'=>$this->getH1NotEmpty(),
                'DATE_CREATED'=>$this->getCreatedDateString(),
                'DATE_MODIFIED'=>$this->getModifiedDateString(),
                'DATE_PUBLISHED'=>$this->getPublishedTimeAsString(),
                'ID' => $this->getId()
            );
            $res = $sqlite->query("SELECT count(*) FROM PAGES where ID = ?", $this->getId());
            if ($sqlite->res2single($res) == 1) {
                // Upset not supported on all version
                //$upsert = 'insert into PAGES (ID,CANONICAL,ANALYTICS) values (?,?,?) on conflict (ID,CANONICAL) do update set ANALYTICS = EXCLUDED.ANALYTICS';
                $update = 'update PAGES SET CANONICAL = ?, ANALYTICS = ?, PATH = ?, NAME = ?, TITLE = ?, H1 = ?, DATE_CREATED = ?, DATE_MODIFIED = ?, DATE_PUBLISHED = ? where ID=?';
                $res = $sqlite->query($update, $entry);
            } else {
                $res = $sqlite->storeEntry('PAGES', $entry);
            }
            if (!$res) {
                LogUtility::msg("There was a problem during the upsert: {$sqlite->getAdapter()->getDb()->errorInfo()}");
            }
            $sqlite->res_close($res);
        }

    }

    /**
     * @param string $mode delete the cache for the format XHTML and {@link renderer_plugin_combo_analytics::RENDERER_NAME_MODE}
     */
    public
    function deleteCache($mode = "xhtml")
    {

        if ($this->exists()) {


            $cache = $this->getInstructionsCache();
            $cache->removeCache();

            $cache = $this->getRenderCache($mode);
            $cache->removeCache();

        }
    }


    public
    function isAnalyticsCached()
    {

        $cache = new CacheRenderer($this->getId(), $this->getFileSystemPath(), renderer_plugin_combo_analytics::RENDERER_NAME_MODE);
        $cacheFile = $cache->cache;
        return file_exists($cacheFile);
    }

    /**
     *
     * @return string - the full path to the meta file
     */
    public
    function getMetaFile()
    {
        return metaFN($this->getId(), '.meta');
    }

    /**
     * @param $reason - a string with the reason
     */
    public
    function deleteCacheAndAskAnalyticsRefresh($reason)
    {
        $this->deleteCache(renderer_plugin_combo_analytics::RENDERER_NAME_MODE);
        $sqlite = Sqlite::getSqlite();
        if ($sqlite != null) {

            /**
             * Check if exists
             */
            $res = $sqlite->query("select count(1) from ANALYTICS_TO_REFRESH where ID = ?", array('ID' => $this->getId()));
            if (!$res) {
                LogUtility::msg("There was a problem during the insert: {$sqlite->getAdapter()->getDb()->errorInfo()}");
            }
            $result = $sqlite->res2single($res);
            $sqlite->res_close($res);

            /**
             * If not insert
             */
            if ($result != 1) {
                $entry = array(
                    "ID" => $this->getId(),
                    "TIMESTAMP" => date('Y-m-d H:i:s', time()),
                    "REASON" => $reason
                );
                $res = $sqlite->storeEntry('ANALYTICS_TO_REFRESH', $entry);
                if (!$res) {
                    LogUtility::msg("There was a problem during the insert: {$sqlite->getAdapter()->getDb()->errorInfo()}");
                }
                $sqlite->res_close($res);
            }

        }

    }

    public
    function isAnalyticsStale()
    {
        $sqlite = Sqlite::getSqlite();
        $res = $sqlite->query("SELECT count(*) FROM ANALYTICS_TO_REFRESH where ID = ?", $this->getId());
        if (!$res) {
            LogUtility::msg("There was a problem during the select: {$sqlite->getAdapter()->getDb()->errorInfo()}");
        }
        $value = $sqlite->res2single($res);
        $sqlite->res_close($res);
        return $value === "1";

    }

    /**
     * Delete the cache, process the analytics
     * and return it
     * If you want the analytics from the cache use {@link Page::getAnalyticsFromFs()}
     * instead
     * @return mixed analytics as array
     */
    public
    function processAnalytics()
    {

        /**
         * Refresh and cache
         * (The delete is normally not needed, just to be sure)
         */
        $this->deleteCache(renderer_plugin_combo_analytics::RENDERER_NAME_MODE);
        $analytics = Analytics::processAndGetDataAsArray($this->getId(), true);

        /**
         * Delete from the table
         */
        $sqlite = Sqlite::getSqlite();
        if ($sqlite != null) {
            $res = $sqlite->query("DELETE FROM ANALYTICS_TO_REFRESH where ID = ?", $this->getId());
            if (!$res) {
                LogUtility::msg("There was a problem during the delete: {$sqlite->getAdapter()->getDb()->errorInfo()}");
            }
            $sqlite->res_close($res);

        }
        return $analytics;

    }

    /**
     * @param bool $cache
     * @return mixed
     *
     */
    public
    function getAnalyticsFromFs($cache = true)
    {
        if ($cache) {
            /**
             * Note for dev: because cache is off in dev environment,
             * you will get it always processed
             */
            return Analytics::processAndGetDataAsArray($this->getId(), $cache);
        } else {
            /**
             * Process analytics delete at the same a asked refresh
             */
            return $this->processAnalytics();
        }
    }

    /**
     * Set the page quality
     * @param boolean $newIndicator true if this is a low quality page rank false otherwise
     */

    public
    function setLowQualityIndicator($newIndicator)
    {
        $actualIndicator = $this->getLowQualityIndicator();
        if ($actualIndicator === null || $actualIndicator !== $newIndicator) {

            /**
             * Don't change the type of the value to a string
             * otherwise dokuwiki will not see a change
             * between true and a string and will not persist the value
             */
            p_set_metadata($this->getId(), array(self::LOW_QUALITY_PAGE_INDICATOR => $newIndicator));

            /**
             * Delete the cache to rewrite the links
             * if the protection is on
             */
            if (PluginUtility::getConfValue(LowQualityPage::CONF_LOW_QUALITY_PAGE_PROTECTION_ENABLE) === 1) {
                foreach ($this->getBacklinks() as $backlink) {
                    $backlink->deleteCache("xhtml");
                }
            }

        }


    }

    /**
     * @return Page[] the backlinks
     */
    public
    function getBacklinks()
    {
        $backlinks = array();
        foreach (ft_backlinks($this->getId()) as $backlinkId) {
            $backlinks[] = Page::createPageFromId($backlinkId);
        }
        return $backlinks;
    }

    /**
     * @return int - An AUTH_ value for this page for the current logged user
     *
     */
    public
    function getAuthAclValue()
    {
        return auth_quickaclcheck($this->getId());
    }

    /**
     * Low page quality
     * @return bool true if this is a low internal page rank
     */
    function isLowQualityPage()
    {

        $lowQualityIndicator = $this->getLowQualityIndicator();
        if ($lowQualityIndicator == null) {
            /**
             * By default, if a file has not been through
             * a {@link \renderer_plugin_combo_analytics}
             * analysis, this is not a low page
             */
            return false;
        } else {
            return $lowQualityIndicator === true;
        }

    }


    public
    function getLowQualityIndicator()
    {

        $low = p_get_metadata($this->getId(), self::LOW_QUALITY_PAGE_INDICATOR, METADATA_DONT_RENDER);
        if ($low === null) {
            return null;
        } else {
            return filter_var($low, FILTER_VALIDATE_BOOLEAN);
        }

    }

    /**
     * @return bool - if a {@link Page::processAnalytics()} for the page should occurs
     */
    public
    function shouldAnalyticsProcessOccurs()
    {
        /**
         * If cache is on
         */
        global $conf;
        if ($conf['cachetime'] !== -1) {
            /**
             * If there is no cache
             */
            if (!$this->isAnalyticsCached()) {
                return true;
            }
        }

        /**
         * Check Db
         */
        $sqlite = Sqlite::getSqlite();
        if ($sqlite != null) {

            $res = $sqlite->query("select count(1) from pages where ID = ? and ANALYTICS is null", $this->getId());
            if (!$res) {
                LogUtility::msg("An exception has occurred with the analytics detection");
            }
            $count = intval($sqlite->res2single($res));
            $sqlite->res_close($res);
            if ($count >= 1) {
                return true;
            }
        }

        /**
         * Check the refresh table
         */
        if ($sqlite != null) {
            $res = $sqlite->query("SELECT count(*) FROM ANALYTICS_TO_REFRESH where ID = ?", $this->getId());
            if (!$res) {
                LogUtility::msg("There was a problem during the delete: {$sqlite->getAdapter()->getDb()->errorInfo()}");
            }
            $count = $sqlite->res2single($res);
            $sqlite->res_close($res);
            return $count >= 1;
        }

        return false;
    }


    public
    function getH1()
    {

        $heading = p_get_metadata($this->getId(), Analytics::H1, METADATA_DONT_RENDER);
        if (!blank($heading)) {
            return $heading;
        } else {
            return null;
        }

    }

    /**
     * Return the Title
     */
    public
    function getTitle()
    {

        $id = $this->getId();
        $title = p_get_metadata($id, Analytics::TITLE, METADATA_RENDER_USING_SIMPLE_CACHE);
        if (!blank($title)) {
            return $title;
        } else {
            return $id;
        }

    }

    /**
     * If true, the page is quality monitored (a note is shown to the writer)
     * @return bool|mixed
     */
    public
    function isQualityMonitored()
    {
        $dynamicQualityIndicator = p_get_metadata($this->getId(), action_plugin_combo_qualitymessage::DISABLE_INDICATOR, METADATA_RENDER_USING_SIMPLE_CACHE);
        if ($dynamicQualityIndicator === null) {
            return true;
        } else {
            return filter_var($dynamicQualityIndicator, FILTER_VALIDATE_BOOLEAN);
        }
    }

    /**
     * @return string|null the title, or h1 if empty or the id if empty
     */
    public
    function getTitleNotEmpty()
    {
        $pageTitle = $this->getTitle();
        if ($pageTitle == null) {
            if (!empty($this->getH1())) {
                $pageTitle = $this->getH1();
            } else {
                $pageTitle = $this->getId();
            }
        }
        return $pageTitle;

    }

    public
    function getH1NotEmpty()
    {

        $h1Title = $this->getH1();
        if ($h1Title == null) {
            if (!empty($this->getTitle())) {
                $h1Title = $this->getTitle();
            } else {
                $h1Title = $this->getPageNameNotEmpty();
            }
        }
        return $h1Title;

    }

    public
    function getDescription()
    {

        $this->processDescriptionIfNeeded();
        if ($this->descriptionOrigin == \syntax_plugin_combo_frontmatter::CANONICAL) {
            return $this->description;
        } else {
            return null;
        }


    }


    /**
     * @return string - the description or the dokuwiki generated description
     */
    public
    function getDescriptionOrElseDokuWiki()
    {
        $this->processDescriptionIfNeeded();
        return $this->description;
    }


    public
    function getContent()
    {
        /**
         * use {@link io_readWikiPage(wikiFN($id, $rev), $id, $rev)};
         */
        return rawWiki($this->getId());
    }


    public
    function isInIndex()
    {
        $Indexer = idx_get_indexer();
        $pages = $Indexer->getPages();
        $return = array_search($this->getId(), $pages, true);
        return $return !== false;
    }


    public
    function upsertContent($content, $summary = "Default")
    {
        saveWikiText($this->getId(), $content, $summary);
        return $this;
    }

    public
    function addToIndex()
    {
        idx_addPage($this->getId());
    }

    public
    function getType()
    {
        $type = $this->getPersistentMetadata(self::TYPE_META_PROPERTY);
        if (isset($type)) {
            return $type;
        } else {
            if ($this->isHomePage()) {
                return self::WEBSITE_TYPE;
            } else {
                $defaultPageTypeConf = PluginUtility::getConfValue(self::CONF_DEFAULT_PAGE_TYPE);
                if (!empty($defaultPageTypeConf)) {
                    return $defaultPageTypeConf;
                } else {
                    return null;
                }
            }
        }
    }


    public
    function getFirstImage()
    {

        $relation = $this->getCurrentMetadata('relation');
        if (isset($relation[Page::FIRST_IMAGE_META_RELATION])) {
            $firstImageId = $relation[Page::FIRST_IMAGE_META_RELATION];
            if (empty($firstImageId)) {
                return null;
            } else {
                // The  metadata store the Id or the url
                // We transform them to a path id
                $pathId = $firstImageId;
                if (!media_isexternal($firstImageId)) {
                    $pathId = DokuPath::PATH_SEPARATOR . $firstImageId;
                }
                return MediaLink::createMediaLinkFromNonQualifiedPath($pathId);
            }
        }
        return null;

    }

    /**
     * Return the media found in the index
     *
     * They are saved via the function {@link \Doku_Renderer_metadata::_recordMediaUsage()}
     * called by the {@link \Doku_Renderer_metadata::internalmedia()}
     *
     *
     * {@link \Doku_Renderer_metadata::externalmedia()} does not save them
     */
    public function getExistingInternalMediaIdFromTheIndex()
    {

        $medias = [];
        $relation = $this->getCurrentMetadata('relation');
        if (isset($relation['media'])) {
            /**
             * The relation is
             * $this->meta['relation']['media'][$src] = $exists;
             *
             */
            foreach ($relation['media'] as $src => $exists) {
                if ($exists) {
                    $medias[] = $src;
                }
            }
        }
        return $medias;

    }

    /**
     * An array of local/internal images that represents the same image
     * but in different dimension and ratio
     * (may be empty)
     * @return MediaLink[]
     */
    public
    function getLocalImageSet()
    {

        /**
         * Google accepts several images dimension and ratios
         * for the same image
         * We may get an array then
         */
        $imageMeta = $this->getMetadata(self::IMAGE_META_PROPERTY);
        $images = array();
        if (!empty($imageMeta)) {
            if (is_array($imageMeta)) {
                foreach ($imageMeta as $imageIdFromMeta) {
                    $images[] = MediaLink::createMediaLinkFromNonQualifiedPath($imageIdFromMeta);
                }
            } else {
                $images = array(MediaLink::createMediaLinkFromNonQualifiedPath($imageMeta));
            }
        } else {
            if (!PluginUtility::getConfValue(self::CONF_DISABLE_FIRST_IMAGE_AS_PAGE_IMAGE)) {
                $firstImage = $this->getFirstImage();
                if ($firstImage != null) {
                    if ($firstImage->getScheme() == DokuPath::LOCAL_SCHEME) {
                        $images = array($firstImage);
                    }
                }
            }
        }
        return $images;

    }


    /**
     * @return MediaLink
     */
    public
    function getImage()
    {

        $images = $this->getLocalImageSet();
        if (sizeof($images) >= 1) {
            return $images[0];
        } else {
            return null;
        }

    }

    /**
     * Get author name
     *
     * @return string
     */
    public
    function getAuthor()
    {
        $author = $this->getPersistentMetadata('creator');
        return ($author ? $author : null);
    }

    /**
     * Get author ID
     *
     * @return string
     */
    public
    function getAuthorID()
    {
        $user = $this->getPersistentMetadata('user');
        return ($user ? $user : null);
    }


    private
    function getPersistentMetadata($key)
    {
        if (isset($this->getMetadatas()['persistent'][$key])) {
            return $this->getMetadatas()['persistent'][$key];
        } else {
            return null;
        }
    }

    public
    function getPersistentMetadatas()
    {
        return $this->getMetadatas()['persistent'];
    }

    /**
     * The modified date is the last modification date
     * the first time, this is the creation date
     * @return string|null
     */
    public
    function getModifiedDateString()
    {
        $modified = $this->getModifiedTime();
        return $modified != null ? $modified->format(Iso8601Date::getFormat()) : null;

    }

    private
    function getCurrentMetadata($key)
    {
        $key = $this->getMetadatas()[self::CURRENT_METADATA][$key];
        return ($key ? $key : null);
    }

    /**
     * Get the create date of page
     *
     * @return DateTime
     */
    public
    function getCreatedTime()
    {
        $createdMeta = $this->getPersistentMetadata('date')['created'];
        if (empty($createdMeta)) {
            return null;
        } else {
            $datetime = new DateTime();
            $datetime->setTimestamp($createdMeta);
            return $datetime;
        }
    }

    /**
     * Get the modified date of page
     *
     * The modified date is the last modification date
     * the first time, this is the creation date
     *
     * @return DateTime
     */
    public
    function getModifiedTime()
    {
        $modified = $this->getCurrentMetadata('date')['modified'];
        if (empty($modified)) {
            return null;
        } else {
            $datetime = new DateTime();
            $datetime->setTimestamp($modified);
            return $datetime;
        }
    }

    /**
     * Creation date can not be null
     * @return null|string
     */
    public
    function getCreatedDateString()
    {

        $created = $this->getCreatedTime();
        return $created != null ? $created->format(Iso8601Date::getFormat()) : null;

    }

    /**
     * Refresh the metadata (used only in test)
     */
    public
    function renderMetadata()
    {

        if ($this->metadatas == null) {
            /**
             * Read the metadata from the file
             */
            $this->metadatas = $this->getMetadatas();
        }

        /**
         * Read/render the metadata from the file
         * with parsing
         */
        $this->metadatas = p_render_metadata($this->getId(), $this->metadatas);

        /**
         * ReInitialize
         */
        $this->descriptionOrigin = null;
        $this->description = null;

        /**
         * Return
         */
        return $this;

    }

    public
    function getCountry()
    {

        $country = $this->getPersistentMetadata(self::COUNTRY_META_PROPERTY);
        if (!empty($country)) {
            if (!StringUtility::match($country, "[a-zA-Z]{2}")) {
                LogUtility::msg("The country value ($country) for the page (" . $this->getId() . ") does not have two letters (ISO 3166 alpha-2 country code)", LogUtility::LVL_MSG_ERROR, "country");
            }
            return $country;
        } else {

            return Site::getCountry();

        }

    }

    public
    function getLang()
    {
        $lang = $this->getPersistentMetadata(self::LANG_META_PROPERTY);
        if (empty($lang)) {
            global $conf;
            if (isset($conf["lang"])) {
                $lang = $conf["lang"];
            }
        }
        return $lang;
    }

    /**
     * Adapted from {@link FsWikiUtility::getHomePagePath()}
     * @return bool
     */
    public
    function isHomePage()
    {
        global $conf;
        $startPageName = $conf['start'];
        if ($this->getName() == $startPageName) {
            return true;
        } else {
            $namespaceName = noNS(cleanID($this->getNamespacePath()));
            if ($namespaceName == $this->getName()) {
                /**
                 * page named like the NS inside the NS
                 * ie ns:ns
                 */
                $startPage = Page::createPageFromId(DokuPath::absolutePathToId($this->getNamespacePath()) . DokuPath::PATH_SEPARATOR . $startPageName);
                if (!$startPage->exists()) {
                    return true;
                }
            }
        }
        return false;
    }


    public
    function getMetadata($key, $default = null)
    {
        $persistentMetadata = $this->getPersistentMetadata($key);
        if (empty($persistentMetadata)) {
            $persistentMetadata = $this->getCurrentMetadata($key);
        }
        if ($persistentMetadata == null) {
            return $default;
        } else {
            return $persistentMetadata;
        }
    }

    public
    function getPublishedTime()
    {
        $persistentMetadata = $this->getPersistentMetadata(Publication::DATE_PUBLISHED);
        if (empty($persistentMetadata)) {
            /**
             * Old metadata key
             */
            $persistentMetadata = $this->getPersistentMetadata("published");
            if (empty($persistentMetadata)) {
                return null;
            }
        }
        // Ms level parsing
        $dateTime = DateTime::createFromFormat(DateTime::ISO8601, $persistentMetadata);
        if ($dateTime === false) {
            /**
             * Should not happen as the data is validate in entry
             * at the {@link \syntax_plugin_combo_frontmatter}
             */
            LogUtility::msg("The published date ($persistentMetadata) of the page ($this) is not a valid ISO date.", LogUtility::LVL_MSG_ERROR, Publication::CANONICAL);
            return null;
        }
        return $dateTime;
    }


    /**
     * @return DateTime
     */
    public
    function getPublishedElseCreationTime()
    {
        $publishedDate = $this->getPublishedTime();
        if (empty($publishedDate)) {
            $publishedDate = $this->getCreatedTime();
        }
        return $publishedDate;
    }


    public
    function isLatePublication()
    {
        return $this->getPublishedElseCreationTime() > new DateTime('now');
    }

    public
    function getCanonicalUrl()
    {
        if (!empty($this->getCanonical())) {
            return getBaseURL(true) . strtr($this->getCanonical(), ':', '/');
        }
        return null;
    }

    public
    function getCanonicalUrlOrDefault()
    {
        $url = $this->getCanonicalUrl();
        if (empty($url)) {
            $url = $this->getUrl();
        }
        return $url;
    }

    /**
     *
     * @return string|null - the locale facebook way
     */
    public
    function getLocale()
    {
        $lang = $this->getLang();
        if (!empty($lang)) {

            $country = $this->getCountry();
            if (empty($country)) {
                $country = $lang;
            }
            return $lang . "_" . strtoupper($country);
        }
        return null;
    }

    private
    function processDescriptionIfNeeded()
    {

        if ($this->descriptionOrigin == null) {
            $descriptionArray = $this->getMetadata(Page::DESCRIPTION_PROPERTY);
            if (!empty($descriptionArray)) {
                if (array_key_exists('abstract', $descriptionArray)) {

                    $temporaryDescription = $descriptionArray['abstract'];

                    $this->descriptionOrigin = "dokuwiki";
                    if (array_key_exists('origin', $descriptionArray)) {
                        $this->descriptionOrigin = $descriptionArray['origin'];
                    }

                    if ($this->descriptionOrigin == "dokuwiki") {

                        // suppress the carriage return
                        $temporaryDescription = str_replace("\n", " ", $descriptionArray['abstract']);
                        // suppress the h1
                        $temporaryDescription = str_replace($this->getH1(), "", $temporaryDescription);
                        // Suppress the star, the tab, About
                        $temporaryDescription = preg_replace('/(\*|\t|About)/im', "", $temporaryDescription);
                        // Suppress all double space and trim
                        $temporaryDescription = trim(preg_replace('/  /m', " ", $temporaryDescription));
                        $this->description = $temporaryDescription;

                    } else {

                        $this->description = $temporaryDescription;

                    }
                }

            }
        }

    }

    public
    function hasXhtmlCache()
    {

        $renderCache = $this->getRenderCache("xhtml");
        return file_exists($renderCache->cache);

    }

    public
    function hasInstructionCache()
    {

        $instructionCache = $this->getInstructionsCache();
        /**
         * $cache->cache is the file
         */
        return file_exists($instructionCache->cache);

    }

    public
    function render()
    {

        if (!$this->isStrapSideSlot()) {
            $template = Site::getTemplate();
            LogUtility::msg("This function renders only sidebar for the " . PluginUtility::getUrl("strap", "strap template") . ". (Actual page: $this, actual template: $template)", LogUtility::LVL_MSG_ERROR);
            return "";
        }


        /**
         * Global ID is the ID of the HTTP request
         * (ie the page id)
         * We change it for the run
         * And restore it at the end
         */
        global $ID;
        $keep = $ID;
        $ID = $this->getId();

        /**
         * The code below is adapted from {@link p_cached_output()}
         * $ret = p_cached_output($file, 'xhtml', $pageid);
         *
         * We don't use {@link CacheRenderer}
         * because the cache key is the physical file
         */
        global $conf;
        $format = 'xhtml';

        $renderCache = $this->getRenderCache($format);
        if ($renderCache->useCache()) {
            $xhtml = $renderCache->retrieveCache(false);
            if (($conf['allowdebug'] || PluginUtility::isDevOrTest()) && $format == 'xhtml') {
                $logicalId = $this->getLogicalId();
                $scope = $this->getScope();
                $xhtml = "<div id=\"{$this->getCacheHtmlId()}\" style=\"display:none;\" data-logical-Id=\"$logicalId\" data-scope=\"$scope\" data-cache-op=\"hit\" data-cache-file=\"{$renderCache->cache}\"></div>" . $xhtml;
            }
        } else {

            /**
             * Get the instructions
             * Adapted from {@link p_cached_instructions()}
             */
            $instructionsCache = $this->getInstructionsCache();
            if ($instructionsCache->useCache()) {
                $instructions = $instructionsCache->retrieveCache();
            } else {
                // no cache - do some work
                $instructions = p_get_instructions($this->getContent());
                if (!$instructionsCache->storeCache($instructions)) {
                    $message = 'Unable to save cache file. Hint: disk full; file permissions; safe_mode setting ?';
                    msg($message, -1);
                    // close restore ID
                    $ID = $keep;
                    return "<div class=\"text-warning\">$message</div>";
                }
            }

            /**
             * Due to the instructions parsing, they may have been changed
             * by a component
             */
            $logicalId = $this->getLogicalId();
            $scope = $this->getScope();

            /**
             * Render
             */
            $xhtml = p_render($format, $instructions, $info);
            if ($info['cache'] && $renderCache->storeCache($xhtml)) {
                if (($conf['allowdebug'] || PluginUtility::isDevOrTest()) && $format == 'xhtml') {
                    $xhtml = "<div id=\"{$this->getCacheHtmlId()}\" style=\"display:none;\" data-logical-Id=\"$logicalId\" data-scope=\"$scope\" data-cache-op=\"created\" data-cache-file=\"{$renderCache->cache}\"></div>" . $xhtml;
                }
            } else {
                $renderCache->removeCache();   //   try to delete cachefile
                if (($conf['allowdebug'] || PluginUtility::isDevOrTest()) && $format == 'xhtml') {
                    $xhtml = "<div id=\"{$this->getCacheHtmlId()}\" style=\"display:none;\" data-logical-Id=\"$logicalId\" data-scope=\"$scope\" data-cache-op=\"forbidden\"></div>" . $xhtml;
                }
            }
        }

        // restore ID
        $ID = $keep;
        return $xhtml;

    }

    /**
     * @param string $outputFormat For instance, "xhtml" or {@links Analytics::RENDERER_NAME_MODE}
     * @return \dokuwiki\Cache\Cache the cache of the page
     *
     * Output of {@link DokuWiki_Syntax_Plugin::render()}
     *
     */
    private
    function getRenderCache($outputFormat)
    {

        if ($this->isStrapSideSlot()) {

            /**
             * Logical cache based on scope (ie logical id) is the scope and part of the key
             */
            return new CacheByLogicalKey($this, $outputFormat);

        } else {

            return new CacheRenderer($this->getId(), $this->getFileSystemPath(), $outputFormat);

        }
    }

    /**
     * @return CacheInstructions
     * The cache of the {@link CallStack call stack} (ie list of output of {@link DokuWiki_Syntax_Plugin::handle})
     */
    private
    function getInstructionsCache()
    {

        if ($this->isStrapSideSlot()) {

            /**
             * @noinspection PhpIncompatibleReturnTypeInspection
             * No inspection because this is not the same object interface
             * because we can't overide the constructor of {@link CacheInstructions}
             * but they should used the same interface (ie manipulate array data)
             */
            return new CacheInstructionsByLogicalKey($this);

        } else {

            return new CacheInstructions($this->getId(), $this->getFileSystemPath());

        }

    }

    public
    function deleteXhtmlCache()
    {
        $this->deleteCache("xhtml");
    }

    public
    function getAnchorLink()
    {
        $url = $this->getCanonicalUrlOrDefault();
        $title = $this->getTitle();
        return "<a href=\"$url\">$title</a>";
    }


    /**
     * Without the `:` at the end
     * @return string
     */
    public
    function getNamespacePath()
    {
        $ns = getNS($this->getId());
        /**
         * False means root namespace
         */
        if ($ns == false) {
            return ":";
        } else {
            return ":$ns";
        }
    }


    public
    function getScope()
    {
        /**
         * The scope may change
         * during a run, we then read the metadata file
         * each time
         */
        if (isset(p_read_metadata($this->getId())["persistent"][Page::SCOPE_KEY])) {
            return p_read_metadata($this->getId())["persistent"][Page::SCOPE_KEY];
        } else {
            return null;
        }
    }

    /**
     * Return the id of the div HTML
     * element that is added for cache debugging
     */
    public
    function getCacheHtmlId()
    {
        return "cache-" . str_replace(":", "-", $this->getId());
    }

    public
    function deleteMetadatas()
    {
        $meta = [Page::CURRENT_METADATA => [], Page::PERSISTENT_METADATA => []];
        p_save_metadata($this->getId(), $meta);
        return $this;
    }

    public
    function getPageNameNotEmpty()
    {
        $name = p_get_metadata($this->getId(), self::NAME_PROPERTY, METADATA_RENDER_USING_SIMPLE_CACHE);
        if (!blank($name)) {
            return $name;
        } else {
            return $this->getName();
        }
    }

    /**
     * @param $property
     */
    private function unsetMetadata($property)
    {
        $meta = p_read_metadata($this->getId());
        if (isset($meta['persistent'][$property])) {
            unset($meta['persistent'][$property]);
        }
        p_save_metadata($this->getId(), $meta);

    }

    /**
     * @return array - return the standard / generated metadata
     * used in templating
     */
    public function getMetadataStandard()
    {


        /**
         * The title/h1 should never be null
         * otherwise a template link such as [[$path|$title]] will return a link without an description
         * and therefore will be not visible
         * We render at least the id
         */
        $array[Analytics::H1] = $this->getH1NotEmpty();
        $title = $this->getTitleNotEmpty();
        /**
         * Hack: Replace every " by a ' to be able to detect/parse the title/h1 on a pipeline
         * @see {@link \syntax_plugin_combo_pipeline}
         */
        $title = str_replace('"', "'", $title);
        $array[Analytics::TITLE] = $title;
        $array[Analytics::PATH] = $this->getAbsolutePath();
        $array[Analytics::DESCRIPTION] = $this->getDescriptionOrElseDokuWiki();
        $array[Analytics::NAME] = $this->getPageNameNotEmpty();
        $array[self::TYPE_META_PROPERTY] = $this->getType() !== null ? $this->getType() : "";



        $array[Analytics::DATE_CREATED] = $this->getCreatedDateString();
        $array[Analytics::DATE_MODIFIED] = $this->getModifiedDateString();
        $array[Publication::DATE_PUBLISHED] = $this->getPublishedTimeAsString();

        return $array;

    }

    public function __toString()
    {
        return $this->getId();
    }

    public function setMetadata($key, $value)
    {
        p_set_metadata($this->getId(),
            [
                $key => $value
            ]
        );
    }

    private function getPublishedTimeAsString()
    {
        return $this->getPublishedTime()!==null ? $this->getPublishedTime()->format(Iso8601Date::getFormat()) : null;
    }


}
