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
class syntax_plugin_linksenhanced_link extends DokuWiki_Syntax_Plugin {

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
        $this->Lexer->addSpecialPattern('\[\[(?:(?:[^[\]]*?\[.*?\])|.*?)\]\]',$mode,'plugin_linksenhanced_link'); 
    }

   /**
    * Handle the match. Use either the standard linking mechanism or, when enabled,
    * pass the title through the parser
    */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        global $ID;
        
        $match = substr($match, 2, -2);
        $link = explode('|',$match,2);
        $nopara = false;
        $optionsSet = explode('^', $link[0], 2);
        $options = array('render' => false,
                   'noparagraph' => false,
                   'check' => false,
                   'class' => false,
                   'target' => false);
        if(isset($optionsSet[1]))
        {
          $link[0] = $optionsSet[1];
          $opts = explode(' ', $optionsSet[0]);
          foreach($opts as $opt)
          {
            if(strpos($opt, 'class') === 0)
              $options['class'] = explode('=', $opt)[1];
            elseif(strpos($opt, 'target') === 0)
              $options['target'] = explode('=', $opt)[1];
            else
              $options[$opt] = true;
          }
        }

        if($this->getConf('check_all_external') == 1)
        {
            if($this->getConf('check_only_namespace') != '')
            {
                $namespaces = explode(',', $this->getConf('check_only_namespace'));
                foreach($namespaces as $namespace) 
                {
                    if(getNS($ID) == $namespace)
                        $options['check'] = true;
                }
            }
            else
            {
                $options['check'] = true;
            }
        }
        
        if($options['render'] && isset($link[1]))
        {
            $link[1] = $this->render_text($link[1]);
            if($options['noparagraph'])
            {
                $link[1] = str_replace('</p>', '', str_replace('<p>', '', $link[1]));
            }
            if($link[1] === '')
                $link[1] = null;
        }
        else if ( isset($link[1]) && preg_match('/^\{\{[^\}]+\}\}$/',$link[1]) ) {
            // If the title is an image, convert it to an array containing the image details
            $link[1] = Doku_Handler_Parse_Media($link[1]);
        }
        else if(!isset($link[1]))
        {
            $link[1] = null;
        }

        $link[0] = trim($link[0]);

        //decide which kind of link it is

        if ( preg_match('/^[a-zA-Z0-9\.]+>{1}.*$/u',$link[0]) ) {
            $type = 'interwiki';
        }elseif ( preg_match('/^\\\\\\\\[^\\\\]+?\\\\/u',$link[0]) ) {
            $type = 'windowsshare';
        }elseif ( preg_match('#^([a-z0-9\-\.+]+?)://#i',$link[0]) ) {
            $type = 'external';
        }elseif ( preg_match('<'.PREG_PATTERN_VALID_EMAIL.'>',$link[0]) ) {
            $type = 'email';
        }elseif ( preg_match('!^#.+!',$link[0]) ){
            $type = 'local';
            $link[0] = substr($link[0],1);
        }else{
            $type = 'internal';
        }
        
        return array($type, $link, $options);
    }

   /**
    * Create output. This is largely based on the internal linking mechanism.
    */
    function render($mode, Doku_Renderer $renderer, $data) {
        if (empty($data)) return false;
        global $conf;
        global $ID;
        global $INFO;
        global $lang;
        
        list($type, $link, $options) = $data;

        $url = $link[0];
        $name = $link[1];
        
        if($mode == 'metadata')
        {
            if($type == 'external')
            {
                $schemes = getSchemes();
                list($scheme) = explode('://',$url);
                $scheme = strtolower($scheme);
                if(!in_array($scheme,$schemes)) $url = '';

                // is there still an URL?
                if(!$url){
                    return true;
                }
                
                $renderer->meta['plugin_linksenhanced']['links'][] = $url;
            }
            return true;
        }
        
        if($mode == 'xhtml') {
          switch($type)
          {
            case 'interwiki':
                $interwiki = explode('>',$link[0],2);
                $wikiName = strtolower($interwiki[0]);
                $wikiUri = $interwiki[1];
                $link = array();
                $link['target'] = $conf['target']['interwiki'];
                $link['pre']    = '';
                $link['suf']    = '';
                $link['more']   = '';
                if($name === null || !$options['render'])
                    $link['name']   = $renderer->_getLinkTitle($name, $wikiUri, $isImage);
                else
                    $link['name'] = $name;

                //get interwiki URL
                $exists = null;
                $url = $renderer->_resolveInterWiki($wikiName, $wikiUri, $exists);

                if(!$isImage) {
                    $class = preg_replace('/[^_\-a-z0-9]+/i', '_', $wikiName);
                    $link['class'] = "interwiki iw_$class";
                } else {
                    $link['class'] = 'media';
                }

                //do we stay at the same server? Use local target
                if(strpos($url, DOKU_URL) === 0 OR strpos($url, DOKU_BASE) === 0) {
                    $link['target'] = $conf['target']['wiki'];
                }
                
                if($options['target'] !== false)
                    $link['target'] = $options['target'];
                    
                if($exists !== null && !$isImage) {
                    if($exists) {
                        $link['class'] .= ' wikilink1';
                    } else {
                        $link['class'] .= ' wikilink2';
                        $link['rel'] = 'nofollow';
                    }
                }
                if($options['class'] !== false)
                    $link['class'] = $options['class'];

                $link['url'] = $url;
                $link['title'] = htmlspecialchars($link['url']);

                //output formatted
                $renderer->doc .= $renderer->_formatLink($link);
            break;
            case 'windowsshare':
                    //simple setup
                $link['target'] = $conf['target']['windows'];
                $link['pre']    = '';
                $link['suf']   = '';
                $link['style']  = '';
                
                if($options['target'] !== false)
                    $link['target'] = $options['target'];
                
                if($name === null || !$options['render'])
                    $link['name'] = $renderer->_getLinkTitle($name, $url, $isImage);
                else
                    $link['name'] = $name;
                if ( !$isImage ) {
                    $link['class'] = 'windows';
                } else {
                    $link['class'] = 'media';
                }
                
                if($options['class'] !== false)
                    $link['class'] = $options['class'];

                $link['title'] = $renderer->_xmlEntities($url);
                $url = str_replace('\\','/',$url);
                $url = 'file:///'.$url;
                $link['url'] = $url;

                //output formatted
                $renderer->doc .= $renderer->_formatLink($link);
            break;
            case 'external':
                if($name === null || !$options['render'])
                    $name = $renderer->_getLinkTitle($name, $url, $isImage);

                // url might be an attack vector, only allow registered protocols
                $schemes = getSchemes();
                list($scheme) = explode('://',$url);
                $scheme = strtolower($scheme);
                if(!in_array($scheme,$schemes)) $url = '';

                // is there still an URL?
                if(!$url){
                    $renderer->doc .= $name;
                    return;
                }

                // set class
                if ( !$isImage ) {
                    $class='urlextern';
                } else {
                    $class='media';
                }
                
                if($options['class'] !== false)
                    $class = $options['class'];

                //prepare for formating
                $link['target'] = $conf['target']['extern'];
                $link['style']  = '';
                $link['pre']    = '';
                $link['suf']    = '';
                $link['more']   = '';
                $link['class']  = $class;
                $link['url']    = $url;
                
                if($options['target'] !== false)
                    $link['target'] = $options['target'];
                
                if($options['check'] !== false)
                {
                    $link['class'] = 'plugin_linksenhanced_pending';
                }
                $link['name']   = $name;
                $link['title']  = $renderer->_xmlEntities($url);
                if($conf['relnofollow']) $link['more'] .= ' rel="nofollow"';

                //output formatted
                $renderer->doc .= $renderer->_formatLink($link);
            break;
            case 'email':
                $link = array();
                $link['target'] = '';
                $link['pre']    = '';
                $link['suf']   = '';
                $link['style']  = '';
                $link['more']   = '';

                if($name === null || !$options['render'])
                    $name = $renderer->_getLinkTitle($name, '', $isImage);
                if ( !$isImage ) {
                    $link['class']='mail';
                } else {
                    $link['class']='media';
                }
                
                if($options['class'] !== false)
                    $link['class'] = $options['class'];

                $url = $renderer->_xmlEntities($url);
                $url = obfuscate($url);
                $title   = $url;

                if(empty($name)){
                    $name = $url;
                }

                if($conf['mailguard'] == 'visible') $url = rawurlencode($url);

                $link['url']   = 'mailto:'.$url;
                $link['name']  = $name;
                $link['title'] = $title;

                //output formatted
                $renderer->doc .= $renderer->_formatLink($link);
            break;
            case 'local':
                if($name === null || !$options['render'])
                    $name  = $renderer->_getLinkTitle($name, $url, $isImage);
                $url  = $renderer->_headerToLink($url);
                $title = $ID.' ↵';
                if($options['class'] !== false)
                    $class = $options['class'];
                else
                    $class = "wikilink1";
                $renderer->doc .= '<a href="#'.$url.'" title="'.$title.'" class="'.$class.'">';
                $renderer->doc .= $name;
                $renderer->doc .= '</a>';
            break;
            case 'internal':
                $id = $url;
                $params = '';
                $linktype = 'content';
                $parts = explode('?', $id, 2);
                if (count($parts) === 2) {
                    $id = $parts[0];
                    $params = $parts[1];
                }

                // For empty $id we need to know the current $ID
                // We need this check because _simpleTitle needs
                // correct $id and resolve_pageid() use cleanID($id)
                // (some things could be lost)
                if ($id === '') {
                    $id = $ID;
                }

                // default name is based on $id as given
                $default = $renderer->_simpleTitle($id);

                // now first resolve and clean up the $id
                resolve_pageid(getNS($ID),$id,$exists);

                if($name === null || !$options['render'])
                    $name = $renderer->_getLinkTitle($name, $default, $isImage, $id, $linktype);
                if ( !$isImage ) {
                    if ( $exists ) {
                        $class='wikilink1';
                    } else {
                        $class='wikilink2';
                        $link['rel']='nofollow';
                    }
                } else {
                    $class='media';
                }
                
                if($options['class'] !== false)
                    $class = $options['class'];

                //keep hash anchor
                @list($id,$hash) = explode('#',$id,2);
                if(!empty($hash)) $hash = $renderer->_headerToLink($hash);

                //prepare for formating
                $link['target'] = $conf['target']['wiki'];
                $link['style']  = '';
                $link['pre']    = '';
                $link['suf']    = '';
                // highlight link to current page
                if ($id == $INFO['id']) {
                    $link['pre']    = '<span class="curid">';
                    $link['suf']    = '</span>';
                }
                $link['more']   = '';
                $link['class']  = $class;
                $link['url']    = wl($id, $params);
                $link['name']   = $name;
                $link['title']  = $id;
                
                if($options['target'] !== false)
                    $link['target'] = $options['target'];
                //add search string
                if($search){
                    ($conf['userewrite']) ? $link['url'].='?' : $link['url'].='&amp;';
                    if(is_array($search)){
                        $search = array_map('rawurlencode',$search);
                        $link['url'] .= 's[]='.join('&amp;s[]=',$search);
                    }else{
                        $link['url'] .= 's='.rawurlencode($search);
                    }
                }

                //keep hash
                if($hash) $link['url'].='#'.$hash;

                //output formatted
                if($returnonly){
                    return $renderer->_formatLink($link);
                }else{
                    $renderer->doc .= $renderer->_formatLink($link);
                }
            break;
          }
        }
        return false;
    }
}
