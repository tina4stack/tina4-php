<?php
/**
 * Folded text Plugin, header component:
 * Render headers included in folded blocks.
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     LarsDW223
 */

/**
 * This class handles header syntax inside folded blocks.
 * A found header syntax will be rendered as an html header element but
 * is not a DokuWiki header (no section edit, do not appear in toc).
 */
class syntax_plugin_folded_header extends DokuWiki_Syntax_Plugin {

    function getType(){ return 'formatting'; }
    function getPType() { return 'block'; }
    function getSort(){ return 50; }
    function connectTo($mode) {
        if ($mode != 'plugin_folded_div') return;

        // Copied from parser: we're not picky about the closing ones, two are enough
        $this->Lexer->addSpecialPattern(
                            '[ \t]*={2,}[^\n]+={2,}[ \t]*(?=\n)',
                            $mode,
                            'plugin_folded_header'
                        );
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, Doku_Handler $handler){
        // Copied from parser: get level and title
        $title = trim($match);
        $level = 7 - strspn($title,'=');
        if($level < 1) $level = 1;
        $title = trim($title,'=');
        $title = trim($title);
        return array($title,$level,$pos);
    }

    /**
     * Create output
     */
    function render($mode, Doku_Renderer $renderer, $data) {
        if($mode != 'xhtml') return;

        list($text,$level,$pos) = $data;

        // Write the header
        $renderer->doc .= DOKU_LF.'<h'.$level.'>';
        $renderer->cdata($text);
        $renderer->doc .= "</h$level>".DOKU_LF;
        return true;
    }
}
