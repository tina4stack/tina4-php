<?php
/**
 * DokuWiki Syntax Plugin Combostrap.
 *
 */

use ComboStrap\CallStack;
use ComboStrap\Icon;
use ComboStrap\PluginUtility;
use ComboStrap\Tag;
use ComboStrap\TagAttributes;


require_once(__DIR__ . '/../class/PluginUtility.php');
require_once(__DIR__ . '/../class/Icon.php');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 *
 * The name of the class must follow a pattern (don't change it)
 * ie:
 *    syntax_plugin_PluginName_ComponentName
 *
 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 * !!!!!!!!!!! The component name must be the name of the php file !!!
 * !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
 *
 * https://icons.getbootstrap.com/
 * https://remixicon.com/
 */
class syntax_plugin_combo_icon extends DokuWiki_Syntax_Plugin
{
    const TAG = "icon";


    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     * @see DokuWiki_Syntax_Plugin::getType()
     */
    function getType()
    {
        return 'substition';
    }

    /**
     * @return array
     * Allow which kind of plugin inside
     *
     * No one of array('container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs')
     * because we manage self the content and we call self the parser
     */
    public function getAllowedTypes()
    {
        // You can't put anything in a icon
        return array('formatting');
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
     * @see Doku_Parser_Mode::getSort()
     * the mode with the lowest sort number will win out
     * the lowest in the tree must have the lowest sort number
     * No idea why it must be low but inside a teaser, it will work
     * https://www.dokuwiki.org/devel:parser#order_of_adding_modes_important
     */
    function getSort()
    {
        return 10;
    }

    /**
     * Create a pattern that will called this plugin
     *
     * @param string $mode
     * @see Doku_Parser_Mode::connectTo()
     */
    function connectTo($mode)
    {


        $specialPattern = PluginUtility::getEmptyTagPattern(self::TAG);
        $this->Lexer->addSpecialPattern($specialPattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));

        /**
         * The content is used to add a {@link syntax_plugin_combo_tooltip}
         */
        $entryPattern = PluginUtility::getContainerTagPattern(self::TAG);
        $this->Lexer->addEntryPattern($entryPattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));


    }

    public function postConnect()
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
     * @param int $pos
     * @param Doku_Handler $handler
     * @return array|bool
     * @throws Exception
     * @see DokuWiki_Syntax_Plugin::handle()
     *
     */
    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_SPECIAL:
            case DOKU_LEXER_ENTER:
                // Get the parameters
                $tagAttributes = TagAttributes::createFromTagMatch($match);
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $tagAttributes->toCallStackArray()
                );
            case DOKU_LEXER_EXIT:
                $callStack = CallStack::createFromHandler($handler);
                $openingCall = $callStack->moveToPreviousCorrespondingOpeningCall();
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES=>$openingCall->getAttributes(),
                    PluginUtility::CONTEXT=>$openingCall->getContext()
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
                {
                    /** @var Doku_Renderer_xhtml $renderer */
                    $state = $data[PluginUtility::STATE];
                    switch ($state) {


                        case DOKU_LEXER_SPECIAL:
                            $tagAttribute = TagAttributes::createFromCallStackArray($data[PluginUtility::ATTRIBUTES]);
                            $renderer->doc .= Icon::renderIconByAttributes($tagAttribute);
                            break;
                        case DOKU_LEXER_ENTER:
                            /**
                             * If there is a tooltip, we need
                             * to start with a span to wrap the svg with it
                             */
                            if ($data[PluginUtility::CONTEXT] == syntax_plugin_combo_tooltip::TAG) {
                                /**
                                 * The inline block is to make the span take the whole space
                                 * of the image (ie dimension)
                                 */
                                $renderer->doc .= "<span class=\"d-inline-block\"";
                            }
                            break;
                        case DOKU_LEXER_EXIT:
                            /**
                             * Print the icon
                             */
                            $tagAttribute = TagAttributes::createFromCallStackArray($data[PluginUtility::ATTRIBUTES]);
                            $renderer->doc .= Icon::renderIconByAttributes($tagAttribute);
                            /**
                             * Close the span if we are in a tooltip context
                             */
                            if ($data[PluginUtility::CONTEXT] == syntax_plugin_combo_tooltip::TAG) {
                                $renderer->doc .= "</span>";
                            }

                            break;
                    }

                }
                break;

        }
        return true;
    }


}
