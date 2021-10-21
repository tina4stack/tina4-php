<?php
/**
 * linksenhanced Plugin - Render Link Title using Wiki Markup
 * Heavily based on the standard linking code.
 *
 * Usage:
 *
 * [[check^http://www.dokuwiki.org|www.dokuwiki.org]]
 * [[render noparagraph^http://www.dokuwiki.org|<faicon fa fe-euro> FontAwesome Code]]
 * [[render^http://www.dokuwiki.org|//Formatted Output, probably within a paragraph//]]
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Böhler <dev@aboehler.at>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_linksenhanced_table extends DokuWiki_Syntax_Plugin {

    function getType() { 
        return 'substition'; 
    }
    
    function getPType() { 
        return 'normal'; 
    }
    
    function getAllowedTypes() { 
        return array('container','substition','protected','disabled','paragraphs','formatting'); 
    }
    
    function getSort() { 
        return 202; 
    }
    
    function connectTo($mode) { 
        $this->Lexer->addSpecialPattern('\{\{linksenhanced>[^}]*\}\}',$mode,'plugin_linksenhanced_table'); 
    }

   /**
    * Handle the match. Use either the standard linking mechanism or, when enabled,
    * pass the title through the parser
    */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        $options = trim(substr($match,16,-2));
        $options = explode(',', $options);
        
        $data = array('links' => array(),
                      'namespace' => false
                     );
                     
        foreach($options as $option)
        {
            list($key, $val) = explode('=', $option);
            $key = strtolower(trim($key));
            $val = trim($val);
            $data[$key] = $val;
        }
        
        $data['links'] = $this->getPagesAndLinks($data['namespace']);
        return $data;
    }
    /**
     * Create output
     */
     
    function getPagesAndLinks($namespace)
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
        if($namespace !== false)
            $namespace = explode(';', $namespace);
        
        $data = array();
        $retData = array();
        search($data, $conf['datadir'],'search_universal',$opts);
        foreach($data as $k => $pdata)
        {
            $ns = getNS($pdata['id']);
            if($ns === false)
                $ns = '';

            if($namespace !== false && !in_array($ns, $namespace))
                continue; 
                
            $meta = p_get_metadata($pdata['id']);
            if(is_array($meta['plugin_linksenhanced']['links']))
            {
                $retData[$pdata['id']] = $meta['plugin_linksenhanced']['links'];
            }
        }
        return $retData;
    } 
    
    function render($format, Doku_Renderer $R, $data) {
        if($format == 'metadata')
        {
            $R->meta['plugin_linksenhanced']['nocache'] = true;
            return true;
        }
        if($format != 'xhtml') return false;
        
        echo '<h1>Link Overview</h1>';
        echo '<table>';
        echo '<tr><th width="30\%">Page</th><th width="90\%">Link Status</th></tr>';

        foreach($data['links'] as $page => $links)
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
