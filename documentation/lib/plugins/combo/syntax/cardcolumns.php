<?php
/**
 * DokuWiki Syntax Plugin Combostrap.
 *
 */

use ComboStrap\Bootstrap;
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
 */
class syntax_plugin_combo_cardcolumns extends DokuWiki_Syntax_Plugin
{

    /**
     * The Tag constant should be the exact same last name of the class
     * This is how we recognize a tag in the {@link \ComboStrap\CallStack}
     */
    const TAG = "cardcolumns";

    /**
     * The syntax tags
     */
    const SYNTAX_TAG_COLUMNS = "card-columns";
    const SYNTAX_TAG_TEASER = 'teaser-columns';

    /**
     * Same as commercial
     * https://isotope.metafizzy.co/
     *
     */
    const MASONRY = "masonry";

    /**
     * @param $renderer
     * @param $context Doku_Renderer
     *
     * Bootstrap five does not include masonry
     * directly, we need to add a column around the children {@link syntax_plugin_combo_card} and
     * {@link syntax_plugin_combo_blockquote}
     * https://getbootstrap.com/docs/5.0/examples/masonry/
     *
     * The column is open with the function {@link syntax_plugin_combo_cardcolumns::endColIfBootstrap5AndCardColumns()}
     *
     * TODO: do it programmatically by adding call with {@link \ComboStrap\CallStack}
     */
    public static function addColIfBootstrap5AndCardColumns(&$renderer, $context)
    {
        $bootstrapVersion = Bootstrap::getBootStrapMajorVersion();
        if ($bootstrapVersion == Bootstrap::BootStrapFiveMajorVersion && $context == syntax_plugin_combo_cardcolumns::TAG) {
            $renderer->doc .= '<div class="col-sm-6 col-lg-4 mb-4">';
        }
    }

    /**
     * In Bootstrap5, to support card-columns, we need masonry javascript and
     * a column
     * We close it as seen here:
     * https://getbootstrap.com/docs/5.0/examples/masonry/
     *
     * The column is open with the function {@link syntax_plugin_combo_cardcolumns::addColIfBootstrap5AndCardColumns()}
     * @param Doku_Renderer $renderer
     * @param $context
     */
    public static function endColIfBootstrap5AnCardColumns(Doku_Renderer $renderer, $context)
    {

        if ($context == syntax_plugin_combo_cardcolumns::TAG) {
            /**
             * Bootstrap five does not include masonry
             * directly, we need to add a column
             * and we close it here
             */
            $bootstrapVersion = Bootstrap::getBootStrapMajorVersion();
            if ($bootstrapVersion == Bootstrap::BootStrapFiveMajorVersion) {
                $renderer->doc .= '</div>';
            }

        }

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
    public
    function getAllowedTypes()
    {
        return array('container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs');
    }

    public
    function accepts($mode)
    {

        return syntax_plugin_combo_preformatted::disablePreformatted($mode);

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
        foreach (self::getSyntaxTags() as $tag) {
            $pattern = '<' . $tag . '.*?>(?=.*?</' . $tag . '>)';
            $this->Lexer->addEntryPattern($pattern, $mode, 'plugin_' . PluginUtility::PLUGIN_BASE_NAME . '_' . $this->getPluginComponent());
        }

    }

    public
    function postConnect()
    {
        foreach (self::getSyntaxTags() as $tag) {
            $this->Lexer->addExitPattern('</' . $tag . '>', 'plugin_' . PluginUtility::PLUGIN_BASE_NAME . '_' . $this->getPluginComponent());
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

                // Suppress the <>
                $match = substr($match, 1, -1);
                // Suppress the tag name
                foreach (self::getSyntaxTags() as $tag) {
                    $match = str_replace($tag, "", $match);
                }
                $parameters = PluginUtility::parseAttributes($match);
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $parameters
                );

            case DOKU_LEXER_UNMATCHED:
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

        if ($format == 'xhtml') {

            /** @var Doku_Renderer_xhtml $renderer */
            $state = $data[PluginUtility::STATE];
            switch ($state) {

                case DOKU_LEXER_ENTER :
                    $bootstrapVersion = Bootstrap::getBootStrapMajorVersion();
                    switch ($bootstrapVersion) {
                        case 5:
                            // No support for 5, we use Bs with their example
                            // https://getbootstrap.com/docs/5.0/examples/masonry/
                            // https://masonry.desandro.com/layout.html#responsive-layouts
                            // https://masonry.desandro.com/extras.html#bootstrap
                            // https://masonry.desandro.com/#initialize-with-vanilla-javascript
                            PluginUtility::getSnippetManager()->upsertTagsForBar(self::MASONRY,
                                array(
                                    "script" => [
                                        array(
                                            "src" => "https://cdn.jsdelivr.net/npm/masonry-layout@4.2.2/dist/masonry.pkgd.min.js",
                                            "integrity" => "sha384-GNFwBvfVxBkLMJpYMOABq3c+d3KnQxudP/mGPkzpZSTYykLBNsZEnG2D9G/X/+7D",
                                            "crossorigin" => "anonymous",
                                            "async" => true
                                        )
                                    ]
                                )
                            );
                            PluginUtility::getSnippetManager()->attachJavascriptSnippetForBar(self::MASONRY);
                            $masonryClass = self::MASONRY;
                            $renderer->doc .= "<div class=\"row $masonryClass\">";
                            break;
                        default:
                            $renderer->doc .= '<div class="card-columns">' . DOKU_LF;
                            break;
                    }
                    break;

                case DOKU_LEXER_UNMATCHED:
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


    public
    static function getSyntaxTags()
    {

        return array(self::SYNTAX_TAG_COLUMNS, self::SYNTAX_TAG_TEASER);
    }


}
