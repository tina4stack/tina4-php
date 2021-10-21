<?php


// must be run within Dokuwiki
use ComboStrap\Bootstrap;
use ComboStrap\PluginUtility;
use ComboStrap\TagAttributes;

if (!defined('DOKU_INC')) die();

/**
 * Class syntax_plugin_combo_inote
 * Implementation of a inline note
 * called an alert in <a href="https://getbootstrap.com/docs/4.0/components/badge/">bootstrap</a>
 *
 * Quickly created with a copy of a badge
 */
class syntax_plugin_combo_inote extends DokuWiki_Syntax_Plugin
{

    const TAG = "inote";

    const CONF_DEFAULT_ATTRIBUTES_KEY = 'defaultInoteAttributes';

    const ATTRIBUTE_TYPE = "type";
    const ATTRIBUTE_ROUNDED = "rounded";

    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     * @see https://www.dokuwiki.org/devel:syntax_plugins#syntax_types
     * @see DokuWiki_Syntax_Plugin::getType()
     */
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
        return array('formatting', 'substition', 'paragraphs');
    }

    function getSort()
    {
        return 201;
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
                $attributes = PluginUtility::getTagAttributes($match);
                $defaultConfValue = $this->getConf(self::CONF_DEFAULT_ATTRIBUTES_KEY);
                $defaultAttributes = PluginUtility::parseAttributes($defaultConfValue);
                $attributes = PluginUtility::mergeAttributes($attributes, $defaultAttributes);
                if (!isset($attributes[TagAttributes::TYPE_KEY])) {
                    $attributes[TagAttributes::TYPE_KEY] = "info";
                }
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $attributes
                );

            case DOKU_LEXER_UNMATCHED :
                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);

            case DOKU_LEXER_EXIT :

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

                    $attributes = $data[PluginUtility::ATTRIBUTES];
                    $tagAttributes = TagAttributes::createFromCallStackArray($attributes);
                    $tagAttributes->addClassName("badge");


                    $type = $tagAttributes->getValue(TagAttributes::TYPE_KEY);

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
                        $bootstrapVersion = Bootstrap::getBootStrapMajorVersion();
                        if ($bootstrapVersion == Bootstrap::BootStrapFiveMajorVersion) {
                            /**
                             * We are using
                             */
                            $tagAttributes->addClassName("alert-" . $type);
                        } else {
                            $tagAttributes->addClassName("badge-" . $type);
                        }
                    } else {
                        if (!$tagAttributes->hasComponentAttribute("background-color")) {
                            $tagAttributes->addStyleDeclaration("background-color", "#fff79f"); // lum - 195
                            $tagAttributes->addClassName("text-dark");
                        }
                    }
                    $rounded = $tagAttributes->getValueAndRemove(self::ATTRIBUTE_ROUNDED);
                    if (!empty($rounded)) {
                        $tagAttributes->addClassName("badge-pill");
                    }


                    $renderer->doc .= $tagAttributes->toHtmlEnterTag("span");
                    break;

                case DOKU_LEXER_UNMATCHED :
                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;

                case DOKU_LEXER_EXIT :
                    $renderer->doc .= '</span>';
                    break;
            }
            return true;
        }

        // unsupported $mode
        return false;
    }


}

