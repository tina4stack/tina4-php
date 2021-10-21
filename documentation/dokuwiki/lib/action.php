<?php
/**
 * DokuWiki Plugin searchresultswithpath (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Sam B <sam@s-blu.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class action_plugin_searchresultswithpath extends DokuWiki_Action_Plugin {

    /**
     * Registers a callback function for a given event
     *
     * @param Doku_Event_Handler $controller DokuWiki's event controller object
     * @return void
     */
    public function register(Doku_Event_Handler $controller) {

       $controller->register_hook('SEARCH_QUERY_PAGELOOKUP', 'AFTER', $this, 'handle_search_query_pagelookup');
   
    }

    /**
     * [Custom event handler which performs action]
     *
     * @param Doku_Event $event  event object by reference
     * @param mixed      $param  [the parameters passed as fifth argument to register_hook() when this
     *                           handler was registered]
     * @return void
     */

    public function handle_search_query_pagelookup(Doku_Event &$event) {
        $useNsHeading = $this->getConf('use_ns_heading');
        $showPathOnlyAsHeading = $this->getConf('show_path_only_as_heading');

        foreach($event->result as $id => $title){

            if (!$useNsHeading) {
                $ns = getNS($id);
            } else {
                //Get Name of the NS Page or NS:start Page, if possible
                $ns = p_get_first_heading(getNS($id));
                if(!$ns) $ns = p_get_first_heading(getNS($id). ':start');

                if (!$ns && !$showPathOnlyAsHeading) {
                    $ns = getNS($id);
                }
            }

            if($ns) $ns = ' ['.$ns.']';
            /*
             * Fix #1: Add the pageId to the title, if the page has no heading
             * Normally this get handled by Dokuwiki itself, but we always set an title here and
             * bypass Dokuwikis handling
             */
            if (!$title) $title = noNs($id);
            $event->result[$id] = $title . $ns;
        }
    }
}

// vim:ts=4:sw=4:et:
