<?php


namespace ComboStrap;


use dokuwiki\Extension\Plugin;
use dokuwiki\Extension\SyntaxPlugin;

require_once(__DIR__ . '/../vendor/autoload.php');

/**
 * Plugin Utility is added in all Dokuwiki extension
 * and
 * all classes are added in plugin utility
 *
 * This is an utility master and the class loader
 *
 * If the load is relative, the load path is used
 * and the bad php file may be loaded
 * Furthermore, the absolute path helps
 * the IDE when refactoring
 */
require_once(__DIR__ . '/AdsUtility.php');
require_once(__DIR__ . '/Align.php');
require_once(__DIR__ . '/Analytics.php');
require_once(__DIR__ . '/AnalyticsMenuItem.php');
require_once(__DIR__ . '/Animation.php');
require_once(__DIR__ . '/ArrayCaseInsensitive.php');
require_once(__DIR__ . '/ArrayUtility.php');
require_once(__DIR__ . '/Background.php');
require_once(__DIR__ . '/Boldness.php');
require_once(__DIR__ . '/Bootstrap.php');
require_once(__DIR__ . '/BreadcrumbHierarchical.php');
require_once(__DIR__ . '/CacheByLogicalKey.php');
require_once(__DIR__ . '/CacheInstructionsByLogicalKey.php');
require_once(__DIR__ . '/CacheManager.php');
require_once(__DIR__ . '/CacheMedia.php');
require_once(__DIR__ . '/Call.php');
require_once(__DIR__ . '/CallStack.php');
require_once(__DIR__ . '/ColorUtility.php');
require_once(__DIR__ . '/ConditionalValue.php');
require_once(__DIR__ . '/ConfUtility.php');
require_once(__DIR__ . '/Dimension.php');
require_once(__DIR__ . '/DokuPath.php');
require_once(__DIR__ . '/DokuwikiUrl.php');
require_once(__DIR__ . '/File.php');
require_once(__DIR__ . '/FloatAttribute.php');
require_once(__DIR__ . '/FontSize.php');
require_once(__DIR__ . '/FsWikiUtility.php');
require_once(__DIR__ . '/HeaderUtility.php');
require_once(__DIR__ . '/HistoricalBreadcrumbMenuItem.php');
require_once(__DIR__ . '/Hover.php');
require_once(__DIR__ . '/Http.php');
require_once(__DIR__ . '/Icon.php');
require_once(__DIR__ . '/Identity.php');
require_once(__DIR__ . '/Iso8601Date.php');
require_once(__DIR__ . '/Lang.php');
require_once(__DIR__ . '/LineSpacing.php');
require_once(__DIR__ . '/LogUtility.php');
require_once(__DIR__ . '/LowQualityPage.php');
require_once(__DIR__ . '/MediaLink.php');
require_once(__DIR__ . '/Message.php');
require_once(__DIR__ . '/MetadataUtility.php');
require_once(__DIR__ . '/NavBarUtility.php');
require_once(__DIR__ . '/Opacity.php');
require_once(__DIR__ . '/Page.php');
require_once(__DIR__ . '/PageProtection.php');
require_once(__DIR__ . '/PageRules.php');
require_once(__DIR__ . '/PageSql.php');
require_once(__DIR__ . '/PageSqlParser/PageSqlLexer.php');
require_once(__DIR__ . '/PageSqlParser/PageSqlParser.php');
require_once(__DIR__ . '/PageSqlTreeListener.php');
require_once(__DIR__ . '/PagesIndex.php');
require_once(__DIR__ . '/PipelineUtility.php');
require_once(__DIR__ . '/Position.php');
require_once(__DIR__ . '/Prism.php');
require_once(__DIR__ . '/Publication.php');
require_once(__DIR__ . '/RasterImageLink.php');
require_once(__DIR__ . '/RenderUtility.php');
require_once(__DIR__ . '/Resources.php');
require_once(__DIR__ . '/Shadow.php');
require_once(__DIR__ . '/Site.php');
require_once(__DIR__ . '/Skin.php');
require_once(__DIR__ . '/Snippet.php');
require_once(__DIR__ . '/SnippetManager.php');
require_once(__DIR__ . '/Spacing.php');
require_once(__DIR__ . '/Sqlite.php');
require_once(__DIR__ . '/StringUtility.php');
require_once(__DIR__ . '/StyleUtility.php');
require_once(__DIR__ . '/SvgDocument.php');
require_once(__DIR__ . '/SvgImageLink.php');
require_once(__DIR__ . '/Syntax.php');
require_once(__DIR__ . '/TableUtility.php');
require_once(__DIR__ . '/Tag.php');
require_once(__DIR__ . '/TagAttributes.php');
require_once(__DIR__ . '/Template.php');
require_once(__DIR__ . '/TemplateUtility.php');
require_once(__DIR__ . '/TextAlign.php');
require_once(__DIR__ . '/TextColor.php');
require_once(__DIR__ . '/ThirdMediaLink.php');
require_once(__DIR__ . '/ThirdPartyPlugins.php');
require_once(__DIR__ . '/TocUtility.php');
require_once(__DIR__ . '/Toggle.php');
require_once(__DIR__ . '/Underline.php');
require_once(__DIR__ . '/Unit.php');
require_once(__DIR__ . '/UrlManagerBestEndPage.php');
require_once(__DIR__ . '/UrlUtility.php');
require_once(__DIR__ . '/XhtmlUtility.php');
require_once(__DIR__ . '/XmlDocument.php');
require_once(__DIR__ . '/XmlUtility.php');


/**
 * Class url static
 * List of static utilities
 */
class PluginUtility
{

    const DOKU_DATA_DIR = '/dokudata/pages';
    const DOKU_CACHE_DIR = '/dokudata/cache';

    /**
     * Key in the data array between the handle and render function
     */
    const STATE = "state";
    const PAYLOAD = "payload"; // The html or text
    const ATTRIBUTES = "attributes";
    // The context is generally the parent tag but it may be also the grandfather.
    // It permits to determine the HTML that is outputted
    const CONTEXT = 'context';
    const TAG = "tag";

    /**
     * The name of the hidden/private namespace
     * where the icon and other artifactory are stored
     */
    const COMBOSTRAP_NAMESPACE_NAME = "combostrap";

    const PARENT = "parent";
    const POSITION = "position";

    /**
     * Class to center an element
     */
    const CENTER_CLASS = "mx-auto";


    const EDIT_SECTION_TARGET = 'section';
    const ERROR_MESSAGE = "errorAtt";

    /**
     * The URL base of the documentation
     */
    static $URL_BASE;


    /**
     * @var string - the plugin base name (ie the directory)
     * ie $INFO_PLUGIN['base'];
     * This is a constant because it permits code analytics
     * such as verification of a path
     */
    const PLUGIN_BASE_NAME = "combo";

    /**
     * The name of the template plugin
     */
    const TEMPLATE_STRAP_NAME = "strap";

    /**
     * @var array
     */
    static $INFO_PLUGIN;

    static $PLUGIN_LANG;

    /**
     * The plugin name
     * (not the same than the base as it's not related to the directory
     * @var string
     */
    public static $PLUGIN_NAME;
    /**
     * @var mixed the version
     */
    private static $VERSION;


    /**
     * Initiate the static variable
     * See the call after this class
     */
    static function init()
    {

        $pluginInfoFile = __DIR__ . '/../plugin.info.txt';
        self::$INFO_PLUGIN = confToHash($pluginInfoFile);
        self::$PLUGIN_NAME = 'ComboStrap';
        global $lang;
        self::$PLUGIN_LANG = $lang[self::PLUGIN_BASE_NAME];
        self::$URL_BASE = "https://" . parse_url(self::$INFO_PLUGIN['url'], PHP_URL_HOST);
        self::$VERSION = self::$INFO_PLUGIN['version'];

        PluginUtility::initStaticManager();

    }

    /**
     * @param $inputExpression
     * @return false|int 1|0
     * returns:
     *    - 1 if the input expression is a pattern,
     *    - 0 if not,
     *    - FALSE if an error occurred.
     */
    static function isRegularExpression($inputExpression)
    {

        $regularExpressionPattern = "/(\\/.*\\/[gmixXsuUAJ]?)/";
        return preg_match($regularExpressionPattern, $inputExpression);

    }

    /**
     * Return a mode from a tag (ie from a {@link Plugin::getPluginComponent()}
     * @param $tag
     * @return string
     *
     * A mode is just a name for a class
     * Example: $Parser->addMode('listblock',new Doku_Parser_Mode_ListBlock());
     */
    public static function getModeFromTag($tag)
    {
        return "plugin_" . self::getComponentName($tag);
    }

    /**
     * @param $tag
     * @return string
     *
     * Create a lookahead pattern for a container tag used to enter in a mode
     */
    public static function getContainerTagPattern($tag)
    {
        // this pattern ensure that the tag
        // `accordion` will not intercept also the tag `accordionitem`
        // where:
        // ?: means non capturing group (to not capture the last >)
        // (\s.*?): is a capturing group that starts with a space
        $pattern = "(?:\s.*?>|>)";
        return '<' . $tag . $pattern . '(?=.*?<\/' . $tag . '>)';
    }

    /**
     * This pattern allows space after the tag name
     * for an end tag
     * As XHTML (https://www.w3.org/TR/REC-xml/#dt-etag)
     * @param $tag
     * @return string
     */
    public static function getEndTagPattern($tag)
    {
        return "</$tag\s*>";
    }

    /**
     * @param $tag
     * @return string
     *
     * Create a open tag pattern without lookahead.
     * Used for https://dev.w3.org/html5/html-author/#void-elements-0
     */
    public static function getVoidElementTagPattern($tag)
    {
        return '<' . $tag . '.*?>';
    }


    /**
     * Take an array  where the key is the attribute name
     * and return a HTML tag string
     *
     * The attribute name and value are escaped
     *
     * @param $attributes - combo attributes
     * @return string
     * @deprecated to allowed background and other metadata, use {@link TagAttributes::toHtmlEnterTag()}
     */
    public static function array2HTMLAttributesAsString($attributes)
    {

        $tagAttributes = TagAttributes::createFromCallStackArray($attributes);
        return $tagAttributes->toHTMLAttributeString();

    }

    /**
     *
     * Parse the attributes part of a match
     *
     * Example:
     *   line-numbers="value"
     *   line-numbers='value'
     *
     * This value may be in:
     *   * configuration value
     *   * as well as in the match of a {@link SyntaxPlugin}
     *
     * @param $string
     * @return array
     *
     * To parse a match, use {@link PluginUtility::getTagAttributes()}
     *
     *
     */
    public static function parseAttributes($string)
    {

        $parameters = array();

        // Rules
        //  * name may be alone (ie true boolean attribute)
        //  * a name may get a `-`
        //  * there may be space every everywhere when the value is enclosed with a quote
        //  * there may be no space in the value and between the equal sign when the value is not enclosed
        //
        // /i not case sensitive
        $attributePattern = '\s*([-\w]+)\s*(?:=(\s*[\'"]([^`"]*)[\'"]\s*|[^\s]*))?';
        $result = preg_match_all('/' . $attributePattern . '/i', $string, $matches);
        if ($result != 0) {
            foreach ($matches[1] as $key => $parameterKey) {

                // group 3 (ie the value between quotes)
                $value = $matches[3][$key];
                if ($value == "") {
                    // check the value without quotes
                    $value = $matches[2][$key];
                }
                // if there is no value, this is a boolean
                if ($value == "") {
                    $value = true;
                } else {
                    $value = hsc($value);
                }
                $parameters[hsc(strtolower($parameterKey))] = $value;
            }
        }
        return $parameters;

    }

    public static function getTagAttributes($match)
    {
        return self::getQualifiedTagAttributes($match, false, "");
    }

    /**
     * Return the attribute of a tag
     * Because they are users input, they are all escaped
     * @param $match
     * @param $hasThirdValue - if true, the third parameter is treated as value, not a property and returned in the `third` key
     * use for the code/file/console where they accept a name as third value
     * @param $keyThirdArgument - if a third argument is found, return it with this key
     * @return array
     */
    public static function getQualifiedTagAttributes($match, $hasThirdValue, $keyThirdArgument)
    {

        $match = PluginUtility::getPreprocessEnterTag($match);

        // Suppress the tag name (ie until the first blank)
        $spacePosition = strpos($match, " ");
        if (!$spacePosition) {
            // No space, meaning this is only the tag name
            return array();
        }
        $match = trim(substr($match, $spacePosition));
        if ($match == "") {
            return array();
        }

        // Do we have a type as first argument ?
        $attributes = array();
        $spacePosition = strpos($match, " ");
        if ($spacePosition) {
            $nextArgument = substr($match, 0, $spacePosition);
        } else {
            $nextArgument = $match;
        }
        if (!strpos($nextArgument, "=")) {
            $attributes["type"] = $nextArgument;
            // Suppress the type
            $match = substr($match, strlen($nextArgument));
            $match = trim($match);

            // Do we have a value as first argument ?
            if (!empty($hasThirdValue)) {
                $spacePosition = strpos($match, " ");
                if ($spacePosition) {
                    $nextArgument = substr($match, 0, $spacePosition);
                } else {
                    $nextArgument = $match;
                }
                if (!strpos($nextArgument, "=") && !empty($nextArgument)) {
                    $attributes[$keyThirdArgument] = $nextArgument;
                    // Suppress the third argument
                    $match = substr($match, strlen($nextArgument));
                    $match = trim($match);
                }
            }
        }

        // Parse the remaining attributes
        $parsedAttributes = self::parseAttributes($match);

        // Merge
        $attributes = array_merge($attributes, $parsedAttributes);;

        return $attributes;

    }

    /**
     * @param array $styleProperties - an array of CSS properties with key, value
     * @return string - the value for the style attribute (ie all rules where joined with the comma)
     */
    public static function array2InlineStyle(array $styleProperties)
    {
        $inlineCss = "";
        foreach ($styleProperties as $key => $value) {
            $inlineCss .= "$key:$value;";
        }
        // Suppress the last ;
        if ($inlineCss[strlen($inlineCss) - 1] == ";") {
            $inlineCss = substr($inlineCss, 0, -1);
        }
        return $inlineCss;
    }

    /**
     * @param $tag
     * @return string
     * Create a pattern used where the tag is not a container.
     * ie
     * <br/>
     * <icon/>
     * This is generally used with a subtition plugin
     * and a {@link Lexer::addSpecialPattern} state
     * where the tag is just replaced
     */
    public static function getEmptyTagPattern($tag)
    {
        return '<' . $tag . '.*?/>';
    }

    /**
     * Just call this function from a class like that
     *     getTageName(get_called_class())
     * to get the tag name (ie the component plugin)
     * of a syntax plugin
     *
     * @param $get_called_class
     * @return string
     */
    public static function getTagName($get_called_class)
    {
        list(/* $t */, /* $p */, /* $n */, $c) = explode('_', $get_called_class, 4);
        return (isset($c) ? $c : '');
    }

    /**
     * Just call this function from a class like that
     *     getAdminPageName(get_called_class())
     * to get the page name of a admin plugin
     *
     * @param $get_called_class
     * @return string - the admin page name
     */
    public static function getAdminPageName($get_called_class)
    {
        $names = explode('_', $get_called_class);
        $names = array_slice($names, -2);
        return implode('_', $names);
    }

    public static function getNameSpace()
    {
        // No : at the begin of the namespace please
        return self::PLUGIN_BASE_NAME . ':';
    }

    /**
     * @param $get_called_class - the plugin class
     * @return array
     */
    public static function getTags($get_called_class)
    {
        $elements = array();
        $elementName = PluginUtility::getTagName($get_called_class);
        $elements[] = $elementName;
        $elements[] = strtoupper($elementName);
        return $elements;
    }

    /**
     * Render a text
     * @param $pageContent
     * @return string|null
     */
    public static function render($pageContent)
    {
        return RenderUtility::renderText2XhtmlAndStripPEventually($pageContent, false);
    }


    /**
     * This method will takes attributes
     * and process the plugin styling attribute such as width and height
     * to put them in a style HTML attribute
     * @param TagAttributes $attributes
     */
    public static function processStyle(&$attributes)
    {
        // Style
        $styleAttributeName = "style";
        if ($attributes->hasComponentAttribute($styleAttributeName)) {
            $properties = explode(";", $attributes->getValueAndRemove($styleAttributeName));
            foreach ($properties as $property) {
                list($key, $value) = explode(":", $property);
                if ($key != "") {
                    $attributes->addStyleDeclaration($key, $value);
                }
            }
        }


        /**
         * Border Color
         * For background color, see {@link TagAttributes::processBackground()}
         * For text color, see {@link TextColor}
         */

        if ($attributes->hasComponentAttribute(ColorUtility::BORDER_COLOR)) {
            $colorValue = $attributes->getValueAndRemove(ColorUtility::BORDER_COLOR);
            $attributes->addStyleDeclaration(ColorUtility::BORDER_COLOR, ColorUtility::getColorValue($colorValue));
            self::checkDefaultBorderColorAttributes($attributes);
        }


    }

    /**
     * Return the name of the requested script
     */
    public
    static function getRequestScript()
    {
        $scriptPath = null;
        $testPropertyValue = self::getPropertyValue("SCRIPT_NAME");
        if (defined('DOKU_UNITTEST') && $testPropertyValue != null) {
            return $testPropertyValue;
        }
        if (array_key_exists("DOCUMENT_URI", $_SERVER)) {
            $scriptPath = $_SERVER["DOCUMENT_URI"];
        }
        if ($scriptPath == null && array_key_exists("SCRIPT_NAME", $_SERVER)) {
            $scriptPath = $_SERVER["SCRIPT_NAME"];
        }
        if ($scriptPath == null) {
            msg("Unable to find the main script", LogUtility::LVL_MSG_ERROR);
        }
        $path_parts = pathinfo($scriptPath);
        return $path_parts['basename'];
    }

    /**
     *
     * @param $name
     * @param $default
     * @return string - the value of a query string property or if in test mode, the value of a test variable
     * set with {@link self::setTestProperty}
     * This is used to test script that are not supported by the dokuwiki test framework
     * such as css.php
     */
    public
    static function getPropertyValue($name, $default = null)
    {
        global $INPUT;
        $value = $INPUT->str($name);
        if ($value == null && defined('DOKU_UNITTEST')) {
            global $COMBO;
            $value = $COMBO[$name];
        }
        if ($value == null) {
            return $default;
        } else {
            return $value;
        }

    }

    /**
     * Create an URL to the documentation website
     * @param $canonical - canonical id or slug
     * @param $text -  the text of the link
     * @param bool $withIcon - used to break the recursion with the message in the {@link Icon}
     * @return string - an url
     */
    public
    static function getUrl($canonical, $text, $withIcon = true)
    {
        /** @noinspection SpellCheckingInspection */

        $xhtmlIcon = "";
        if ($withIcon) {

            /**
             * We don't include it as an external resource via url
             * because it then make a http request for every logo
             * in the configuration page and makes it really slow
             */
            $path = File::createFromPath(Resources::getImagesDirectory() . "/logo.svg");
            $tagAttributes = TagAttributes::createEmpty(SvgImageLink::CANONICAL);
            $tagAttributes->addComponentAttributeValue(TagAttributes::TYPE_KEY, SvgDocument::ICON_TYPE);
            $tagAttributes->addComponentAttributeValue(Dimension::WIDTH_KEY, "20");
            $cache = new CacheMedia($path, $tagAttributes);
            if (!$cache->isCacheUsable()) {
                $xhtmlIcon = SvgDocument::createFromPath($path)
                    ->setShouldBeOptimized(true)
                    ->getXmlText($tagAttributes);
                $cache->storeCache($xhtmlIcon);
            }
            $xhtmlIcon = file_get_contents($cache->getFile()->getFileSystemPath());


        }
        return $xhtmlIcon . ' <a href="' . self::$URL_BASE . '/' . str_replace(":", "/", $canonical) . '" title="' . $text . '">' . $text . '</a>';
    }

    /**
     * An utility function to not search every time which array should be first
     * @param array $inlineAttributes - the component inline attributes
     * @param array $defaultAttributes - the default configuration attributes
     * @return array - a merged array
     */
    public
    static function mergeAttributes(array $inlineAttributes, array $defaultAttributes = array())
    {
        return array_merge($defaultAttributes, $inlineAttributes);
    }

    /**
     * A pattern for a container tag
     * that needs to catch the content
     *
     * Use as a special pattern (substition)
     *
     * The {@link \syntax_plugin_combo_math} use it
     * @param $tag
     * @return string - a pattern
     */
    public
    static function getLeafContainerTagPattern($tag)
    {
        return '<' . $tag . '.*?>.*?<\/' . $tag . '>';
    }

    /**
     * Return the content of a tag
     * <math>Content</math>
     * @param $match
     * @return string the content
     */
    public
    static function getTagContent($match)
    {
        // From the first >
        $start = strpos($match, ">");
        if ($start == false) {
            LogUtility::msg("The match does not contain any opening tag. Match: {$match}", LogUtility::LVL_MSG_ERROR);
            return "";
        }
        $match = substr($match, $start + 1);
        // If this is the last character, we get a false
        if ($match == false) {
            LogUtility::msg("The match does not contain any closing tag. Match: {$match}", LogUtility::LVL_MSG_ERROR);
            return "";
        }

        $end = strrpos($match, "</");
        if ($end == false) {
            LogUtility::msg("The match does not contain any closing tag. Match: {$match}", LogUtility::LVL_MSG_ERROR);
            return "";
        }

        return substr($match, 0, $end);
    }

    /**
     *
     * Check if a HTML tag was already added for a request
     * The request id is just the timestamp
     * An indicator array should be provided
     * @return string
     */
    public
    static function getRequestId()
    {

        if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            // since php 5.4
            $requestTime = $_SERVER['REQUEST_TIME_FLOAT'];
        } else {
            // DokuWiki test framework use this
            $requestTime = $_SERVER['REQUEST_TIME'];
        }
        $keyPrefix = 'combo_';

        global $ID;
        return $keyPrefix . hash('crc32b', $_SERVER['REMOTE_ADDR'] . $_SERVER['REMOTE_PORT'] . $requestTime . $ID);

    }

    /**
     * Get the page id
     * If the page is a sidebar, it will not return the id of the sidebar
     * but the one of the page
     * @return string
     */
    public
    static function getPageId()
    {
        return FsWikiUtility::getMainPageId();
    }

    /**
     * Transform special HTML characters to entity
     * Example:
     * <hello>world</hello>
     * to
     * "&lt;hello&gt;world&lt;/hello&gt;"
     *
     * @param $text
     * @return string
     */
    public
    static function htmlEncode($text)
    {
        /**
         * See https://stackoverflow.com/questions/46483/htmlentities-vs-htmlspecialchars/3614344
         * {@link htmlentities }
         */
        //return htmlspecialchars($text, ENT_QUOTES);
        return htmlentities($text);
    }


    /**
     * Add a class
     * @param $classValue
     * @param array $attributes
     */
    public
    static function addClass2Attributes($classValue, array &$attributes)
    {
        self::addAttributeValue("class", $classValue, $attributes);
    }

    /**
     * Add a style property to the attributes
     * @param $property
     * @param $value
     * @param array $attributes
     * @deprecated use {@link TagAttributes::addStyleDeclaration()} instead
     */
    public
    static function addStyleProperty($property, $value, array &$attributes)
    {
        if (isset($attributes["style"])) {
            $attributes["style"] .= ";$property:$value";
        } else {
            $attributes["style"] = "$property:$value";
        }

    }

    /**
     * Add default border attributes
     * to see a border
     * Doc
     * https://combostrap.com/styling/color#border_color
     * @param TagAttributes $tagAttributes
     */
    private
    static function checkDefaultBorderColorAttributes(&$tagAttributes)
    {
        /**
         * border color was set without the width
         * setting the width
         */
        if (!(
            $tagAttributes->hasStyleDeclaration("border")
            ||
            $tagAttributes->hasStyleDeclaration("border-width")
        )
        ) {
            $tagAttributes->addStyleDeclaration("border-width", "1px");
        }
        /**
         * border color was set without the style
         * setting the style
         */
        if (!
        (
            $tagAttributes->hasStyleDeclaration("border")
            ||
            $tagAttributes->hasStyleDeclaration("border-style")
        )
        ) {
            $tagAttributes->addStyleDeclaration("border-style", "solid");

        }
        if (!$tagAttributes->hasStyleDeclaration("border-radius")) {
            $tagAttributes->addStyleDeclaration("border-radius", ".25rem");
        }

    }

    public
    static function getConfValue($confName, $defaultValue = null)
    {
        global $conf;
        if (isset($conf['plugin'][PluginUtility::PLUGIN_BASE_NAME][$confName])) {
            return $conf['plugin'][PluginUtility::PLUGIN_BASE_NAME][$confName];
        } else {
            return $defaultValue;
        }
    }

    /**
     * @param $match
     * @return null|string - return the tag name or null if not found
     */
    public
    static function getTag($match)
    {

        // Trim to start clean
        $match = trim($match);

        // Until the first >
        $pos = strpos($match, ">");
        if ($pos == false) {
            LogUtility::msg("The match does not contain any tag. Match: {$match}", LogUtility::LVL_MSG_ERROR);
            return null;
        }
        $match = substr($match, 0, $pos);

        // Suppress the <
        if ($match[0] == "<") {
            $match = substr($match, 1);
        } else {
            LogUtility::msg("This is not a text tag because it does not start with the character `>`");
        }

        // Suppress the tag name (ie until the first blank)
        $spacePosition = strpos($match, " ");
        if (!$spacePosition) {
            // No space, meaning this is only the tag name
            return $match;
        } else {
            return substr($match, 0, $spacePosition);
        }

    }


    /**
     * @param string $string add a command into HTML
     */
    public
    static function addAsHtmlComment($string)
    {
        print_r('<!-- ' . self::htmlEncode($string) . '-->');
    }

    public
    static function getResourceBaseUrl()
    {
        return DOKU_URL . 'lib/plugins/' . PluginUtility::PLUGIN_BASE_NAME . '/resources';
    }

    /**
     * @param $TAG - the name of the tag that should correspond to the name of the css file in the style directory
     * @return string - a inline style element to inject in the page or blank if no file exists
     */
    public
    static function getTagStyle($TAG)
    {
        $script = self::getCssRules($TAG);
        if (!empty($script)) {
            return "<style>" . $script . "</style>";
        } else {
            return "";
        }

    }


    public
    static function getComponentName($tag)
    {
        return strtolower(PluginUtility::PLUGIN_BASE_NAME) . "_" . $tag;
    }

    public
    static function addAttributeValue($attribute, $value, array &$attributes)
    {
        if (array_key_exists($attribute, $attributes) && $attributes[$attribute] !== "") {
            $attributes[$attribute] .= " {$value}";
        } else {
            $attributes[$attribute] = "{$value}";
        }
    }

    /**
     * Plugin Utility is available to all plugin,
     * this is a convenient way to the the snippet manager
     * @return SnippetManager
     */
    public
    static function getSnippetManager()
    {
        return SnippetManager::get();
    }

    public
    static function initStaticManager()
    {
        CacheManager::init();
        SnippetManager::init();
    }

    /**
     * Function used in a render
     * @param $data - the data from {@link PluginUtility::handleAndReturnUnmatchedData()}
     * @return string
     */
    public
    static function renderUnmatched($data)
    {
        /**
         * Attributes
         */
        if (isset($data[PluginUtility::ATTRIBUTES])) {
            $attributes = $data[PluginUtility::ATTRIBUTES];
        } else {
            $attributes = [];
        }
        $tagAttributes = TagAttributes::createFromCallStackArray($attributes);
        $display = $tagAttributes->getValue(TagAttributes::DISPLAY);
        if ($display != "none") {
            $payload = $data[self::PAYLOAD];
            $context = $data[self::CONTEXT];
            if (!in_array($context, Call::INLINE_DOKUWIKI_COMPONENTS)) {
                $payload = ltrim($payload);
            }
            return PluginUtility::htmlEncode($payload);
        } else {
            return "";
        }
    }

    /**
     * Function used in a handle function of a syntax plugin for
     * unmatched context
     * @param $tagName
     * @param $match
     * @param \Doku_Handler $handler
     * @return array
     */
    public
    static function handleAndReturnUnmatchedData($tagName, $match, \Doku_Handler $handler)
    {
        $tag = new Tag($tagName, array(), DOKU_LEXER_UNMATCHED, $handler);
        $sibling = $tag->getPreviousSibling();
        $context = null;
        if (!empty($sibling)) {
            $context = $sibling->getName();
        }
        return array(
            PluginUtility::STATE => DOKU_LEXER_UNMATCHED,
            PluginUtility::PAYLOAD => $match,
            PluginUtility::CONTEXT => $context
        );
    }

    public
    static function setConf($key, $value, $namespace = 'plugin')
    {
        global $conf;
        if ($namespace != null) {
            $conf[$namespace][PluginUtility::PLUGIN_BASE_NAME][$key] = $value;
        } else {
            $conf[$key] = $value;
        }

    }

    /**
     * Utility methodPreprocess a start tag to be able to extract the name
     * and the attributes easily
     *
     * It will delete:
     *   * the characters <> and the /> if present
     *   * and trim
     *
     * It will remain the tagname and its attributes
     * @param $match
     * @return false|string|null
     */
    private
    static function getPreprocessEnterTag($match)
    {
        // Until the first >
        $pos = strpos($match, ">");
        if ($pos == false) {
            LogUtility::msg("The match does not contain any tag. Match: {$match}", LogUtility::LVL_MSG_WARNING);
            return null;
        }
        $match = substr($match, 0, $pos);


        // Trim to start clean
        $match = trim($match);

        // Suppress the <
        if ($match[0] == "<") {
            $match = substr($match, 1);
        }

        // Suppress the / for a leaf tag
        if ($match[strlen($match) - 1] == "/") {
            $match = substr($match, 0, strlen($match) - 1);
        }
        return $match;
    }

    /**
     * Retrieve the tag name used in the text document
     * @param $match
     * @return false|string|null
     */
    public
    static function getSyntaxTagNameFromMatch($match)
    {
        $preprocessMatch = PluginUtility::getPreprocessEnterTag($match);

        // Tag name (ie until the first blank)
        $spacePosition = strpos($match, " ");
        if (!$spacePosition) {
            // No space, meaning this is only the tag name
            return $preprocessMatch;
        } else {
            return trim(substr(0, $spacePosition));
        }

    }

    /**
     * @param \Doku_Renderer_xhtml $renderer
     * @param $position
     * @param $name
     */
    public
    static function startSection($renderer, $position, $name)
    {


        if (empty($position)) {
            LogUtility::msg("The position for a start section should not be empty", LogUtility::LVL_MSG_ERROR, "support");
        }
        if (empty($name)) {
            LogUtility::msg("The name for a start section should not be empty", LogUtility::LVL_MSG_ERROR, "support");
        }

        /**
         * New Dokuwiki Version
         * for DokuWiki Greebo and more recent versions
         */
        if (defined('SEC_EDIT_PATTERN')) {
            $renderer->startSectionEdit($position, array('target' => self::EDIT_SECTION_TARGET, 'name' => $name));
        } else {
            /**
             * Old version
             */
            /** @noinspection PhpParamsInspection */
            $renderer->startSectionEdit($position, self::EDIT_SECTION_TARGET, $name);
        }
    }

    /**
     * Add an enter call to the stack
     * @param \Doku_Handler $handler
     * @param $tagName
     * @param array $callStackArray
     */
    public
    static function addEnterCall(
        \Doku_Handler &$handler,
        $tagName,
        $callStackArray = array()
    )
    {
        $pluginName = PluginUtility::getComponentName($tagName);
        $handler->addPluginCall(
            $pluginName,
            $callStackArray,
            DOKU_LEXER_ENTER,
            null,
            null
        );
    }

    /**
     * Add an end call dynamically
     * @param \Doku_Handler $handler
     * @param $tagName
     * @param array $callStackArray
     */
    public
    static function addEndCall(\Doku_Handler $handler, $tagName, $callStackArray = array())
    {
        $pluginName = PluginUtility::getComponentName($tagName);
        $handler->addPluginCall(
            $pluginName,
            $callStackArray,
            DOKU_LEXER_END,
            null,
            null
        );
    }

    /**
     * General Debug
     */
    public
    static function isDebug()
    {
        global $conf;
        return $conf["allowdebug"] === 1;

    }

    /**
     * @return bool true if loaded, false otherwise
     * Strap is loaded only if this is the same version
     * to avoid function, class, or members that does not exist
     */
    public
    static function loadStrapUtilityTemplateIfPresentAndSameVersion()
    {
        $templateUtilityFile = __DIR__ . '/../../../tpl/strap/class/TplUtility.php';
        if (file_exists($templateUtilityFile)) {
            /**
             * Check the version
             */
            $templateInfo = confToHash(__DIR__ . '/../../../tpl/strap/template.info.txt');
            $templateVersion = $templateInfo['version'];
            $comboVersion = self::$INFO_PLUGIN['version'];
            if ($templateVersion != $comboVersion) {
                if ($comboVersion > $templateVersion) {
                    LogUtility::msg("You should upgrade <a href=\"https://www.dokuwiki.org/template:strap\">strap</a> to the latest version to get a fully functional experience. The version of Combo is ($comboVersion) while the version of Strap is ($templateVersion).");
                } else {
                    LogUtility::msg("You should upgrade <a href=\"https://www.dokuwiki.org/plugin:combo\">combo</a>  to the latest version to get a fully functional experience. The version of Combo is ($comboVersion) while the version of Strap is ($templateVersion).");
                }
                return false;
            } else {
                /** @noinspection PhpIncludeInspection */
                require_once($templateUtilityFile);
                return true;
            }
        } else {
            $level = LogUtility::LVL_MSG_DEBUG;
            if (defined('DOKU_UNITTEST')) {
                // fail
                $level = LogUtility::LVL_MSG_ERROR;
            }
            if (Site::getTemplate() != "strap") {
                LogUtility::msg("The strap template is not installed", $level);
            } else {
                LogUtility::msg("The file ($templateUtilityFile) was not found", $level);
            }
            return false;
        }
    }


    /**
     *
     * See also dev.md file
     */
    public static function isDevOrTest()
    {
        if (self::isDev()) {
            return true;
        }
        return self::isTest();
    }

    public static function isDev()
    {
        global $_SERVER;
        if ($_SERVER["REMOTE_ADDR"] == "127.0.0.1") {
            return true;
        }
        return false;
    }

    public static function getInstructions($markiCode)
    {
        return p_get_instructions($markiCode);
    }

    public static function getInstructionsWithoutRoot($markiCode)
    {
        return RenderUtility::getInstructionsAndStripPEventually($markiCode);
    }

    /**
     * Transform a text into a valid HTML id
     * @param $string
     * @return string
     */
    public static function toHtmlId($string)
    {
        /**
         * sectionId calls cleanID
         * cleanID delete all things before a ':'
         * we do then the replace before to not
         * lost a minus '-' separator
         */
        $string = str_replace(array(':', '.'), '', $string);
        return sectionID($string, $check);
    }

    public static function isTest()
    {
        return defined('DOKU_UNITTEST');
    }


    public static function getCacheManager()
    {
        return CacheManager::get();
    }

    public static function getModeFromPluginName($name)
    {
        return "plugin_$name";
    }

    public static function isCi(): bool
    {
        // https://docs.travis-ci.com/user/environment-variables/#default-environment-variables
        return getenv("CI")==="true";
    }


}

PluginUtility::init();
