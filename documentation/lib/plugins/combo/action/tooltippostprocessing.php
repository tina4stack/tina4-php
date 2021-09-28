<?php


use ComboStrap\CallStack;

require_once(__DIR__ . '/../class/PluginUtility.php');

/**
 * Because of the automatic processing of p paragraph via {@link \dokuwiki\Parsing\Handler\Block::process()}
 * We get a closing p before and an opening p after
 *
 * We delete them
 *
 */
class action_plugin_combo_tooltippostprocessing extends DokuWiki_Action_Plugin
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
            '_post_process_tooltip',
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
    function _post_process_tooltip(&$event, $param)
    {
        /**
         * @var Doku_Handler $handler
         *
         * Due to the {@link \dokuwiki\Parsing\Handler\Block::process() block processing}
         *
         * When a tooltip is for a block, in the tooltip, in the content (ie title) we may get the following
         *
         * </p><h3>Title<h3><p>Hallo
         *
         * We transform it
         *
         * <h3>Title<h3><p>Hallo</p>
         *
         */
        $handler = $event->data;
        $status = $handler->getStatus(syntax_plugin_combo_tooltip::TOOLTIP_FOUND);
        if ($status === true) {

            $callStack = CallStack::createFromHandler($handler);
            $callStack->moveToStart();

            $inTooltip = false;
            $inTooltipPassedPElement = false;
            $closedPCall = null;
            while ($actualCall = $callStack->next()) {

                if ($actualCall->getTagName() == syntax_plugin_combo_tooltip::TAG) {
                    switch ($actualCall->getState()) {
                        case DOKU_LEXER_ENTER:
                            $inTooltip = true;
                            continue 2;
                        case DOKU_LEXER_EXIT:
                            if ($closedPCall != null) {
                                $callStack->insertBefore($closedPCall);
                            }
                            $inTooltip = false;
                            $inTooltipPassedPElement = false;
                            $closedPCall = null;
                            continue 2;
                    }
                }
                if ($inTooltip) {
                    if (!(in_array($actualCall->getTagName(), ["cdata", "p"]))) {
                        $inTooltipPassedPElement = true;
                    } else {
                        if (!$inTooltipPassedPElement) {
                            if ($actualCall->getTagName() == "p" && $actualCall->getState() == DOKU_LEXER_EXIT) {
                                $closedPCall = $callStack->deleteActualCallAndPrevious();
                            }
                        }
                    }
                }

            }

        }
    }


}
