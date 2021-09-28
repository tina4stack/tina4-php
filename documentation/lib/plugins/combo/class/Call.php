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


/**
 * Class Call
 * @package ComboStrap
 *
 * A wrapper around what's called a call
 * which is an array of information such
 * the mode, the data
 *
 * The {@link CallStack} is the only syntax representation that
 * is available in DokuWiki
 */
class Call
{

    const INLINE_DISPLAY = "inline";
    const BlOCK_DISPLAY = "block";
    /**
     * List of inline components
     * Used to manage white space before an unmatched string.
     * The syntax tree of Dokuwiki (ie {@link \Doku_Handler::$calls})
     * has only data and no class, for now, we create this
     * lists manually because this is a hassle to retrieve this information from {@link \DokuWiki_Syntax_Plugin::getType()}
     */
    const INLINE_DOKUWIKI_COMPONENTS = array(
        /**
         * Formatting https://www.dokuwiki.org/devel:syntax_plugins#syntax_types
         * Comes from the {@link \dokuwiki\Parsing\ParserMode\Formatting} class
         */
        "cdata",
        "unformatted", // ie %% or nowiki
        "doublequoteclosing", // https://www.dokuwiki.org/config:typography / https://www.dokuwiki.org/wiki:syntax#text_to_html_conversions
        "doublequoteopening",
        "singlequoteopening",
        "singlequoteclosing",
        "multiplyentity",
        "apostrophe",
        "strong",
        "emphasis",
        "emphasis_open",
        "emphasis_close",
        "underline",
        "monospace",
        "subscript",
        "superscript",
        "deleted",
        "footnote",
        /**
         * Others
         */
        "acronym",
        "strong_close",
        "strong_open",
        "monospace_open",
        "monospace_close",
        "doublequoteopening", // ie the character " in "The"
        "entity", // for instance `...` are transformed in character
        "linebreak",
        "externallink",
        "internallink",
        MediaLink::INTERNAL_MEDIA_CALL_NAME,
        MediaLink::EXTERNAL_MEDIA_CALL_NAME,
        /**
         * The inline of combo
         * TODO: Should be deleted when {@link PluginUtility::renderUnmatched()} is not using the array anymore
         * but is using {@link Call::getDisplay()} instead or any other rewrite
         */
        \syntax_plugin_combo_link::TAG,
        \syntax_plugin_combo_icon::TAG,
        \syntax_plugin_combo_inote::TAG,
        \syntax_plugin_combo_button::TAG,
        \syntax_plugin_combo_tooltip::TAG,
    );


    const BLOCK_DOKUWIKI_COMPONENTS = array(
        "listu_open", // ul
        "listu_close",
        "listitem_open", //li
        "listitem_close",
        "listcontent_open", // after li ???
        "listcontent_close",
        "table_open",
        "table_close",
    );

    private $call;

    /**
     * The key identifier in the {@link CallStack}
     * @var mixed|string
     */
    private $key;

    /**
     * Call constructor.
     * @param $call - the instruction array (ie called a call)
     */
    public function __construct(&$call, $key = "")
    {
        $this->call = &$call;
        $this->key = $key;
    }

    /**
     * Insert a tag above
     * @param $tagName
     * @param $state
     * @param $attribute
     * @param $context
     * @param string $content
     * @return Call - a call
     */
    public static function createComboCall($tagName, $state, $attribute = array(), $context = null, $content = '', $payload=null)
    {
        $data = array(
            PluginUtility::ATTRIBUTES => $attribute,
            PluginUtility::CONTEXT => $context,
            PluginUtility::STATE => $state
        );
        if($payload!=null){
            $data[PluginUtility::PAYLOAD] = $payload;
        }
        $positionInText = null;

        $call = [
            "plugin",
            array(
                PluginUtility::getComponentName($tagName),
                $data,
                $state,
                $content
            ),
            $positionInText
        ];
        return new Call($call);
    }

    /**
     * Insert a dokuwiki call
     * @param $callName
     * @param $array
     * @param $positionInText
     * @return Call
     */
    public static function createNativeCall($callName, $array = [], $positionInText = null)
    {
        $call = [
            $callName,
            $array,
            $positionInText
        ];
        return new Call($call);
    }


    /**
     *
     * Return the tag name from a call array
     *
     * This is not the logical tag.
     * This is much more what's called:
     *   * the component name for a plugin
     *   * or the handler name for dokuwiki
     *
     * For a plugin, this is equivalent
     * to the {@link SyntaxPlugin::getPluginComponent()}
     *
     * This is not the fully qualified component name:
     *   * with the plugin as prefix such as in {@link Call::getComponentName()}
     *   * or with the `open` and `close` prefix such as `p_close` ...
     *
     * @return mixed|string
     */
    public function getTagName()
    {
        $mode = $this->call[0];
        if ($mode != "plugin") {

            /**
             * This is a standard dokuwiki node
             */
            $dokuWikiNodeName = $this->call[0];

            /**
             * The dokwuiki node name has also the open and close notion
             * We delete this is not in the doc and therefore not logical
             */
            $tagName = str_replace("_close", "", $dokuWikiNodeName);
            $tagName = str_replace("_open", "", $tagName);

        } else {

            /**
             * This is a plugin node
             */
            $pluginDokuData = $this->call[1];
            $component = $pluginDokuData[0];
            if (!is_array($component)) {
                /**
                 * Tag name from class
                 */
                $componentNames = explode("_", $component);
                /**
                 * To take care of
                 * PHP Warning:  sizeof(): Parameter must be an array or an object that implements Countable
                 * in lib/plugins/combo/class/Tag.php on line 314
                 */
                if (is_array($componentNames)) {
                    $tagName = $componentNames[sizeof($componentNames) - 1];
                } else {
                    $tagName = $component;
                }
            } else {
                // To resolve: explode() expects parameter 2 to be string, array given
                LogUtility::msg("The call (" . print_r($this->call, true) . ") has an array and not a string as component (" . print_r($component, true) . "). Page: " . PluginUtility::getPageId(), LogUtility::LVL_MSG_ERROR);
                $tagName = "";
            }


        }
        return $tagName;

    }


    /**
     * The parser state
     * @return mixed
     * May be null (example eol, internallink, ...)
     */
    public function getState()
    {
        $mode = $this->call[0];
        if ($mode != "plugin") {

            /**
             * There is no state because this is a standard
             * dokuwiki syntax found in {@link \Doku_Renderer_xhtml}
             * check if this is not a `...._close` or `...._open`
             * to derive the state
             */
            $mode = $this->call[0];
            $lastPositionSepName = strrpos($mode, "_");
            $closeOrOpen = substr($mode, $lastPositionSepName + 1);
            switch ($closeOrOpen) {
                case "open":
                    return DOKU_LEXER_ENTER;
                case "close":
                    return DOKU_LEXER_EXIT;
                default:
                    return null;
            }

        } else {
            // Plugin
            $returnedArray = $this->call[1];
            if (array_key_exists(2, $returnedArray)) {
                return $returnedArray[2];
            } else {
                return null;
            }
        }
    }

    /**
     * @return mixed the data returned from the {@link DokuWiki_Syntax_Plugin::handle} (ie attributes, payload, ...)
     */
    public function &getPluginData()
    {
        return $this->call[1][1];
    }

    /**
     * @return mixed the matched content from the {@link DokuWiki_Syntax_Plugin::handle}
     */
    public function getCapturedContent()
    {
        $caller = $this->call[0];
        switch ($caller) {
            case "plugin":
                return $this->call[1][3];
            case "internallink":
                return '[[' . $this->call[1][0] . '|' . $this->call[1][1] . ']]';
            case "eol":
                return DOKU_LF;
            case "header":
            case "cdata":
                return $this->call[1][0];
            default:
                if (isset($this->call[1][0]) && is_string($this->call[1][0])) {
                    return $this->call[1][0];
                } else {
                    return "";
                }
        }
    }


    public function getAttributes()
    {

        $tagName = $this->getTagName();
        switch ($tagName) {
            case MediaLink::INTERNAL_MEDIA_CALL_NAME:
                return $this->call[1];
            default:
                $data = $this->getPluginData();
                if (isset($data[PluginUtility::ATTRIBUTES])) {
                    return $data[PluginUtility::ATTRIBUTES];
                } else {
                    return null;
                }
        }
    }

    public function removeAttributes()
    {

        $data = &$this->getPluginData();
        if (isset($data[PluginUtility::ATTRIBUTES])) {
            unset($data[PluginUtility::ATTRIBUTES]);
        }

    }

    public function updateToPluginComponent($component, $state, $attributes = array())
    {
        if ($this->call[0] == "plugin") {
            $match = $this->call[1][3];
        } else {
            $this->call[0] = "plugin";
            $match = "";
        }
        $this->call[1] = array(
            0 => $component,
            1 => array(
                PluginUtility::ATTRIBUTES => $attributes,
                PluginUtility::STATE => $state,
            ),
            2 => $state,
            3 => $match
        );

    }

    public function getDisplay()
    {
        if ($this->getState() == DOKU_LEXER_UNMATCHED) {
            /**
             * Unmatched are content (ie text node in XML/HTML) and have
             * no display
             */
            return Call::INLINE_DISPLAY;
        } else {
            $mode = $this->call[0];
            if ($mode == "plugin") {
                global $DOKU_PLUGINS;
                $component = $this->getComponentName();
                /**
                 * @var SyntaxPlugin $syntaxPlugin
                 */
                $syntaxPlugin = $DOKU_PLUGINS['syntax'][$component];
                $pType = $syntaxPlugin->getPType();
                switch ($pType) {
                    case "normal":
                        return Call::INLINE_DISPLAY;
                    case "block":
                    case "stack":
                        return Call::BlOCK_DISPLAY;
                    default:
                        LogUtility::msg("The ptype (" . $pType . ") is unknown.");
                        return null;
                }
            } else {
                if ($mode == "eol") {
                    /**
                     * Control character
                     * We return it as it's used in the
                     * {@link \syntax_plugin_combo_para::fromEolToParagraphUntilEndOfStack()}
                     * to create the paragraph
                     * This is not a block, nor an inline
                     */
                    return $mode;
                }

                if (in_array($mode, self::INLINE_DOKUWIKI_COMPONENTS)) {
                    return Call::INLINE_DISPLAY;
                }

                if (in_array($mode, self::BLOCK_DOKUWIKI_COMPONENTS)) {
                    return Call::BlOCK_DISPLAY;
                }

                LogUtility::msg("The display of the call with the mode " . $mode . " is unknown");
                return null;


            }
        }

    }

    /**
     * Same as {@link Call::getTagName()}
     * but fully qualified
     * @return string
     */
    public function getComponentName()
    {
        $mode = $this->call[0];
        if ($mode == "plugin") {
            $pluginDokuData = $this->call[1];
            return $pluginDokuData[0];
        } else {
            return $mode;
        }
    }

    public function updateEolToSpace()
    {
        $mode = $this->call[0];
        if ($mode != "eol") {
            LogUtility::msg("You can't update a " . $mode . " to a space. It should be a eol", LogUtility::LVL_MSG_WARNING, "support");
        } else {
            $this->call[0] = "cdata";
            $this->call[1] = array(
                0 => " "
            );
        }

    }

    public function addAttribute($key, $value)
    {
        $mode = $this->call[0];
        if ($mode == "plugin") {
            $this->call[1][1][PluginUtility::ATTRIBUTES][$key] = $value;
        } else {
            LogUtility::msg("You can't add an attribute to the non plugin call mode (" . $mode . ")", LogUtility::LVL_MSG_WARNING, "support");
        }
    }

    public function getContext()
    {
        $mode = $this->call[0];
        if ($mode == "plugin") {
            return $this->call[1][1][PluginUtility::CONTEXT];
        } else {
            LogUtility::msg("You can't ask for a context from a non plugin call mode (" . $mode . ")", LogUtility::LVL_MSG_WARNING, "support");
            return null;
        }
    }

    /**
     *
     * @return array
     */
    public function toCallArray()
    {
        return $this->call;
    }

    public function __toString()
    {
        $name = $this->key;
        if (!empty($name)) {
            $name .= " - ";
        }
        $name .= $this->getTagName();
        return $name;
    }

    public function getType()
    {
        if ($this->getState() == DOKU_LEXER_UNMATCHED) {
            LogUtility::msg("The unmatched tag ($this) does not have any attributes. Get its parent if you want the type", LogUtility::LVL_MSG_ERROR);
            return null;
        } else {
            /**
             * don't use {@link Call::getAttribute()} to get the type
             * as this function stack also depends on
             * this function {@link Call::getType()}
             * to return the value
             * Ie: if this is a boolean attribute without specified type
             * if the boolean value is in the type, we return it
             */
            return $this->call[1][1][PluginUtility::ATTRIBUTES][TagAttributes::TYPE_KEY];
        }
    }

    /**
     * @param $key
     * @param null $default
     * @return string|null
     */
    public function getAttribute($key, $default = null)
    {
        $attributes = $this->getAttributes();
        if (isset($attributes[$key])) {
            return $attributes[$key];
        } else {
            // boolean attribute
            if ($this->getType() == $key) {
                return true;
            } else {
                return $default;
            }
        }
    }

    public function getPayload()
    {
        $mode = $this->call[0];
        if ($mode == "plugin") {
            return $this->call[1][1][PluginUtility::PAYLOAD];
        } else {
            LogUtility::msg("You can't ask for a payload from a non plugin call mode (" . $mode . ").", LogUtility::LVL_MSG_WARNING, "support");
            return null;
        }
    }

    public function setContext($value)
    {
        $this->call[1][1][PluginUtility::CONTEXT] = $value;
        return $this;
    }

    public function hasAttribute($attributeName)
    {
        $attributes = $this->getAttributes();
        if (isset($attributes[$attributeName])) {
            return true;
        } else {
            if ($this->getType() == $attributeName) {
                return true;
            } else {
                return false;
            }
        }
    }

    public function isPluginCall()
    {
        return $this->call[0] === "plugin";
    }

    /**
     * @return mixed|string the position (ie key) in the array
     */
    public function getKey()
    {
        return $this->key;
    }

    public function &getCall()
    {
        return $this->call;
    }

    public function setState($state)
    {
        if ($this->call[0] == "plugin") {
            // for dokuwiki
            $this->call[1][2] = $state;
            // for the combo plugin if any
            if (isset($this->call[1][1][PluginUtility::STATE])) {
                $this->call[1][1][PluginUtility::STATE] = $state;
            }
        } else {
            LogUtility::msg("This modification of state is not yet supported for a native call");
        }
    }


    /**
     * Return the position of the first matched character in the text file
     * @return mixed
     */
    public function getFirstMatchedCharacterPosition()
    {

        return $this->call[2];

    }

    /**
     * Return the position of the last matched character in the text file
     *
     * This is the {@link Call::getFirstMatchedCharacterPosition()}
     * plus the length of the {@link Call::getCapturedContent()}
     * matched content
     * @return int|mixed
     */
    public function getLastMatchedCharacterPosition()
    {
        return $this->getFirstMatchedCharacterPosition() + strlen($this->getCapturedContent());
    }

    /**
     * @param $value string the class string to add
     * @return Call
     */
    public function addClassName($value)
    {
        $class = $this->getAttribute("class");
        if ($class != null) {
            $value = "$class $value";
        }
        $this->addAttribute("class", $value);
        return $this;

    }

    /**
     * @param $key
     * @return mixed|null - the delete value of null if not found
     */
    public function removeAttribute($key)
    {

        $data = &$this->getPluginData();
        if (isset($data[PluginUtility::ATTRIBUTES][$key])) {
            $value = $data[PluginUtility::ATTRIBUTES][$key];
            unset($data[PluginUtility::ATTRIBUTES][$key]);
            return $value;
        } else {
            // boolean attribute as first attribute
            if ($this->getType() == $key) {
                unset($data[PluginUtility::ATTRIBUTES][TagAttributes::TYPE_KEY]);
                return true;
            }
            return null;
        }

    }

    public function setPayload($text)
    {
        if ($this->isPluginCall()) {
            $this->call[1][1][PluginUtility::PAYLOAD] = $text;
        } else {
            LogUtility::msg("Setting the payload for a non-native call ($this) is not yet implemented");
        }
    }

    /**
     * @return bool true if the call is a text call (same as dom text node)
     */
    public function isTextCall()
    {
        return (
            $this->getState() == DOKU_LEXER_UNMATCHED ||
            $this->getTagName() == "cdata" ||
            $this->getTagName() == "acronym"
        );
    }

    public function setType($type)
    {
        if ($this->isPluginCall()) {
            $this->call[1][1][PluginUtility::ATTRIBUTES][TagAttributes::TYPE_KEY] = $type;
        } else {
            LogUtility::msg("This is not a plugin call ($this), you can't set the type");
        }
    }

    public function addCssStyle($key, $value)
    {
        $style = $this->getAttribute("style");
        $cssValue = "$key:$value";
        if ($style != null) {
            $cssValue = "$style; $cssValue";
        }
        $this->addAttribute("style", $cssValue);
    }

    public function setSyntaxComponentFromTag($tag)
    {

        if ($this->isPluginCall()) {
            $this->call[1][0] = PluginUtility::getComponentName($tag);
        } else {
            LogUtility::msg("The call ($this) is a native call and we don't support yet the modification of the component to ($tag)");
        }
    }

    /**
     * @param Page $page
     * @return Call
     */
    public function render(Page $page)
    {
        $state = $this->getState();
        if ( $state == DOKU_LEXER_UNMATCHED) {
            if ($this->isPluginCall()) {
                $payload = $this->getPayload();
                if (!empty($payload)) {
                    $this->setPayload(TemplateUtility::renderFromStringForPage($payload, $page));
                }
            }
        } else {
            $tagName = $this->getTagName();
            switch ($tagName) {
                case "eol":
                    break;
                case \syntax_plugin_combo_pipeline::TAG:
                    $pageTemplate = PluginUtility::getTagContent($this->getCapturedContent());
                    $script = TemplateUtility::renderFromStringForPage($pageTemplate, $page);
                    $string = PipelineUtility::execute($script);
                    $this->setPayload($string);
                    break;
                case \syntax_plugin_combo_link::TAG:
                    switch ($this->getState()) {
                        case DOKU_LEXER_ENTER:
                            $ref = $this->getAttribute("ref");
                            $this->addAttribute("ref", TemplateUtility::renderFromStringForPage($ref, $page));
                            break;
                    }
                    break;
            }
        }
        return $this;

    }


}
