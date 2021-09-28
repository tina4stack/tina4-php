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

use dokuwiki\Extension\SyntaxPlugin;
use syntax_plugin_combo_cell;

/**
 * An helper to create manipulate component and html attributes
 *
 * You can:
 *   * declare component attribute after parsing
 *   * declare Html attribute during parsing
 *   * output the final HTML attributes at the end of the process with the function {@link TagAttributes::toHTMLAttributeString()}
 *
 * Component attributes have precedence on HTML attributes.
 *
 * @package ComboStrap
 */
class TagAttributes
{
    /**
     * @var string the alt attribute value (known as the title for dokuwiki)
     */
    const TITLE_KEY = 'title';


    const TYPE_KEY = "type";
    const ID_KEY = "id";

    /**
     * The logical attributes that:
     *   * are not becoming HTML attributes
     *   * are never deleted
     * (ie internal reserved words)
     */
    const RESERVED_ATTRIBUTES = [
        self::SCRIPT_KEY, // no script attribute for security reason
        TagAttributes::TYPE_KEY, // type is the component class
        MediaLink::LINKING_KEY, // internal to image
        CacheMedia::CACHE_KEY, // internal also
        \syntax_plugin_combo_webcode::RENDERING_MODE_ATTRIBUTE,
        syntax_plugin_combo_cell::VERTICAL_ATTRIBUTE,
        self::OPEN_TAG,
        self::HTML_BEFORE,
        self::HTML_AFTER
    ];

    /**
     * The inline element
     * We could pass the plugin object into tag attribute in place of the logical tag
     * and check if the {@link SyntaxPlugin::getPType()} is normal
     */
    const INLINE_LOGICAL_ELEMENTS = [
        SvgImageLink::CANONICAL,
        RasterImageLink::CANONICAL,
        \syntax_plugin_combo_link::TAG, // link button for instance
        \syntax_plugin_combo_button::TAG
    ];
    const SCRIPT_KEY = "script";
    const TRANSFORM = "transform";

    const CANONICAL = "tag";
    const DISPLAY = "display";
    const CLASS_KEY = "class";
    const WIKI_ID = "wiki-id";

    /**
     * The open tag attributes
     * permit to not close the tag in {@link TagAttributes::toHtmlEnterTag()}
     *
     * It's used for instance by the {@link \syntax_plugin_combo_tooltip}
     * to advertise that it will add attribute and close it
     */
    const OPEN_TAG = "open-tag";

    /**
     * If an attribute has this value,
     * it will not be added to the output (ie {@link TagAttributes::toHtmlEnterTag()})
     * Child element can unset attribute this way
     * in order to write their own
     *
     * This is used by the {@link \syntax_plugin_combo_tooltip}
     * to advertise that the title attribute should not be set
     */
    const UN_SET = "unset";

    /**
     * When wrapping an element
     * A tag may get HTML before and after
     * Uses for instance to wrap a svg in span
     * when adding a {@link \syntax_plugin_combo_tooltip}
     */
    const HTML_BEFORE = "htmlBefore";
    const HTML_AFTER = "htmlAfter";

    /**
     * A global static counter
     * to {@link TagAttributes::generateAndSetId()}
     */
    private static $counter = 0;


    /**
     * @var array attribute that were set on a component
     */
    private $componentAttributesCaseInsensitive;

    /**
     * @var array the style declaration array
     */
    private $styleDeclaration = array();

    /**
     * @var bool - set when the transformation from component attribute to html attribute
     * was done to avoid circular problem
     */
    private $componentToHtmlAttributeProcessingWasDone = false;

    /**
     * @var array - html attributes set in the code. This is needed to make a difference
     * on attribute name that are the same such as the component attribute `width` that is
     * transformed as a style `max-width` but exists also as attribute of an image for instance
     */
    private $htmlAttributes = array();

    /**
     * @var array - the final html array
     */
    private $finalHtmlArray = array();

    /**
     * @var string the functional tag to which the attributes applies
     * It's not an HTML tag (a div can have a flex display or a block and they don't carry this information)
     * The tag gives also context for the attributes (ie an div has no natural width while an img has)
     */
    private $logicalTag;

    /**
     * An html that should be added after the enter tag
     * (used for instance to add metadata  such as backgrounds, illustration image for cards ,...
     * @var string
     */
    private $htmlAfterEnterTag;

    /**
     * Use to make the difference between
     * an HTTP call for a media (ie SVG) vs an HTTP call for a page (HTML)
     */
    const TEXT_HTML_MIME = "text/html";
    private $mime = TagAttributes::TEXT_HTML_MIME;


    /**
     * ComponentAttributes constructor.
     * Use the static create function to instantiate this object
     * @param $tag - tag (the tag gives context for the attributes
     *     * an div has no natural width while an img has
     *     * this is not always the component name / syntax name (for instance the {@link \syntax_plugin_combo_codemarkdown} is another syntax
     * for a {@link \syntax_plugin_combo_code} and have therefore the same logical name)
     * @param array $componentAttributes
     */
    private function __construct($componentAttributes = array(), $tag = null)
    {
        $this->logicalTag = $tag;
        $this->componentAttributesCaseInsensitive = new ArrayCaseInsensitive($componentAttributes);

        /**
         * Delete null values
         * Empty string, 0 may exist
         */
        foreach ($componentAttributes as $key => $value) {
            if (is_null($value)) {
                unset($this->componentAttributesCaseInsensitive[$key]);
            }
        }

    }

    /**
     * @param $match - the {@link SyntaxPlugin::handle()} match
     * @param array $defaultAttributes
     * @return TagAttributes
     */
    public static function createFromTagMatch($match, $defaultAttributes = [])
    {
        $inlineHtmlAttributes = PluginUtility::getTagAttributes($match);
        $tag = PluginUtility::getTag($match);
        $mergedAttributes = PluginUtility::mergeAttributes($inlineHtmlAttributes, $defaultAttributes);
        return self::createFromCallStackArray($mergedAttributes, $tag);
    }


    public static function createEmpty($logicalTag = "")
    {
        if ($logicalTag !== "") {
            return new TagAttributes([], $logicalTag);
        } else {
            return new TagAttributes();
        }
    }

    /**
     * @param array $renderArray - an array of key value pair
     * @param string $logicalTag - the logical tag for which this attribute will apply
     * @return TagAttributes
     */
    public static function createFromCallStackArray($renderArray, $logicalTag = null)
    {
        if (!is_array($renderArray)) {
            LogUtility::msg("The renderArray variable passed is not an array ($renderArray)", LogUtility::LVL_MSG_ERROR);
            $renderArray = TagAttributes::createEmpty($logicalTag);
        }
        return new TagAttributes($renderArray, $logicalTag);
    }


    /**
     * For CSS a unit is mandatory (not for HTML or SVG attributes)
     * @param $value
     * @return string return a CSS property with pixel as unit if the unit is not specified
     */
    public static function toQualifiedCssValue($value)
    {
        /**
         * A length value may be also `fit-content`
         * we just check that if there is only number,
         * we add the pixel
         * Same as {@link is_numeric()} ?
         */
        if (is_numeric($value)) {
            return $value . "px";
        } else {
            return $value;
        }

    }

    /**
     * Function used to normalize the attribute name to the combostrap attribute name
     * @param $name
     * @return mixed|string
     */
    public static function AttributeNameFromDokuwikiToCombo($name)
    {
        switch ($name) {
            case "w":
                return Dimension::WIDTH_KEY;
            case "h":
                return Dimension::HEIGHT_KEY;
            default:
                return $name;
        }
    }

    public function addClassName($className)
    {

        $this->addComponentAttributeValue(self::CLASS_KEY, $className);
        return $this;

    }

    public function getClass()
    {
        return $this->getValue(self::CLASS_KEY);
    }

    public function getStyle()
    {
        if (sizeof($this->styleDeclaration) != 0) {
            return PluginUtility::array2InlineStyle($this->styleDeclaration);
        } else {
            /**
             * null is needed to see if the attribute was set or not
             * because an attribute may have the empty string
             * Example: the wiki id of the root namespace
             */
            return null;
        }

    }

    /**
     * Add an attribute with its value if the value is not empty
     * @param $attributeName
     * @param $attributeValue
     */
    public function addComponentAttributeValue($attributeName, $attributeValue)
    {

        if (empty($attributeValue) && !is_bool($attributeValue)) {
            LogUtility::msg("The value of the attribute ($attributeName) is empty. Use the nonEmpty function instead", LogUtility::LVL_MSG_WARNING, "support");
        }

        $attLower = strtolower($attributeName);
        if ($this->hasComponentAttribute($attLower)) {
            $actual = $this->componentAttributesCaseInsensitive[$attLower];
        }

        if ($attributeName === "class") {
            if (!is_string($attributeValue)) {
                LogUtility::msg("The value ($attributeValue) for the `class` attribute is not a string", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
            }
            /**
             * It may be in the form "value1 value2"
             */
            $newValues = StringUtility::explodeAndTrim($attributeValue, " ");
            if (!empty($actual)) {
                $actualValues = StringUtility::explodeAndTrim($actual, " ");
            } else {
                $actualValues = [];
            }
            $newValues = PluginUtility::mergeAttributes($newValues, $actualValues);
            $this->componentAttributesCaseInsensitive[$attLower] = implode(" ", $newValues);
        } else {
            if (!empty($actual)) {
                LogUtility::msg("The attribute ($attLower) has already a value ($actual). Adding another value ($attributeValue) is not yet implemented. Use the set operation instead", LogUtility::LVL_MSG_ERROR, self::CANONICAL);
            }
            $this->componentAttributesCaseInsensitive[$attLower] = $attributeValue;
        }


    }

    public function setComponentAttributeValue($attributeName, $attributeValue)
    {
        $attLower = strtolower($attributeName);
        $actualValue = $this->getValue($attributeName);
        if ($actualValue === null || $actualValue !== TagAttributes::UN_SET) {
            $this->componentAttributesCaseInsensitive[$attLower] = $attributeValue;
        }
    }

    public function addComponentAttributeValueIfNotEmpty($attributeName, $attributeValue)
    {
        if (!empty($attributeValue)) {
            $this->addComponentAttributeValue($attributeName, $attributeValue);
        }
    }

    public function hasComponentAttribute($attributeName)
    {
        $isset = isset($this->componentAttributesCaseInsensitive[$attributeName]);
        if ($isset === false) {
            /**
             * Edge effect
             * if this is a boolean value and the first value, it may be stored in the type
             */
            if (isset($this->componentAttributesCaseInsensitive[TagAttributes::TYPE_KEY])) {
                if ($attributeName == $this->componentAttributesCaseInsensitive[TagAttributes::TYPE_KEY]) {
                    return true;
                }
            }
        }
        return $isset;
    }

    /**
     * To an HTML array in the form
     *   class => 'value1 value2',
     *   att => 'value1 value 2'
     * For historic reason, data passed between the handle and the render
     * can still be in this format
     */
    public function toHtmlArray()
    {
        if (!$this->componentToHtmlAttributeProcessingWasDone) {

            $this->componentToHtmlAttributeProcessingWasDone = true;

            /**
             * Following the rule 2 to encode the unknown value
             * We encode the component attribute (ie not the HTML attribute because
             * they may have already encoded value)
             * https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html#rule-2-attribute-encode-before-inserting-untrusted-data-into-html-common-attributes
             */

            $originalArray = $this->componentAttributesCaseInsensitive->getOriginalArray();
            $this->escapeComponentAttribute($originalArray);


            /**
             * Width and height
             */
            Dimension::processWidthAndHeight($this);

            /**
             * Process animation (onHover, onView)
             */
            Hover::processOnHover($this);
            Animation::processOnView($this);


            /**
             * Position and Stickiness
             */
            Position::processStickiness($this);
            Position::processPosition($this);

            /**
             * Block processing
             *
             * Float, align, spacing
             */
            FloatAttribute::processFloat($this);
            Align::processAlignAttributes($this);
            Spacing::processSpacingAttributes($this);
            Opacity::processOpacityAttribute($this);
            Background::processBackgroundAttributes($this);
            Shadow::process($this);

            /**
             * Process text attributes
             */
            LineSpacing::processLineSpacingAttributes($this);
            TextAlign::processTextAlign($this);
            Boldness::processBoldnessAttribute($this);
            FontSize::processFontSizeAttribute($this);
            TextColor::processTextColorAttribute($this);
            Underline::processUnderlineAttribute($this);

            /**
             * Process the style attributes if any
             */
            PluginUtility::processStyle($this);
            Toggle::processToggle($this);


            /**
             * Skin Attribute
             */
            Skin::processSkinAttribute($this);

            /**
             * Lang
             */
            Lang::processLangAttribute($this);

            /**
             * Transform
             */
            if ($this->hasComponentAttribute(self::TRANSFORM)) {
                $transformValue = $this->getValueAndRemove(self::TRANSFORM);
                $this->addStyleDeclaration("transform", $transformValue);
            }

            /**
             * Add the type class used for CSS styling
             */
            StyleUtility::addStylingClass($this);

            /**
             * Add the style has html attribute
             * before processing
             */
            $this->addHtmlAttributeValueIfNotEmpty("style", $this->getStyle());

            /**
             * Create a non-sorted temporary html attributes array
             */
            $tempHtmlArray = $this->htmlAttributes;

            /**
             * copy the unknown component attributes
             */
            $originalArray = $this->componentAttributesCaseInsensitive->getOriginalArray();
            foreach ($originalArray as $key => $value) {

                // Null Value, not needed
                if (is_null($value)) {
                    continue;
                }

                // No overwrite
                if (isset($tempHtmlArray[$key])) {
                    continue;
                }

                // Reserved attribute
                if (!in_array($key, self::RESERVED_ATTRIBUTES)) {
                    $tempHtmlArray[$key] = $value;
                }

            }


            /**
             * Sort by attribute
             * https://datacadamia.com/web/html/attribute#order
             */
            $sortedArray = array();
            $once = "once";
            $multiple = "multiple";
            $orderPatterns = [
                "class" => $once,
                "id" => $once,
                "name" => $once,
                "data-.*" => $multiple,
                "src.*" => $multiple,
                "for" => $once,
                "type" => $once,
                "href" => $once,
                "value" => $once,
                "title" => $once,
                "alt" => $once,
                "role" => $once,
                "aria-*" => $multiple];
            foreach ($orderPatterns as $pattern => $type) {
                foreach ($tempHtmlArray as $name => $value) {
                    if (empty($value)) {
                        break;
                    }
                    $searchPattern = "^$pattern$";
                    if (preg_match("/$searchPattern/", $name)) {
                        $sortedArray[$name] = $value;
                        unset($tempHtmlArray[$name]);
                        if ($type == $once) {
                            break;
                        }
                    }
                }
            }
            foreach ($tempHtmlArray as $name => $value) {

                if (!is_null($value)) {
                    /**
                     *
                     * Don't add a filter on the empty values
                     *
                     * The value of an HTML attribute may be empty
                     * Example the wiki id of the root namespace
                     *
                     * By default, {@link TagAttributes::addHtmlAttributeValue()}
                     * will not accept any value, it must be implicitly said with the
                     * {@link TagAttributes::addHtmlAttributeValue()}
                     *
                     */
                    $sortedArray[$name] = $value;
                }

            }
            $this->finalHtmlArray = $sortedArray;

        }


        return $this->finalHtmlArray;

    }

    /**
     * HTML attribute are attributes
     * that are not transformed to HTML
     * (We make a difference between a high level attribute
     * that we have in the written document set on a component
     * @param $key
     * @param $value
     * @return TagAttributes
     */
    public function addHtmlAttributeValue($key, $value)
    {
        if (blank($value)) {
            LogUtility::msg("The value of the HTML attribute is blank for the key ($key) - Tag ($this->logicalTag). Use the empty function if the value can be empty", LogUtility::LVL_MSG_ERROR);
        }
        /**
         * We encode all HTML attribute
         * because `Unescaped '<' not allowed in attributes values`
         *
         * except for url that have another encoding
         * (ie only the query parameters value should be encoded)
         */
        $urlEncoding = ["href", "src", "data-src", "data-srcset"];
        if (!in_array($key, $urlEncoding)) {
            /**
             * htmlencode the value `true` as `1`,
             * We transform it first as string, then
             */
            $value = PluginUtility::htmlEncode(StringUtility::toString($value));
        }
        $this->htmlAttributes[$key] = $value;
        return $this;
    }


    public function addHtmlAttributeValueIfNotEmpty($key, $value)
    {
        if (!empty($value)) {
            $this->addHtmlAttributeValue($key, $value);
        }
    }

    /**
     * @param $attributeName
     * @param null $default
     * @return string|array|null a HTML value in the form 'value1 value2...'
     */
    public function getValue($attributeName, $default = null)
    {
        $attributeName = strtolower($attributeName);
        if ($this->hasComponentAttribute($attributeName)) {
            return $this->componentAttributesCaseInsensitive[$attributeName];
        } else {
            return $default;
        }
    }

    /**
     * @return array - the storage format returned from the {@link SyntaxPlugin::handle()}  method
     */
    public function toInternalArray()
    {
        return $this->componentAttributesCaseInsensitive;
    }

    /**
     * Get the value and remove it from the attributes
     * @param $attributeName
     * @param $default
     * @return string|array|null
     */
    public function getValueAndRemove($attributeName, $default = null)
    {
        $attributeName = strtolower($attributeName);
        $value = $default;
        if ($this->hasComponentAttribute($attributeName)) {
            $value = $this->getValue($attributeName);

            if (!in_array($attributeName, self::RESERVED_ATTRIBUTES)) {
                /**
                 * Don't remove for instance the `type`
                 * because it may be used elsewhere
                 */
                unset($this->componentAttributesCaseInsensitive[$attributeName]);
            } else {
                LogUtility::msg("Internal: The attribute $attributeName is a reserved word and cannot be removed. Use the get function instead", LogUtility::LVL_MSG_WARNING, "support");
            }

        }
        return $value;
    }


    /**
     * @return array - an array of key string and value of the component attributes
     * This array is saved on the disk
     */
    public function toCallStackArray()
    {
        $array = array();
        $originalArray = $this->componentAttributesCaseInsensitive->getOriginalArray();
        foreach ($originalArray as $key => $value) {
            /**
             * Only null value are not passed
             * width can be zero, wiki-id can be the empty string (ie root namespace)
             */
            if (!is_null($value)) {
                $array[$key] = StringUtility::toString($value);
            }
        }
        $style = $this->getStyle();
        if ($style != null) {
            $array["style"] = $style;
        }
        return $array;
    }

    public
    function getComponentAttributeValue($attributeName, $default = null)
    {
        $lowerAttribute = strtolower($attributeName);
        $value = $default;
        if ($this->hasComponentAttribute($lowerAttribute)) {
            $value = $this->getValue($lowerAttribute);
        }
        return $value;
    }

    public
    function addStyleDeclaration($property, $value)
    {
        ArrayUtility::addIfNotSet($this->styleDeclaration, $property, $value);
    }


    public
    function hasStyleDeclaration($styleDeclaration)
    {
        return isset($this->styleDeclaration[$styleDeclaration]);
    }

    public
    function getAndRemoveStyleDeclaration($styleDeclaration)
    {
        $styleValue = $this->styleDeclaration[$styleDeclaration];
        unset($this->styleDeclaration[$styleDeclaration]);
        return $styleValue;
    }


    public
    function toHTMLAttributeString()
    {

        $tagAttributeString = "";

        $htmlArray = $this->toHtmlArray();
        foreach ($htmlArray as $name => $value) {

            /**
             * Empty value are authorized
             * null are just not set
             */
            if (!is_null($value)) {

                /**
                 * Unset attribute should not be added
                 */
                if ($value === TagAttributes::UN_SET) {
                    continue;
                }

                /**
                 * The condition is important
                 * because we may pass the javascript character `\n` in a `srcdoc` for javascript
                 * and the {@link StringUtility::toString()} will transform it as `\\n`
                 * making it unusable
                 */
                if (!is_string($value)) {
                    $stringValue = StringUtility::toString($value);
                } else {
                    $stringValue = $value;
                }


                $tagAttributeString .= $name . '="' . $stringValue . '" ';
            }

        }
        return trim($tagAttributeString);


    }

    public
    function getComponentAttributes()
    {
        return $this->toCallStackArray();
    }

    public
    function removeComponentAttributeIfPresent($attributeName)
    {
        if ($this->hasComponentAttribute($attributeName)) {
            unset($this->componentAttributesCaseInsensitive[$attributeName]);
        }

    }

    public
    function toHtmlEnterTag($htmlTag)
    {

        $enterTag = "<" . $htmlTag;
        $attributeString = $this->toHTMLAttributeString();
        if (!empty($attributeString)) {
            $enterTag .= " " . $attributeString;
        }
        /**
         * Is it an open tag ?
         */
        if (!$this->getValue(self::OPEN_TAG, false)) {

            $enterTag .= ">";

            /**
             * Do we have html after the tag is closed
             */
            if (!empty($this->htmlAfterEnterTag)) {
                $enterTag .= DOKU_LF . $this->htmlAfterEnterTag;
            }

        }


        return $enterTag;

    }

    public
    function getLogicalTag()
    {
        return $this->logicalTag;
    }

    public
    function setLogicalTag($tag)
    {
        $this->logicalTag = $tag;
    }

    public
    function removeComponentAttribute($attribute)
    {
        $lowerAtt = strtolower($attribute);
        if (isset($this->componentAttributesCaseInsensitive[$lowerAtt])) {
            $value = $this->componentAttributesCaseInsensitive[$lowerAtt];
            unset($this->componentAttributesCaseInsensitive[$lowerAtt]);
            return $value;
        } else {
            /**
             * Edge case, this is the first boolean attribute
             * and may has been categorized as the type
             */
            if (!$this->getType() == $lowerAtt) {
                LogUtility::msg("Internal Error: The component attribute ($attribute) is not present. Use the ifPresent function, if you don't want this message", LogUtility::LVL_MSG_ERROR);
            }

        }

    }

    /**
     * @param $html - an html that should be closed and added after the enter tag
     */
    public
    function addHtmlAfterEnterTag($html)
    {
        $this->htmlAfterEnterTag = $html . $this->htmlAfterEnterTag;
    }

    /**
     * The mime of the HTTP request
     * This is not the good place but yeah,
     * this class has become the context class
     *
     * Mime make the difference for a svg to know if it's required as external resource (ie SVG)
     * or as included in HTML page
     * @param $mime
     */
    public
    function setMime($mime)
    {
        $this->mime = $mime;
    }

    /**
     * @return string - the mime of the request
     */
    public
    function getMime()
    {
        return $this->mime;
    }

    public
    function getType()
    {
        return $this->getValue(self::TYPE_KEY);
    }

    /**
     * @param $attributeName
     * @return ConditionalValue
     */
    public
    function getConditionalValueAndRemove($attributeName)
    {
        $value = $this->getConditionalValueAndRemove($attributeName);
        return new ConditionalValue($value);

    }

    /**
     * @param $attributeName
     * @return false|string[] - an array of values
     */
    public
    function getValuesAndRemove($attributeName)
    {

        /**
         * Trim
         */
        $trim = trim($this->getValueAndRemove($attributeName));

        /**
         * Replace all suite of space that have more than 2 characters
         */
        $value = preg_replace("/\s{2,}/", " ", $trim);
        return explode(" ", $value);

    }

    public
    function setType($type)
    {
        $this->setComponentAttributeValue(TagAttributes::TYPE_KEY, $type);
    }

    /**
     * Merging will add the values, no replace or overwrite
     * @param $callStackArray
     */
    public
    function mergeWithCallStackArray($callStackArray)
    {
        foreach ($callStackArray as $key => $value) {
            if ($this->hasComponentAttribute($key)) {
                $this->addComponentAttributeValue($key, $value);
            } else {
                $this->setComponentAttributeValue($key, $value);
            }
        }

    }

    /**
     * @param $string
     */
    public
    function removeAttributeIfPresent($string)
    {
        $this->removeComponentAttributeIfPresent($string);
        $this->removeHTMLAttributeIfPresent($string);

    }

    private
    function removeHTMLAttributeIfPresent($string)
    {
        $lowerAtt = strtolower($string);
        if (isset($this->htmlAttributes[$lowerAtt])) {
            unset($this->htmlAttributes[$lowerAtt]);
        }
    }

    public
    function getValueAndRemoveIfPresent($attribute, $default = null)
    {
        $value = $this->getValue($attribute, $default);
        $this->removeAttributeIfPresent($attribute);
        return $value;
    }

    public
    function generateAndSetId()
    {
        self::$counter += 1;
        $id = self::$counter;
        $logicalTag = $this->getLogicalTag();
        if (!empty($logicalTag)) {
            $id = $this->logicalTag . $id;
        }
        $this->setComponentAttributeValue("id", $id);
        return $id;
    }

    /**
     *
     * @param $markiTag
     * @return string - the marki tag made of logical attribute
     * There is no processing to transform it to an HTML tag
     */
    public
    function toMarkiEnterTag($markiTag)
    {
        $enterTag = "<" . $markiTag;

        $attributeString = "";
        foreach ($this->getComponentAttributes() as $key => $value) {
            $attributeString .= "$key=\"$value\" ";
        }
        $attributeString = trim($attributeString);

        if (!empty($attributeString)) {
            $enterTag .= " " . $attributeString;
        }
        $enterTag .= ">";
        return $enterTag;

    }

    /**
     * @param string $key add an html attribute with the empty string
     */
    public
    function addEmptyHtmlAttributeValue($key)
    {

        $this->htmlAttributes[$key] = '';
        return $this;

    }

    public
    function addEmptyComponentAttributeValue($attribute)
    {
        $this->componentAttributesCaseInsensitive[$attribute] = "";
    }

    /**
     * @param $attribute
     * @param null $default
     * @return mixed
     */
    public
    function getBooleanValueAndRemove($attribute, $default = null)
    {
        $value = $this->getValueAndRemove($attribute);
        if ($value == null) {
            return $default;
        } else {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
    }

    public
    function hasAttribute($attribute)
    {
        $hasAttribute = $this->hasComponentAttribute($attribute);
        if ($hasAttribute === true) {
            return true;
        } else {
            return $this->hasHtmlAttribute($attribute);
        }
    }

    private
    function hasHtmlAttribute($attribute)
    {
        return isset($this->htmlAttributes[$attribute]);
    }

    /**
     * Component attribute are entered by the user and should be encoded
     * @param array $arrayToEscape
     * @param null $subKey
     */
    private
    function escapeComponentAttribute(array $arrayToEscape, $subKey = null)
    {

        foreach ($arrayToEscape as $name => $value) {

            $encodedName = PluginUtility::htmlEncode($name);

            /**
             * Boolean does not need to be encoded
             */
            if (is_bool($value)) {
                if ($subKey == null) {
                    $this->componentAttributesCaseInsensitive[$encodedName] = $value;
                } else {
                    $this->componentAttributesCaseInsensitive[$subKey][$encodedName] = $value;
                }
                continue;
            }

            if (is_array($value)) {
                $this->escapeComponentAttribute($value, $encodedName);
            } else {

                $value = PluginUtility::htmlEncode($value);
                if ($subKey == null) {
                    $this->componentAttributesCaseInsensitive[$encodedName] = $value;
                } else {
                    $this->componentAttributesCaseInsensitive[$subKey][$encodedName] = $value;
                }
            }
        }
    }


}
