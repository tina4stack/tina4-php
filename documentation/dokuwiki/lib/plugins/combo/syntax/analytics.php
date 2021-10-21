<?php


// must be run within Dokuwiki
use ComboStrap\Analytics;
use ComboStrap\PluginUtility;

if (!defined('DOKU_INC')) die();

/**
 * Class syntax_plugin_combo_analytics
 * This class was just created to add syntax analytics
 * to the metadata.
 */
class syntax_plugin_combo_analytics extends DokuWiki_Syntax_Plugin
{

    const TAG = "analytics";

    /**
     * Syntax Type.
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
     * array('container', 'baseonly', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs')
     *
     */
    function getAllowedTypes()
    {
        return array();
    }

    function getSort()
    {
        return 201;
    }


    /**
     * Create a pattern that will called this plugin
     *
     * @param string $mode
     * @see Doku_Parser_Mode::connectTo()
     */
    function connectTo($mode)
    {
        /**
         * The instruction `calls` are not created via syntax
         * but dynamically via {@link action_plugin_combo_pluginanalytics::_extract_plugin_info()}
         */

    }

    function postConnect()
    {

        /**
         * The instruction `calls` are not created via syntax
         * but dynamically via {@link action_plugin_combo_pluginanalytics::_extract_plugin_info()}
         */

    }

    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        /**
         * The instruction `calls` are not created via syntax
         * but dynamically via {@link action_plugin_combo_pluginanalytics::_extract_plugin_info()}
         */

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

        if ($format == renderer_plugin_combo_analytics::RENDERER_FORMAT) {

            /** @var renderer_plugin_combo_analytics $renderer */
            $state = $data[PluginUtility::STATE];
            if ($state == DOKU_LEXER_SPECIAL) {
                $attributes = $data[PluginUtility::ATTRIBUTES];
                $renderer->stats[Analytics::SYNTAX_COUNT] = $attributes;
                return true;
            }

        }

        return false;
    }


}

