<?php


// must be run within Dokuwiki
use ComboStrap\PluginUtility;
use ComboStrap\TagAttributes;

if (!defined('DOKU_INC')) die();


class syntax_plugin_combo_brand extends DokuWiki_Syntax_Plugin
{

    const TAG = "brand";

    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in $PARSER_MODES in parser.php
     * @see DokuWiki_Syntax_Plugin::getType()
     */
    function getType()
    {
        return 'formatting';
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
        return 'normal';
    }

    /**
     * @return array
     * Allow which kind of plugin inside
     *
     * array('container', 'baseonly', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs')
     *
     */
    function getAllowedTypes()
    {
        return array('container', 'baseonly', 'formatting', 'substition', 'protected', 'disabled');
    }

    function getSort()
    {
        return 201;
    }

    public function accepts($mode)
    {
        return syntax_plugin_combo_preformatted::disablePreformatted($mode);
    }


    /**
     * Create a pattern that will called this plugin
     *
     * @param string $mode
     * @see Doku_Parser_Mode::connectTo()
     */
    function connectTo($mode)
    {

        $pattern = PluginUtility::getContainerTagPattern(self::getTag());
        $this->Lexer->addEntryPattern($pattern, $mode, 'plugin_' . PluginUtility::PLUGIN_BASE_NAME . '_' . $this->getPluginComponent());

    }

    function postConnect()
    {

        $this->Lexer->addExitPattern('</' . self::getTag() . '>', 'plugin_' . PluginUtility::PLUGIN_BASE_NAME . '_' . $this->getPluginComponent());

    }

    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_ENTER :
                $defaultParameters = [];
                global $conf;
                $title = $conf['title'];
                if (!empty($title)) {
                    $defaultParameters["title"] = $title;
                }
                $parameters = PluginUtility::getTagAttributes($match);
                $parameters = PluginUtility::mergeAttributes($parameters, $defaultParameters);
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $parameters
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
                    $parameters = $data[PluginUtility::ATTRIBUTES];
                    $tagAttributes = TagAttributes::createFromCallStackArray($parameters);
                    $tagAttributes->addHtmlAttributeValue("href", wl());
                    $tagAttributes->addHtmlAttributeValue("accesskey", "h");
                    $tagAttributes->addClassName("navbar-brand");

                    $renderer->doc .= $tagAttributes->toHtmlEnterTag("a");
                    break;

                case DOKU_LEXER_UNMATCHED :
                    // What about:
                    //   * the title of the website ? $conf['title']
                    //   * the logo ? $logo = tpl_getMediaFile(array(':wiki:logo.png', ':logo.png', 'images/logo.png'), false, $logoSize);
                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;

                case DOKU_LEXER_EXIT :
                    $renderer->doc .= '</a>';
                    break;
            }
            return true;
        }

        // unsupported $mode
        return false;
    }

    public static function getTag()
    {
        list(/* $t */, /* $p */, /* $n */, $c) = explode('_', get_called_class(), 4);
        return (isset($c) ? $c : '');
    }

}

