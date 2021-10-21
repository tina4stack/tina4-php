<?php

// implementation of
// https://developer.mozilla.org/en-US/docs/Web/HTML/Element/cite

// must be run within Dokuwiki
use ComboStrap\Bootstrap;
use ComboStrap\CallStack;
use ComboStrap\MediaLink;
use ComboStrap\LogUtility;
use ComboStrap\PluginUtility;
use ComboStrap\Tag;
use ComboStrap\TagAttributes;


require_once(__DIR__ . '/../ComboStrap/HeaderUtility.php');

if (!defined('DOKU_INC')) die();


class syntax_plugin_combo_label extends DokuWiki_Syntax_Plugin
{


    const TAG = "label";

    /**
     * The id of the heading element for a accordion label
     */
    const HEADING_ID = "headingId";
    /**
     * The id of the collapsable target
     */
    const TARGET_ID = "targetId";

    /**
     * An indicator attribute that tells if the accordion is collpased or not
     */
    const COLLAPSED = "collapsed";

    function getType()
    {
        return 'formatting';
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
        return 'block';
    }

    function getAllowedTypes()
    {
        return array('substition', 'formatting', 'disabled');
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
                $tagAttributes = PluginUtility::getTagAttributes($match);

                $tag = new Tag(self::TAG, $tagAttributes, $state, $handler);
                $parentTag = $tag->getParent();
                $context = null;
                if ($parentTag != null) {
                    $grandfather = $parentTag->getParent();
                    if ($grandfather != null) {
                        $grandFatherName = $grandfather->getName();
                        switch ($grandFatherName) {
                            case syntax_plugin_combo_accordion::TAG:
                                $id = $parentTag->getAttribute("id");
                                $tagAttributes["id"] = $id;
                                $tagAttributes[self::HEADING_ID] = "heading" . ucfirst($id);
                                $tagAttributes[self::TARGET_ID] = "collapse" . ucfirst($id);
                                $parentAttribute = $parentTag->getAttributes();
                                if (!key_exists(self::COLLAPSED, $parentAttribute)) {
                                    // Accordion are collapsed by default
                                    $tagAttributes[self::COLLAPSED] = "true";
                                } else {
                                    $tagAttributes[self::COLLAPSED] = $parentAttribute[self::COLLAPSED];
                                }
                                $context = syntax_plugin_combo_accordion::TAG;
                                break;
                            case  syntax_plugin_combo_tabs::TAG:
                                $context = syntax_plugin_combo_tabs::TAG;
                                $tagAttributes = $parentTag->getAttributes();
                                break;
                            default:
                                LogUtility::log2FrontEnd("The label is included in the $grandFatherName component and this is unexpected", LogUtility::LVL_MSG_WARNING, self::TAG);
                        }
                    } else {
                        /**
                         * An panel may render alone in preview
                         */
                        if ($parentTag->getContext() == syntax_plugin_combo_panel::CONTEXT_PREVIEW_ALONE) {
                            $context = syntax_plugin_combo_panel::CONTEXT_PREVIEW_ALONE;
                        }
                    }
                }

                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::ATTRIBUTES => $tagAttributes,
                    PluginUtility::CONTEXT => $context
                );

            case DOKU_LEXER_UNMATCHED :
                return PluginUtility::handleAndReturnUnmatchedData(self::TAG, $match, $handler);

            case DOKU_LEXER_EXIT :
                $callStack = CallStack::createFromHandler($handler);
                $openingTag = $callStack->moveToPreviousCorrespondingOpeningCall();
                $context = $openingTag->getContext();

                /**
                 * An image in a label should have no link (ie no anchor)
                 * because a anchor is used for navigation
                 */
                $callStack->processNoLinkOnImageToEndStack();

                $callStack->closeAndResetPointer();


                return array(
                    PluginUtility::STATE => $state,
                    PluginUtility::CONTEXT => $context,
                    PluginUtility::ATTRIBUTES => $openingTag->getAttributes()
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

                case DOKU_LEXER_ENTER:

                    $context = $data[PluginUtility::CONTEXT];
                    switch ($context) {
                        case syntax_plugin_combo_accordion::TAG:
                            $attribute = $data[PluginUtility::ATTRIBUTES];
                            $headingId = $attribute[self::HEADING_ID];
                            $collapseId = $attribute[self::TARGET_ID];
                            $collapsed = $attribute[self::COLLAPSED];
                            if ($collapsed == "false") {
                                $collapsedClass = "collapsed";
                            } else {
                                $collapsedClass = "";
                            }
                            $renderer->doc .= "<div class=\"card-header\" id=\"$headingId\">" . DOKU_LF;
                            $renderer->doc .= "<h2 class=\"mb-0\">";
                            $dataNamespace = Bootstrap::getDataNamespace();
                            $renderer->doc .= "<button class=\"btn btn-link btn-block text-left $collapsedClass\" type=\"button\" data{$dataNamespace}-toggle=\"collapse\" data{$dataNamespace}-target=\"#$collapseId\" aria-expanded=\"true\" aria-controls=\"$collapseId\">";
                            break;
                        case syntax_plugin_combo_tabs::TAG:
                            $attributes = $data[PluginUtility::ATTRIBUTES];
                            $renderer->doc .= syntax_plugin_combo_tabs::openNavigationalTabElement($attributes);
                            break;
                        case syntax_plugin_combo_panel::CONTEXT_PREVIEW_ALONE:
                            $attributes = syntax_plugin_combo_panel::CONTEXT_PREVIEW_ALONE_ATTRIBUTES;
                            $renderer->doc .= "<ul style=\"list-style-type: none;padding-inline-start: 0;\">";
                            $renderer->doc .= syntax_plugin_combo_tabs::openNavigationalTabElement($attributes);
                            break;
                        default:
                            LogUtility::log2FrontEnd("The context ($context) of the label is unknown in enter", LogUtility::LVL_MSG_WARNING, self::TAG);

                    }
                    break;

                case DOKU_LEXER_UNMATCHED :
                    $renderer->doc .= PluginUtility::renderUnmatched($data);
                    break;

                case DOKU_LEXER_EXIT:
                    $context = $data[PluginUtility::CONTEXT];
                    switch ($context) {
                        case syntax_plugin_combo_accordion::TAG:
                            $attribute = $data[PluginUtility::ATTRIBUTES];
                            $collapseId = $attribute[self::TARGET_ID];
                            $headingId = $attribute[self::HEADING_ID];
                            $collapsed = $attribute[self::COLLAPSED];
                            if ($collapsed == "false") {
                                $showClass = "show";
                            } else {
                                $showClass = "";
                            }
                            $renderer->doc .= "</button></h2></div>";
                            $dataNamespace = Bootstrap::getDataNamespace();
                            $renderer->doc .= "<div id=\"$collapseId\" class=\"collapse $showClass\" aria-labelledby=\"$headingId\" data-{$dataNamespace}parent=\"#$headingId\">";
                            $renderer->doc .= "<div class=\"card-body\">" . DOKU_LF;
                            break;
                        case syntax_plugin_combo_tabs::TAG:
                            $renderer->doc .= syntax_plugin_combo_tabs::closeNavigationalTabElement();
                            break;
                        case syntax_plugin_combo_panel::CONTEXT_PREVIEW_ALONE:
                            $renderer->doc .= syntax_plugin_combo_tabs::closeNavigationalTabElement();
                            $renderer->doc .= "</ul>";
                            break;
                        default:
                            LogUtility::log2FrontEnd("The context ($context) of the label is unknown in exit", LogUtility::LVL_MSG_WARNING, self::TAG);


                    }
                    break;


            }
        }
        // unsupported $mode
        return false;
    }


}

