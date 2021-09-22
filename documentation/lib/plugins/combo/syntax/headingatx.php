<?php


use ComboStrap\CallStack;
use ComboStrap\LogUtility;
use ComboStrap\PluginUtility;
use ComboStrap\TagAttributes;


if (!defined('DOKU_INC')) die();

/**
 * Atx headings
 * https://github.github.com/gfm/#atx-headings
 * https://spec.commonmark.org/0.29/#atx-heading
 * http://www.aaronsw.com/2002/atx/intro
 */
class syntax_plugin_combo_headingatx extends DokuWiki_Syntax_Plugin
{


    const TAG = "headingatx";
    const LEVEL = 'level';
    const EXIT_PATTERN = "\r??\n";


    function getType()
    {
        return 'formatting';
    }

    /**
     *
     * How Dokuwiki will add P element
     *
     *  * 'normal' - The plugin can be used inside paragraphs (inline)
     *  * 'block'  - Open paragraphs need to be closed before plugin output - block should not be inside paragraphs
     *  * 'stack'  - Special case. Plugin wraps other paragraphs. - Stacks can contain paragraphs
     *
     * @see DokuWiki_Syntax_Plugin::getPType()
     *
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
        return array('formatting', 'substition', 'protected', 'disabled');
    }

    /**
     *
     * @return int
     */
    function getSort()
    {
        return 49;
    }


    function connectTo($mode)
    {

        $pattern = '\r??\n\s*#{1,6}\s?(?=.*' . self::EXIT_PATTERN . ')';
        $this->Lexer->addSpecialPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));

    }


    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_SPECIAL :

                $attributes = [syntax_plugin_combo_heading::LEVEL => strlen(trim($match))];
                $callStack = CallStack::createFromHandler($handler);

                // Determine the type
                $context = syntax_plugin_combo_heading::getContext($callStack);

                /**
                 * The context is needed:
                 *   * to add the bootstrap class if it's a card title for instance
                 *   * and to delete {@link syntax_plugin_combo_heading::TYPE_OUTLINE} call
                 * in the {@link action_plugin_combo_headingpostprocess} (The rendering is done via Dokuwiki,
                 * see the exit processing for more info on the handling of outline headings)
                 *
                 */
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $attributes,
                    PluginUtility::CONTEXT => $context,
                    PluginUtility::POSITION => $pos
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

        /**
         *
         * The atx special call is transformed by the {@link action_plugin_combo_headingpostprocess}
         * into enter and exit call
         */
        if ($format == 'xhtml') {

            /** @var Doku_Renderer_xhtml $renderer */
            $state = $data[PluginUtility::STATE];
            switch ($state) {

                case DOKU_LEXER_ENTER:

                    $attributes = $data[PluginUtility::ATTRIBUTES];
                    $context = $data[PluginUtility::CONTEXT];
                    $tagAttributes = TagAttributes::createFromCallStackArray($attributes, syntax_plugin_combo_heading::TAG);
                    $pos = $data[PluginUtility::POSITION];
                    syntax_plugin_combo_heading::renderOpeningTag($context, $tagAttributes, $renderer, $pos);
                    return true;


                case DOKU_LEXER_EXIT:

                    $attributes = $data[PluginUtility::ATTRIBUTES];
                    $tagAttributes = TagAttributes::createFromCallStackArray($attributes);
                    $level = $tagAttributes->getValue(syntax_plugin_combo_heading::LEVEL);
                    $renderer->doc .= "</h$level>" . DOKU_LF;
                    return true;

            }
        } else if ($format == renderer_plugin_combo_analytics::RENDERER_FORMAT) {

            /**
             * @var renderer_plugin_combo_analytics $renderer
             */
            syntax_plugin_combo_heading::processMetadataAnalytics($data, $renderer);

        } else if ($format == "metadata") {

            /**
             * @var Doku_Renderer_metadata $renderer
             */
            syntax_plugin_combo_heading::processHeadingMetadata($data, $renderer);

        }

        return false;
    }


}

