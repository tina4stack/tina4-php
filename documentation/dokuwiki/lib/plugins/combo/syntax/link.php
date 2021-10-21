<?php


require_once(__DIR__ . "/../ComboStrap/Analytics.php");
require_once(__DIR__ . "/../ComboStrap/PluginUtility.php");
require_once(__DIR__ . "/../ComboStrap/LinkUtility.php");
require_once(__DIR__ . "/../ComboStrap/XhtmlUtility.php");

use ComboStrap\CallStack;
use ComboStrap\LinkUtility;
use ComboStrap\ThirdPartyPlugins;
use ComboStrap\PluginUtility;
use ComboStrap\Tag;
use ComboStrap\TagAttributes;

if (!defined('DOKU_INC')) die();

/**
 *
 * A link pattern to take over the link of Dokuwiki
 * and transform it as a bootstrap link
 *
 * The handle of the move of link is to be found in the
 * admin action {@link action_plugin_combo_linkmove}
 *
 */
class syntax_plugin_combo_link extends DokuWiki_Syntax_Plugin
{
    const TAG = 'link';
    const COMPONENT = 'combo_link';

    /**
     * Disable the link component
     */
    const CONF_DISABLE_LINK = "disableLink";

    /**
     * The link Tag
     * a or p
     */
    const LINK_TAG = "linkTag";

    /**
     * Do the link component allows to be spawn on multilines
     */
    const CLICKABLE_ATTRIBUTE = "clickable";


    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     * @see https://www.dokuwiki.org/devel:syntax_plugins#syntax_types
     */
    function getType()
    {
        return 'substition';
    }

    /**
     * How Dokuwiki will add P element
     *
     *  * 'normal' - The plugin can be used inside paragraphs
     *  * 'block'  - Open paragraphs need to be closed before plugin output - block should not be inside paragraphs
     *  * 'stack'  - Special case. Plugin wraps other paragraphs. - Stacks can contain paragraphs
     *
     * @see DokuWiki_Syntax_Plugin::getPType()
     */
    function getPType()
    {
        return 'normal';
    }

    /**
     * @return array
     * Allow which kind of plugin inside
     *
     * No one of array('container', 'baseonly', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs')
     * because we manage self the content and we call self the parser
     */
    function getAllowedTypes()
    {
        return array('substition', 'formatting', 'disabled');
    }

    /**
     * @param string $mode
     * @return bool
     * Accepts inside
     */
    public function accepts($mode)
    {
        /**
         * To avoid that the description if it contains a link
         * will be taken by the links mode
         *
         * For instance, [[https://hallo|https://hallo]] will send https://hallo
         * to the external link mode
         */
        $linkModes = [
            "externallink",
            "locallink",
            "internallink",
            "interwikilink",
            "emaillink",
            "emphasis", // double slash can not be used inside to preserve the possibility to write an URL in the description
            //"emphasis_open", // italic use // and therefore take over a link as description which is not handy when copying a tweet
            //"emphasis_close",
            //"acrnonym"
        ];
        if (in_array($mode, $linkModes)) {
            return false;
        } else {
            return true;
        }
    }


    /**
     * @see Doku_Parser_Mode::getSort()
     * The mode with the lowest sort number will win out
     */
    function getSort()
    {
        /**
         * It should be less than the number
         * at {@link \dokuwiki\Parsing\ParserMode\Internallink::getSort}
         * and the like
         *
         * For whatever reason, the number below should be less than 100,
         * otherwise on windows with DokuWiki Stick, the link syntax may be not taken
         * into account
         */
        return 99;
    }


    function connectTo($mode)
    {

        if (!$this->getConf(self::CONF_DISABLE_LINK, false)
            &&
            $mode !== PluginUtility::getModeFromPluginName(ThirdPartyPlugins::IMAGE_MAPPING_NAME)
        ) {

            $pattern = LinkUtility::ENTRY_PATTERN_SINGLE_LINE;
            $this->Lexer->addEntryPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));

        }

    }

    public function postConnect()
    {
        if (!$this->getConf(self::CONF_DISABLE_LINK, false)) {
            $this->Lexer->addExitPattern(LinkUtility::EXIT_PATTERN, PluginUtility::getModeFromTag($this->getPluginComponent()));
        }
    }


    /**
     * The handler for an internal link
     * based on `internallink` in {@link Doku_Handler}
     * The handler call the good renderer in {@link Doku_Renderer_xhtml} with
     * the parameters (ie for instance internallink)
     * @param string $match
     * @param int $state
     * @param int $pos
     * @param Doku_Handler $handler
     * @return array|bool
     */
    function handle($match, $state, $pos, Doku_Handler $handler)
    {


        switch ($state) {
            case DOKU_LEXER_ENTER:
                $tagAttributes = TagAttributes::createFromCallStackArray(LinkUtility::parse($match));
                $callStack = CallStack::createFromHandler($handler);


                $parent = $callStack->moveToParent();
                $parentName = "";
                if ($parent != false) {

                    /**
                     * Button Link
                     * Getting the attributes
                     */
                    $parentName = $parent->getTagName();
                    if ($parentName == syntax_plugin_combo_button::TAG) {
                        $tagAttributes->mergeWithCallStackArray($parent->getAttributes());
                    }

                    /**
                     * Searching Clickable parent
                     */
                    $maxLevel = 3;
                    $level = 0;
                    while (
                        $parent != false &&
                        !$parent->hasAttribute(self::CLICKABLE_ATTRIBUTE) &&
                        $level < $maxLevel
                    ) {
                        $parent = $callStack->moveToParent();
                        $level++;
                    }
                    if ($parent != false) {
                        if ($parent->getAttribute(self::CLICKABLE_ATTRIBUTE)) {
                            $tagAttributes->addClassName("stretched-link");
                            $parent->addClassName("position-relative");
                            $parent->removeAttribute(self::CLICKABLE_ATTRIBUTE);
                        }
                    }

                }
                $callStackAttributes = $tagAttributes->toCallStackArray();
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $callStackAttributes,
                    PluginUtility::CONTEXT => $parentName
                );
            case DOKU_LEXER_UNMATCHED:

                $data = PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);
                /**
                 * Delete the separator `|` between the ref and the description if any
                 */
                $tag = new Tag(self::TAG, array(), $state, $handler);
                $parent = $tag->getParent();
                if ($parent->getName() == self::TAG) {
                    if (strpos($match, '|') === 0) {
                        $data[PluginUtility::PAYLOAD] = substr($match, 1);
                    }
                }
                return $data;

            case DOKU_LEXER_EXIT:
                $callStack = CallStack::createFromHandler($handler);
                $openingTag = $callStack->moveToPreviousCorrespondingOpeningCall();
                $openingAttributes = $openingTag->getAttributes();
                $openingPosition = $openingTag->getKey();

                $callStack->moveToEnd();
                $previousCall = $callStack->previous();
                $previousCallPosition = $previousCall->getKey();
                $previousCallContent = $previousCall->getCapturedContent();

                if (
                    $openingPosition == $previousCallPosition // ie [[id]]
                    ||
                    ($openingPosition == $previousCallPosition - 1 && $previousCallContent == "|") // ie [[id|]]
                ) {
                    // There is no name
                    $link = new LinkUtility($openingAttributes[LinkUtility::ATTRIBUTE_REF]);
                    $linkName = $link->getName();
                } else {
                    $linkName = "";
                }
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $openingAttributes,
                    PluginUtility::PAYLOAD => $linkName,
                    PluginUtility::CONTEXT => $openingTag->getContext()
                );
        }
        return true;


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
        // The data
        switch ($format) {
            case 'xhtml':

                /** @var Doku_Renderer_xhtml $renderer */
                /**
                 * Cache problem may occurs while releasing
                 */
                if (isset($data[PluginUtility::ATTRIBUTES])) {
                    $callStackAttributes = $data[PluginUtility::ATTRIBUTES];
                } else {
                    $callStackAttributes = $data;
                }

                PluginUtility::getSnippetManager()->attachCssSnippetForBar(self::TAG);

                $state = $data[PluginUtility::STATE];
                switch ($state) {
                    case DOKU_LEXER_ENTER:
                        $tagAttributes = TagAttributes::createFromCallStackArray($callStackAttributes, self::TAG);
                        $ref = $tagAttributes->getValueAndRemove(LinkUtility::ATTRIBUTE_REF);
                        $link = new LinkUtility($ref, $tagAttributes);

                        /**
                         * Extra styling
                         */
                        $parentTag = $data[PluginUtility::CONTEXT];
                        switch ($parentTag) {
                            /**
                             * Button link
                             */
                            case syntax_plugin_combo_button::TAG:
                                $tagAttributes->addHtmlAttributeValue("role", "button");
                                syntax_plugin_combo_button::processButtonAttributesToHtmlAttributes($tagAttributes);
                                $htmlLink = $link->renderOpenTag($renderer);
                                break;
                            case syntax_plugin_combo_badge::TAG:
                            case syntax_plugin_combo_cite::TAG:
                            case syntax_plugin_combo_contentlistitem::DOKU_TAG:
                            case syntax_plugin_combo_preformatted::TAG:
                                $htmlLink = $link->renderOpenTag($renderer);
                                break;
                            case syntax_plugin_combo_dropdown::TAG:
                                $tagAttributes->addClassName("dropdown-item");
                                $htmlLink = $link->renderOpenTag($renderer);
                                break;
                            case syntax_plugin_combo_navbarcollapse::COMPONENT:
                                $tagAttributes->addClassName("navbar-link");
                                $htmlLink = '<div class="navbar-nav">' . $link->renderOpenTag($renderer);
                                break;
                            case syntax_plugin_combo_navbargroup::COMPONENT:
                                $tagAttributes->addClassName("nav-link");
                                $htmlLink = '<li class="nav-item">' . $link->renderOpenTag($renderer);
                                break;
                            default:
                                $htmlLink = $link->renderOpenTag($renderer);

                        }


                        /**
                         * Add it to the rendering
                         */
                        $renderer->doc .= $htmlLink;
                        break;
                    case DOKU_LEXER_UNMATCHED:
                        $renderer->doc .= PluginUtility::renderUnmatched($data);
                        break;
                    case DOKU_LEXER_EXIT:

                        // if there is no link name defined, we get the name as ref in the payload
                        // otherwise null string
                        $renderer->doc .= $data[PluginUtility::PAYLOAD];

                        // Close the link
                        $renderer->doc .= "</a>";

                        // Close the html wrapper element
                        $context = $data[PluginUtility::CONTEXT];
                        switch ($context) {
                            case syntax_plugin_combo_navbarcollapse::COMPONENT:
                                $renderer->doc .= '</div>';
                                break;
                            case syntax_plugin_combo_navbargroup::COMPONENT:
                                $renderer->doc .= '</li>';
                                break;
                        }


                }


                return true;

            case 'metadata':

                $state = $data[PluginUtility::STATE];
                if ($state == DOKU_LEXER_ENTER) {
                    /**
                     * Keep track of the backlinks ie meta['relation']['references']
                     * @var Doku_Renderer_metadata $renderer
                     */
                    if (isset($data[PluginUtility::ATTRIBUTES])) {
                        $tagAttributes = $data[PluginUtility::ATTRIBUTES];
                    } else {
                        $tagAttributes = $data;
                    }
                    $ref = $tagAttributes[LinkUtility::ATTRIBUTE_REF];

                    $link = new LinkUtility($ref);
                    $name = $tagAttributes[LinkUtility::ATTRIBUTE_NAME];
                    if ($name != null) {
                        $link->setName($name);
                    }
                    $link->handleMetadata($renderer);

                    return true;
                }
                break;

            case renderer_plugin_combo_analytics::RENDERER_FORMAT:

                $state = $data[PluginUtility::STATE];
                if ($state == DOKU_LEXER_ENTER) {
                    /**
                     *
                     * @var renderer_plugin_combo_analytics $renderer
                     */
                    $tagAttributes = $data[PluginUtility::ATTRIBUTES];
                    $ref = $tagAttributes[LinkUtility::ATTRIBUTE_REF];
                    $link = new LinkUtility($ref);
                    $link->processLinkStats($renderer->stats);
                    break;
                }

        }
        // unsupported $mode
        return false;
    }


}

