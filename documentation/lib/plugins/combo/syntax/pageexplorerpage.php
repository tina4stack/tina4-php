<?php


use ComboStrap\PluginUtility;
use ComboStrap\TagAttributes;


/**
 * Implementation of the page item
 *   - a page in the list menu
 *   - a leaf tree node in the collapsible menu (http://localhost:63342/bootstrap-5.0.1-examples/sidebars/index.html)
 *
 * This syntax is not a classic syntax plugin
 *
 * The instructions are captured at the {@link DOKU_LEXER_END}
 * state of {@link syntax_plugin_combo_pageexplorer::handle()}
 * to create/generate the list of pages
 *
 *
 */
class syntax_plugin_combo_pageexplorerpage extends DokuWiki_Syntax_Plugin
{

    /**
     * Tag in Dokuwiki cannot have a `-`
     * This is the last part of the class
     */
    const TAG = "pageexplorerpage";

    /**
     * The pattern
     */
    const MARKI_PAGE_TAG = "page";
    const MARKI_PAGE_OLD_TAG = "page-item";
    const MARKI_PAGE_TAGS = [self::MARKI_PAGE_TAG, self::MARKI_PAGE_OLD_TAG];


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
     *  * 'normal' - The plugin can be used inside paragraphs (inline or inside)
     *  * 'block'  - Open paragraphs need to be closed before plugin output (box) - block should not be inside paragraphs
     *  * 'stack'  - Special case. Plugin wraps other paragraphs. - Stacks can contain paragraphs
     *
     * @see DokuWiki_Syntax_Plugin::getPType()
     * @see https://www.dokuwiki.org/devel:syntax_plugins#ptype
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
        return array('formatting', 'substition');
    }

    function getSort()
    {
        return 201;
    }

    public function accepts($mode)
    {
        return syntax_plugin_combo_preformatted::disablePreformatted($mode);
    }


    function connectTo($mode)
    {
        if ($mode == PluginUtility::getModeFromTag(syntax_plugin_combo_pageexplorer::TAG)) {
            foreach (self::MARKI_PAGE_TAGS as $page) {
                $pattern = PluginUtility::getContainerTagPattern($page);
                $this->Lexer->addEntryPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
            }
        }

    }


    public function postConnect()
    {
        foreach (self::MARKI_PAGE_TAGS as $page) {
            $this->Lexer->addExitPattern('</' . $page . '>', PluginUtility::getModeFromTag($this->getPluginComponent()));
        }

    }


    /**
     *
     * The handle function goal is to parse the matched syntax through the pattern function
     * and to return the result for use in the renderer
     * This result is always cached until the page is modified.
     * @param string $match
     * @param int $state
     * @param int $pos - byte position in the original source file
     * @param Doku_Handler $handler
     * @return array|bool
     * @throws Exception
     * @see DokuWiki_Syntax_Plugin::handle()
     *
     */
    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_ENTER :
                $attributes = PluginUtility::getTagAttributes($match);
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $attributes
                );

            case DOKU_LEXER_UNMATCHED :

                // We should not ever come here but a user does not not known that
                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);


            case DOKU_LEXER_EXIT :


                return array(
                    PluginUtility::STATE => $state,
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
                    $logicalTagForTree = syntax_plugin_combo_pageexplorer::CANONICAL . "-tree-" . self::MARKI_PAGE_TAG;
                    $tagAttributes = TagAttributes::createFromCallStackArray($data[PluginUtility::ATTRIBUTES], $logicalTagForTree);
                    $renderer->doc .= $tagAttributes->toHtmlEnterTag("li")  . DOKU_LF;
                    break;
                case DOKU_LEXER_UNMATCHED :
                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;
                case DOKU_LEXER_EXIT :
                    $renderer->doc .= "</li>" . DOKU_LF;
                    break;
            }
            return true;
        }

        // unsupported $mode
        return false;
    }


}

