<?php
// must be run within Dokuwiki
use ComboStrap\LogUtility;
use ComboStrap\PageRules;
use ComboStrap\PluginUtility;
use ComboStrap\Resources;

if (!defined('DOKU_INC')) die();

if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');

require_once(DOKU_PLUGIN . 'admin.php');
require_once(DOKU_INC . 'inc/parser/xhtml.php');
require_once(__DIR__ . '/../class/PageRules.php');
require_once(__DIR__ . '/../class/PluginUtility.php');
require_once(__DIR__ . '/../class/Resources.php');

/**
 * The admin pages
 * need to inherit from this class
 *
 *
 * ! important !
 * The suffix of the class name should:
 *   * be equal to the name of the file
 *   * and have only letters
 */
class admin_plugin_combo_pagerules extends DokuWiki_Admin_Plugin
{



    /**
     * @var array|string[]
     */
    private $infoPlugin;

    /**
     * @var PageRules
     */
    private $pageRuleManager;


    /**
     * admin_plugin_combo constructor.
     *
     * Use the get function instead
     */
    public function __construct()
    {

        // enable direct access to language strings
        // of use of $this->getLang
        $this->setupLocale();
        $this->currentDate = date("c");
        $this->infoPlugin = $this->getInfo();


    }

    /**
     * Handle Sqlite instantiation  here and not in the constructor
     * to not make sqlite mandatory everywhere
     */
    private function initiatePageRuleManager()
    {

        if ($this->pageRuleManager == null) {

            $this->pageRuleManager = new PageRules();

        }
    }


    /**
     * Access for managers allowed
     */
    function forAdminOnly()
    {
        return false;
    }

    /**
     * return sort order for position in admin menu
     */
    function getMenuSort()
    {
        return 140;
    }

    /**
     * return prompt for admin menu
     * @param string $language
     * @return string
     */
    function getMenuText($language)
    {
        return ucfirst(PluginUtility::$PLUGIN_NAME) . " - " . $this->lang['PageRules'];
    }

    public function getMenuIcon()
    {
        return Resources::getImagesDirectory() .'/page-next.svg';
    }


    /**
     * handle user request
     */
    function handle()
    {

        $this->initiatePageRuleManager();

        /**
         * If one of the form submit has the add key
         */
        if ($_POST['save'] && checkSecurityToken()) {

            $id = $_POST[PageRules::ID_NAME];
            $matcher = $_POST[PageRules::MATCHER_NAME];
            $target = $_POST[PageRules::TARGET_NAME];
            $priority = $_POST[PageRules::PRIORITY_NAME];

            if ($matcher == null) {
                msg('Matcher can not be null', LogUtility::LVL_MSG_ERROR);
                return;
            }
            if ($target == null) {
                msg('Target can not be null', LogUtility::LVL_MSG_ERROR);
                return;
            }

            if ($matcher == $target) {
                msg($this->lang['SameSourceAndTargetAndPage'] . ': ' . $matcher . '', LogUtility::LVL_MSG_ERROR);
                return;
            }

            if ($id == null) {
                if (!$this->pageRuleManager->patternExists($matcher)) {
                    $this->pageRuleManager->addRule($matcher, $target, $priority);
                    msg($this->lang['Saved'], LogUtility::LVL_MSG_INFO);
                } else {
                    msg("The matcher pattern ($matcher) already exists. The page rule was not inserted.", LogUtility::LVL_MSG_ERROR);
                }
            } else {
                $this->pageRuleManager->updateRule($id, $matcher, $target, $priority);
                msg($this->lang['Saved'], LogUtility::LVL_MSG_INFO);
            }



        }

        if ($_POST['Delete'] && checkSecurityToken()) {

            $ruleId = $_POST[PageRules::ID_NAME];
            $this->pageRuleManager->deleteRule($ruleId);
            msg($this->lang['Deleted'], LogUtility::LVL_MSG_INFO);

        }

    }

    /**
     * output appropriate html
     * TODO: Add variable parsing where the key is the key of the lang object ??
     */
    function html()
    {

        $this->initiatePageRuleManager();

        ptln('<h1>' . ucfirst(PluginUtility::$PLUGIN_NAME) . ' - ' . ucfirst($this->getPluginComponent()) . '</a></h1>');
        $relativePath = 'admin/' . $this->getPluginComponent() . '_intro';
        echo $this->locale_xhtml($relativePath);

        // Forms
        if ($_POST['upsert']) {

            $matcher = null;
            $target = null;
            $priority = 1;

            // Update ?
            $id = $_POST[PageRules::ID_NAME];
            if ($id != null){
                $rule = $this->pageRuleManager->getRule($id);
                $matcher = $rule[PageRules::MATCHER_NAME];
                $target = $rule[PageRules::TARGET_NAME];
                $priority = $rule[PageRules::PRIORITY_NAME];
            }


            // Forms
            ptln('<div class="level2" >');
            ptln('<div id="form_container" style="max-width: 600px;">');
            ptln('<form action="" method="post">');
            ptln('<input type="hidden" name="sectok" value="'.getSecurityToken().'" />');
            ptln('<p><b>If the Dokuwiki ID matches the following pattern:</b></p>');
            $matcherDefault = "";
            if ($matcher != null) {
                $matcherDefault = 'value="' . $matcher . '"';
            }
            ptln('<label for="' . PageRules::MATCHER_NAME . '">(You can use the asterisk (*) character)</label>');
            ptln('<p><input type="text"  style="width: 100%;" id="' . PageRules::MATCHER_NAME . '" required="required" name="' . PageRules::MATCHER_NAME . '" ' . $matcherDefault . ' class="edit" placeholder="pattern"/> </p>');
            ptln('<p><b>Then applies this redirect settings:</b></p>');
            $targetDefault = "";
            if ($matcher != null) {
                $targetDefault = 'value="' . $target . '"';
            }
            ptln('<label for="' . PageRules::TARGET_NAME . '">Target: (A DokuWiki Id or an URL where you can use the ($) group character)</label>');
            ptln('<p><input type="text" style="width: 100%;" required="required" id="' . PageRules::TARGET_NAME . '" name="' . PageRules::TARGET_NAME . '" ' . $targetDefault . ' class="edit" placeholder="target" /></p>');
            ptln('<label for="' . PageRules::PRIORITY_NAME . '">Priority: (The order in which rules are applied)</label>');
            ptln('<p><input type="id" id="' . PageRules::PRIORITY_NAME . '." style="width: 100%;" required="required" placeholder="priority" name="' . PageRules::PRIORITY_NAME . '" value="' . $priority . '" class="edit" /></p>');
            ptln('<input type="hidden" name="do"    value="admin" />');
            if ($id != null) {
                ptln('<input type="hidden" name="' . PageRules::ID_NAME . '" value="' . $id . '" />');
            }
            ptln('<input type="hidden" name="page"  value="' . $this->getPluginName() . '_' . $this->getPluginComponent() . '" />');
            ptln('<p>');
            ptln('<a class="btn btn-light" href="?do=admin&page=webcomponent_pagerules" > ' . 'Cancel' . ' <a/>');
            ptln('<input class="btn btn-primary" type="submit" name="save" class="button" value="' . 'Save' . '" />');
            ptln('</p>');
            ptln('</form>');
            ptln('</div>');

            ptln('</div>');


        } else {

            ptln('<h2><a name="" id="pagerules_list">' . 'Rules' . '</a></h2>');
            ptln('<div class="level2">');

            ptln('<form class="pt-3 pb-3" action="" method="post">');
            ptln('<input type="hidden" name="sectok" value="'.getSecurityToken().'" />');
            ptln('    <input type="hidden" name="do"    value="admin" />');
            ptln('	<input type="hidden" name="page"  value="' . $this->getPluginName() . '_' . $this->getPluginComponent() . '" />');
            ptln('	<input type="submit" name="upsert" name="Create a page rule" class="button" value="' . $this->getLangOrDefault('AddNewRule','Add a new rule') . '" />');
            ptln('</form>');

            // List of redirection
            $rules = $this->pageRuleManager->getRules();

            if (sizeof($rules)==0){
                ptln('<p>No Rules found</p>');
            } else {
                ptln('<div class="table-responsive">');

                ptln('<table class="table table-hover">');
                ptln('	<thead>');
                ptln('		<tr>');
                ptln('			<th>&nbsp;</th>');
                ptln('			<th>' . $this->getLangOrDefault('Priority', 'Priority') . '</th>');
                ptln('			<th>' . $this->getLangOrDefault('Matcher', 'Matcher') . '</th>');
                ptln('			<th>' . $this->getLangOrDefault('Target', 'Target') . '</th>');
                ptln('			<th>' . $this->getLangOrDefault('NDate', 'Date') . '</th>');
                ptln('	    </tr>');
                ptln('	</thead>');
                ptln('	<tbody>');


                foreach ($rules as $key => $row) {

                    $id = $row[PageRules::ID_NAME];
                    $matcher = $row[PageRules::MATCHER_NAME];
                    $target = $row[PageRules::TARGET_NAME];
                    $timestamp = $row[PageRules::TIMESTAMP_NAME];
                    $priority = $row[PageRules::PRIORITY_NAME];


                    ptln('	  <tr class="redirect_info">');
                    ptln('		<td>');
                    ptln('			<form action="" method="post" style="display: inline-block">');
                    ptln('<input type="hidden" name="sectok" value="'.getSecurityToken().'" />');
                    ptln('<button style="background: none;border: 0;">');
                    ptln(inlineSVG(Resources::getImagesDirectory() . '/delete.svg'));
                    ptln('</button>');
                    ptln('				<input type="hidden" name="Delete"  value="Yes" />');
                    ptln('				<input type="hidden" name="' . PageRules::ID_NAME . '"  value="' . $id . '" />');
                    ptln('			</form>');
                    ptln('			<form action="" method="post" style="display: inline-block">');
                    ptln('<input type="hidden" name="sectok" value="'.getSecurityToken().'" />');
                    ptln('<button style="background: none;border: 0;">');
                    ptln(inlineSVG(Resources::getImagesDirectory() . '/file-document-edit-outline.svg'));
                    ptln('</button>');
                    ptln('				<input type="hidden" name="upsert"  value="Yes" />');
                    ptln('				<input type="hidden" name="' . PageRules::ID_NAME . '"  value="' . $id . '" />');
                    ptln('			</form>');

                    ptln('		</td>');
                    ptln('		<td>' . $priority . '</td>');
                    ptln('	    <td>' . $matcher . '</td>');
                    ptln('		<td>' . $target . '</td>');
                    ptln('		<td>' . $timestamp . '</td>');
                    ptln('    </tr>');
                }
                ptln('  </tbody>');
                ptln('</table>');
                ptln('</div>'); //End Table responsive
            }

            ptln('</div>'); // End level 2


        }


    }

    /**
     * An utility function to return the plugin translation or a default value
     * @param $id
     * @param $default
     * @return mixed|string
     */
    private function getLangOrDefault($id, $default)
    {
        $lang = $this->getLang($id);
        return $lang !='' ? $lang : $default;
    }


    static function getAdminPageName(){
        return PluginUtility::getAdminPageName(get_called_class());
    }

}
