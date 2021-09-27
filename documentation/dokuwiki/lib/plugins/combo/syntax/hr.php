<?php

// implementation of
// https://developer.mozilla.org/en-US/docs/Web/HTML/Element/hr

// must be run within Dokuwiki
use ComboStrap\PluginUtility;
use ComboStrap\TitleUtility;


if (!defined('DOKU_INC')) die();

/**
 * Class syntax_plugin_combo_hr
 * [[https://www.w3.org/TR/2011/WD-html5-author-20110809/the-hr-element.html|W3c reference]]
 * [[https://www.digitala11y.com/separator-role/|Separator role]]
 * [[https://material.io/components/dividers|Divider]]
 */
class syntax_plugin_combo_hr extends DokuWiki_Syntax_Plugin
{


    const TAG = "hr";

    function getType()
    {
        return 'substition';
    }

    /**
     * How Dokuwiki will add P element
     *
     *  * 'normal' - The plugin can be used inside paragraphs (inline)
     *  * 'block'  - Open paragraphs need to be closed before plugin output - block should not be inside paragraphs
     *  * 'stack'  - Special case. Plugin wraps other paragraphs. - Stacks can contain paragraphs
     *
     * @see DokuWiki_Syntax_Plugin::getPType()
     */
    function getPType()
    {
        return 'block';
    }

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
        /**
         * HR is a void element and support both syntax
         * https://dev.w3.org/html5/html-author/#void-elements-0
         */
        $this->Lexer->addSpecialPattern(PluginUtility::getEmptyTagPattern(self::TAG), $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
        $this->Lexer->addSpecialPattern(PluginUtility::getVoidElementTagPattern(self::TAG), $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));

    }


    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {


            case DOKU_LEXER_SPECIAL :

                $attributes = PluginUtility::getTagAttributes($match);
                $html = "<hr/>";
                if (sizeof($attributes)>0){
                    $html = "<hr ".PluginUtility::array2HTMLAttributesAsString($attributes)."/>";
                }
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::PAYLOAD => $html);


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
            $renderer->doc .= $data[PluginUtility::PAYLOAD];

        }
        // unsupported $mode
        return false;
    }


}

