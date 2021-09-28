<?php

use ComboStrap\LogUtility;
use ComboStrap\Message;
use ComboStrap\PagesIndex;
use dokuwiki\Extension\ActionPlugin;

if (!defined('DOKU_INC')) die();
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');


require_once(__DIR__ . '/../class/PageRules.php');
require_once(__DIR__ . '/../class/Page.php');
require_once(__DIR__ . '/urlmanager.php');
require_once(__DIR__ . '/../class/Message.php');

/**
 *
 * To show a message after redirection or rewriting
 *
 *
 *
 */
class action_plugin_combo_urlmessage extends ActionPlugin
{

    // a class can not start with a number then webcomponent is not a valid class name
    const REDIRECT_MANAGER_BOX_CLASS = "redirect-manager";

    // Property key
    const ORIGIN_PAGE = 'redirectId';
    const ORIGIN_TYPE = 'redirectOrigin';
    const CONF_SHOW_PAGE_NAME_IS_NOT_UNIQUE = 'ShowPageNameIsNotUnique';

    function __construct()
    {
        // enable direct access to language strings
        // ie $this->lang
        $this->setupLocale();
    }

    /**
     *
     * Return the message properties from a query string
     *
     * An internal HTTP redirect pass them via query string
     */
    private static function getMessageQueryStringProperties()
    {

        $returnValues = array();

        global $INPUT;
        $origin = $INPUT->str(self::ORIGIN_PAGE, null);
        if ($origin != null) {
            $returnValues = array(
                $origin,
                $INPUT->str(self::ORIGIN_TYPE, null)
            );
        }
        return $returnValues;

    }


    function register(Doku_Event_Handler $controller)
    {

        /* This will call the function _displayRedirectMessage */
        $controller->register_hook(
            'TPL_ACT_RENDER',
            'BEFORE',
            $this,
            '_displayRedirectMessage',
            array()
        );


    }


    /**
     * Main function; dispatches the visual comment actions
     * @param   $event Doku_Event
     */
    function _displayRedirectMessage(&$event, $param)
    {


        // Message
        $message = new Message($this);
        $message->setClass(action_plugin_combo_urlmessage::REDIRECT_MANAGER_BOX_CLASS);
        $message->setSignatureCanonical(action_plugin_combo_urlmanager::CANONICAL);
        $message->setSignatureName("Url Manager");

        $pageIdOrigin = null;
        $redirectSource = null;
        $messageSessionProperties = self::getMessageSessionProperties();
        if (!empty($messageSessionProperties)) {
            list($pageIdOrigin, $redirectSource) = $messageSessionProperties;
        } else {
            $messageQueryStringProperties = self::getMessageQueryStringProperties();
            if(!empty($messageQueryStringProperties)) {
                list($pageIdOrigin, $redirectSource) = $messageQueryStringProperties;
            }
        }


        // Are we a test call
        // The redirection does not exist the process otherwise the test fails
        global $ID;
        if ($ID == $pageIdOrigin && action_plugin_combo_urlmanager::GO_TO_EDIT_MODE != $redirectSource) {
            return;
        }

        if ($pageIdOrigin) {

            switch ($redirectSource) {

                case action_plugin_combo_urlmanager::TARGET_ORIGIN_PAGE_RULES:
                    $message->addContent(sprintf($this->getLang('message_redirected_by_redirect'), hsc($pageIdOrigin)));
                    $message->setType(Message::TYPE_CLASSIC);
                    break;

                case action_plugin_combo_urlmanager::TARGET_ORIGIN_START_PAGE:
                    $message->addContent(sprintf($this->lang['message_redirected_to_startpage'], hsc($pageIdOrigin)));
                    $message->setType(Message::TYPE_WARNING);
                    break;

                case  action_plugin_combo_urlmanager::TARGET_ORIGIN_BEST_PAGE_NAME:
                    $message->addContent(sprintf($this->lang['message_redirected_to_bestpagename'], hsc($pageIdOrigin)));
                    $message->setType(Message::TYPE_WARNING);
                    break;

                case action_plugin_combo_urlmanager::TARGET_ORIGIN_BEST_NAMESPACE:
                    $message->addContent(sprintf($this->lang['message_redirected_to_bestnamespace'], hsc($pageIdOrigin)));
                    $message->setType(Message::TYPE_WARNING);
                    break;

                case action_plugin_combo_urlmanager::TARGET_ORIGIN_SEARCH_ENGINE:
                    $message->addContent(sprintf($this->lang['message_redirected_to_searchengine'], hsc($pageIdOrigin)));
                    $message->setType(Message::TYPE_WARNING);
                    break;

                case action_plugin_combo_urlmanager::GO_TO_EDIT_MODE:
                    $message->addContent($this->lang['message_redirected_to_edit_mode']);
                    $message->setType(Message::TYPE_CLASSIC);
                    break;

            }

            // Add a list of page with the same name to the message
            // if the redirections is not planned
            if ($redirectSource != action_plugin_combo_urlmanager::TARGET_ORIGIN_PAGE_RULES) {
                $this->addToMessagePagesWithSameName($message, $pageIdOrigin);
            }

        }

        if ($event->data == 'show' || $event->data == 'edit' || $event->data == 'search') {

            ptln($message->toHtml());

        }

    }


    /**
     * Add the page with the same page name but in an other location
     * @param $message
     * @param $pageId
     */
    function addToMessagePagesWithSameName($message, $pageId)
    {

        if ($this->getConf(self::CONF_SHOW_PAGE_NAME_IS_NOT_UNIQUE) == 1) {

            global $ID;
            // The page name
            $pageName = noNS($pageId);
            $pagesWithSameName = PagesIndex::pagesWithSameName($pageName, $ID);

            if (count($pagesWithSameName) > 0) {

                $message->setType(Message::TYPE_WARNING);

                // Assign the value to a variable to be able to use the construct .=
                if ($message->getContent() <> '') {
                    $message->addContent('<br/><br/>');
                }
                $message->addContent($this->lang['message_pagename_exist_one']);
                $message->addContent('<ul>');

                $i = 0;
                foreach ($pagesWithSameName as $PageId => $title) {
                    $i++;
                    if ($i > 10) {
                        $message->addContent('<li>' .
                            tpl_link(
                                wl($pageId) . "?do=search&q=" . rawurldecode($pageName),
                                "More ...",
                                'class="" rel="nofollow" title="More..."',
                                $return = true
                            ) . '</li>');
                        break;
                    }
                    if ($title == null) {
                        $title = $PageId;
                    }
                    $message->addContent('<li>' .
                        tpl_link(
                            wl($PageId),
                            $title,
                            'class="" rel="nofollow" title="' . $title . '"',
                            $return = true
                        ) . '</li>');
                }
                $message->addContent('</ul>');
            }
        }
    }


    /**
     * Set the redirect in a session that will be be read after the redirect
     * in order to show a message to the user
     * @param string $id
     * @param string $redirectSource
     */
    static function notify($id, $redirectSource)
    {
        // Msg via session
        if (!defined('NOSESSION')) {
            //reopen session, store data and close session again
            self::sessionStart();
            $_SESSION[DOKU_COOKIE][self::ORIGIN_PAGE] = $id;
            $_SESSION[DOKU_COOKIE][self::ORIGIN_TYPE] = $redirectSource;
            self::sessionClose();

        }
    }

    /**
     * You can't unset when rendering because the write
     * of a session may fail because some data may have already been send
     * during the rendering process
     * Unset is done at the start of the 404 manager
     */
    static function unsetNotification()
    {

        // Open session
        self::sessionStart();

        // Read the data and unset
        if (isset($_SESSION[DOKU_COOKIE][self::ORIGIN_PAGE])) {
            unset($_SESSION[DOKU_COOKIE][self::ORIGIN_PAGE]);
        }
        if (isset($_SESSION[DOKU_COOKIE][self::ORIGIN_TYPE])) {
            unset($_SESSION[DOKU_COOKIE][self::ORIGIN_TYPE]);
        }
    }

    /**
     * Return notification data or an empty array
     * @return array - of the source id and of the type of redirect if a redirect has occurs otherwise an empty array
     */
    static function getMessageSessionProperties()
    {
        $returnArray = array();
        if (!defined('NOSESSION')) {

            $pageIdOrigin = null;
            $redirectSource = null;

            // Read the data and unset
            if (isset($_SESSION[DOKU_COOKIE][self::ORIGIN_PAGE])) {
                $pageIdOrigin = $_SESSION[DOKU_COOKIE][self::ORIGIN_PAGE];
            }
            if (isset($_SESSION[DOKU_COOKIE][self::ORIGIN_TYPE])) {
                $redirectSource = $_SESSION[DOKU_COOKIE][self::ORIGIN_TYPE];
            }


            if ($pageIdOrigin) {
                $returnArray = array($pageIdOrigin, $redirectSource);
            }

        }
        return $returnArray;

    }

    private static function sessionStart()
    {
        $sessionStatus = session_status();
        switch ($sessionStatus) {
            case PHP_SESSION_DISABLED:
                throw new RuntimeException("Sessions are disabled");
                break;
            case PHP_SESSION_NONE:
                $result = @session_start();
                if (!$result) {
                    throw new RuntimeException("The session was not successfully started");
                }
                break;
            case PHP_SESSION_ACTIVE:
                break;
        }
    }

    private static function sessionClose()
    {
        // Close the session
        $phpVersion =  phpversion();
        if ($phpVersion>"7.2.0") {
            /** @noinspection PhpVoidFunctionResultUsedInspection */
            $result = session_write_close();
            if (!$result) {
                // Session is really not a well known mechanism
                // Set this error in a info level to not fail the test
                LogUtility::msg("Failure to write the session", LogUtility::LVL_MSG_INFO);
            }
        } else {
            session_write_close();
        }

    }

}
