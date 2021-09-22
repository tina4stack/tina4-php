<?php


use ComboStrap\Call;
use ComboStrap\CallStack;
use ComboStrap\LogUtility;
use ComboStrap\MediaLink;
use ComboStrap\PluginUtility;

class action_plugin_combo_headingpostprocessing extends DokuWiki_Action_Plugin
{


    public function register(\Doku_Event_Handler $controller)
    {
        /**
         * Found in {@link Doku_Handler::finalize()}
         *
         * Doc: https://www.dokuwiki.org/devel:event:parser_handler_done
         */
        $controller->register_hook(
            'PARSER_HANDLER_DONE',
            'AFTER',
            $this,
            '_post_process_heading',
            array()
        );

    }


    /**
     * Transform the special heading atx call
     * in an enter and exit heading atx calls
     *
     * Code extracted and adapted from the end of {@link Doku_Handler::header()}
     *
     * @param   $event Doku_Event
     */
    function _post_process_heading(&$event, $param)
    {
        /**
         * @var Doku_Handler $handler
         */
        $handler = $event->data;
        $callStack = CallStack::createFromHandler($handler);
        $callStack->moveToStart();

        /**
         * Close the section
         * for whatever reason, the section status is true
         * even if the sections are closed
         * We take the hypothesis that the sections are closed
         */
        $handler->setStatus('section', false);

        /**
         * Processing variable about the context
         */
        $actualHeadingParsingState = DOKU_LEXER_EXIT; // enter if we have entered a heading, exit otherwise
        $actualSectionState = null; // enter if we have created a section
        $headingEnterCall = null; // the enter call
        $lastEndPosition = null; // the last end position to close the section if any
        $headingText = ""; // text only content in the heading
        $headingComboCounter = 0; // The number of combo heading found (The first one that is not the first one should close)
        $headingTotalCounter = 0; // The number of combo heading found (The first one that is not the first one should close)
        while ($actualCall = $callStack->next()) {


            $tagName = $actualCall->getTagName();
            if (
                ($lastEndPosition != null && $actualCall->getFirstMatchedCharacterPosition() >= $lastEndPosition)
                || $lastEndPosition == null
            ) {
                // p_open and p_close have always a position value of 0, we filter them
                $lastEndPosition = $actualCall->getLastMatchedCharacterPosition();
            }

            /**
             * Enter
             */
            switch ($tagName) {
                case syntax_plugin_combo_headingatx::TAG:
                    $actualCall->setState(DOKU_LEXER_ENTER);
                    $actualHeadingParsingState = DOKU_LEXER_ENTER;
                    $headingEnterCall = $callStack->getActualCall();
                    $headingComboCounter++;
                    $headingTotalCounter++;
                    self::closeSectionIfNeeded($actualCall, $handler, $callStack, $actualSectionState, $headingComboCounter, $headingTotalCounter);
                    continue 2;
                case syntax_plugin_combo_heading::TAG:
                case syntax_plugin_combo_headingwiki::TAG:
                    if ($actualCall->getState() == DOKU_LEXER_ENTER) {
                        $actualHeadingParsingState = DOKU_LEXER_ENTER;
                        $headingEnterCall = $callStack->getActualCall();
                        $headingComboCounter++;
                        $headingTotalCounter++;
                        self::closeSectionIfNeeded($actualCall, $handler, $callStack, $actualSectionState, $headingComboCounter, $headingTotalCounter);
                        continue 2;
                    }
                    break;
                case "header":
                    $headingTotalCounter++;
                    break;
            }


            /**
             * Close and Inside the heading description
             */
            if ($actualHeadingParsingState == DOKU_LEXER_ENTER) {

                switch ($actualCall->getTagName()) {

                    case syntax_plugin_combo_heading::TAG:
                    case syntax_plugin_combo_headingwiki::TAG:
                        if ($actualCall->getState() == DOKU_LEXER_EXIT) {
                            self::insertOpenSectionAfterAndCloseHeadingParsingStateAndNext(
                                $headingEnterCall,
                                $handler,
                                $callStack,
                                $actualSectionState,
                                $headingText,
                                $actualHeadingParsingState
                            );
                        } else {
                            // unmatched
                            self::addToTextHeading($headingText, $actualCall);
                        }
                        continue 2;

                    case "internalmedia":
                        // no link for media in heading
                        $actualCall->getCall()[1][6] = MediaLink::LINKING_NOLINK_VALUE;
                        continue 2;

                    case "header":
                        if (PluginUtility::getConfValue(syntax_plugin_combo_headingwiki::CONF_WIKI_HEADING_ENABLE, syntax_plugin_combo_headingwiki::CONF_DEFAULT_WIKI_ENABLE_VALUE) == 1) {
                            LogUtility::msg("The combo heading wiki is enabled, we should not see `header` calls in the call stack");
                        }
                        break;

                    case syntax_plugin_combo_media::TAG:
                        // no link for media in heading
                        $actualCall->addAttribute(MediaLink::LINKING_KEY, MediaLink::LINKING_NOLINK_VALUE);
                        continue 2;

                    default:
                        self::addToTextHeading($headingText, $actualCall);
                        continue 2;

                    case "p":
                        if ($headingEnterCall->getTagName() == syntax_plugin_combo_headingatx::TAG) {

                            /**
                             * Delete the p_enter / close
                             */
                            $callStack->deleteActualCallAndPrevious();

                            /**
                             * If this was a close tag
                             */
                            if ($actualCall->getComponentName() == "p_close") {

                                $callStack->next();

                                /**
                                 * Create the exit call
                                 * and open the section
                                 * Code extracted and adapted from the end of {@link Doku_Handler::header()}
                                 */
                                $callStack->insertBefore(
                                    Call::createComboCall(
                                        syntax_plugin_combo_headingatx::TAG,
                                        DOKU_LEXER_EXIT,
                                        $headingEnterCall->getAttributes()
                                    )
                                );
                                $callStack->previous();

                                /**
                                 * Close and section
                                 */
                                self::insertOpenSectionAfterAndCloseHeadingParsingStateAndNext(
                                    $headingEnterCall,
                                    $handler,
                                    $callStack,
                                    $actualSectionState,
                                    $headingText,
                                    $actualHeadingParsingState
                                );


                            }

                        }
                        continue 2;


                }


            }
            /**
             * when a heading of dokuwiki is mixed with
             * an atx heading, there is already a section close
             * at the end or in the middle
             */
            if ($actualCall->getComponentName() == "section_close") {
                $actualSectionState = DOKU_LEXER_EXIT;
            }
        }

        /**
         * If the section was open by us or is still open, we close it
         *
         * We don't use the standard `section` key (ie  $handler->getStatus('section')
         * because it's open when we receive the handler
         * even if the `section_close` is present in the call stack
         *
         * We make sure that we close only what we have open
         */
        if ($actualSectionState == DOKU_LEXER_ENTER) {
            $handler->setStatus('section', false);
            $callStack->insertAfter(
                Call::createNativeCall(
                    'section_close',
                    array(),
                    $lastEndPosition
                )
            );
        }


    }

    /**
     * @param $headingEntryCall
     * @param $handler
     * @param CallStack $callStack
     * @param $actualSectionState
     * @param $headingText
     * @param $actualHeadingParsingState
     */
    private static function insertOpenSectionAfterAndCloseHeadingParsingStateAndNext(&$headingEntryCall, &$handler, &$callStack, &$actualSectionState, &$headingText, &$actualHeadingParsingState)
    {
        /**
         * We are no more in a heading
         */
        $actualHeadingParsingState = DOKU_LEXER_EXIT;


        /**
         * Outline ?
         * Update the text and open a section
         */
        if ($headingEntryCall->getContext() == syntax_plugin_combo_heading::TYPE_OUTLINE) {

            /**
             * Update the entering call with the text capture
             */
            /**
             * Check the text
             */
            if (empty($headingText)) {
                LogUtility::msg("The heading text for the entry call ($headingEntryCall) is empty");
            }
            $headingEntryCall->addAttribute(syntax_plugin_combo_heading::HEADING_TEXT_ATTRIBUTE, $headingText);

            $headingText = "";

            $callStack->insertAfter(
                Call::createNativeCall(
                    'section_open',
                    array($headingEntryCall->getAttribute(syntax_plugin_combo_headingatx::LEVEL)),
                    $headingEntryCall->getFirstMatchedCharacterPosition()
                )
            );
            $handler->setStatus('section', true);
            $actualSectionState = DOKU_LEXER_ENTER;
            $callStack->next();

        }
    }

    private static function closeSectionIfNeeded(&$actualCall, &$handler, &$callStack, &$actualSectionState, $headingComboCounter, $headingTotalCounter)
    {
        if ($actualCall->getContext() == syntax_plugin_combo_heading::TYPE_OUTLINE) {
            $close = $handler->getStatus('section');
            if ($headingComboCounter == 1 && $headingTotalCounter != 1) {
                /**
                 * If this is the first combo heading
                 * We need to close the previous to open
                 * this one
                 */
                $close = true;
            }
            if ($close) {
                $callStack->insertBefore(
                    Call::createNativeCall(
                        'section_close',
                        array(),
                        $actualCall->getLastMatchedCharacterPosition()
                    )
                );
                $actualSectionState = DOKU_LEXER_EXIT;
                $handler->setStatus('section', false);
            }
        }
    }

    /**
     * @param $headingText
     * @param Call $call
     */
    private static function addToTextHeading(&$headingText, $call)
    {
        if ($call->isTextCall()) {
            // Building the text for the toc
            // only cdata for now
            // no image, ...
            if ($headingText != "") {
                $headingText .= " ";
            }
            $headingText .= trim($call->getCapturedContent());
        }
    }
}
