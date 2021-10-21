<?php

/**
 * DokuWiki linksenhanced PlugIn - Ajax component
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Böhler <dev@aboehler.at>
 */

if(!defined('DOKU_INC')) die();

class action_plugin_linksenhanced extends DokuWiki_Action_Plugin {

    function register(Doku_Event_Handler $controller) {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handle_ajax_call_unknown');
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'add_jsinfo_information');
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'handle_parser_cache_use');
    }

    /**
     * Add the language variable to the JSINFO variable
     */
    function add_jsinfo_information(Doku_Event $event, $param) {
      global $JSINFO;
 
      $JSINFO['plugin']['linksenhanced']['sectok'] = getSecurityToken();
    }  

    function handle_ajax_call_unknown(&$event, $param) {
      if($event->data != 'plugin_linksenhanced') return;
      
      $event->preventDefault();
      $event->stopPropagation();
      global $INPUT;
      
      $action = trim($INPUT->post->str('action'));
      $url = trim($INPUT->post->str('url'));
      
      if(!checkSecurityToken())
      {
          echo "CSRF Attack.";
          return;
      }
      
      $data = array();
      
      $data['result'] = false;
      
      if($action === 'check')
      {
           $client = new DokuHTTPClient();
           $client->sendRequest($url);
           $httpcode = $client->status;
           
           $data['error'] = $client->error;
           $data['httpcode'] = $httpcode;
           
           if($httpcode>=200 && $httpcode<300)
            $data['result'] = true;
      }
      else 
      {
          echo "Unknown operation.";
          return;
      }
    
      // If we are still here, JSON output is requested
      
      //json library of DokuWiki
      require_once DOKU_INC . 'inc/JSON.php';
      $json = new JSON();
 
      //set content type
      header('Content-Type: application/json');
      echo $json->encode($data);            
    }
    
    function handle_parser_cache_use(&$event, $param) {
      global $ID;
      $cache = &$event->data;
      if(!isset($cache->page)) return;
      
      $leMeta = p_get_metadata($ID, 'plugin_linksenhanced');
      if(!$leMeta)
        return;
      
      if(isset($leMeta['nocache']) && $leMeta['nocache'] === true)
      {
            $event->preventDefault();
            $event->stopPropagation();
            $event->result = false;
      }         
    }
 
}              
