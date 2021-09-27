<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     LarsDW223
 */

class helper_plugin_folded extends DokuWiki_Plugin {
    static protected $ids_count = 0;

    function getMethods() {
        $result = array();
        $result[] = array(
                'name'   => 'getNextID',
                'desc'   => 'Returns the next folded ID.',
                );
        return $result;
    }

    /**
     * Returns the next folded ID.
     */
    function getNextID() {
        global $ID, $ACT;

        $hash = md5($ID.$ACT);
        $this->ids_count++;
        $id = 'folded_'.$hash.'_'.$this->ids_count;
        return $id;
    }
}
// vim:ts=4:sw=4:et: 
