<?php


require_once(__DIR__ . "/../class/Analytics.php");
require_once(__DIR__ . "/../class/PluginUtility.php");
require_once(__DIR__ . "/../class/LinkUtility.php");
require_once(__DIR__ . "/../class/XhtmlUtility.php");

use ComboStrap\Call;
use ComboStrap\CallStack;
use ComboStrap\LogUtility;
use ComboStrap\PluginUtility;
use ComboStrap\Tag;
use ComboStrap\TagAttributes;

if (!defined('DOKU_INC')) die();

/**
 *
 * A paragraph syntax
 *
 * This syntax component is used dynamically while parsing (at the {@link DOKU_LEXER_END} of {@link \dokuwiki\Extension\SyntaxPlugin::handle()}
 * with the function {@link \syntax_plugin_combo_para::fromEolToParagraphUntilEndOfStack()}
 *
 *
 * !!!!!
 *
 * Info: The `eol` call are temporary created with {@link \dokuwiki\Parsing\ParserMode\Eol}
 * and transformed to `p_open` and `p_close` via {@link \dokuwiki\Parsing\Handler\Block::process()}
 *
 * Note: p_open call may appears when the {@link \ComboStrap\Syntax::getPType()} is set to `block` or `stack`
 * and the next call is not a block or a stack
 *
 * !!!!!
 *
 *
 * Note on Typography
 * TODO: https://github.com/typekit/webfontloader
 * https://www.dokuwiki.org/plugin:typography
 * https://stackoverflow.design/email/base/typography/
 * http://kyleamathews.github.io/typography.js/
 * https://docs.gitbook.com/features/advanced-branding (Roboto, Roboto Slab, Open Sans, Source Sans Pro, Lato, Ubuntu, Raleway, Merriweather)
 * http://themenectar.com/docs/salient/theme-options/typography/ (Doc)
 * https://www.modularscale.com/ - see the size of font
 *
 * See the fonts on your computer
 * https://wordmark.it/
 *
 * What's a type ? Type terminology
 * https://www.supremo.co.uk/typeterms/
 *
 * https://theprotoolbox.com/browse/font-tools/
 */
class syntax_plugin_combo_para extends DokuWiki_Syntax_Plugin
{

    const TAG = 'para';
    const COMPONENT = "combo_para";


    /**
     * The component that have a `normal` {@link \dokuwiki\Extension\SyntaxPlugin::getPType()}
     * are wrapped in a p element by {@link Block::process()} if they are not in a component with a `block` ptype
     *
     * This function makes it easy for the test
     * to do it and gives a little bit of documentation
     * on why there is a `p` in the test
     * @param $html
     * @return string the html wrapped in p
     */
    public static function wrapInP($html)
    {
        return "<p>" . $html . "</p>";
    }


    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     * @see https://www.dokuwiki.org/devel:syntax_plugins#syntax_types
     */
    function getType()
    {
        return 'paragraphs';
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
        /**
         * !important!
         * The {@link \dokuwiki\Parsing\Handler\Block::process()}
         * will then not create an extra paragraph after it encounters a block
         */
        return 'block';
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
        /**
         * Not needed as we don't have any {@link syntax_plugin_combo_para::connectTo()}
         */
        return array();
    }


    /**
     * @see Doku_Parser_Mode::getSort()
     * The mode with the lowest sort number will win out
     *
     */
    function getSort()
    {
        /**
         * Not really needed as we don't have any {@link syntax_plugin_combo_para::connectTo()}
         *
         * Note: if we start to use it should be less than 370
         * Ie Less than {@link \dokuwiki\Parsing\ParserMode\Eol::getSort()}
         */
        return 369;
    }


    function connectTo($mode)
    {

        /**
         * No need to connect
         * This syntax plugin is added dynamically with the {@link Tag::processEolToEndStack()}
         * function
         */

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

        /**
         * No need to handle,
         * there is no {@link syntax_plugin_combo_para::connectTo() connection}
         */
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
                $state = $data[PluginUtility::STATE];

                switch ($state) {
                    case DOKU_LEXER_ENTER:
                        $attributes = $data[PluginUtility::ATTRIBUTES];
                        $tagAttributes = TagAttributes::createFromCallStackArray($attributes);
                        if ($tagAttributes->hasComponentAttribute(TagAttributes::TYPE_KEY)) {
                            $class = $tagAttributes->getType();
                            $tagAttributes->addClassName($class);
                        }
                        $renderer->doc .= $tagAttributes->toHtmlEnterTag("p");
                        break;
                    case DOKU_LEXER_SPECIAL:
                        $attributes = $data[PluginUtility::ATTRIBUTES];
                        $tagAttributes = TagAttributes::createFromCallStackArray($attributes);
                        $renderer->doc .= $tagAttributes->toHtmlEnterTag("p");
                        $renderer->doc .= "</p>";
                        break;
                    case DOKU_LEXER_EXIT:
                        $renderer->doc .= "</p>";
                        break;
                }
                return true;

            case 'metadata':

                /** @var Doku_Renderer_metadata $renderer */


                return true;


            case renderer_plugin_combo_analytics::RENDERER_FORMAT:

                /**
                 * @var renderer_plugin_combo_analytics $renderer
                 */
                return true;

        }
        // unsupported $mode
        return false;
    }

    /**
     *
     * Transform EOL into paragraph
     * between the {@link CallStack::getActualCall()} and the {@link CallStack::isPointerAtEnd()}
     * of the stack
     *
     * Info: Basically, you get an new paragraph with a blank line or `\\` : https://www.dokuwiki.org/faq:newlines
     *
     * It replaces the  {@link \dokuwiki\Parsing\Handler\Block::process() eol to paragraph Dokuwiki process}
     * that takes place at the end of parsing process on the whole stack
     *
     * Info: The `eol` call are temporary created with {@link \dokuwiki\Parsing\ParserMode\Eol}
     * and transformed to `p_open` and `p_close` via {@link \dokuwiki\Parsing\Handler\Block::process()}
     *
     * @param CallStack $callstack
     * @param array $attributes - the attributes passed to the paragraph
     */
    public static function fromEolToParagraphUntilEndOfStack(&$callstack, $attributes)
    {

        if (!is_array($attributes)) {
            LogUtility::msg("The passed attributes array ($attributes) for the creation of the paragraph is not an array", LogUtility::LVL_MSG_ERROR);
            $attributes = [];
        }

        /**
         * The syntax plugin that implements the paragraph
         * ie {@link \syntax_plugin_combo_para}
         * We will transform the eol with a call to this syntax plugin
         * to create the paragraph
         */
        $paragraphComponent = \syntax_plugin_combo_para::COMPONENT;
        $paragraphTag = \syntax_plugin_combo_para::TAG;

        /**
         * The running variables
         */
        $paragraphIsOpen = false; // A pointer to see if the paragraph is open
        while ($actualCall = $callstack->next()) {

            /**
             * end of line is not always present
             * because the pattern is eating it
             * Example (list_open)
             */
            if ($paragraphIsOpen && $actualCall->getTagName() !== "eol") {
                if ($actualCall->getDisplay() == Call::BlOCK_DISPLAY) {
                    $paragraphIsOpen = false;
                    $callstack->insertBefore(
                        Call::createComboCall(
                            $paragraphTag,
                            DOKU_LEXER_EXIT,
                            $attributes
                        )
                    );
                }
            }

            if ($actualCall->getTagName() === "eol") {

                /**
                 * Next Call that is not the empty string
                 * Because Empty string would create an empty paragraph
                 *
                 * Start at 1 because we may not do
                 * a loop if we are at the end, the next call
                 * will return false
                 */
                $i = 1;
                while ($nextCall = $callstack->next()) {
                    if (!(
                        trim($nextCall->getCapturedContent()) == "" &&
                        $nextCall->isTextCall()
                    )) {
                        break;
                    }
                    $i++;
                }
                while ($i > 0) { // go back
                    $i--;
                    $callstack->previous();
                }
                if ($nextCall === false) {
                    $nextDisplay = "last";
                    $nextCall = null;
                } else {
                    $nextDisplay = $nextCall->getDisplay();
                }


                /**
                 * Processing
                 */
                if (!$paragraphIsOpen) {

                    switch ($nextDisplay) {
                        case Call::BlOCK_DISPLAY:
                        case "last":
                            $callstack->deleteActualCallAndPrevious();
                            break;
                        case Call::INLINE_DISPLAY:
                            $paragraphIsOpen = true;
                            $actualCall->updateToPluginComponent(
                                $paragraphComponent,
                                DOKU_LEXER_ENTER,
                                $attributes
                            );
                            break;
                        case "eol":
                            /**
                             * Empty line
                             */
                            $actualCall->updateToPluginComponent(
                                $paragraphComponent,
                                DOKU_LEXER_ENTER,
                                $attributes
                            );
                            $nextCall->updateToPluginComponent(
                                $paragraphComponent,
                                DOKU_LEXER_EXIT
                            );
                            $callstack->next();
                            break;
                        default:
                            LogUtility::msg("The eol action for the combination enter / (" . $nextDisplay . ") of the call ( $nextCall ) was not implemented", LogUtility::LVL_MSG_ERROR);
                            break;
                    }
                } else {
                    /**
                     * Paragraph is open
                     */
                    switch ($nextDisplay) {
                        case "eol":
                            /**
                             * Empty line
                             */
                            $actualCall->updateToPluginComponent(
                                $paragraphComponent,
                                DOKU_LEXER_EXIT
                            );
                            $nextCall->updateToPluginComponent(
                                $paragraphComponent,
                                DOKU_LEXER_ENTER,
                                $attributes
                            );
                            $callstack->next();
                            break;
                        case Call::INLINE_DISPLAY:
                            // A space
                            $actualCall->updateEolToSpace();
                            break;
                        case Call::BlOCK_DISPLAY:
                        case "last";
                            $actualCall->updateToPluginComponent(
                                $paragraphComponent,
                                DOKU_LEXER_EXIT
                            );
                            $paragraphIsOpen = false;
                            break;
                        default:
                            LogUtility::msg("The display for a open paragraph (" . $nextDisplay . ") is not implemented", LogUtility::LVL_MSG_ERROR);
                            break;
                    }
                }

            }
        }

        // if the paragraph is open close it
        if ($paragraphIsOpen) {
            $callstack->insertBefore(
                Call::createComboCall(
                    \syntax_plugin_combo_para::TAG,
                    DOKU_LEXER_EXIT
                )
            );
        }
    }

}

