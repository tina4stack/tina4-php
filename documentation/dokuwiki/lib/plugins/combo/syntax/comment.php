<?php


// must be run within Dokuwiki
use ComboStrap\PluginUtility;

if (!defined('DOKU_INC')) die();

/**
 * Class syntax_plugin_combo_itext
 * Setting text attributes on words
 *
 */
class syntax_plugin_combo_comment extends DokuWiki_Syntax_Plugin
{

    const TAG = "comment";

    /**
     * If yes, the comment are in the rendering
     */
    const CONF_OUTPUT_COMMENT = "outputComment";
    const CANONICAL = "comment";

    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in {@link $PARSER_MODES} in parser.php
     * @see DokuWiki_Syntax_Plugin::getType()
     */
    function getType()
    {
        return 'formatting';
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
     * No one of array('baseonly','container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs')
     * because we manage self the content and we call self the parser
     *
     * Return an array of one or more of the mode types {@link $PARSER_MODES} in Parser.php
     */
    function getAllowedTypes()
    {
        return array();
    }


    function getSort()
    {
        return 201;
    }


    function connectTo($mode)
    {


        $this->Lexer->addEntryPattern("<!--", $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));

    }


    function postConnect()
    {

        $this->Lexer->addExitPattern("-->", PluginUtility::getModeFromTag($this->getPluginComponent()));


    }

    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_ENTER :
            case DOKU_LEXER_EXIT :
                return array(
                    PluginUtility::STATE => $state
                );

            case DOKU_LEXER_UNMATCHED :
                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);

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
        if(PluginUtility::getConfValue(self::CONF_OUTPUT_COMMENT,0)) {
            if ($format === "xhtml") {
                if ($data[PluginUtility::STATE] === DOKU_LEXER_UNMATCHED) {
                    $renderer->doc .= "<!-- " . PluginUtility::renderUnmatched($data) . "-->";
                }
            }
        }
        // unsupported $mode
        return false;

    }


}

