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
use ComboStrap\XhtmlUtility;
use ComboStrap\LinkUtility;
use ComboStrap\NavBarUtility;
use ComboStrap\PluginUtility;


require_once(__DIR__ . '/../class/PluginUtility.php');
require_once(__DIR__ . '/../class/NavBarUtility.php');


/**
 *
 * See https://getbootstrap.com/docs/4.0/components/collapse/
 *
 * The name of the class must follow a pattern (don't change it) ie syntax_plugin_PluginName_ComponentName
 */
class syntax_plugin_combo_navbarcollapse extends DokuWiki_Syntax_Plugin
{
    const TAG = 'collapse';
    const COMPONENT = 'navbarcollapse';

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
     *
     * No one of array('container', 'baseonly', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs')
     * because we manage self the content and we call self the parser
     */
    public function getAllowedTypes()
    {
        return array('container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs');
    }

    public function accepts($mode)
    {

        $accept = syntax_plugin_combo_preformatted::disablePreformatted($mode);

        // P element are not welcome in a navbar
        if ($mode == "eol") {
            $accept = false;
        }

        return $accept;

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
        // Only inside a navbar
        if ($mode == PluginUtility::getModeFromTag(syntax_plugin_combo_menubar::TAG)) {
            $pattern = PluginUtility::getContainerTagPattern(self::TAG);
            $this->Lexer->addEntryPattern($pattern, $mode, 'plugin_' . PluginUtility::PLUGIN_BASE_NAME . '_' . $this->getPluginComponent());
        }

    }

    public function postConnect()
    {

        $this->Lexer->addExitPattern('</' . self::TAG . '>', 'plugin_' . PluginUtility::PLUGIN_BASE_NAME . '_' . $this->getPluginComponent());

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

                $tagAttributes = PluginUtility::getTagAttributes($match);
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $tagAttributes
                );

            case DOKU_LEXER_UNMATCHED :

                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);


            case DOKU_LEXER_EXIT :

                return array(PluginUtility::STATE => $state);


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
        $state = $data[PluginUtility::STATE];
        switch ($format) {
            case 'xhtml':
                /** @var Doku_Renderer_xhtml $renderer */

                switch ($state) {

                    case DOKU_LEXER_ENTER :

                        $attributes = $data[PluginUtility::ATTRIBUTES];

                        // The button is the hamburger menu that will be shown
                        $idElementToCollapse = 'navbarcollapse';
                        $dataNamespace = Bootstrap::getDataNamespace();
                        $renderer->doc .= "<button class=\"navbar-toggler\" type=\"button\" data{$dataNamespace}-toggle=\"collapse\" data{$dataNamespace}-target=\"#$idElementToCollapse\" aria-controls=\"$idElementToCollapse\" aria-expanded=\"false\" aria-label=\"Toggle navigation\"";
                        if (array_key_exists("order", $attributes)) {
                            $renderer->doc .= ' style="order:' . $attributes["order"] . '"';
                            unset($attributes["order"]);
                        }
                        $renderer->doc .= '>' . DOKU_LF;
                        $renderer->doc .= '<span class="navbar-toggler-icon"></span>' . DOKU_LF;
                        $renderer->doc .= '</button>' . DOKU_LF;


                        $classValue = "collapse navbar-collapse";
                        if (array_key_exists("class", $attributes)) {
                            $attributes["class"] .= " {$classValue}";
                        } else {
                            $attributes["class"] = "{$classValue}";
                        }
                        $renderer->doc .= '<div id="' . $idElementToCollapse . '" ' . PluginUtility::array2HTMLAttributesAsString($attributes) . '>';

                        // All element below will collapse
                        break;

                    case DOKU_LEXER_UNMATCHED:
                        $renderer->doc .= NavBarUtility::text(PluginUtility::renderUnmatched($data));
                        break;


                    case DOKU_LEXER_EXIT :

                        $renderer->doc .= '</div>' . DOKU_LF;
                        break;
                }
                return true;

        }
        return false;
    }


    public static function getElementName()
    {
        return PluginUtility::getTagName(get_called_class());
    }


}
