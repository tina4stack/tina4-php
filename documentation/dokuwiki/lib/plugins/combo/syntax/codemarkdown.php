<?php

// implementation of
// https://developer.mozilla.org/en-US/docs/Web/HTML/Element/code

// must be run within Dokuwiki
use ComboStrap\PluginUtility;
use ComboStrap\Prism;
use ComboStrap\Tag;
use ComboStrap\TagAttributes;

require_once(__DIR__ . '/../ComboStrap/StringUtility.php');
require_once(__DIR__ . '/../ComboStrap/Prism.php');

if (!defined('DOKU_INC')) die();

/**
 *
 * Support <a href="https://github.github.com/gfm/#fenced-code-blocks">Github code block</a>
 *
 * The original code markdown code block is the {@link syntax_plugin_combo_preformatted}
 */
class syntax_plugin_combo_codemarkdown extends DokuWiki_Syntax_Plugin
{


    /**
     * The exit pattern of markdown
     * use in the enter pattern as regexp look-ahead
     */
    const MARKDOWN_EXIT_PATTERN = "^\s*(?:\`|~){3}\s*$";

    /**
     * The syntax name, not a tag
     * This must be the same that the last part name
     * of the class (without any space !)
     * This is the id in the callstack
     * and gives us just a way to get the
     * {@link \dokuwiki\Extension\SyntaxPlugin::getPluginComponent()}
     * value (tag = plugin component)
     */
    const TAG = "codemarkdown";


    function getType()
    {
        /**
         * You can't write in a code block
         */
        return 'protected';
    }

    /**
     * How DokuWiki will add P element
     *
     *  * 'normal' - The plugin can be used inside paragraphs
     *  * 'block'  - Open paragraphs need to be closed before plugin output - block should not be inside paragraphs
     *  * 'stack'  - Special case. Plugin wraps other paragraphs. - Stacks can contain paragraphs
     *
     * @see DokuWiki_Syntax_Plugin::getPType()
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
        return array();
    }

    function getSort()
    {

        return 199;
    }


    function connectTo($mode)
    {

        //
        // This pattern works with mgs flag
        //
        // Match:
        //  * the start of a line
        //  * any consecutive blank character
        //  * 3 consecutive backtick characters (`) or tildes (~)
        //  * a word (info string - ie the language)
        //  * any consecutive blank character
        //  * the end of a line
        //  * a look ahead to see if we have the exit pattern
        // https://github.github.com/gfm/#fenced-code-blocks
        $gitHubMarkdownPattern = "^\s*(?:\`|~){3}\w*\s*$(?=.*?" . self::MARKDOWN_EXIT_PATTERN . ")";
        $this->Lexer->addEntryPattern($gitHubMarkdownPattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));


    }


    function postConnect()
    {


        $this->Lexer->addExitPattern(self::MARKDOWN_EXIT_PATTERN, PluginUtility::getModeFromTag($this->getPluginComponent()));


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

            case DOKU_LEXER_ENTER:
                $trimmedMatch = trim($match);
                $language = preg_replace("(`{3}|~{3})", "", $trimmedMatch);

                $attributes = [TagAttributes::TYPE_KEY => $language];
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $attributes
                );

            case DOKU_LEXER_UNMATCHED :

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


        if ($format == 'xhtml') {

            /** @var Doku_Renderer_xhtml $renderer */
            $state = $data [PluginUtility::STATE];
            switch ($state) {
                case DOKU_LEXER_ENTER :
                    $attributes = TagAttributes::createFromCallStackArray($data[PluginUtility::ATTRIBUTES], syntax_plugin_combo_code::CODE_TAG);
                    Prism::htmlEnter($renderer, $this, $attributes);
                    break;

                case DOKU_LEXER_UNMATCHED :

                    // Delete the eol at the beginning and end
                    // otherwise we get a big block
                    $payload = trim($data[PluginUtility::PAYLOAD], "\n\r");
                    $renderer->doc .= PluginUtility::htmlEncode($payload);
                    break;

                case DOKU_LEXER_EXIT :

                    Prism::htmlExit($renderer);
                    break;

            }
            return true;
        } else if ($format == 'code') {

            /**
             * The renderer to download the code
             * @var Doku_Renderer_code $renderer
             */
            $state = $data [PluginUtility::STATE];
            if ($state == DOKU_LEXER_UNMATCHED) {

                $attributes = $data[PluginUtility::ATTRIBUTES];
                $text = $data[PluginUtility::PAYLOAD];
                $language = strtolower($attributes["type"]);
                $filename = $language;
                $renderer->code($text, $language, $filename);

            }
        }

        // unsupported $mode
        return false;

    }


}

