<?php


use ComboStrap\CallStack;
use ComboStrap\PluginUtility;
use ComboStrap\TagAttributes;

require_once(__DIR__ . '/../ComboStrap/PluginUtility.php');


/**
 *
 * An iterator to iterate over templates.
 *
 * *******************
 * Iteration driver
 * *******************
 * The end tag of the template node is driving the iteration.
 * This way, the tags just after the template
 * sees them in the {@link CallStack} and can change their context
 *
 * For instance, a {@link syntax_plugin_combo_masonry}
 * component will change the context of all card inside it.
 *
 * ********************
 * Header and footer delimitation
 * ********************
 * The iterator delimits also the header and footer.
 * Some component needs the header to be generate completely.
 * This is the case of a complex markup such as a table
 *
 * ******************************
 * Delete if no data
 * ******************************
 * It gives also the possibility to {@link syntax_plugin_combo_iterator::EMPTY_ROWS_COUNT_ATTRIBUTE
 * delete the whole block}
 * (header and footer also) if there is no data
 *
 * *****************************
 * Always Contextual
 * *****************************
 * We don't capture the text markup such as in a {@link syntax_plugin_combo_code}
 * in order to loop because you can't pass the actual handler (ie callstack)
 * when you {@link p_get_instructions() parse again} a markup.
 *
 * The markup is then seen as a new single page without any context.
 * That may lead to problems.
 * Example: `heading` may then think that they are `outline heading` ...
 *
 */
class syntax_plugin_combo_iterator extends DokuWiki_Syntax_Plugin
{

    /**
     * Tag in Dokuwiki cannot have a `-`
     * This is the last part of the class
     */
    const TAG = "iterator";

    /**
     * Page canonical and tag pattern
     */
    const CANONICAL = "iterator";

    /**
     * An attribute that is set back
     * by the {@link DOKU_LEXER_EXIT} state in {@link syntax_plugin_combo_template::handle()}
     * in order to delete the whole iterator content (ie header, footer)
     * at the {@link DOKU_LEXER_EXIT} state of {@link syntax_plugin_combo_iterator::handle()}
     * if there is no rows to iterate
     */
    const EMPTY_ROWS_COUNT_ATTRIBUTE = "emptyRowCount";


    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     * @see https://www.dokuwiki.org/devel:syntax_plugins#syntax_types
     * @see DokuWiki_Syntax_Plugin::getType()
     */
    function getType()
    {
        return 'container';
    }

    /**
     * How Dokuwiki will add P element
     *
     *  * 'normal' - The plugin can be used inside paragraphs (inline or inside)
     *  * 'block'  - Open paragraphs need to be closed before plugin output (box) - block should not be inside paragraphs
     *  * 'stack'  - Special case. Plugin wraps other paragraphs. - Stacks can contain paragraphs
     *
     * @see DokuWiki_Syntax_Plugin::getPType()
     * @see https://www.dokuwiki.org/devel:syntax_plugins#ptype
     */
    function getPType()
    {
        return 'block';
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
        return array('container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs');
    }

    function getSort()
    {
        return 201;
    }

    public function accepts($mode)
    {
        return syntax_plugin_combo_preformatted::disablePreformatted($mode);
    }


    function connectTo($mode)
    {


        $pattern = PluginUtility::getContainerTagPattern(self::TAG);
        $this->Lexer->addEntryPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));


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
     * @param int $pos - byte position in the original source file
     * @param Doku_Handler $handler
     * @return array|bool
     * @throws Exception
     * @see DokuWiki_Syntax_Plugin::handle()
     *
     */
    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_ENTER :

                $tagAttributes = TagAttributes::createFromTagMatch($match);
                $callStackArray = $tagAttributes->toCallStackArray();
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $callStackArray
                );

            case DOKU_LEXER_UNMATCHED :

                // We should not ever come here but a user does not not known that
                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);


            case DOKU_LEXER_EXIT :

                return array(PluginUtility::STATE => $state);

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
        // unsupported $mode
        return false;
    }


}

