<?php


use ComboStrap\Call;
use ComboStrap\CallStack;
use ComboStrap\PluginUtility;

class action_plugin_combo_syntaxanalytics extends DokuWiki_Action_Plugin
{

    const CONF_SYNTAX_ANALYTICS_ENABLE = "syntaxAnalyticsEnable";

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
            '_extract_plugin_info',
            array()
        );

    }

    /**
     * Extract the plugin info
     * @param   $event Doku_Event
     */
    function _extract_plugin_info(&$event, $param)
    {
        $enable = PluginUtility::getConfValue(self::CONF_SYNTAX_ANALYTICS_ENABLE,true);

        if($enable) {
            /**
             * @var Doku_Handler $handler
             */
            $handler = $event->data;
            $callStack = CallStack::createFromHandler($handler);
            $callStack->moveToStart();
            $tagUsed = [];
            while ($actualCall = $callStack->next()) {
                if (in_array($actualCall->getState(), CallStack::TAG_STATE)) {
                    $tagName = $actualCall->getComponentName();
                    // The dokuwiki component name have open in their name
                    $tagName = str_replace("_open", "", $tagName);
                    if (isset($tagUsed[$tagName])) {
                        $tagUsed[$tagName] = $tagUsed[$tagName] + 1;
                    } else {
                        $tagUsed[$tagName] = 1;
                    }
                }
            }
            $pluginAnalyticsCall = Call::createComboCall(
                syntax_plugin_combo_analytics::TAG,
                DOKU_LEXER_SPECIAL,
                $tagUsed
            );
            $callStack->insertAfter($pluginAnalyticsCall);
        }

    }
}
