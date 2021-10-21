<?php


use ComboStrap\PluginUtility;
use ComboStrap\TagAttributes;


/**
 * Class syntax_plugin_combo_list
 * Implementation of a list
 *
 * This component is not not public
 *
 */
class syntax_plugin_combo_contentlistitem extends DokuWiki_Syntax_Plugin
{

    const DOKU_TAG = "contentlistitem";
    const MARKI_TAG = "content-list-item";
    const ALL_TAGS = array(self::MARKI_TAG, "list-item", "li");



    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     * @see https://www.dokuwiki.org/devel:syntax_plugins#syntax_types
     * @see DokuWiki_Syntax_Plugin::getType()
     */
    function getType()
    {
        return 'container';
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
        /**
         * No paragraph inside, this is a layout
         */
        return 'block';
    }

    public function accepts($mode)
    {
        return syntax_plugin_combo_preformatted::disablePreformatted($mode);
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

    /**
     * @see Doku_Parser_Mode::getSort()
     * the mode with the lowest sort number will win out
     * Higher than {@link syntax_plugin_combo_contentlist}
     * but less than {@link syntax_plugin_combo_preformatted}
     */
    function getSort()
    {
        return 18;
    }


    function connectTo($mode)
    {

        /**
         * This is now know as `row`
         * This is the old tags
         */
        foreach (self::ALL_TAGS as $tag) {
            $pattern = PluginUtility::getContainerTagPattern($tag);
            $this->Lexer->addEntryPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
        }


    }

    public function postConnect()
    {
        foreach (self::ALL_TAGS as $tag) {
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
     * @param int $pos - byte position in the original source file
     * @param Doku_Handler $handler
     * @return array|bool
     * @see DokuWiki_Syntax_Plugin::handle()
     *
     */
    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_ENTER :

                $attributes = TagAttributes::createFromTagMatch($match);
                $tag = PluginUtility::getTag($match);
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $attributes->toCallStackArray(),
                    PluginUtility::PAYLOAD=>$tag
                );

            case DOKU_LEXER_UNMATCHED :
                return PluginUtility::handleAndReturnUnmatchedData(self::DOKU_TAG, $match, $handler);

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

        /**
         * The normal flow is that the `row` in a content
         * list are transformed to `content-list-item` in the {@link DOKU_LEXER_EXIT} state
         * of {@link syntax_plugin_combo_contentlist::handle()}
         *
         */
        if ($format == 'xhtml') {

            /** @var Doku_Renderer_xhtml $renderer */
            $state = $data[PluginUtility::STATE];
            switch ($state) {
                case DOKU_LEXER_ENTER :
                    $tagAttributes = TagAttributes::createFromCallStackArray($data[PluginUtility::ATTRIBUTES], self::MARKI_TAG);
                    $tagAttributes->addClassName("list-group-item");
                    $renderer->doc .= $tagAttributes->toHtmlEnterTag("li");
                    break;
                case DOKU_LEXER_EXIT :
                    $renderer->doc .= "</li>" . DOKU_LF;
                    break;
                case DOKU_LEXER_UNMATCHED :
                    $render = PluginUtility::renderUnmatched($data);
                    if (!empty($render)) {
                        $renderer->doc .= "<span>" . $render . '</span>';
                    }
                    break;
            }
            return true;
        }

        // unsupported $mode
        return false;
    }


}

