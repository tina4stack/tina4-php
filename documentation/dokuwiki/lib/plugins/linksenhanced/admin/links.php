<?php
/**
 * DokuWiki Plugin linksenhanced (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Böhler <dev@aboehler.at>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();
require_once(DOKU_PLUGIN.'admin.php');

class admin_plugin_linksenhanced_links extends DokuWiki_Admin_Plugin {



    function getMenuSort() { return 501; }
    function forAdminOnly() { return true; }

    function getMenuText($language) {
        return "Show external link status";
    }

    function handle() {
        if(!is_array($_REQUEST['d']) || !checkSecurityToken()) return;
        
    }
    
    function getPagesAndLinks()
    {
        global $conf;
        $opts = array(
        'depth' => 0,
        'listfiles' => true,
        'listdirs'  => false,
        'pagesonly' => true,
        'firsthead' => true,
        'sneakyacl' => $conf['sneaky_index'],
        );
        
        $data = array();
        $retData = array();
        search($data, $conf['datadir'],'search_universal',$opts);
        foreach($data as $k => $pdata)
        {
            $meta = p_get_metadata($pdata['id']);
            if(is_array($meta['plugin_linksenhanced']['links']))
            {
                $retData[$pdata['id']] = $meta['plugin_linksenhanced']['links'];
            }
        }
        return $retData;
    }

    function html() {
        echo '<h1>Link Overview</h1>';
        echo '<table>';
        echo '<tr><th width="30\%">Page</th><th width="90\%">Link Status</th></tr>';
        $linkData = $this->getPagesAndLinks();
        foreach($linkData as $page => $links)
        {
            echo '<td rowspan="'.count($links).'">'.$page.'</td>';
            $first = true;
            foreach($links as $link)
            {
                if(!$first)
                    echo '<tr>';
                else
                    $first = false;
                echo '<td><a href="'.$link.'" class="plugin_linksenhanced_pending">'.$link.'</td>';
                echo '</tr>';
            }
        }
        echo '</tr>';
        echo '</table>';
    }

}

// vim:ts=4:sw=4:et:enc=utf-8:
