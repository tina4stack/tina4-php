<?php

use ComboStrap\SnippetManager;
use ComboStrap\LogUtility;
use ComboStrap\PluginUtility;

if (!defined('DOKU_INC')) die();
require_once(__DIR__ . '/../class/PluginUtility.php');


/**
 * Class syntax_plugin_combo_math
 */
class syntax_plugin_combo_math extends DokuWiki_Syntax_Plugin
{

    const TAG = "math";


    /**
     * syntax_plugin_combo_math constructor.
     */
    public function __construct()
    {
        LogUtility::msg("The math syntax object was instantiated", LogUtility::LVL_MSG_DEBUG);
    }


    /**
     * Syntax Type
     *
     * Protected in order to say that we don't want it to be modified
     * The mathjax javascript will take care of the rendering
     *
     * @return string
     */
    public function getType()
    {
        return 'substition';
    }

    /**
     * @return array
     * Allow which kind of plugin inside
     *
     * No one of array('container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs')
     * because we manage self the content and we call self the parser
     */
    public function getAllowedTypes()
    {
        return array();
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
     *
     * @return int
     */
    public function getSort()
    {
        return 195;
    }

    /**
     *
     * @param string $mode
     */
    public function connectTo($mode)
    {

        // Add the entry patterns
        foreach (self::getTags() as $element) {

            $pattern = PluginUtility::getLeafContainerTagPattern($element);
            $this->Lexer->addSpecialPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));

        }


    }


    /**
     *
     * @param string $match The text matched by the patterns
     * @param int $state The lexer state for the match
     * @param int $pos The character position of the matched text
     * @param Doku_Handler $handler The Doku_Handler object
     * @return  array Return an array with all data you want to use in render
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {

        return array($match);
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

        list($content) = $data;
        switch ($format) {
            case 'xhtml':
            case 'odt':
                /** @var Doku_Renderer_xhtml $renderer */
                $renderer->doc .= $renderer->_xmlEntities($content) . DOKU_LF;

                /**
                 * CSS
                 */
                PluginUtility::getSnippetManager()->upsertCssSnippetForBar(self::TAG);

                /**
                 * Javascript config
                 */
                $headHtmlElement = <<<EOD
MathJax.Hub.Config({
    showProcessingMessages: true,
    extensions: ["tex2jax.js","TeX/AMSmath.js","TeX/AMSsymbols.js"],
    jax: ["input/TeX", "output/HTML-CSS"],
    tex2jax: {
        inlineMath: [ ["<math>","</math>"]],
        displayMath: [ ["<MATH>","</MATH>"] ],
        processEscapes: true,
        scale:120
    },
    "HTML-CSS": { fonts: ["TeX"] }
});
EOD;

                PluginUtility::getSnippetManager()->upsertTagsForBar(self::TAG,
                    array("script" => [
                        array(
                            "type" => "text/x-mathjax-config",
                            "_data" => $headHtmlElement
                        ),
                        array(
                            "type" => "text/javascript",
                            "src" => "https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.5/latest.js",
                            "async" => true
                        )
                    ])
                );

                break;

            case 'latexport':
                // Pass math expressions to latexport renderer
                $renderer->mathjax_content($content);
                break;

            default:
                $renderer->doc .= $renderer->$data;
                break;

        }

        return true;

    }

    static public function getTags()
    {
        return PluginUtility::getTags(get_called_class());
    }

    public static function getComponentName()
    {
        return PluginUtility::getTagName(get_called_class());
    }

}

