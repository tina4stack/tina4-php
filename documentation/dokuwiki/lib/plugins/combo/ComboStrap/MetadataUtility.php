<?php

namespace ComboStrap;

use dokuwiki\Extension\Plugin;

/**
 * Class MetadataUtility
 * @package ComboStrap
 * A class with Dokuwiki Metadata utility
 */
class MetadataUtility
{

    /**
     * The HTML id of the box (for testing purpose)
     */
    const META_MESSAGE_BOX_ID = "metadata-viewer";
    /**
     * The default attributes
     */
    const CONF_METADATA_DEFAULT_ATTRIBUTES = "metadataViewerDefaultAttributes";

    /**
     * Enable metadata viewer when editing a page
     */
    const CONF_ENABLE_WHEN_EDITING = "enableMetadataViewerWhenEditing";

    /**
     * A regular expression to filter the output
     */
    const EXCLUDE_ATTRIBUTE = "exclude";

    /**
     * The HTML tag
     */
    const TAG = "metadata";
    const TITLE_ATTRIBUTE = "title";

    /**
     *
     * @param Plugin $plugin - the calling dokuwiki plugin
     * @param $inlineAttributes - the inline attribute of a component if any
     * @return string - an HTML box of the array
     */
    public static function getHtmlMetadataBox($plugin, $inlineAttributes = array())
    {

        // Attributes processing
        $defaultStringAttributes = $plugin->getConf(MetadataUtility::CONF_METADATA_DEFAULT_ATTRIBUTES);
        $defaultAttributes = PluginUtility::parseAttributes($defaultStringAttributes);
        $attributes = PluginUtility::mergeAttributes($inlineAttributes, $defaultAttributes);

        // Building the box
        $content = '<div id="' . self::META_MESSAGE_BOX_ID . '" class="alert alert-success " role="note">';
        if (array_key_exists(self::TITLE_ATTRIBUTE, $attributes)) {
            $content .= '<h2 class="alert-heading" ">' . $attributes[self::TITLE_ATTRIBUTE] . '</h2>';
        }
        global $ID;
        $metadata = p_read_metadata($ID);
        $metas = $metadata['persistent'];


        if (array_key_exists(self::EXCLUDE_ATTRIBUTE, $attributes)) {
            $filter = $attributes[self::EXCLUDE_ATTRIBUTE];
            ArrayUtility::filterArrayByKey($metas, $filter);
        }
        if (!array_key_exists("canonical", $metas)) {
            $metas["canonical"] = PluginUtility::getUrl("canonical", "No Canonical");
        }

        $content .= ArrayUtility::formatAsHtmlList($metas);


        $referenceStyle = array(
            "font-size" => "95%",
            "clear" => "both",
            "bottom" => "10px",
            "right" => "15px",
            "position" => "absolute",
            "font-style" => "italic"
        );

        $content .= '<div style="' . PluginUtility::array2InlineStyle($referenceStyle) . '">' . $plugin->getLang('message_come_from') . PluginUtility::getUrl("metadata:viewer", "ComboStrap Metadata Viewer") . '</div>';
        $content .= '</div>';
        return $content;

    }

    /**
     * Get a meta for the current page
     * @param $key - the meta key
     * @return string
     */
    public static function getMeta($key)
    {
        return p_get_metadata(PluginUtility::getPageId(), $key);
    }

    /**
     * Set a meta for the current page
     * @param $key
     * @param $value
     */
    public static function setMeta($key, $value)
    {
        p_set_metadata(PluginUtility::getPageId(), array($key => $value));
    }
}
