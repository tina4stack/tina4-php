<?php

use ComboStrap\PipelineUtility;
use ComboStrap\PluginUtility;

require_once(__DIR__ . '/../ComboStrap/PluginUtility.php');
require_once(__DIR__ . '/../ComboStrap/PipelineUtility.php');

/**
 *
 */
class syntax_plugin_combo_pipeline extends DokuWiki_Syntax_Plugin
{
    const TAG = "pipeline";


    /**
     * Syntax Type
     *
     * Protected in order to say that we don't want it to be modified
     *
     * @return string
     */
    public function getType()
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
        return array();
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
     *
     * @return int
     */
    public function getSort()
    {
        return 195;
    }

    /**
     *
     * @param string $mode
     */
    public function connectTo($mode)
    {

        $pattern = PluginUtility::getLeafContainerTagPattern(self::TAG);
        $this->Lexer->addSpecialPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));


    }


    /**
     *
     * @param string $match The text matched by the patterns
     * @param int $state The lexer state for the match
     * @param int $pos The character position of the matched text
     * @param Doku_Handler $handler The Doku_Handler object
     * @return  array Return an array with all data you want to use in render
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $script = PluginUtility::getTagContent($match);
        $string = PipelineUtility::execute($script);
        return array(
            PluginUtility::STATE => $state,
            PluginUtility::PAYLOAD=> $string);
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
                $renderer->doc .= $data[PluginUtility::PAYLOAD];
                break;

        }

        return true;

    }


}

