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

use ComboStrap\Bootstrap;
use ComboStrap\CallStack;
use ComboStrap\Dimension;
use ComboStrap\LogUtility;
use ComboStrap\PluginUtility;
use ComboStrap\TagAttributes;

if (!defined('DOKU_INC')) {
    die();
}

require_once(__DIR__ . '/../class/PluginUtility.php');

/**
 * The {@link https://combostrap.com/row row} of a {@link https://combostrap.com/grid grid}
 *
 *
 * Note: The name of the class must follow this pattern ie syntax_plugin_PluginName_ComponentName
 *
 *
 * See also: https://getbootstrap.com/docs/5.0/utilities/flex/
 */
class syntax_plugin_combo_row extends DokuWiki_Syntax_Plugin
{

    const TAG = "row";
    const SNIPPET_ID = "row";

    /**
     * The strap template permits to
     * change this value
     * but because of the new grid system, it has been deprecated
     * We therefore don't get the grid total columns value from strap
     * @see {@link https://combostrap.com/dynamic_grid Dynamic Grid }
     */
    const GRID_TOTAL_COLUMNS = 12;


    const CANONICAL = self::GRID;
    const GRID = "grid";

    /**
     * A row can be used as a grid
     * with the div element
     * or as a list item
     *
     * By default, this is a div but a list
     * or any other component can change that
     */
    const HTML_TAG_ATT = "html-tag";

    /**
     * Meant to be a children of a component
     * Vertically centered and no padding on the first cell and last cell
     *
     * Used in @link syntax_plugin_combo_contentlist} or
     * within a card for instance
     *
     * This value is not yet public or in the documentation
     */
    const CONTAINED_CONTEXT = "contained";
    const ROOT_CONTEXT = "root";

    /**
     * Used when the grid is not contained
     * and is just below the root
     * We set a value
     */
    const TYPE_AUTO_VALUE = "auto";
    const TYPE_FIT_OLD_VALUE = "natural";
    const TYPE_FIT_VALUE = "fit";
    const MINIMAL_WIDTH = 300;

    /**
     * The {@link syntax_plugin_combo_contentlist}
     * component use row under the hood
     * and add its own class, this attribute
     * helps to see if the user has enter any class
     */
    const HAD_USER_CLASS = "hasClass";


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
        /**
         * Only column.
         * See {@link syntax_plugin_combo_cell::getType()}
         */
        return array('container');
    }


    public function accepts($mode)
    {

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
        return 'block';
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


        $pattern = PluginUtility::getContainerTagPattern(self::TAG);
        $this->Lexer->addEntryPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));


    }

    public function postConnect()
    {

        $this->Lexer->addExitPattern('</' . self::TAG . '>', PluginUtility::getModeFromTag($this->getPluginComponent()));

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

                $attributes = TagAttributes::createFromTagMatch($match);


                $callStack = CallStack::createFromHandler($handler);
                $parent = $callStack->moveToParent();

                /**
                 * The deprecation
                 */
                if ($attributes->hasComponentAttribute(TagAttributes::TYPE_KEY)) {
                    $value = $attributes->getType();
                    if ($value == self::TYPE_FIT_OLD_VALUE) {
                        $attributes->setType(self::TYPE_FIT_VALUE);
                        LogUtility::msg("Deprecation: The type value (" . self::TYPE_FIT_OLD_VALUE . ") for the row component should be been renamed to (" . self::TYPE_FIT_VALUE . ")", LogUtility::LVL_MSG_WARNING, self::CANONICAL);
                    }
                }

                /**
                 * Context
                 *   To add or not a margin-bottom,
                 *   To delete the image link or not
                 */
                $context = self::ROOT_CONTEXT;
                if ($parent != false
                    && !in_array($parent->getTagName(), [syntax_plugin_combo_container::TAG, syntax_plugin_combo_cell::TAG])) {
                    $context = self::CONTAINED_CONTEXT;
                }

                /**
                 * Type
                 */
                if (!$attributes->hasComponentAttribute(TagAttributes::TYPE_KEY)
                    && !$attributes->hasComponentAttribute(TagAttributes::CLASS_KEY)) {

                    if ($context == self::CONTAINED_CONTEXT) {
                        $attributes->setType(self::TYPE_FIT_VALUE);
                    } else {
                        $attributes->setType(self::TYPE_AUTO_VALUE);
                    }
                }


                /**
                 * By default, div but used in a ul, it could be a li
                 * This is modified in the callstack by the other component
                 */
                $attributes->addComponentAttributeValue(self::HTML_TAG_ATT, "div");


                /**
                 * User Class
                 */
                if ($attributes->hasComponentAttribute(TagAttributes::CLASS_KEY)) {
                    $attributes->addComponentAttributeValue(self::HAD_USER_CLASS, true);
                } else {
                    $attributes->addComponentAttributeValue(self::HAD_USER_CLASS, false);
                }

                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $attributes->toCallStackArray(),
                    PluginUtility::CONTEXT => $context
                );

            case DOKU_LEXER_UNMATCHED:
                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);

            case DOKU_LEXER_EXIT :
                $callStack = CallStack::createFromHandler($handler);
                $openingCall = $callStack->moveToPreviousCorrespondingOpeningCall();
                $type = $openingCall->getType();

                /**
                 * Auto width calculation
                 */
                if ($type == syntax_plugin_combo_row::TYPE_AUTO_VALUE) {
                    $numberOfColumns = 0;
                    /**
                     * If the size or the class is set, we don't
                     * apply the automatic sizing
                     */
                    $hasSizeOrClass = false;
                    while ($actualCall = $callStack->next()) {
                        if ($actualCall->getTagName() == syntax_plugin_combo_cell::TAG
                            &&
                            $actualCall->getState() == DOKU_LEXER_ENTER
                        ) {
                            $numberOfColumns++;
                            if ($actualCall->hasAttribute(syntax_plugin_combo_cell::WIDTH_ATTRIBUTE)) {
                                $hasSizeOrClass = true;
                            }
                            if ($actualCall->hasAttribute(TagAttributes::CLASS_KEY)) {
                                $hasSizeOrClass = true;
                            }

                        }
                    }
                    if (!$hasSizeOrClass && $numberOfColumns > 1) {
                        /**
                         * Parameters
                         */
                        $minimalWidth = self::MINIMAL_WIDTH;
                        $numberOfGridColumns = self::GRID_TOTAL_COLUMNS;
                        $breakpoints =
                            [
                                "xs" => 270,
                                "sm" => 540,
                                "md" => 720,
                                "lg" => 960,
                                "xl" => 1140,
                                "xxl" => 1320
                            ];
                        /**
                         * Calculation of the sizes value
                         */
                        $sizes = [];
                        $previousRatio = null;
                        foreach ($breakpoints as $breakpoint => $value) {
                            $spaceByColumn = $value / $numberOfColumns;
                            if ($spaceByColumn < $minimalWidth) {
                                $spaceByColumn = $minimalWidth;
                            }
                            $ratio = floor($numberOfGridColumns / ($value / $spaceByColumn));
                            if ($ratio > $numberOfGridColumns) {
                                $ratio = $numberOfGridColumns;
                            }
                            // be sure that it's divisible by the number of grids columns
                            // if for 3 columns, we get a ratio of 5, we want 4;
                            while (($numberOfGridColumns % $ratio) != 0) {
                                $ratio = $ratio - 1;
                            }

                            // Closing
                            if ($ratio != $previousRatio) {
                                $sizes[] = "$breakpoint-$ratio";
                                $previousRatio = $ratio;
                            } else {
                                break;
                            }
                        }
                        $sizeValue = implode(" ", $sizes);
                        $callStack->moveToPreviousCorrespondingOpeningCall();
                        while ($actualCall = $callStack->next()) {
                            if ($actualCall->getTagName() == syntax_plugin_combo_cell::TAG
                                &&
                                $actualCall->getState() == DOKU_LEXER_ENTER
                            ) {
                                $actualCall->addAttribute(syntax_plugin_combo_cell::WIDTH_ATTRIBUTE, $sizeValue);
                            }
                        }
                    }
                }

                if ($openingCall->getContext() == self::CONTAINED_CONTEXT || $openingCall->getType() == self::TYPE_FIT_VALUE) {
                    /**
                     * No link for the media image by default
                     */
                    $callStack->moveToEnd();
                    $callStack->moveToPreviousCorrespondingOpeningCall();
                    $callStack->processNoLinkOnImageToEndStack();

                    /**
                     * Process the P to make them container friendly
                     * Needed to make the diff between a p added
                     * by the user via the {@link syntax_plugin_combo_para text}
                     * and a p added automatically by Dokuwiki
                     *
                     */
                    $callStack->moveToPreviousCorrespondingOpeningCall();
                    // Follow the bootstrap and combo convention
                    // ie text for bs and combo as suffix
                    $class = "row-contained-text-combo";
                    $callStack->processEolToEndStack(["class" => $class]);

                    /**
                     * If the type is fit value (ie flex auto),
                     * we constraint the cell that have text
                     */
                    $callStack->moveToEnd();
                    $callStack->moveToPreviousCorrespondingOpeningCall();
                    $hasText = false;
                    while ($actualCall = $callStack->next()) {
                        if ($actualCall->getTagName() == syntax_plugin_combo_cell::TAG) {
                            switch ($actualCall->getState()) {
                                case DOKU_LEXER_ENTER:
                                    $actualCellOpenTag = $actualCall;
                                    $hasText = false;
                                    break;
                                case DOKU_LEXER_EXIT:
                                    if ($hasText) {
                                        if (isset($actualCellOpenTag) && !$actualCellOpenTag->hasAttribute(Dimension::WIDTH_KEY)) {
                                            $actualCellOpenTag->addAttribute(Dimension::WIDTH_KEY, self::MINIMAL_WIDTH);
                                        }
                                    };
                                    break;
                            }
                        } else if ($actualCall->isTextCall()) {
                            $hasText = true;
                        }

                    }
                }

                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::CONTEXT => $openingCall->getContext(),
                    PluginUtility::ATTRIBUTES => $openingCall->getAttributes()
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
                    $attributes = TagAttributes::createFromCallStackArray($data[PluginUtility::ATTRIBUTES], self::TAG);
                    $hadClassAttribute = $attributes->getBooleanValueAndRemove(self::HAD_USER_CLASS);
                    $htmlElement = $attributes->getValueAndRemove(self::HTML_TAG_ATT);

                    $attributes->addClassName("row");

                    /**
                     * The type is responsible
                     * for the width and space between the cells
                     */
                    $type = $attributes->getValue(TagAttributes::TYPE_KEY);
                    if (!empty($type)) {
                        switch ($type) {
                            case syntax_plugin_combo_row::TYPE_FIT_VALUE:
                                $attributes->addClassName("row-cols-auto");
                                if (Bootstrap::getBootStrapMajorVersion() != Bootstrap::BootStrapFiveMajorVersion) {
                                    // row-cols-auto is not in 4.0
                                    PluginUtility::getSnippetManager()->attachCssSnippetForBar("row-cols-auto");
                                }
                                break;
                            case syntax_plugin_combo_row::TYPE_AUTO_VALUE:
                                /**
                                 * The class are set on the cells, not on the row,
                                 * nothing to do
                                 */
                                break;
                        }
                    }

                    /**
                     * Add the css
                     */
                    $context = $data[PluginUtility::CONTEXT];
                    $tagClass = self::TAG . "-" . $context;

                    if (!$hadClassAttribute) {
                        /**
                         * when wrapping, there will be a space between the cells
                         * on the y axis
                         */
                        $attributes->addClassName("gy-3");
                    }
                    switch ($context) {
                        case self::CONTAINED_CONTEXT:
                            /**
                             * All element are centered vertically and horizontally
                             */
                            if (!$hadClassAttribute) {
                                $attributes->addClassName("justify-content-center");
                                $attributes->addClassName("align-items-center");
                            }
                            /**
                             * p children should be flex
                             * p generated should have no bottom-margin (because contained)
                             */
                            $attributes->addClassName($tagClass);
                            PluginUtility::getSnippetManager()->attachCssSnippetForBar($tagClass);
                            break;
                        case self::ROOT_CONTEXT:
                            /**
                             * All element are centered
                             * If their is 5 cells and the last one
                             * is going at the line, it will be centered
                             */
                            if (!$hadClassAttribute) {
                                $attributes->addClassName("justify-content-center");
                            }
                            $attributes->addClassName($tagClass);
                            PluginUtility::getSnippetManager()->attachCssSnippetForBar($tagClass);
                            break;
                    }


                    /**
                     * Render
                     */
                    $renderer->doc .= $attributes->toHtmlEnterTag($htmlElement) . DOKU_LF;
                    break;

                case DOKU_LEXER_UNMATCHED :

                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;

                case DOKU_LEXER_EXIT :

                    $tagAttributes = TagAttributes::createFromCallStackArray($data[PluginUtility::ATTRIBUTES]);
                    $htmlElement = $tagAttributes->getValue(self::HTML_TAG_ATT);
                    $renderer->doc .= "</$htmlElement>" . DOKU_LF;
                    break;
            }
            return true;
        }
        return false;
    }


}

