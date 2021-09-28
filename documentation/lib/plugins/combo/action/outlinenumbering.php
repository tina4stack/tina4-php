<?php

use ComboStrap\PluginUtility;
use ComboStrap\Snippet;

if (!defined('DOKU_INC')) die();

/**
 * Add the heading numbering snippet
 */
class action_plugin_combo_outlinenumbering extends DokuWiki_Action_Plugin
{

    const SNIPPET_ID = "outline-numbering";

    const CONF_OUTLINE_NUMBERING_COUNTER_STYLE_LEVEL2 = "outlineNumberingCounterStyleLevel2";
    const CONF_OUTLINE_NUMBERING_COUNTER_STYLE_LEVEL3 = "outlineNumberingCounterStyleLevel3";
    const CONF_OUTLINE_NUMBERING_COUNTER_STYLE_LEVEL4 = "outlineNumberingCounterStyleLevel4";
    const CONF_OUTLINE_NUMBERING_COUNTER_STYLE_LEVEL5 = "outlineNumberingCounterStyleLevel5";
    const CONF_OUTLINE_NUMBERING_COUNTER_STYLE_LEVEL6 = "outlineNumberingCounterStyleLevel6";
    const CONF_OUTLINE_NUMBERING_COUNTER_SEPARATOR = "outlineNumberingCounterSeparator";
    const CONF_OUTLINE_NUMBERING_PREFIX = "outlineNumberingPrefix";
    const CONF_OUTLINE_NUMBERING_SUFFIX = "outlineNumberingSuffix";
    const CANONICAL = "outline";
    const CONF_OUTLINE_NUMBERING_ENABLE = "outlineNumberingEnable";


    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, '_outline_numbering', array());
    }

    /**
     * As seen on
     * https://drafts.csswg.org/css-counter-styles-3/#predefined-counters
     */
    const CONF_COUNTER_STYLES_CHOICES = [
        'arabic-indic',
        'bengali',
        'cambodian/khmer',
        'cjk-decimal',
        'decimal',
        'decimal-leading-zero',
        'devanagari',
        'georgian',
        'gujarati',
        'gurmukhi',
        'hebrew',
        'hiragana',
        'hiragana-iroha',
        'kannada',
        'katakana',
        'katakana-iroha',
        'lao',
        'lower-alpha',
        'lower-armenian',
        'lower-greek',
        'lower-roman',
        'malayalam',
        'mongolian',
        'myanmar',
        'oriya',
        'persian',
        'tamil',
        'telugu',
        'thai',
        'tibetan',
        'upper-alpha',
        'upper-armenian',
        'upper-roman'
    ];

    /**
     *
     * @param $event
     */
    function _outline_numbering($event)
    {

        $enable = PluginUtility::getConfValue(self::CONF_OUTLINE_NUMBERING_ENABLE, 0);
        if ($enable) {

            global $ACT;
            if ($ACT == "preview") {
                $mainContainerSelector = ".pad";
            } else {
                $mainContainerSelector = "main";
            }
            $level2CounterStyle = PluginUtility::getConfValue(self::CONF_OUTLINE_NUMBERING_COUNTER_STYLE_LEVEL2, "decimal");
            $level3CounterStyle = PluginUtility::getConfValue(self::CONF_OUTLINE_NUMBERING_COUNTER_STYLE_LEVEL3, "decimal");
            $level4CounterStyle = PluginUtility::getConfValue(self::CONF_OUTLINE_NUMBERING_COUNTER_STYLE_LEVEL4, "decimal");
            $level5CounterStyle = PluginUtility::getConfValue(self::CONF_OUTLINE_NUMBERING_COUNTER_STYLE_LEVEL5, "decimal");
            $level6CounterStyle = PluginUtility::getConfValue(self::CONF_OUTLINE_NUMBERING_COUNTER_STYLE_LEVEL6, "decimal");
            $counterSeparator = PluginUtility::getConfValue(self::CONF_OUTLINE_NUMBERING_COUNTER_SEPARATOR, ".");
            $prefix = PluginUtility::getConfValue(self::CONF_OUTLINE_NUMBERING_PREFIX, "");
            $suffix = PluginUtility::getConfValue(self::CONF_OUTLINE_NUMBERING_SUFFIX, " - ");

            /**
             * The level counter on the toc are based
             * on the https://www.dokuwiki.org/config:toptoclevel
             * configuration
             * if toptoclevel = 2, then level1 = h2 and not h1
             */
            global $conf;
            $topTocLevel = $conf['toptoclevel'];
            $tocLevel2 = 2;
            $tocLevel3 = 3;
            $tocLevel4 = 4;
            $tocLevel5 = 5;
            $tocLevel6 = 6;
            if ($topTocLevel == "2") {
                $tocLevel2 -= 1;
                $tocLevel3 -= 1;
                $tocLevel4 -= 1;
                $tocLevel5 -= 1;
                $tocLevel6 -= 1;
            }

            $numberingCss = <<<EOF
$mainContainerSelector > h2 { counter-increment: h2; }
$mainContainerSelector > h3 { counter-increment: h3; }
$mainContainerSelector > h4 { counter-increment: h4; }
$mainContainerSelector > h5 { counter-increment: h5; }
$mainContainerSelector > h6 { counter-increment: h6; }
$mainContainerSelector > h2::before { content: "$prefix" counter(h2, $level2CounterStyle) "$suffix\A"; }
$mainContainerSelector > h3::before { content: "$prefix" counter(h2, $level2CounterStyle) "$counterSeparator" counter(h3,$level3CounterStyle) "$suffix\A"; }
$mainContainerSelector > h4::before { content: "$prefix" counter(h2, $level2CounterStyle) "$counterSeparator" counter(h3,$level3CounterStyle) "$counterSeparator" counter(h4,$level4CounterStyle) "$suffix\A"; }
$mainContainerSelector > h5::before { content: "$prefix" counter(h2, $level2CounterStyle) "$counterSeparator" counter(h3,$level3CounterStyle) "$counterSeparator" counter(h4,$level4CounterStyle) "$counterSeparator" counter(h5,$level5CounterStyle) "$suffix\A"; }
$mainContainerSelector > h6::before { content: "$prefix" counter(h2, $level2CounterStyle) "$counterSeparator" counter(h3,$level3CounterStyle) "$counterSeparator" counter(h4,$level4CounterStyle) "$counterSeparator" counter(h5,$level5CounterStyle) "$counterSeparator" counter(h6,$level6CounterStyle) "$suffix\A"; }
#dw__toc .level{$tocLevel2} { counter-increment: toc2; }
#dw__toc .level{$tocLevel3} { counter-increment: toc3; }
#dw__toc .level{$tocLevel4} { counter-increment: toc4; }
#dw__toc .level{$tocLevel5} { counter-increment: toc5; }
#dw__toc .level{$tocLevel6} { counter-increment: toc6; }
#dw__toc .level{$tocLevel2} a::before { content: "$prefix" counter(toc2, $level2CounterStyle) "$suffix\A"; }
#dw__toc .level{$tocLevel3} a::before { content: "$prefix" counter(toc2, $level2CounterStyle) "$counterSeparator" counter(toc3,$level3CounterStyle) "$suffix\A"; }
#dw__toc .level{$tocLevel4} a::before { content: "$prefix" counter(toc2, $level2CounterStyle) "$counterSeparator" counter(toc3,$level3CounterStyle) "$counterSeparator" counter(toc4,$level4CounterStyle) "$suffix\A"; }
#dw__toc .level{$tocLevel5} a::before { content: "$prefix" counter(toc2, $level2CounterStyle) "$counterSeparator" counter(toc3,$level3CounterStyle) "$counterSeparator" counter(toc4,$level4CounterStyle) "$counterSeparator" counter(toc5,$level5CounterStyle) "$suffix\A"; }
#dw__toc .level{$tocLevel6} a::before { content: "$prefix" counter(toc2, $level2CounterStyle) "$counterSeparator" counter(toc3,$level3CounterStyle) "$counterSeparator" counter(toc4,$level4CounterStyle) "$counterSeparator" counter(toc5,$level5CounterStyle) "$counterSeparator" counter(toc6,$level6CounterStyle) "$suffix\A"; }
EOF;


            PluginUtility::getSnippetManager()->upsertCssSnippetForRequest(self::SNIPPET_ID, $numberingCss);
        }
    }


}
