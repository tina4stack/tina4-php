<?php

namespace ComboStrap;

require_once(__DIR__ . '/File.php');

class DokuPath extends File
{
    const MEDIA_TYPE = "media";
    const PAGE_TYPE = "page";
    const UNKNOWN_TYPE = "unknown";
    const PATH_SEPARATOR = ":";

    // https://www.dokuwiki.org/config:useslash
    const SEPARATOR_SLASH = "/";

    const SEPARATORS = [self::PATH_SEPARATOR, self::SEPARATOR_SLASH];
    const LOCAL_SCHEME = 'local'; // knwon also as internal media
    const INTERWIKI_SCHEME = 'interwiki';
    const INTERNET_SCHEME = "internet";
    const PATH_ATTRIBUTE = "path";

    /**
     * @var string the path id passed to function (cleaned)
     */
    private $id;

    /**
     * @var string the absolute id with the root separator
     * See {@link $id} for the absolute id without root separator for the index
     */
    private $absolutePath;

    /**
     * @var string
     */
    private $finalType;
    /**
     * @var string|null
     */
    private $rev;

    /**
     * @var string a value with an absolute id without the root
     * used in the index (ie the id)
     */
    private $qualifiedId;

    /**
     * @var string the path scheme one constant that starts with SCHEME
     * ie
     * {@link DokuPath::LOCAL_SCHEME},
     * {@link DokuPath::INTERNET_SCHEME},
     * {@link DokuPath::INTERWIKI_SCHEME}
     */
    private $scheme;


    /**
     * DokuPath constructor.
     *
     * An attempt to get all file system in one class
     *
     * protected and not private
     * otherwise the cascading init will not work
     *
     * @param string $absolutePath - the dokuwiki absolute path (may not be relative but may be a namespace)
     * @param string $type - the type (media, page)
     * @param string $rev - the revision (mtime)
     *
     * Thee path should be a qualified/absolute path because in Dokuwiki, a link to a {@link Page}
     * that ends with the {@link DokuPath::PATH_SEPARATOR} points to a start page
     * and not to a namespace. The qualification occurs in the transformation
     * from ref to page.
     *   For a page: in {@link LinkUtility::getInternalPage()}
     *   For a media: in the {@link MediaLink::createMediaLinkFromNonQualifiedPath()}
     * Because this class is mostly the file representation, it should be able to
     * represents also a namespace
     */
    protected function __construct($absolutePath, $type, $rev = null)
    {

        if (empty($absolutePath)) {
            LogUtility::msg("A null path was given", LogUtility::LVL_MSG_WARNING);
        }
        $this->absolutePath = $absolutePath;


        // Check whether this is a local or remote image or interwiki
        if (media_isexternal($absolutePath)) {

            $this->scheme = self::INTERNET_SCHEME;

        } else if (link_isinterwiki($absolutePath)) {

            $this->scheme = self::INTERWIKI_SCHEME;

        } else {

            if (substr($absolutePath, 0, 1) != DokuPath::PATH_SEPARATOR) {
                if(PluginUtility::isDevOrTest()) {
                    // Feel too much the log, test are not seeing anything, may be minimap ?
                    LogUtility::msg("The path given ($absolutePath) is not qualified", LogUtility::LVL_MSG_ERROR);
                }
                $this->absolutePath = ":" . $absolutePath;
            }
            $this->scheme = self::LOCAL_SCHEME;

        }


        /**
         * ACL check does not care about the type of id
         * https://www.dokuwiki.org/devel:event:auth_acl_check
         * https://github.com/splitbrain/dokuwiki/issues/3476
         *
         * We check if there is an extension
         * If this is the case, this is a media
         */
        if ($type == self::UNKNOWN_TYPE) {
            $lastPosition = StringUtility::lastIndexOf($absolutePath, ".");
            if ($lastPosition === FALSE) {
                $type = self::PAGE_TYPE;
            } else {
                $type = self::MEDIA_TYPE;
            }
        }
        $this->finalType = $type;
        $this->rev = $rev;

        /**
         * File path
         */
        $filePath = $this->absolutePath;
        if ($this->scheme == self::LOCAL_SCHEME) {

            $this->id = DokuPath::absolutePathToId($this->absolutePath);
            $isNamespacePath = false;
            if (\mb_substr($this->absolutePath, -1) == self::PATH_SEPARATOR) {
                $isNamespacePath = true;
            }

            global $ID;

            if (!$isNamespacePath) {

                if ($type == self::MEDIA_TYPE) {
                    if (!empty($rev)) {
                        $filePath = mediaFN($this->id, $rev);
                    } else {
                        $filePath = mediaFN($this->id);
                    }
                } else {
                    if (!empty($rev)) {
                        $filePath = wikiFN($this->id, $rev);
                    } else {
                        $filePath = wikiFN($this->id);
                    }
                }
            } else {
                /**
                 * Namespace
                 * (Fucked up is fucked up)
                 * We qualify for the namespace here
                 * because there is no link or media for a namespace
                 */
                $this->id = resolve_id(getNS($ID), $this->id, true);
                global $conf;
                if ($type == self::MEDIA_TYPE) {
                    $filePath = $conf['mediadir'] . '/' . utf8_encodeFN($this->id);
                } else {
                    $filePath = $conf['datadir'] . '/' . utf8_encodeFN($this->id);
                }
            }
        }
        parent::__construct($filePath);
    }


    /**
     *
     * @param $absolutePath
     * @return DokuPath
     */
    public static function createPagePathFromPath($absolutePath)
    {
        return new DokuPath($absolutePath, DokuPath::PAGE_TYPE);
    }

    public static function createMediaPathFromAbsolutePath($absolutePath, $rev = '')
    {
        return new DokuPath($absolutePath, DokuPath::MEDIA_TYPE, $rev);
    }

    public static function createUnknownFromId($id)
    {
        return new DokuPath(DokuPath::PATH_SEPARATOR . $id, DokuPath::UNKNOWN_TYPE);
    }

    /**
     * @param $url - a URL path http://whatever/hello/my/lord (The canonical)
     * @return string - a dokuwiki Id hello:my:lord
     */
    public static function createFromUrl($url)
    {
        // Replace / by : and suppress the first : because the global $ID does not have it
        $parsedQuery = parse_url($url, PHP_URL_QUERY);
        $parsedQueryArray = [];
        parse_str($parsedQuery, $parsedQueryArray);
        $queryId = 'id';
        if (array_key_exists($queryId, $parsedQueryArray)) {
            // Doku form (ie doku.php?id=)
            $id = $parsedQueryArray[$queryId];
        } else {
            // Slash form ie (/my/id)
            $urlPath = parse_url($url, PHP_URL_PATH);
            $id = substr(str_replace("/", ":", $urlPath), 1);
        }
        return self::createPagePathFromPath(":$id");
    }

    /**
     * Static don't ask why
     * @param $pathId
     * @return false|string
     */
    public static function getLastPart($pathId)
    {
        $endSeparatorLocation = StringUtility::lastIndexOf($pathId, DokuPath::PATH_SEPARATOR);
        if ($endSeparatorLocation === false) {
            $endSeparatorLocation = StringUtility::lastIndexOf($pathId, DokuPath::SEPARATOR_SLASH);
        }
        if ($endSeparatorLocation === false) {
            $lastPathPart = $pathId;
        } else {
            $lastPathPart = substr($pathId, $endSeparatorLocation + 1);
        }
        return $lastPathPart;
    }

    /**
     * @param $id
     * @return string
     * Return an path from a id
     */
    public static function IdToAbsolutePath($id)
    {
        if (is_null($id)) {
            LogUtility::msg("The id passed should not be null");
        }
        return DokuPath::PATH_SEPARATOR . $id;
    }

    public
    static function absolutePathToId($absolutePath)
    {
        if ($absolutePath != ":") {
            return substr($absolutePath, 1);
        } else {
            return "";
        }
    }

    public static function createMediaPathFromId($id)
    {
        return self::createMediaPathFromAbsolutePath(DokuPath::PATH_SEPARATOR . $id);
    }

    public static function createPagePathFromId($id)
    {
        return new DokuPath(DokuPath::PATH_SEPARATOR . $id, self::PAGE_TYPE);
    }


    public
    function getName()
    {
        /**
         * See also {@link noNSorNS}
         */
        $names = $this->getNames();
        return $names[sizeOf($names) - 1];
    }

    public
    function getNames()
    {
        return preg_split("/" . self::PATH_SEPARATOR . "/", $this->getId());
    }

    /**
     * @return bool true if this id represents a page
     */
    public
    function isPage()
    {

        if (
            $this->finalType === self::PAGE_TYPE
            &&
            !$this->isGlob()
        ) {
            return true;
        } else {
            return false;
        }

    }


    public
    function isGlob()
    {
        /**
         * {@link search_universal} triggers ACL check
         * with id of the form :path:*
         * (for directory ?)
         */
        return StringUtility::endWiths($this->getId(), ":*");
    }

    public
    function __toString()
    {
        return $this->getId();
    }

    /**
     * @return string - the id of dokuwiki is the absolute path
     * without the root separator (ie normalized)
     *
     * The index stores and needs this value
     * And most of the function that are not links related
     * use this format
     */
    public
    function getId()
    {

        if ($this->getScheme() == self::LOCAL_SCHEME) {
            return $this->id;
        } else {
            // the url (it's stored as id in the metadata)
            return $this->getPath();
        }

    }

    public
    function getPath()
    {

        return $this->absolutePath;

    }

    public
    function getScheme()
    {

        return $this->scheme;

    }

    /**
     * The dokuwiki revision value
     * as seen in the {@link basicinfo()} function
     * is the {@link File::getModifiedTime()} of the file
     *
     * Let op passing a revision to Dokuwiki will
     * make ti search to the history
     * The actual file will then not be found
     *
     * @return string|null
     */
    public
    function getRevision()
    {
        return $this->rev;
    }


    /**
     * @return string
     *
     * This is the absolute path WITH the root separator.
     * It's used in ref present in {@link LinkUtility link} or {@link MediaLink}
     * when creating test, otherwise the ref is considered as relative
     *
     *
     * Otherwise everywhere in Dokuwiki, they use the {@link DokuPath::getId()} absolute value that does not have any root separator
     * and is absolute (internal index, function, ...)
     *
     */
    public
    function getAbsolutePath()
    {

        return $this->absolutePath;

    }

    /**
     * @return array the pages where the dokuwiki file (page or media) is used
     *   * backlinks for page
     *   * page with media for media
     */
    public
    function getRelatedPages()
    {
        $absoluteId = $this->getId();
        if ($this->finalType == self::MEDIA_TYPE) {
            return idx_get_indexer()->lookupKey('relation_media', $absoluteId);
        } else {
            return idx_get_indexer()->lookupKey('relation_references', $absoluteId);
        }
    }


    /**
     * Return the path relative to the base directory
     * (ie $conf[basedir])
     * @return string
     */
    public
    function toRelativeFileSystemPath()
    {
        $relativeSystemPath = ".";
        if (!empty($this->getId())) {
            $relativeSystemPath .= "/" . utf8_encodeFN(str_replace(':', '/', $this->getId()));
        }
        return $relativeSystemPath;

    }

}
