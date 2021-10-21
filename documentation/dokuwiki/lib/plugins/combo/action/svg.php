<?php


require_once(__DIR__ . '/../ComboStrap/PluginUtility.php');

use ComboStrap\Dimension;
use ComboStrap\Identity;
use ComboStrap\CacheMedia;
use ComboStrap\DokuPath;
use ComboStrap\MediaLink;
use ComboStrap\LogUtility;
use ComboStrap\PluginUtility;
use ComboStrap\Resources;
use ComboStrap\SvgImageLink;
use ComboStrap\TagAttributes;

if (!defined('DOKU_INC')) exit;
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');

/**
 * Class action_plugin_combo_svg
 * Returned an svg optimized version
 */
class action_plugin_combo_svg extends DokuWiki_Action_Plugin
{


    const CONF_SVG_UPLOAD_GROUP_NAME = "svgUploadGroupName";

    public function register(Doku_Event_Handler $controller)
    {

        $controller->register_hook('FETCH_MEDIA_STATUS', 'BEFORE', $this, 'svg_optimization');


        /**
         * Hack the upload is done via the ajax.php file
         * {@link media_upload()}
         */
        $controller->register_hook('AUTH_ACL_CHECK', 'BEFORE', $this, 'svg_mime');

        /**
         * When the parsing of a page starts
         */
        $controller->register_hook('PARSER_WIKITEXT_PREPROCESS', 'BEFORE', $this, 'svg_mime');

    }

    /**
     * @param Doku_Event $event
     * https://www.dokuwiki.org/devel:event:fetch_media_status
     */
    public function svg_optimization(Doku_Event &$event)
    {

        if ($event->data['ext'] != 'svg') return;
        if ($event->data['status'] >= 400) return; // ACLs and precondition checks


        $tagAttributes = TagAttributes::createEmpty();
        $width = $event->data['width'];
        if ($width != 0) {
            $tagAttributes->addComponentAttributeValue(Dimension::WIDTH_KEY, $width);
        }
        $height = $event->data['height'];
        if ($height != 0) {
            $tagAttributes->addComponentAttributeValue(Dimension::HEIGHT_KEY, $height);
        }
        $tagAttributes->addComponentAttributeValue(CacheMedia::CACHE_KEY, $event->data['cache']);

        $mime = "image/svg+xml";
        $event->data["mime"] = $mime;
        $tagAttributes->setMime($mime);

        /**
         * Add the extra attributes
         */
        $rev = null;
        foreach ($_REQUEST as $name => $value) {
            switch ($name) {
                case "media":
                case "w":
                case "h":
                case "cache":
                case CacheMedia::CACHE_BUSTER_KEY:
                case "tok": // A checker
                    // Nothing to do, we take them
                    break;
                case "rev":
                    $rev = $value;
                    break;
                case "u":
                case "p":
                case "http_credentials":
                    // Credentials data
                    break;
                default:
                    if (!empty($value)) {
                        if (!in_array(strtolower($name), MediaLink::NON_URL_ATTRIBUTES)) {
                            $tagAttributes->addComponentAttributeValue($name, $value);
                        } else {
                            LogUtility::msg("The attribute ($name) is not a valid fetch image URL attribute and was not added", LogUtility::LVL_MSG_WARNING, SvgImageLink::CANONICAL);
                        }
                    } else {
                        LogUtility::msg("Internal Error: the value of the query name ($name) is empty", LogUtility::LVL_MSG_WARNING, SvgImageLink::CANONICAL);
                    }
            }
        }


        $id = $event->data["media"];
        $pathId = DokuPath::IdToAbsolutePath($id);
        $svgImageLink = SvgImageLink::createMediaLinkFromNonQualifiedPath($pathId, $rev, $tagAttributes);
        try {
            $event->data['file'] = $svgImageLink->getSvgFile();
        } catch (RuntimeException $e) {

            $event->data['file'] = PluginUtility::getResourceBaseUrl()."/images/error-bad-format.svg";
            $event->data['status'] = 422;

        }


    }

    /**
     * @param Doku_Event $event
     * {@link media_save} is checking the authorized mime type
     * Svg is not by default, we add it here if the user is admin or
     * in a specified group
     */
    public function svg_mime(Doku_Event &$event)
    {

        self::allowSvgIfAuthorized();

    }

    /**
     *
     */
    public static function allowSvgIfAuthorized()
    {
        $isAdmin = Identity::isAdmin();
        $isMember = Identity::isMember("@" . self::CONF_SVG_UPLOAD_GROUP_NAME);

        if ($isAdmin || $isMember) {
            /**
             * Enhance the svg mime type
             * {@link getMimeTypes()}
             */
            global $config_cascade;
            $svgMimeConf = Resources::getConfResourceDirectory() . "/svg.mime.conf";
            $config_cascade['mime']['local'][] = $svgMimeConf;
        }

    }


}
