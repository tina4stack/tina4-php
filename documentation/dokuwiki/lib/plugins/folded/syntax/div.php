<?php
/**
 * Folded text Plugin: enables folded text font size with syntax ++ text ++
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Fabian van-de-l_Isle <webmaster [at] lajzar [dot] co [dot] uk>
 * @author     Christopher Smith <chris@jalakai.co.uk>
 * @author     Esther Brunner <esther@kaffeehaus.ch>
 */

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_folded_div extends DokuWiki_Syntax_Plugin {
    protected $helper = null;

    function getType(){ return 'container'; }
    function getPType() { return 'stack'; }
    function getAllowedTypes() { return array('container','substition','protected','disabled','paragraphs','formatting'); }
    function getSort(){ return 404; }
    function connectTo($mode) { $this->Lexer->addEntryPattern('\+\+\+\+.*?\|(?=.*\+\+\+\+)',$mode,'plugin_folded_div'); }
    function postConnect() { $this->Lexer->addExitPattern('\+\+\+\+','plugin_folded_div'); }

   /**
    * Handle the match
    */
    function handle($match, $state, $pos, Doku_Handler $handler){
        if ($state == DOKU_LEXER_ENTER){
            $match = trim(substr($match,4,-1)); // strip markup
        } else if ($state == DOKU_LEXER_UNMATCHED) {
            $handler->_addCall('cdata',array($match), $pos);
            return false;
        }
        return array($state, $match);
    }

   /**
    * Create output
    */
    function render($mode, Doku_Renderer $renderer, $data) {
        if (empty($data)) return false;
        list($state, $cdata) = $data;

        if($mode == 'xhtml') {
            switch ($state){
              case DOKU_LEXER_ENTER:
                if ($this->helper === null) {
                    $this->helper = plugin_load('helper', 'folded');
                }
                $folded_id = $this->helper->getNextID();

                if ($this->getConf('unfold_default')) {
                    $renderer->doc .= '<p><a class="folder open" href="#'.$folded_id.'">';
                } else {
                    $renderer->doc .= '<p><a class="folder" href="#'.$folded_id.'">';
                }

                if ($cdata)
                    $renderer->doc .= ' '.$renderer->cdata($cdata);

                if ($this->getConf('unfold_default')) {
                    $renderer->doc .= '</a></p><div class="folded" id="'.$folded_id.'">';
                } else {
                    $renderer->doc .= '</a></p><div class="folded hidden" id="'.$folded_id.'">';
                }
                break;

              case DOKU_LEXER_UNMATCHED:                            // defensive, shouldn't occur
                $renderer->cdata($cdata);
                break;

              case DOKU_LEXER_EXIT:
                $renderer->doc .= '</div>';
                break;
            }
            return true;
        } else {
            //  handle unknown formats generically - by calling standard render methods
            switch ( $state ) {
               case DOKU_LEXER_ENTER: 
                $renderer->p_open();
                $renderer->cdata($cdata);
                $renderer->p_close();
                break;
              case DOKU_LEXER_UNMATCHED:                            // defensive, shouldn't occur
                $renderer->cdata($cdata);
                break;
              case DOKU_LEXER_EXIT:
                break;
            }
        }
        return false;
    }
}
