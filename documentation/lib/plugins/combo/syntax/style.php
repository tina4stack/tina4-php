<?php

// implementation of
// https://developer.mozilla.org/en-US/docs/Web/HTML/Element/cite

// must be run within Dokuwiki
use ComboStrap\LogUtility;
use ComboStrap\PluginUtility;
use ComboStrap\Tag;
use ComboStrap\TitleUtility;

require_once(__DIR__ . '/../class/HeaderUtility.php');

if (!defined('DOKU_INC')) die();


class syntax_plugin_combo_style extends DokuWiki_Syntax_Plugin
{


    const TAG = "style";
    const STATE_ATTRIBUTE = "state";

    /**
     * @var int an id counter to add unique id on the page
     */
    private $idCounter = 0;

    /**
     * You can't write in a style block
     */
    function getType()
    {
        return 'protected';
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

    function getAllowedTypes()
    {
        return array();
    }

    function getSort()
    {
        return 201;
    }


    function connectTo($mode)
    {

        $this->Lexer->addEntryPattern(PluginUtility::getContainerTagPattern(self::TAG), $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
    }

    public function postConnect()
    {
        $this->Lexer->addExitPattern('</' . self::TAG . '>', PluginUtility::getModeFromTag($this->getPluginComponent()));
    }

    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_ENTER:
                $attributes = PluginUtility::getTagAttributes($match);
                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $attributes
                );
            case DOKU_LEXER_UNMATCHED :
                $tag = new Tag(self::TAG, array(), DOKU_LEXER_UNMATCHED, $handler);
                $parent = $tag->getParent();
                $stateAttribute = $parent->getAttribute(self::STATE_ATTRIBUTE);
                $opa = $parent->getParent();
                if (!empty($opa)) {
                    $stateSelector = "";
                    if (!empty($stateAttribute)) {
                        $stateSelector = ":" . $stateAttribute;
                    }
                    /**
                     * Id of the snippet
                     */
                    $this->idCounter++;
                    $styleId = self::TAG . "-" . $this->idCounter;

                    /**
                     * Id of the element for the CSS rule
                     */
                    $id = $opa->getAttribute("id");
                    if (empty($id)) {
                        $id = "combo-" . $styleId;
                        $opa->setAttribute("id", $id);
                    }

                    /**
                     * The css
                     */
                    $css = <<<EOF
#{$id}$stateSelector {
    $match
}
EOF;
                    PluginUtility::getSnippetManager()->upsertCssSnippetForBar($styleId, $css);

                }
                /**
                 * State is mandatory when we traverse the stack
                 * with {@link Tag}
                 */
                return array(
                    PluginUtility::STATE => $state
                );
                break;

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

        // nothing to do
        return false;
    }


}

