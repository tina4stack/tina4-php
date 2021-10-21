<?php


use ComboStrap\HeaderUtility;
use ComboStrap\PluginUtility;
use ComboStrap\Tag;
use ComboStrap\TagAttributes;

require_once(__DIR__ . '/../ComboStrap/HeaderUtility.php');

if (!defined('DOKU_INC')) die();


class syntax_plugin_combo_header extends DokuWiki_Syntax_Plugin
{


    const TAG = "header";

    function getType()
    {
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
     */
    function getPType()
    {
        return 'block';
    }

    function getAllowedTypes()
    {
        return array('substition', 'formatting', 'disabled');
    }

    function getSort()
    {
        return 201;
    }


    function connectTo($mode)
    {

        $this->Lexer->addEntryPattern(PluginUtility::getContainerTagPattern(HeaderUtility::HEADER), $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
    }

    public function postConnect()
    {
        $this->Lexer->addExitPattern('</' . HeaderUtility::HEADER . '>', PluginUtility::getModeFromTag($this->getPluginComponent()));
    }

    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_ENTER:
                $tagAttributes = PluginUtility::getTagAttributes($match);
                $tag = new Tag(HeaderUtility::HEADER, $tagAttributes, $state, $handler);
                $parent = $tag->getParent();
                $parentName = "";
                if ($parent != null) {
                    $parentName = $parent->getName();
                }
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $tagAttributes,
                    PluginUtility::CONTEXT => $parentName
                );

            case DOKU_LEXER_UNMATCHED :
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::PAYLOAD => $match);

            case DOKU_LEXER_EXIT :
                return array(
                    PluginUtility::STATE => $state
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

                case DOKU_LEXER_ENTER:
                    $parent = $data[PluginUtility::CONTEXT];
                    switch ($parent) {
                        case syntax_plugin_combo_blockquote::TAG:
                        case syntax_plugin_combo_card::TAG:
                        default:
                            $tagAttributes = TagAttributes::createFromCallStackArray($data[PluginUtility::ATTRIBUTES]);
                            $tagAttributes->addClassName("card-header");
                            $renderer->doc .= $tagAttributes->toHtmlEnterTag("div");
                            break;
                    }
                    break;

                case DOKU_LEXER_UNMATCHED :
                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;

                case DOKU_LEXER_EXIT:
                    $renderer->doc .= "</div>". DOKU_LF;
                    break;


            }
        }
        // unsupported $mode
        return false;
    }


}

