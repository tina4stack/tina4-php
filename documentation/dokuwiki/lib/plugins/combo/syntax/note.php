<?php


// must be run within Dokuwiki
use ComboStrap\Background;
use ComboStrap\CallStack;
use ComboStrap\ColorUtility;
use ComboStrap\Dimension;
use ComboStrap\PluginUtility;
use ComboStrap\TagAttributes;

if (!defined('DOKU_INC')) die();

/**
 * Class syntax_plugin_combo_note
 * Implementation of a note
 * called an alert in <a href="https://getbootstrap.com/docs/4.0/components/alerts/">bootstrap</a>
 */
class syntax_plugin_combo_note extends DokuWiki_Syntax_Plugin
{

    const TAG = "note";
    const COMPONENT = "combo_note";

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
        return 'stack';
    }

    /**
     * @return array
     * Allow which kind of plugin inside
     *
     * ************************
     * This function has no effect because {@link SyntaxPlugin::accepts()} is used
     * ************************
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
        /**
         * header mode is disable to take over
         * and replace it with {@link syntax_plugin_combo_heading}
         */
        if ($mode == "header") {
            return false;
        }
        return syntax_plugin_combo_preformatted::disablePreformatted($mode);

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
                $defaultAttributes = array("type" => "info");
                $inlineAttributes = PluginUtility::getTagAttributes($match);
                $attributes = PluginUtility::mergeAttributes($inlineAttributes, $defaultAttributes);
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $attributes
                );

            case DOKU_LEXER_UNMATCHED :
                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);

            case DOKU_LEXER_EXIT :

                $callStack = CallStack::createFromHandler($handler);
                Dimension::addScrollToggleOnClickIfNoControl($callStack);


                // Important otherwise we don't get an exit in the render
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
                case DOKU_LEXER_ENTER :
                    PluginUtility::getSnippetManager()->attachCssSnippetForBar(self::TAG);
                    $attributes = TagAttributes::createFromCallStackArray($data[PluginUtility::ATTRIBUTES], self::TAG);
                    $attributes->addClassName("alert");
                    $type = $attributes->getValue(TagAttributes::TYPE_KEY);
                    // Switch for the color
                    switch ($type) {
                        case "important":
                            $type = "warning";
                            break;
                        case "warning":
                            $type = "danger";
                            break;
                    }

                    if ($type != "tip") {
                        $attributes->addClassName("alert-" . $type);
                    } else {
                        // There is no alert-tip color
                        // base color was background color and we have modified the luminance
                        if (!$attributes->hasComponentAttribute(ColorUtility::COLOR)) {
                            $attributes->addComponentAttributeValue(ColorUtility::COLOR, "#6c6400"); // lum - 51
                        }
                        if (!$attributes->hasComponentAttribute("border-color")) {
                            $attributes->addComponentAttributeValue("border-color", "#FFF78c"); // lum - 186
                        }
                        if (!$attributes->hasComponentAttribute(Background::BACKGROUND_COLOR)) {
                            $attributes->addComponentAttributeValue(Background::BACKGROUND_COLOR, "#fff79f"); // lum - 195
                        }
                    }

                    $attributes->addHtmlAttributeValue("role", "note");
                    $renderer->doc .= $attributes->toHtmlEnterTag('div');
                    break;

                case DOKU_LEXER_UNMATCHED :
                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;

                case DOKU_LEXER_EXIT :
                    $renderer->doc .= '</div>';
                    break;
            }
            return true;
        }

        // unsupported $mode
        return false;
    }


}

