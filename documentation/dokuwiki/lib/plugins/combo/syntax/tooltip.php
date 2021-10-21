<?php


use ComboStrap\Bootstrap;
use ComboStrap\Call;
use ComboStrap\CallStack;
use ComboStrap\LogUtility;
use ComboStrap\PluginUtility;
use ComboStrap\TagAttributes;

if (!defined('DOKU_INC')) die();

/**
 * Class syntax_plugin_combo_tooltip
 * Implementation of a tooltip
 *
 * A tooltip is implemented as a super title attribute
 * on a HTML element such as a link or a button
 *
 * The implementation pass the information that there is
 * a tooltip on the container which makes the output of {@link TagAttributes::toHtmlEnterTag()}
 * to print all attributes until the title and not closing.
 *
 * Bootstrap generate the <a href="https://getbootstrap.com/docs/5.0/components/tooltips/#markup">markup tooltip</a>
 * on the fly. It's possible to generate a bootstrap markup like and use popper directly
 * but this is far more difficult
 *
 *
 * https://material.io/components/tooltips
 * [[https://getbootstrap.com/docs/4.0/components/tooltips/|Tooltip Boostrap version 4]]
 * [[https://getbootstrap.com/docs/5.0/components/tooltips/|Tooltip Boostrap version 5]]
 */
class syntax_plugin_combo_tooltip extends DokuWiki_Syntax_Plugin
{

    const TAG = "tooltip";
    const TEXT_ATTRIBUTE = "text";
    const POSITION_ATTRIBUTE = "position";

    /**
     * An attribute that hold the
     * information that a tooltip was found
     */
    const TOOLTIP_FOUND = "tooltipFound";

    /**
     * Class added to the parent
     */
    const CANONICAL = "tooltip";

    /**
     * @var string
     */
    private $docCapture;


    /**
     * tooltip is used also in page protection
     */
    public static function addToolTipSnippetIfNeeded()
    {
        PluginUtility::getSnippetManager()->attachJavascriptSnippetForBar(self::TAG);
        PluginUtility::getSnippetManager()->attachCssSnippetForBar(self::TAG);
    }


    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     * @see https://www.dokuwiki.org/devel:syntax_plugins#syntax_types
     * @see DokuWiki_Syntax_Plugin::getType()
     */
    function getType()
    {
        /**
         * You could add a tooltip to a {@link syntax_plugin_combo_itext}
         */
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
     * @see https://www.dokuwiki.org/devel:syntax_plugins#ptype
     */
    function getPType()
    {
        return 'normal';
    }

    /**
     * @return array
     * Allow which kind of plugin inside
     *
     * No one of array('baseonly','container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs')
     * because we manage self the content and we call self the parser
     *
     * Return an array of one or more of the mode types {@link $PARSER_MODES} in Parser.php
     */
    function getAllowedTypes()
    {
        return array('baseonly', 'container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs');
    }

    function getSort()
    {
        return 201;
    }


    function connectTo($mode)
    {

        $pattern = PluginUtility::getContainerTagPattern(self::TAG);
        $this->Lexer->addEntryPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));

    }

    function postConnect()
    {

        $this->Lexer->addExitPattern('</' . self::TAG . '>', PluginUtility::getModeFromTag($this->getPluginComponent()));

    }

    /**
     *
     * The handle function goal is to parse the matched syntax through the pattern function
     * and to return the result for use in the renderer
     * This result is always cached until the page is modified.
     * @param string $match
     * @param int $state
     * @param int $pos - byte position in the original source file
     * @param Doku_Handler $handler
     * @return array|bool
     * @see DokuWiki_Syntax_Plugin::handle()
     *
     */
    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_ENTER :
                $tagAttributes = TagAttributes::createFromTagMatch($match);

                /**
                 * Old Syntax
                 */
                if ($tagAttributes->hasComponentAttribute(self::TEXT_ATTRIBUTE)) {
                    return array(
                        PluginUtility::STATE => $state,
                        PluginUtility::ATTRIBUTES => $tagAttributes->toCallStackArray()
                    );
                }


                /**
                 * New Syntax, the tooltip attribute
                 * are applied to the parent and is seen as an advanced attribute
                 */

                /**
                 * Advertise that we got a tooltip
                 * to start the {@link action_plugin_combo_tooltippostprocessing postprocessing}
                 * or not
                 */
                $handler->setStatus(self::TOOLTIP_FOUND, true);

                /**
                 * Callstack manipulation
                 */
                $callStack = CallStack::createFromHandler($handler);

                /**
                 * Processing
                 * We should have one parent
                 * and no Sibling
                 */
                $parent = false;
                $sibling = false;
                while ($actualCall = $callStack->previous()) {
                    if ($actualCall->getState() == DOKU_LEXER_ENTER) {
                        $parent = $actualCall;
                        $context = $parent->getTagName();

                        /**
                         * If this is an svg icon, the title attribute is not existing on a svg
                         * the icon should be wrapped up in a span (ie {@link syntax_plugin_combo_itext})
                         */
                        if ($parent->getTagName() == syntax_plugin_combo_icon::TAG) {
                            $parent->setContext(self::TAG);
                        } else {

                            /**
                             * Do not close the tag
                             */
                            $parent->addAttribute(TagAttributes::OPEN_TAG, true);
                            /**
                             * Do not output the title
                             */
                            $parent->addAttribute(TagAttributes::TITLE_KEY, TagAttributes::UN_SET);

                        }

                        return array(
                            PluginUtility::STATE => $state,
                            PluginUtility::ATTRIBUTES => $tagAttributes->toCallStackArray(),
                            PluginUtility::CONTEXT => $context

                        );
                    } else {
                        if ($actualCall->getTagName() == "eol") {
                            $callStack->deleteActualCallAndPrevious();
                            $callStack->next();
                        } else {
                            // sibling ?
                            // In a link, we would get the separator
                            if ($actualCall->getState() != DOKU_LEXER_UNMATCHED) {
                                $sibling = $actualCall;
                                break;
                            }
                        }
                    }
                }


                /**
                 * Error
                 */
                $errorMessage = "Error: ";
                if ($parent == false) {
                    $errorMessage .= "A tooltip was found without parent and this is mandatory.";
                } else {
                    if ($sibling != false) {
                        $errorMessage .= "A tooltip should be just below its parent. We found a tooltip next to the other sibling component ($sibling) and this will not work";
                    }
                }
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ERROR_MESSAGE => $errorMessage
                );


            case DOKU_LEXER_UNMATCHED :
                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);

            case DOKU_LEXER_EXIT :

                $callStack = CallStack::createFromHandler($handler);
                $openingTag = $callStack->moveToPreviousCorrespondingOpeningCall();

                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $openingTag->getAttributes()
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
        if ($format == 'xhtml') {

            /** @var Doku_Renderer_xhtml $renderer */
            $state = $data[PluginUtility::STATE];
            switch ($state) {

                case DOKU_LEXER_ENTER :
                    if (isset($data[PluginUtility::ERROR_MESSAGE])) {
                        LogUtility::msg($data[PluginUtility::ERROR_MESSAGE], LogUtility::LVL_MSG_ERROR, self::CANONICAL);
                        return false;
                    }

                    $tagAttributes = TagAttributes::createFromCallStackArray($data[PluginUtility::ATTRIBUTES]);

                    /**
                     * Snippet
                     */
                    self::addToolTipSnippetIfNeeded();

                    /**
                     * Tooltip
                     */
                    $dataAttributeNamespace = Bootstrap::getDataNamespace();
                    $tagAttributes->addHtmlAttributeValue("data{$dataAttributeNamespace}-toggle", "tooltip");

                    /**
                     * Position
                     */
                    $position = $tagAttributes->getValueAndRemove(self::POSITION_ATTRIBUTE, "top");
                    $tagAttributes->addHtmlAttributeValue("data{$dataAttributeNamespace}-placement", "${position}");


                    /**
                     * Old tooltip syntax
                     */
                    if ($tagAttributes->hasComponentAttribute(self::TEXT_ATTRIBUTE)) {
                        $tagAttributes->addHtmlAttributeValue("title", $tagAttributes->getValueAndRemove(self::TEXT_ATTRIBUTE));
                        $tagAttributes->addClassName("d-inline-block");

                        // Arbitrary HTML elements (such as <span>s) can be made focusable by adding the tabindex="0" attribute
                        $tagAttributes->addHtmlAttributeValue("tabindex", "0");

                        $renderer->doc .= $tagAttributes->toHtmlEnterTag("span");
                    } else {
                        /**
                         * New Syntax
                         * (The new syntax add the attributes to the previous element
                         */
                        $tagAttributes->addHtmlAttributeValue("data{$dataAttributeNamespace}-html", "true");

                        /**
                         * Keyboard user and assistive technology users
                         * If not button or link (ie span), add tabindex to make the element focusable
                         * in order to see the tooltip
                         * Not sure, if this is a good idea
                         */
                        if (!in_array($data[PluginUtility::CONTEXT], [syntax_plugin_combo_link::TAG, syntax_plugin_combo_button::TAG])) {
                            $tagAttributes->addHtmlAttributeValue("tabindex", "0");
                        }

                        $renderer->doc = rtrim($renderer->doc) . " {$tagAttributes->toHTMLAttributeString()} title=\"";
                        $this->docCapture = $renderer->doc;
                        $renderer->doc = "";
                    }

                    break;

                case DOKU_LEXER_UNMATCHED:
                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;

                case DOKU_LEXER_EXIT:

                    if (isset($data[PluginUtility::ERROR_MESSAGE])) {
                        return false;
                    }

                    if (isset($data[PluginUtility::ATTRIBUTES][self::TEXT_ATTRIBUTE])) {

                        $text = $data[PluginUtility::ATTRIBUTES][self::TEXT_ATTRIBUTE];
                        if (!empty($text)) {
                            $renderer->doc .= "</span>";
                        }

                    } else {
                        /**
                         * We get the doc created since the enter
                         * We replace the " by ' to be able to add it in the title attribute
                         */
                        $renderer->doc = PluginUtility::htmlEncode(preg_replace("/\r|\n/", "", $renderer->doc));

                        /**
                         * We recreate the whole document
                         */
                        $renderer->doc = $this->docCapture . $renderer->doc . "\">";
                        $this->docCapture = null;
                    }
                    break;


            }
            return true;
        }

        // unsupported $mode
        return false;
    }


}

