<?php


// must be run within Dokuwiki
use ComboStrap\PluginUtility;
use ComboStrap\TagAttributes;

if (!defined('DOKU_INC')) die();

/**
 * Class syntax_plugin_combo_box
 * Implementation of a div
 *
 */
class syntax_plugin_combo_slide extends DokuWiki_Syntax_Plugin
{

    const TAG = "slide";
    const CONF_ENABLE_SECTION_EDITING = "enableSlideSectionEditing";

    /**
     * @var int a slide counter
     */
    var $slideCounter = 0;


    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     * @see DokuWiki_Syntax_Plugin::getType()
     */
    function getType()
    {
        return 'container';
    }

    /**
     * How Dokuwiki will add P element
     *
     * * 'normal' - The plugin can be used inside paragraphs
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
        return array('container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs');
    }

    public function accepts($mode)
    {

        return syntax_plugin_combo_preformatted::disablePreformatted($mode);

    }

    function getSort()
    {
        return 200;
    }


    function connectTo($mode)
    {

        $pattern = PluginUtility::getContainerTagPattern(self::TAG);
        $this->Lexer->addEntryPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
    }


    function postConnect()
    {

        $this->Lexer->addExitPattern('</' . self::TAG . '>', PluginUtility::getModeFromTag($this->getPluginComponent()));

    }

    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_ENTER :
                $defaultAttributes = array();
                $inlineAttributes = PluginUtility::getTagAttributes($match);
                $attributes = PluginUtility::mergeAttributes($inlineAttributes, $defaultAttributes);
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $attributes,
                    PluginUtility::POSITION => $pos
                );

            case DOKU_LEXER_UNMATCHED :
                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);

            case DOKU_LEXER_EXIT :

                // +1 to go at the line ?
                $endPosition = $pos + strlen($match) + 1;
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::POSITION => $endPosition
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
                case DOKU_LEXER_ENTER :

                    /**
                     * Section Edit
                     */
                    if (PluginUtility::getConfValue(self::CONF_ENABLE_SECTION_EDITING, 1)) {
                        $position = $data[PluginUtility::POSITION];
                        $this->slideCounter++;
                        $name = self::TAG . $this->slideCounter;
                        PluginUtility::startSection($renderer, $position, $name);
                    }

                    /**
                     * Attributes
                     */
                    $attributes = TagAttributes::createFromCallStackArray($data[PluginUtility::ATTRIBUTES]);
                    $attributes->addClassName(self::TAG);

                    $sizeAttribute = "size";
                    $size = "md";
                    if ($attributes->hasComponentAttribute($sizeAttribute)) {
                        $size = $attributes->getValueAndRemove($sizeAttribute);
                    }
                    switch ($size) {
                        case "lg":
                        case "large":
                            $attributes->addClassName(self::TAG . "-lg");
                            break;
                        case "sm":
                        case "small":
                            $attributes->addClassName(self::TAG . "-sm");
                            break;
                        case "xl":
                        case "extra-large":
                            $attributes->addClassName(self::TAG . "-xl");
                            break;
                        default:
                            $attributes->addClassName(self::TAG . "-md");
                            break;
                    }

                    PluginUtility::getSnippetManager()->attachCssSnippetForBar(self::TAG);


                    $renderer->doc .= $attributes->toHtmlEnterTag("section");
                    $renderer->doc .= "<div class=\"slide-body\" style=\"z-index:1;position: relative;\">";
                    break;

                case DOKU_LEXER_UNMATCHED :
                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;

                case DOKU_LEXER_EXIT :

                    /**
                     * End body
                     */
                    $renderer->doc .= '</div>';
                    /**
                     * End section
                     */
                    if (PluginUtility::getConfValue(self::CONF_ENABLE_SECTION_EDITING, 1)) {
                        $renderer->finishSectionEdit($data[PluginUtility::POSITION]);
                    }

                    /**
                     * End component
                     */
                    $renderer->doc .= '</section>';

                    break;
            }
            return true;
        }

        // unsupported $mode
        return false;
    }


}

