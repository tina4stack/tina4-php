<?php


// must be run within Dokuwiki
use ComboStrap\CallStack;
use ComboStrap\Dimension;
use ComboStrap\PluginUtility;
use ComboStrap\TagAttributes;
use ComboStrap\TextAlign;

if (!defined('DOKU_INC')) die();

/**
 * Class syntax_plugin_combo_text
 * A text block that permits to style
 * paragraph at once
 *
 * The output will be a series of {@link syntax_plugin_combo_para paragraph}
 * with the same properties
 *
 * It permits to have several paragraph
 */
class syntax_plugin_combo_text extends DokuWiki_Syntax_Plugin
{

    const TAG = "text";
    const TAGS = ["typo", self::TAG];

    /**
     * Syntax Type.
     *
     * Needs to return one of the mode types defined in {@link $PARSER_MODES} in parser.php
     * @see DokuWiki_Syntax_Plugin::getType()
     */
    function getType()
    {
        return 'paragraphs';
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
        return array('formatting', 'substition', 'paragraphs');
    }

    public function accepts($mode)
    {

        return syntax_plugin_combo_preformatted::disablePreformatted($mode);

    }


    function getSort()
    {
        return 201;
    }


    function connectTo($mode)
    {

        foreach (self::TAGS as $tag) {
            $pattern = PluginUtility::getContainerTagPattern($tag);
            $this->Lexer->addEntryPattern($pattern, $mode, PluginUtility::getModeFromTag($this->getPluginComponent()));
        }
    }


    function postConnect()
    {
        foreach (self::TAGS as $tag) {
            $this->Lexer->addExitPattern('</' . $tag . '>', PluginUtility::getModeFromTag($this->getPluginComponent()));
        }

    }

    function handle($match, $state, $pos, Doku_Handler $handler)
    {

        switch ($state) {

            case DOKU_LEXER_ENTER :
                $attributes = TagAttributes::createFromTagMatch($match);
                $callStackArray = $attributes->toCallStackArray();

                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $callStackArray
                );

            case DOKU_LEXER_UNMATCHED :
                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);

            case DOKU_LEXER_EXIT :
                /**
                 * Transform all paragraphs
                 * with the type as class
                 */
                $callStack = CallStack::createFromHandler($handler);
                $openingCall = $callStack->moveToPreviousCorrespondingOpeningCall();
                $attributes = $openingCall->getAttributes();
                // if there is no EOL, we add one to create at minimal a paragraph
                $callStack->insertEolIfNextCallIsNotEolOrBlock();
                $callStack->processEolToEndStack($attributes);

                /**
                 * Check and add a scroll toggle if the
                 * text is constrained by height
                 */
                Dimension::addScrollToggleOnClickIfNoControl($callStack);

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
                case DOKU_LEXER_EXIT :
                case DOKU_LEXER_ENTER :
                    /**
                     * The {@link DOKU_LEXER_EXIT} of the {@link syntax_plugin_combo_text::handle()}
                     * has already created in the callstack the {@link syntax_plugin_combo_para} call
                     */
                    $renderer->doc .= "";
                    break;
                case DOKU_LEXER_UNMATCHED :
                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;
            }
            return true;
        }

        // unsupported $mode
        return false;
    }


}

