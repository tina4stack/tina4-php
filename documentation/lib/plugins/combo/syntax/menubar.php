<?php
/**
 * DokuWiki Syntax Plugin Combostrap.
 *
 */

use ComboStrap\PluginUtility;

if (!defined('DOKU_INC')) {
    die();
}

require_once(__DIR__ . '/../class/PluginUtility.php');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 *
 * The name of the class must follow a pattern (don't change it)
 * ie:
 *    syntax_plugin_PluginName_ComponentName
 *
 * See also: doc :
 * https://getbootstrap.com/docs/5.0/components/navbar/
 * https://material.io/components/app-bars-top
 *
 * Name:
 *  * header bar: http://themenectar.com/docs/salient/theme-options/header-navigation
 *  * menu bar: https://en.wikipedia.org/wiki/Menu_bar
 *  * app bar: https://material.io/components/app-bars-top
 *  * navbar: https://getbootstrap.com/docs/5.0/examples/navbars/#
 */
class syntax_plugin_combo_menubar extends DokuWiki_Syntax_Plugin
{

    const TAG = 'menubar';
    const OLD_TAG = "navbar";
    const TAGS = [self::TAG,self::OLD_TAG];

    /**
     * Do we need to add a container
     * @var bool
     */
    private $containerInside = false;

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

        $accept = syntax_plugin_combo_preformatted::disablePreformatted($mode);


        // Create P element
        if ($mode == "eol") {
            $accept = false;
        }

        return $accept;

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

        foreach(self::TAGS as $tag) {
            $pattern = PluginUtility::getContainerTagPattern($tag);
            $this->Lexer->addEntryPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
        }

    }

    public function postConnect()
    {
        foreach(self::TAGS as $tag) {
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

                $tagAttributes = PluginUtility::getTagAttributes($match);
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $tagAttributes
                );

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

                    $attributes = $data[PluginUtility::ATTRIBUTES];
                    $class = 'navbar';
                    if (array_key_exists("class", $attributes)) {
                        $attributes["class"] .= ' ' . $class;
                    } else {
                        $attributes["class"] = $class;
                    }

                    if (!array_key_exists("background-color", $attributes)) {
                        $attributes["background-color"] = 'light';
                    }

                    /**
                     * Without the expand, the flex has a row direction
                     * and not a column
                     */
                    $breakpoint = "lg";
                    if (array_key_exists("breakpoint", $attributes)) {
                        $breakpoint = $attributes["breakpoint"];
                        unset($attributes["breakpoint"]);
                    }
                    $attributes["class"] .= ' navbar-expand-' . $breakpoint;

                    // Grab the position
                    if (array_key_exists("position", $attributes)) {
                        $position = $attributes["position"];
                        if ($position === "top") {
                            $fixedTopClass = 'fixed-top';
                            $attributes["class"] .= ' ' . $fixedTopClass . '';
                            $fixedTopSnippetId = self::TAG."-".$fixedTopClass;
                            // See http://stackoverflow.com/questions/17181355/boostrap-using-fixed-navbar-and-anchor-tags-to-jump-to-sections
                            PluginUtility::getSnippetManager()->attachJavascriptSnippetForBar($fixedTopSnippetId);
                        }
                        unset($attributes["position"]);
                    }

                    // Theming
                    $theme = "light";
                    if (array_key_exists("theme", $attributes)) {
                        $theme = $attributes["theme"];
                        unset($attributes["theme"]);
                    }
                    $attributes["class"] .= ' navbar-' . $theme;

                    // Align
                    $align = "center";
                    if (array_key_exists("align", $attributes)) {
                        $align = $attributes["align"];
                        unset($attributes["align"]);
                    }

                    // Container
                    if ($align === "center") {
                        $this->containerInside = true;
                    }


                    $inlineAttributes = PluginUtility::array2HTMLAttributesAsString($attributes);

                    $containerTag = "";
                    if ($this->containerInside) {
                        $containerTag = '<div class="container">';
                    }

                    $renderer->doc .= "<nav {$inlineAttributes}>" . DOKU_LF;

                    // When the top is fixed, the container should be inside the navbar
                    $renderer->doc .= "{$containerTag}" . DOKU_LF;
                    break;

                case DOKU_LEXER_UNMATCHED:
                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;

                case DOKU_LEXER_EXIT :
                    $containerTag = "";
                    if ($this->containerInside) {
                        $containerTag = '</div>';
                    }
                    $renderer->doc .= "{$containerTag}</nav>" . DOKU_LF;
                    break;
            }
            return true;
        }
        return false;
    }


}
