<?php
/**
 * Composant Action pour Konsole (Toolbar)
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Fabrice DEJAIGHER <fabrice@chtiland.com>
 */

if (!defined('DOKU_INC'))
{
	die();
}

if (!defined('DOKU_PLUGIN'))
{
	define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
}
require_once(DOKU_PLUGIN.'action.php');

class action_plugin_konsole extends DokuWiki_Action_Plugin
{


    function register(Doku_Event_Handler $controller)
	{
        $controller->register_hook('TOOLBAR_DEFINE', 'AFTER', $this, 'handle_toolbar', array ());
    }

    function handle_toolbar(&$event, $param)
	{
        $event->data[] = array (
            'type' => 'picker',
            'title' => 'Konsole',
			'class' => 'konsole_toolbar',
            'icon' => '../../plugins/konsole/images/konsole_select.png',
            'list' => array(
                array(
                    'type'   => 'format',
                    'title'  => $this->getLang('konsoleuser'),
                    'icon'   => '../../plugins/konsole/images/konsole.png',
                    'open'   => '<konsole>\n',
                    'close'  => '\n</konsole>\n',
                ),
                array(
                    'type'   => 'format',
                    'title'  => $this->getLang('konsoleroot'),
                    'icon'   => '../../plugins/konsole/images/konsole_root.png',
                    'open'   => '<konsole root>\n',
                    'close'  => '\n</konsole>\n',
                )

            )
        );
    }
}

