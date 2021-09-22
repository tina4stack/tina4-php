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

namespace ComboStrap;


use DOMAttr;
use DOMElement;

require_once(__DIR__ . '/XmlDocument.php');
require_once(__DIR__ . '/Unit.php');

class SvgDocument extends XmlDocument
{


    const CANONICAL = "svg";

    /**
     * Namespace (used to query with xpath only the svg node)
     */
    const SVG_NAMESPACE_PREFIX = "svg";
    const CONF_SVG_OPTIMIZATION_ENABLE = "svgOptimizationEnable";

    /**
     * Optimization Configuration
     */
    const CONF_OPTIMIZATION_NAMESPACES_TO_KEEP = "svgOptimizationNamespacesToKeep";
    const CONF_OPTIMIZATION_ATTRIBUTES_TO_DELETE = "svgOptimizationAttributesToDelete";
    const CONF_OPTIMIZATION_ELEMENTS_TO_DELETE_IF_EMPTY = "svgOptimizationElementsToDeleteIfEmpty";
    const CONF_OPTIMIZATION_ELEMENTS_TO_DELETE = "svgOptimizationElementsToDelete";

    /**
     * The namespace of the editors
     * https://github.com/svg/svgo/blob/master/plugins/_collections.js#L1841
     */
    const EDITOR_NAMESPACE = [
        'http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd',
        'http://inkscape.sourceforge.net/DTD/sodipodi-0.dtd',
        'http://www.inkscape.org/namespaces/inkscape',
        'http://www.bohemiancoding.com/sketch/ns',
        'http://ns.adobe.com/AdobeIllustrator/10.0/',
        'http://ns.adobe.com/Graphs/1.0/',
        'http://ns.adobe.com/AdobeSVGViewerExtensions/3.0/',
        'http://ns.adobe.com/Variables/1.0/',
        'http://ns.adobe.com/SaveForWeb/1.0/',
        'http://ns.adobe.com/Extensibility/1.0/',
        'http://ns.adobe.com/Flows/1.0/',
        'http://ns.adobe.com/ImageReplacement/1.0/',
        'http://ns.adobe.com/GenericCustomNamespace/1.0/',
        'http://ns.adobe.com/XPath/1.0/',
        'http://schemas.microsoft.com/visio/2003/SVGExtensions/',
        'http://taptrix.com/vectorillustrator/svg_extensions',
        'http://www.figma.com/figma/ns',
        'http://purl.org/dc/elements/1.1/',
        'http://creativecommons.org/ns#',
        'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'http://www.serif.com/',
        'http://www.vector.evaxdesign.sk',
    ];

    /**
     * Default SVG values
     * https://github.com/svg/svgo/blob/master/plugins/_collections.js#L1579
     * The key are exact (not lowercase) to be able to look them up
     * for optimization
     */
    const SVG_DEFAULT_ATTRIBUTES_VALUE = array(
        "x" => '0',
        "y" => '0',
        "width" => '100%',
        "height" => '100%',
        "preserveAspectRatio" => 'xMidYMid meet',
        "zoomAndPan" => 'magnify',
        "version" => '1.1',
        "baseProfile" => 'none',
        "contentScriptType" => 'application/ecmascript',
        "contentStyleType" => 'text/css',
    );
    const CONF_PRESERVE_ASPECT_RATIO_DEFAULT = "svgPreserveAspectRatioDefault";
    const SVG_NAMESPACE_URI = "http://www.w3.org/2000/svg";


    /**
     * Type of svg
     */
    const ICON_TYPE = "icon";
    const ILLUSTRATION_TYPE = "illustration";
    const TILE_TYPE = "tile";

    /**
     * There is only two type of svg icon / tile
     *   * fill color is on the surface (known also as Solid)
     *   * stroke, the color is on the path (known as Outline
     */
    const COLOR_TYPE_FILL_SOLID = "fill";
    const COLOR_TYPE_STROKE_OUTLINE = "stroke";
    const DEFAULT_ICON_WIDTH = "24";

    /**
     * @var string - a name identifier that is added in the SVG
     */
    private $name;

    /**
     * @var boolean do the svg should be optimized
     */
    private $shouldBeOptimized;


    public function __construct($text)
    {
        parent::__construct($text);

        $this->shouldBeOptimized = PluginUtility::getConfValue(self::CONF_SVG_OPTIMIZATION_ENABLE, 1);

    }

    /**
     * @param File $file
     * @return SvgDocument
     */
    public static function createFromPath($file)
    {
        $svg = new SvgDocument($file->getContent());
        $svg->setName($file->getBaseNameWithoutExtension());
        return $svg;
    }

    public static function createFromMarkup($markup)
    {
        return new SvgDocument($markup);
    }

    /**
     * @param TagAttributes $tagAttributes
     * @return string
     */
    public function getXmlText($tagAttributes = null)
    {

        if ($tagAttributes == null) {
            $tagAttributes = TagAttributes::createEmpty();
        }

        if ($this->shouldOptimize()) {
            $this->optimize();
        }

        // Set the name (icon) attribute for test selection
        if ($tagAttributes->hasComponentAttribute("name")) {
            $name = $tagAttributes->getValueAndRemove("name");
            $this->setRootAttribute('data-name', $name);
        }

        /**
         * Width and height are in reality style properties.
         *   ie the max-width style
         * They are treated in {@link PluginUtility::processStyle()}
         */
        $svgType = $tagAttributes->getValue(TagAttributes::TYPE_KEY, self::ILLUSTRATION_TYPE);
        switch ($svgType) {
            case self::ICON_TYPE:
            case self::TILE_TYPE:
                /**
                 * Determine if this is a:
                 *   * fill
                 *   * or stroke
                 * svg
                 *
                 * The color can be set:
                 *   * on fill (surface)
                 *   * on stroke (line)
                 *
                 * Feather set it on the stroke
                 * Example: view-source:https://raw.githubusercontent.com/feathericons/feather/master/icons/airplay.svg
                 *
                 * By default, the icon should have this property when downloaded
                 * but if this not the case (such as for Material design), we set them
                 */
                $documentElement = $this->getXmlDom()->documentElement;


                /**
                 * Note: if fill is not set, the default is black
                 */
                if (!$documentElement->hasAttribute("fill")) {

                    $tagAttributes->addHtmlAttributeValue("fill", "currentColor");

                }

                /**
                 * Color is set
                 * We don't set it as a styling attribute
                 * because it's not taken into account if the
                 * svg is used as a background image
                 * fill or stroke should have at minimum "currentColor"
                 */
                if ($tagAttributes->hasComponentAttribute(ColorUtility::COLOR)) {
                    $color = $tagAttributes->getValueAndRemove(ColorUtility::COLOR);
                    $colorValue = ColorUtility::getColorValue($color);

                    /**
                     * if the stroke element is not present this is a fill icon
                     */
                    $svgColorType = self::COLOR_TYPE_FILL_SOLID;
                    if ($documentElement->hasAttribute("stroke")) {
                        $svgColorType = self::COLOR_TYPE_STROKE_OUTLINE;
                    }

                    switch ($svgColorType) {
                        case self::COLOR_TYPE_FILL_SOLID:
                            $tagAttributes->addHtmlAttributeValue("fill", $colorValue);
                            break;
                        case self::COLOR_TYPE_STROKE_OUTLINE:
                            $tagAttributes->addHtmlAttributeValue("fill", "none");
                            $tagAttributes->addHtmlAttributeValue("stroke", $colorValue);
                            break;
                    }

                }

                /**
                 * Using a icon in the navbrand component of bootstrap
                 * require the set of width and height otherwise
                 * the svg has a calculated width of null
                 * and the bar component are below the brand text
                 *
                 */
                if ($svgType == self::ICON_TYPE) {
                    $defaultWidth = self::DEFAULT_ICON_WIDTH;
                } else {
                    // tile
                    $defaultWidth = "192";
                }
                /**
                 * The default unit on attribute is pixel, no need to add it
                 * as in CSS
                 */
                $width = $tagAttributes->getValueAndRemove(Dimension::WIDTH_KEY, $defaultWidth);
                $tagAttributes->addHtmlAttributeValue("width", $width);
                $height = $tagAttributes->getValueAndRemove(Dimension::HEIGHT_KEY, $width);
                $tagAttributes->addHtmlAttributeValue("height", $height);

                break;
            default:
                /**
                 * Illustration / Image
                 */
                /**
                 * Responsive SVG
                 */
                if (!$tagAttributes->hasComponentAttribute("preserveAspectRatio")) {
                    /**
                     *
                     * Keep the same height
                     * Image in the Middle and border deleted when resizing
                     * https://developer.mozilla.org/en-US/docs/Web/SVG/Attribute/preserveAspectRatio
                     * Default is xMidYMid meet
                     */
                    $defaultAspectRatio = PluginUtility::getConfValue(self::CONF_PRESERVE_ASPECT_RATIO_DEFAULT, "xMidYMid slice");
                    $tagAttributes->addHTMLAttributeValue("preserveAspectRatio", $defaultAspectRatio);
                }

                /**
                 * Adapt to the container
                 * Height `auto` and not `100%` otherwise you get a layout shift
                 */
                $tagAttributes->addStyleDeclaration("width", "100%");
                $tagAttributes->addStyleDeclaration("height", "auto");
                break;

        }


        // Add a class on each path for easy styling
        if (!empty($this->name)) {
            $svgPaths = $this->xpath("//*[local-name()='path']");
            for ($i = 0; $i < $svgPaths->length; $i++) {

                $stylingClass = $this->name . "-" . $i;
                $this->addAttributeValue("class", $stylingClass, $svgPaths[$i]);

            }
        }

        /**
         * Svg attribute are case sensitive
         * but not the component attribute key
         * we get the value and set it then as HTML to have the good casing
         * on this attribute
         */
        $caseSensitives = ["preserveAspectRatio"];
        foreach ($caseSensitives as $caseSensitive) {
            if ($tagAttributes->hasComponentAttribute($caseSensitive)) {
                $aspectRatio = $tagAttributes->getValueAndRemove($caseSensitive);
                $tagAttributes->addHTMLAttributeValue($caseSensitive, $aspectRatio);
            }
        }

        /**
         * Set the attributes to the root
         */
        $toHtmlArray = $tagAttributes->toHtmlArray();
        foreach ($toHtmlArray as $name => $value) {
            $this->setRootAttribute($name, $value);
        }

        return parent::getXmlText();

    }

    public function getOptimizedSvg($tagAttributes = null)
    {
        $this->optimize();

        return $this->getXmlText($tagAttributes);

    }

    /**
     * @param $boolean
     * @return SvgDocument
     */
    public function setShouldBeOptimized($boolean)
    {
        $this->shouldBeOptimized = $boolean;
        return $this;
    }

    public function getMediaWidth()
    {
        $viewBox = $this->getXmlDom()->documentElement->getAttribute("viewBox");
        $attributes = explode(" ", $viewBox);
        return intval(round($attributes[2]));
    }

    public function getMediaHeight()
    {
        $viewBox = $this->getXmlDom()->documentElement->getAttribute("viewBox");
        $attributes = explode(" ", $viewBox);
        return intval(round($attributes[3]));
    }


    private function getSvgPaths()
    {
        if ($this->isXmlExtensionLoaded()) {

            /**
             * If the file was optimized, the svg namespace
             * does not exist anymore
             */
            $namespace = $this->getDocNamespaces();
            if (isset($namespace[self::SVG_NAMESPACE_PREFIX])) {
                $svgNamespace = self::SVG_NAMESPACE_PREFIX;
                $query = "//$svgNamespace:path";
            } else {
                $query = "//*[local-name()='path']";
            }
            return $this->xpath($query);
        } else {
            return array();
        }


    }


    /**
     * Optimization
     * Based on https://jakearchibald.github.io/svgomg/
     * (gui of https://github.com/svg/svgo)
     */
    public function optimize()
    {

        if ($this->shouldOptimize()) {

            /**
             * Delete Editor namespace
             * https://github.com/svg/svgo/blob/master/plugins/removeEditorsNSData.js
             */
            $confNamespaceToKeeps = PluginUtility::getConfValue(self::CONF_OPTIMIZATION_NAMESPACES_TO_KEEP);
            $namespaceToKeep = StringUtility::explodeAndTrim($confNamespaceToKeeps, ",");
            foreach ($this->getDocNamespaces() as $namespacePrefix => $namespaceUri) {
                if (
                    !empty($namespacePrefix)
                    && $namespacePrefix != "svg"
                    && !in_array($namespacePrefix, $namespaceToKeep)
                    && in_array($namespaceUri, self::EDITOR_NAMESPACE)
                ) {
                    $this->removeNamespace($namespaceUri);
                }
            }

            /**
             * Delete empty namespace rules
             */
            $documentElement = &$this->getXmlDom()->documentElement;
            foreach ($this->getDocNamespaces() as $namespacePrefix => $namespaceUri) {
                $nodes = $this->xpath("//*[namespace-uri()='$namespaceUri']");
                $attributes = $this->xpath("//@*[namespace-uri()='$namespaceUri']");
                if ($nodes->length == 0 && $attributes->length == 0) {
                    $result = $documentElement->removeAttributeNS($namespaceUri, $namespacePrefix);
                    if ($result === false) {
                        LogUtility::msg("Internal error: The deletion of the empty namespace ($namespacePrefix:$namespaceUri) didn't succeed", LogUtility::LVL_MSG_WARNING, "support");
                    }
                }
            }


            /**
             * Delete default value (version=1.1 for instance)
             */
            $defaultValues = self::SVG_DEFAULT_ATTRIBUTES_VALUE;
            foreach ($documentElement->attributes as $attribute) {
                /** @var DOMAttr $attribute */
                $name = $attribute->name;
                if (isset($defaultValues[$name])) {
                    if ($defaultValues[$name] == $attribute->value) {
                        $documentElement->removeAttributeNode($attribute);
                    }
                }
            }

            /**
             * Suppress the attributes (by default id and style)
             */
            $attributeConfToDelete = PluginUtility::getConfValue(self::CONF_OPTIMIZATION_ATTRIBUTES_TO_DELETE, "id, style");
            $attributesNameToDelete = StringUtility::explodeAndTrim($attributeConfToDelete, ",");
            foreach ($attributesNameToDelete as $value) {
                $nodes = $this->xpath("//@$value");
                foreach ($nodes as $node) {
                    /** @var DOMAttr $node */
                    /** @var DOMElement $DOMNode */
                    $DOMNode = $node->parentNode;
                    $DOMNode->removeAttributeNode($node);
                }
            }

            /**
             * Remove width/height that coincides with a viewBox attr
             * https://www.w3.org/TR/SVG11/coords.html#ViewBoxAttribute
             * Example:
             * <svg width="100" height="50" viewBox="0 0 100 50">
             * <svg viewBox="0 0 100 50">
             *
             */
            $widthAttributeValue = $documentElement->getAttribute("width");
            if (!empty($widthAttributeValue)) {
                $widthPixel = Unit::toPixel($widthAttributeValue);

                $heightAttributeValue = $documentElement->getAttribute("height");
                if (!empty($heightAttributeValue)) {
                    $heightPixel = Unit::toPixel($heightAttributeValue);

                    // ViewBox
                    $viewBoxAttribute = $documentElement->getAttribute("viewBox");
                    if (!empty($viewBoxAttribute)) {
                        $viewBoxAttributeAsArray = StringUtility::explodeAndTrim($viewBoxAttribute, " ");

                        if (sizeof($viewBoxAttributeAsArray) == 4) {
                            $minX = $viewBoxAttributeAsArray[0];
                            $minY = $viewBoxAttributeAsArray[1];
                            $widthViewPort = $viewBoxAttributeAsArray[2];
                            $heightViewPort = $viewBoxAttributeAsArray[3];
                            if (
                                $minX == 0 &
                                $minY == 0 &
                                $widthViewPort == $widthPixel &
                                $heightViewPort == $heightPixel
                            ) {
                                $documentElement->removeAttribute("width");
                                $documentElement->removeAttribute("height");
                            }

                        }
                    }
                }
            }


            /**
             * Suppress script metadata node
             * Delete of:
             *   * https://developer.mozilla.org/en-US/docs/Web/SVG/Element/script
             */
            $elementsToDeleteConf = PluginUtility::getConfValue(self::CONF_OPTIMIZATION_ELEMENTS_TO_DELETE, "script, style");
            $elementsToDelete = StringUtility::explodeAndTrim($elementsToDeleteConf, ",");
            foreach ($elementsToDelete as $elementToDelete) {
                $nodes = $this->xpath("//*[local-name()='$elementToDelete']");
                foreach ($nodes as $node) {
                    /** @var DOMElement $node */
                    $node->parentNode->removeChild($node);
                }
            }

            // Delete If Empty
            //   * https://developer.mozilla.org/en-US/docs/Web/SVG/Element/defs
            //   * https://developer.mozilla.org/en-US/docs/Web/SVG/Element/metadata
            $elementsToDeleteIfEmptyConf = PluginUtility::getConfValue(self::CONF_OPTIMIZATION_ELEMENTS_TO_DELETE_IF_EMPTY, "metadata, defs, g");
            $elementsToDeleteIfEmpty = StringUtility::explodeAndTrim($elementsToDeleteIfEmptyConf);
            foreach ($elementsToDeleteIfEmpty as $elementToDeleteIfEmpty) {
                $elementNodeList = $this->xpath("//*[local-name()='$elementToDeleteIfEmpty']");
                foreach ($elementNodeList as $element) {
                    /** @var DOMElement $element */
                    if (!$element->hasChildNodes()) {
                        $element->parentNode->removeChild($element);
                    }
                }
            }

            /**
             * Delete the svg prefix namespace definition
             * At the end to be able to query with svg as prefix
             */
            if (!in_array("svg", $namespaceToKeep)) {
                $documentElement->removeAttributeNS(self::SVG_NAMESPACE_URI, self::SVG_NAMESPACE_PREFIX);
            }

        }
    }

    public function shouldOptimize()
    {

        return $this->shouldBeOptimized;

    }

    private function setName($name)
    {
        $this->name = $name;
    }


}
