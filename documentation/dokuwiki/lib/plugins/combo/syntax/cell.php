<?php
/**
 * Copyright (c) 2020. ComboStrap, Inc. and its affiliates. All Rights Reserved.
 *
 * This source code is licensed under the GPL license found in the
 * COPYING  file in the root directory of this source tree.
 *
 * @license  GPL 3 (https://www.gnu.org/licenses/gpl-3.0.en.html)
 * @author   ComboStrap <support@combostrap.com>
 *
 */

use ComboStrap\ConditionalValue;
use ComboStrap\Dimension;
use ComboStrap\PluginUtility;
use ComboStrap\TagAttributes;


require_once(__DIR__ . '/../ComboStrap/PluginUtility.php');

/**
 * The {@link https://combostrap.com/column column} of a {@link https://combostrap.com/grid grid}
 *
 *
 * Note: The name of the class must follow this pattern ie syntax_plugin_PluginName_ComponentName
 */
class syntax_plugin_combo_cell extends DokuWiki_Syntax_Plugin
{

    const TAG = "cell";

    const WIDTH_ATTRIBUTE = Dimension::WIDTH_KEY;
    const VERTICAL_ATTRIBUTE = "vertical";

    static function getTags()
    {
        return [self::TAG, "col", "column"];
    }

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
     * @return array
     * Allow which kind of plugin inside
     * All
     */
    public function getAllowedTypes()
    {
        return array('container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs');
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
        return 'stack';
    }

    /**
     * @see Doku_Parser_Mode::getSort()
     *
     * the mode with the lowest sort number will win out
     * the container (parent) must then have a lower number than the child
     */
    function getSort()
    {
        return 100;
    }

    /**
     * Create a pattern that will called this plugin
     *
     * @param string $mode
     * @see Doku_Parser_Mode::connectTo()
     */
    function connectTo($mode)
    {

        // A cell can be anywhere
        foreach (self::getTags() as $tag) {
            $pattern = PluginUtility::getContainerTagPattern($tag);
            $this->Lexer->addEntryPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
        }


    }

    public function postConnect()
    {

        foreach (self::getTags() as $tag) {
            $this->Lexer->addExitPattern('</' . $tag . '>', PluginUtility::getModeFromTag($this->getPluginComponent()));
        }

    }

    /**
     *
     * The handle function goal is to parse the matched syntax through the pattern function
     * and to return the result for use in the renderer
     * This result is always cached until the page is modified.
     * @param string $match
     * @param int $state
     * @param int $pos
     * @param Doku_Handler $handler
     * @return array|bool
     * @see DokuWiki_Syntax_Plugin::handle()
     *
     */
    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_ENTER:

                $attributes = TagAttributes::createFromTagMatch($match)->toCallStackArray();
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $attributes);

            case DOKU_LEXER_UNMATCHED:
                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);

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

                case DOKU_LEXER_ENTER :

                    PluginUtility::getSnippetManager()->attachCssSnippetForBar(self::TAG);
                    $callStackArray = $data[PluginUtility::ATTRIBUTES];
                    $attributes = TagAttributes::createFromCallStackArray($callStackArray, self::TAG);
                    $attributes->addClassName("col");
                    if ($attributes->hasComponentAttribute(self::VERTICAL_ATTRIBUTE)) {
                        $value = $attributes->getValue(self::VERTICAL_ATTRIBUTE);
                        if ($value == "center") {
                            //$attributes->addClassName("d-inline-flex");
                            $attributes->addClassName("align-self-center");
                        }
                    }
                    if ($attributes->hasComponentAttribute(syntax_plugin_combo_cell::WIDTH_ATTRIBUTE)) {
                        $sizeValues = $attributes->getValuesAndRemove(syntax_plugin_combo_cell::WIDTH_ATTRIBUTE);
                        foreach ($sizeValues as $sizeValue) {
                            $conditionalValue = ConditionalValue::createFrom($sizeValue);
                            if ($conditionalValue->getBreakpoint() == "xs") {
                                $attributes->addClassName("col-" . $conditionalValue->getValue());
                            } else {
                                if ($conditionalValue->getBreakpoint() != null) {
                                    $attributes->addClassName("col-$sizeValue");
                                } else {
                                    /**
                                     * No breakpoint given
                                     * If this is a number between 1 and 12,
                                     * we take the assumption that this is a ratio
                                     * otherwise, this a width in CSS length
                                     */
                                    if ($sizeValue >= 1 && $sizeValue <= syntax_plugin_combo_row::GRID_TOTAL_COLUMNS) {
                                        $attributes->addClassName("col-$sizeValue");
                                    } else {
                                        $attributes->addComponentAttributeValue(Dimension::WIDTH_KEY, $sizeValue);
                                    }
                                }
                            }
                        }
                    }
                    $renderer->doc .= $attributes->toHtmlEnterTag("div") . DOKU_LF;
                    break;

                case DOKU_LEXER_UNMATCHED :

                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;

                case DOKU_LEXER_EXIT :

                    $renderer->doc .= '</div>' . DOKU_LF;
                    break;
            }
            return true;
        }
        return false;
    }


}
