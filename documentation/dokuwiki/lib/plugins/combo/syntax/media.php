<?php


use ComboStrap\Analytics;
use ComboStrap\CallStack;
use ComboStrap\DokuPath;
use ComboStrap\LogUtility;
use ComboStrap\MediaLink;
use ComboStrap\PluginUtility;
use ComboStrap\ThirdPartyPlugins;


require_once(__DIR__ . '/../ComboStrap/PluginUtility.php');


/**
 * Media
 *
 * Takes over the {@link \dokuwiki\Parsing\ParserMode\Media media mode}
 * that is processed by {@link Doku_Handler_Parse_Media}
 *
 *
 *
 * It can be a internal / external media
 *
 * See:
 * https://developers.google.com/search/docs/advanced/guidelines/google-images
 */
class syntax_plugin_combo_media extends DokuWiki_Syntax_Plugin
{


    const TAG = "media";

    /**
     * Used in the move plugin
     * !!! The two last word of the plugin class !!!
     */
    const COMPONENT = 'combo_' . self::TAG;


    /**
     * Found at {@link \dokuwiki\Parsing\ParserMode\Media}
     */
    const MEDIA_PATTERN = "\{\{(?:[^>\}]|(?:\}[^\}]))+\}\}";

    /**
     * Enable or disable the image
     */
    const CONF_IMAGE_ENABLE = "imageEnable";

    /**
     * Svg Rendering error
     */
    const SVG_RENDERING_ERROR_CLASS = "combo-svg-rendering-error";


    /**
     * @param $attributes
     * @param renderer_plugin_combo_analytics $renderer
     */
    public static function updateStatistics($attributes, renderer_plugin_combo_analytics $renderer)
    {
        $media = MediaLink::createFromCallStackArray($attributes);
        $renderer->stats[Analytics::MEDIAS_COUNT]++;
        $scheme = $media->getScheme();
        switch ($scheme) {
            case DokuPath::LOCAL_SCHEME:
                $renderer->stats[Analytics::INTERNAL_MEDIAS_COUNT]++;
                if (!$media->exists()) {
                    $renderer->stats[Analytics::INTERNAL_BROKEN_MEDIAS_COUNT]++;
                }
                break;
            case DokuPath::INTERNET_SCHEME:
                $renderer->stats[Analytics::EXTERNAL_MEDIAS_COUNT]++;
                break;
        }
    }


    function getType()
    {
        return 'formatting';
    }

    /**
     * How Dokuwiki will add P element
     *
     *  * 'normal' - The plugin can be used inside paragraphs (inline)
     *  * 'block'  - Open paragraphs need to be closed before plugin output - block should not be inside paragraphs
     *  * 'stack'  - Special case. Plugin wraps other paragraphs. - Stacks can contain paragraphs
     *
     * @see DokuWiki_Syntax_Plugin::getPType()
     */
    function getPType()
    {
        /**
         * An image is not a block (it can be inside paragraph)
         */
        return 'normal';
    }

    function getAllowedTypes()
    {
        return array('substition', 'formatting', 'disabled');
    }

    /**
     * It should be less than {@link \dokuwiki\Parsing\ParserMode\Media::getSort()}
     * (It was 320 at the time of writing this code)
     * @return int
     *
     */
    function getSort()
    {
        return 319;
    }


    function connectTo($mode)
    {
        $enable = $this->getConf(self::CONF_IMAGE_ENABLE, 1);
        if (!$enable) {

            // Inside a card, we need to take over and enable it
            $modes = [
                PluginUtility::getModeFromTag(syntax_plugin_combo_card::TAG),
            ];
            $enable = in_array($mode, $modes);
        }

        if ($enable) {
            if ($mode !== PluginUtility::getModeFromPluginName(ThirdPartyPlugins::IMAGE_MAPPING_NAME)) {
                $this->Lexer->addSpecialPattern(self::MEDIA_PATTERN, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
            }
        }
    }


    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {


            // As this is a container, this cannot happens but yeah, now, you know
            case DOKU_LEXER_SPECIAL :

                $media = MediaLink::createFromRenderMatch($match);
                $attributes = $media->toCallStackArray();

                $callStack = CallStack::createFromHandler($handler);

                /**
                 * Parent
                 */
                $parent = $callStack->moveToParent();
                $parentTag = "";
                if (!empty($parent)) {
                    $parentTag = $parent->getTagName();
                    if ($parentTag == syntax_plugin_combo_link::TAG) {
                        /**
                         * The image is in a link, we don't want another link
                         * to the image
                         */
                        $attributes[MediaLink::LINKING_KEY] = MediaLink::LINKING_NOLINK_VALUE;
                    }
                }

                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $attributes,
                    PluginUtility::CONTEXT => $parentTag
                );


        }
        return array();

    }

    /**
     * Render the output
     * @param string $format
     * @param Doku_Renderer $renderer
     * @param array $data - what the function handle() return'ed
     * @return boolean - rendered correctly? (however, returned value is not used at the moment)
     * @see DokuWiki_Syntax_Plugin::render()
     *
     *
     */
    function render($format, Doku_Renderer $renderer, $data)
    {

        switch ($format) {

            case 'xhtml':

                /** @var Doku_Renderer_xhtml $renderer */
                $attributes = $data[PluginUtility::ATTRIBUTES];
                $media = MediaLink::createFromCallStackArray($attributes);
                if ($media->getScheme() == DokuPath::LOCAL_SCHEME) {
                    $media = MediaLink::createFromCallStackArray($attributes, $renderer->date_at);
                    if ($media->isImage() || $media->getExtension() === "svg") {
                        /**
                         * We don't support crop
                         */
                        $crop = false;
                        if ($media->getRequestedWidth() != null && $media->getRequestedHeight() != null) {
                            /**
                             * Width of 0 = resizing by height (supported)
                             */
                            if ($media->getRequestedWidth() != "0") {
                                $crop = true;
                            }
                        }
                        if (!$crop) {
                            try {
                                $renderer->doc .= $media->renderMediaTagWithLink();
                            } catch (RuntimeException $e) {
                                $errorClass = self::SVG_RENDERING_ERROR_CLASS;
                                $message = "Media ({$media->getPath()}). Error while rendering: {$e->getMessage()}";
                                $renderer->doc .= "<span class=\"text-alert $errorClass\">" . hsc($message) . "</span>";
                                if(!PluginUtility::isTest()) {
                                    LogUtility::msg($message, LogUtility::LVL_MSG_WARNING, MediaLink::CANONICAL);
                                }
                            }
                            return true;
                        }
                    }
                }

                /**
                 * This is not an local internal media image (a video or an url image)
                 * Dokuwiki takes over
                 */
                $type = $attributes[MediaLink::MEDIA_DOKUWIKI_TYPE];
                $src = $attributes['src'];
                $title = $attributes['title'];
                $align = $attributes['align'];
                $width = $attributes['width'];
                $height = $attributes['height'];
                $cache = $attributes['cache'];
                if ($cache == null) {
                    // Dokuwiki needs a value
                    // If their is no value it will output it without any value
                    // in the query string.
                    $cache = "cache";
                }
                $linking = $attributes['linking'];
                switch ($type) {
                    case MediaLink::INTERNAL_MEDIA_CALL_NAME:
                        $renderer->doc .= $renderer->internalmedia($src, $title, $align, $width, $height, $cache, $linking, true);
                        break;
                    case MediaLink::EXTERNAL_MEDIA_CALL_NAME:
                        $renderer->doc .= $renderer->externalmedia($src, $title, $align, $width, $height, $cache, $linking, true);
                        break;
                    default:
                        LogUtility::msg("The dokuwiki media type ($type) is unknown");
                        break;
                }

                return true;

            case
            "metadata":

                /**
                 * Keep track of the metadata
                 * @var Doku_Renderer_metadata $renderer
                 */
                $attributes = $data[PluginUtility::ATTRIBUTES];
                self::registerImageMeta($attributes, $renderer);
                return true;

            case renderer_plugin_combo_analytics::RENDERER_FORMAT:

                /**
                 * Special pattern call
                 * @var renderer_plugin_combo_analytics $renderer
                 */
                $attributes = $data[PluginUtility::ATTRIBUTES];
                self::updateStatistics($attributes, $renderer);
                return true;

        }
        // unsupported $mode
        return false;
    }

    /**
     * Update the index for the move plugin
     * and {@link Page::FIRST_IMAGE_META_RELATION}
     *
     * @param array $attributes
     * @param Doku_Renderer_metadata $renderer
     */
    static public function registerImageMeta($attributes, $renderer)
    {
        $type = $attributes[MediaLink::MEDIA_DOKUWIKI_TYPE];
        $src = $attributes['src'];
        if ($src == null) {
            $src = $attributes[DokuPath::PATH_ATTRIBUTE];
        }
        $title = $attributes['title'];
        $align = $attributes['align'];
        $width = $attributes['width'];
        $height = $attributes['height'];
        $cache = $attributes['cache']; // Cache: https://www.dokuwiki.org/images#caching
        $linking = $attributes['linking'];

        switch ($type) {
            case MediaLink::INTERNAL_MEDIA_CALL_NAME:
                $renderer->internalmedia($src, $title, $align, $width, $height, $cache, $linking);
                break;
            case MediaLink::EXTERNAL_MEDIA_CALL_NAME:
                $renderer->externalmedia($src, $title, $align, $width, $height, $cache, $linking);
                break;
            default:
                LogUtility::msg("The dokuwiki media type ($type)  for metadata registration is unknown");
                break;
        }

    }


}

