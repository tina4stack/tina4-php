<?php
/**
 * Userhomepage plugin main file
 * Previous authors: James GuanFeng Lin, Mikhail I. Izmestev, Daniel Stonier
 * @author   Simon DELAGE <sdelage@gmail.com>
 * @license: CC Attribution-Share Alike 3.0 Unported <http://creativecommons.org/licenses/by-sa/3.0/>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');

require_once (DOKU_PLUGIN . 'action.php');
require_once (DOKU_PLUGIN . '/acl/admin.php');

class action_plugin_userhomepage extends DokuWiki_Action_Plugin{

    function register(Doku_Event_Handler $controller) {
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'init',array());
        $controller->register_hook('DETAIL_STARTED', 'AFTER', $this, 'init',array());
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'AFTER', $this, 'redirect',array());
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'AFTER', $this, 'acl',array());
        $controller->register_hook('COMMON_USER_LINK', 'AFTER', $this, 'replaceUserLink',array());
    }

    function init(&$event, $param) {
        global $conf;
        global $INFO;

        // If there's no conflict between private and public space
        if ($this->multiNsOk(true)) {
            $this->helper = plugin_load('helper','userhomepage');
            // If templates_path option starts with 'data/pages' it can automatically be adapted but should be changed
            if (substr($this->getConf('templates_path'),0,10) == 'data/pages') {
                $dest = str_replace("data/pages", "./pages", $this->getConf('templates_path'));
                msg("Userhomepage option [<code>templates_path</code>] should be changed to a path relative to data folder (as set by Dokuwiki's [<code>savedir</code>] setting). Current value is based on former default (i.e. <code>data/pages/...</code>) and will still work but this message will keep appearing until the value is corrected, check <a href='https://www.dokuwiki.org/plugin:userhomepage'>this page</a> for details.",2);
            } else {
                $dest = $this->getConf('templates_path');
            }
            if (!plugin_isdisabled('avatar')) {
                $avatarHelper = plugin_load('helper','avatar');
                $avatarsFolder = $avatarHelper->getConf('namespace');
                if (($avatarsFolder == "user") && ($avatarsFolder == $this->getConf('users_namespace')) && (isadmin)) {
                    msg($this->getLang('avatarsconflict'), -1);
                }
            }
            //if ($event == "DETAIL_STARTED") { return false; }
            $this->dataDir = $conf['savedir'];
            // CREATE PRIVATE NAMESPACE START PAGE TEMPLATES IF NEEDED (is required by options, doesn't exist yet and a known user is logged in and not showing image details page)
            if (($this->getConf('create_private_ns')) && (!is_file($this->dataDir.'/'.$this->getConf('templates_path').'/userhomepage_private.txt')) && ($this->userOk()) && ($event != "DETAIL_STARTED")) {
                // If a template exists in path as builded before 2015/05/14 version, use it as source to create userhomepage_private.txt in new templates_path
                if ((is_file(DOKU_CONF.'../'.$this->getConf('templates_path').'/userhomepage_private.txt')) && ($this->getConf('templatepath') != null)) {
                    $source = DOKU_CONF.'../'.$this->getConf('templates_path').'/userhomepage_private.txt';
                // If a template from version 3.0.4 exists, use it as source to create userhomepage_private.txt in templates_path
                } elseif ((is_file(DOKU_INC.$this->getConf('templatepath'))) && ($this->getConf('templatepath') != null)) {
                    $source = $this->getConf('templatepath');
                // Otherwise, we're on a fresh install
                } else {
                    $source = 'lib/plugins/userhomepage/lang/'.$conf['lang'].'/userhomepage_private.default';
                }
                $this->copyFile($source, $dest, 'userhomepage_private.txt');
            }
            // CREATE PUBLIC PAGE TEMPLATES IF NEEDED (is required by options, doesn't exist yet and a known user is logged in and not showing image details page)
            if (($this->getConf('create_public_page')) and (!is_file($this->dataDir.'/'.$this->getConf('templates_path').'/userhomepage_public.txt')) and ($this->userOk()) && ($event != "DETAIL_STARTED")) {
                // If a template exists in path as builded before 2015/05/14 version, use it as source to create userhomepage_private.txt in new templates_path
                if ((is_file(DOKU_CONF.'../'.$this->getConf('templates_path').'/userhomepage_public.txt')) && ($this->getConf('templatepath') != null)) {
                    $source = DOKU_CONF.'../'.$this->getConf('templates_path').'/userhomepage_public.txt';
                } else {
                    $source = 'lib/plugins/userhomepage/lang/'.$conf['lang'].'/userhomepage_public.default';
                }
                $this->copyFile($source, $dest, 'userhomepage_public.txt');
            }
            // CREATE PUBLIC NAMESPACE START PAGE TEMPLATES IF NEEDED (is required by options, doesn't exist yet and a known user is logged in and not showing image details page)
            if (($this->getConf('create_public_page')) and (strpos($this->getConf('public_pages_ns'),':%NAME%:%START%') !== false) and (!is_file($this->dataDir.'/'.$this->getConf('templates_path').'/userhomepage_publicspace.txt')) and ($this->userOk()) && ($event != "DETAIL_STARTED")) {
                // If a template exists in path as builded before 2015/05/14 version, use it as source to create userhomepage_private.txt in new templates_path
                if ((is_file(DOKU_CONF.'../'.$this->getConf('templates_path').'/userhomepage_publicspace.txt')) && ($this->getConf('templatepath') != null)) {
                    $source = DOKU_CONF.'../'.$this->getConf('templates_path').'/userhomepage_publicspace.txt';
                } else {
                    $source = 'lib/plugins/userhomepage/lang/'.$conf['lang'].'/userhomepage_publicspace.default';
                }
                $this->copyFile($source, $dest, 'userhomepage_publicspace.txt');
            }
            // TARGETS
            // ...:start.txt or ...:simon_delage.txt
            $this->private_page = $this->helper->getPrivateID();
            // user:simon.txt
            $this->public_page = $this->helper->getPublicID();
            // If a user is logged in, store timestamp (if it wasn't stored yet)
            if (($_SERVER['REMOTE_USER'] != null) && (!isset($_SESSION['uhptimestamp']))) {
                $_SESSION['uhptimestamp'] = time();
            // If no user is logged in and a timestamp exists, set timestamp to null (ensures that redirection will work if user just logged out and comes back before closing browser)
            } elseif (($_SERVER['REMOTE_USER'] == null) && (isset($_SESSION['uhptimestamp']))) {
                $_SESSION['uhptimestamp'] = null;
            }
        } else {
            return false;
        }
    }

    function redirect(&$event, $param) {
        global $conf;
        global $lang;
        global $ID;

        // If there's no conflict between private and public space and if user did not request an action page
//        if ($this->multiNsOk()) {
        if (($this->multiNsOk()) and ($_GET['do'] == null)) {
//        if (($this->multiNsOk()) and ($_GET['do'] != 'admin')) {
            $created = array();
            // If a user is logged in and not allready requesting his private namespace start page
            if (($this->userOk())&&($_REQUEST['id']!=$this->private_page)) {
                // if private page doesn't exists, create it (from template)
                if ($this->getConf('create_private_ns') && is_file($this->dataDir.'/'.$this->getConf('templates_path').'/userhomepage_private.txt') && !page_exists($this->private_page) && !checklock($this->private_page) && !checkwordblock() && ($this->userOk('private'))) {
                    // Target private start page template
                    $this->private_page_template = $this->dataDir.'/'.$this->getConf('templates_path').'/userhomepage_private.txt';
                    // Create private page
                    lock($this->private_page);
                    saveWikiText($this->private_page,$this->applyTemplate('private'),$this->getLang('uhpcreated'));
                    unlock($this->private_page);
                    // Announce private namespace was created
                    msg($this->getLang('createdprivatens').' ('.$this->private_page.')', 1);
                    // Note that we created private page
                    $created['private'] = page_exists($this->private_page);
                }
                // If private ns is managed by plugin, check for any template from skeleton that doesn't exist yet
                if ($this->getConf('create_private_ns') && (is_dir($this->dataDir.'/'.$this->getConf('templates_path').'/uhp_private_skeleton')) && ($this->userOk('private'))) {
                    //$files = scandir($this->dataDir.'/'.$this->getConf('templates_path').'/uhp_private_skeleton/');
                    $path = realpath($this->dataDir.'/'.$this->getConf('templates_path').'/uhp_private_skeleton/');
                    $objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::SELF_FIRST);
                    if ($this->getConf('group_by_name')) {
                        // private:s:simon or private:s:simon_delage
                        $this->private_ns = cleanID($this->getConf('users_namespace').':'.substr($this->privateNamespace(), 0, 1).':'. $this->privateNamespace());
                    } else {
                        // private:simon or private:simon_delage
                        $this->private_ns = cleanID($this->getConf('users_namespace').':'. $this->privateNamespace());
                    }
                    foreach($objects as $objectName => $object){
                        $file = str_replace($path, '', $objectName);
                        if ((is_file($this->dataDir.'/'.$this->getConf('templates_path').'/uhp_private_skeleton'.$file)) and (strpos($file, '.txt') !== false)) {
                            $custom_page_id = cleanID(str_replace('.txt', '', str_replace('/', ':', str_replace('\\', ':', $file))));
                            $this->custom_target = $this->private_ns.':'.$custom_page_id;
                            if (!page_exists($this->custom_target)) {
                                $this->custom_page_template = $this->dataDir.'/'.$this->getConf('templates_path').'/uhp_private_skeleton'.$file;
                                lock($this->custom_target);
                                saveWikiText($this->custom_target,$this->applyTemplate($this->custom_page_template),$this->getLang('uhpcreated'));
                                msg($this->getLang('fromskeleton').' '.$this->custom_target,0);
                                unlock($this->custom_target);
                            }
                        }
                    }
                }
                // Public page?
                // If public page doesn't exists, create it (from template)
                if ($this->getConf('create_public_page') && is_file($this->dataDir.'/'.$this->getConf('templates_path').'/userhomepage_public.txt') && !page_exists($this->public_page) && !checklock($this->public_page) && !checkwordblock() && ($this->userOk('public'))) {
                    // Target public page template or public namespace start page template
                    if (strpos($this->getConf('public_pages_ns'),':%NAME%:%START%') !== false) {
                        $this->public_page_template = $this->dataDir.'/'.$this->getConf('templates_path').'/userhomepage_publicspace.txt';
                    } else {
                        $this->public_page_template = $this->dataDir.'/'.$this->getConf('templates_path').'/userhomepage_public.txt';
                    }
                    // Create public page
                    lock($this->public_page);
                    saveWikiText($this->public_page,$this->applyTemplate('public'),$this->getLang('uhpcreated'));
                    unlock($this->public_page);
                    // Announce plubic page was created
                    msg($this->getLang('createdpublicpage').' ('.$this->public_page.')', 1);
                    // Note that we created public page
                    $created['public'] = page_exists($this->public_page);
                }
                // If public page is in fact a public namespace and is managed by plugin, check for any template from skeleton that doesn't exist yet
                if ($this->getConf('create_public_page') && (is_dir($this->dataDir.'/'.$this->getConf('templates_path').'/uhp_public_skeleton')) && ($this->userOk('public'))) {
                    $path = realpath($this->dataDir.'/'.$this->getConf('templates_path').'/uhp_public_skeleton/');
                    $objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::SELF_FIRST);
                    $this->public_ns = cleanID(getNS($this->public_page));
                    foreach($objects as $objectName => $object){
                        $file = str_replace($path, '', $objectName);
                        if ((is_file($this->dataDir.'/'.$this->getConf('templates_path').'/uhp_public_skeleton'.$file)) and (strpos($file, '.txt') !== false)) {
                            $custom_page_id = cleanID(str_replace('.txt', '', str_replace('/', ':', str_replace('\\', ':', $file))));
                            $this->custom_target = $this->public_ns.':'.$custom_page_id;
                            if (!page_exists($this->custom_target)) {
                                $this->custom_page_template = $this->dataDir.'/'.$this->getConf('templates_path').'/uhp_public_skeleton'.$file;
                                lock($this->custom_target);
                                saveWikiText($this->custom_target,$this->applyTemplate($this->custom_page_template),$this->getLang('uhpcreated'));
                                msg($this->getLang('fromskeleton').' '.$this->custom_target,0);
                                unlock($this->custom_target);
                            }
                        }
                    }
                }
                // List IDs that can match wiki start
                $wikistart = array($conf['start'], ':'.$conf['start']);
                // If Translation plugin is active, wiki start page can also be '??:start'
                if (!plugin_isdisabled('translation')) {
                    // For each language in Translation settings
                    foreach (explode(' ',$conf['plugin']['translation']['translations']) as $language){
                        array_push($wikistart, $language.':'.$conf['start'], ':'.$language.':'.$conf['start']);
                    }
                }
                // If user isn't on public or private page yet, check for redirection conditions
                if (($ID != $this->public_page) && ($ID != $this->private_page)) {
                    // If Public page was just created, redirect to it and edit (or show)
                    if (($created['public']) && (page_exists($this->public_page))) {
                        send_redirect(wl($this->public_page, array('do='.$this->getConf('action')), true));
//msg('Public page creation redirection: id '.$_GET['id'].'; do '.$_GET['do'], 1);
                    // Else if private start page was just created and edit option is set, redirect to it and edit
                    } elseif (($created['private']) && (page_exists($this->private_page)) && ($this->getConf('edit_before_create'))) {
                        send_redirect(wl($this->private_page, array('do='.$this->getConf('action')), true));
//msg('Private page creation redirection: id '.$_GET['id'].'; do '.$_GET['do'], 1);
                    // Else if redirection is enabled and user's private page exists AND [(user isn't requesting a specific page OR he's requesting wiki start page) AND logged in 2sec ago max]
                    } elseif (($this->getConf('redirection')) && (page_exists($this->private_page)) && (((!isset($_GET['id'])) or (in_array($_GET['id'], $wikistart))) && (time()-$_SESSION["uhptimestamp"] <= 2))) {
//msg('Default redirection: id '.$_GET['id'].'; do '.$_GET['do'], 1);
                        send_redirect(wl($this->private_page, '', true));
                    }
                }
            }
        } else {
            return false;
        }
    }

    function acl(&$event, $param) {
        global $conf;

        // If there's no conflict between private and public space
        if ($this->multiNsOk()) {
            if ((!$this->getConf('no_acl')) && ($conf['useacl']) && ($this->userOk())) {
                global $config_cascade;
                $existingLines = file($config_cascade['acl']['default']);
                $newLines = array();
                // ACL
                $acl = new admin_plugin_acl();
                // On private namespace
                if ($this->getConf('create_private_ns')) {
                    // For known users
                    // If use_name_string or group_by_name is enabled, we can't use ACL wildcards so let's create ACL for current user on his private ns
                    if (($this->getConf('use_name_string')) or ($this->getConf('group_by_name'))) {
                        $where = $this->private_ns.':*';
                        $who = strtolower($_SERVER['REMOTE_USER']);
                    // Otherwise we can set ACL for all known users at once
                    } else {
                        $where = cleanID($this->getConf('users_namespace')).':%USER%:*';
                        $who = '%USER%';
                    }
                    $perm = AUTH_DELETE;
                    if (!in_array("$where\t$who\t$perm\n", $existingLines)) { $newLines[] = array('where' => $where, 'who' => $who, 'perm' => $perm); }
                    // For @ALL
                    if ($this->getConf('acl_all_private') != 'noacl') {
                        $where = cleanID($this->getConf('users_namespace')).':*';
                        $who = '@ALL';
                        $perm = (int)$this->getConf('acl_all_private');
                        if (!in_array("$where\t$who\t$perm\n", $existingLines)) { $newLines[] = array('where' => $where, 'who' => $who, 'perm' => $perm); }
                    }
                    // For @user
                    if (($this->getConf('acl_user_private') != 'noacl') && ($this->getConf('acl_user_private') !== $this->getConf('acl_all_private'))) {
                        $where = cleanID($this->getConf('users_namespace')).':*';
                        $who = '@user';
                        $perm = (int)$this->getConf('acl_user_private');
                        if (!in_array("$where\t$who\t$perm\n", $existingLines)) { $newLines[] = array('where' => $where, 'who' => $who, 'perm' => $perm); }
                    }
                } // end of private namespaces acl
                // On public user pages
                if ($this->getConf('create_public_page')) {
                    // For known users
                    if (strpos($this->getConf('public_pages_ns'),':%NAME%:%START%') !== false) {
                        $where = str_replace('%NAME%:%START%', '%USER%', $this->getConf('public_pages_ns')).':*';
                        $perm = AUTH_DELETE;
                    } else {
                        $where = cleanID($this->getConf('public_pages_ns')).':%USER%';
                        $perm = AUTH_EDIT;
                    }
                    $who = '%USER%';
                    if (!in_array("$where\t$who\t$perm\n", $existingLines)) { $newLines[] = array('where' => $where, 'who' => $who, 'perm' => $perm); }
                    // For others
                    if ($this->getConf('acl_all_public') != 'noacl') {
                        // If both private and public namespaces are identical, we need to force rights for @ALL and/or @user on each public page
                        if ($this->getConf('users_namespace') == $this->getConf('public_pages_ns')) {
                            $files = scandir($this->dataDir.'/pages/'.$this->getConf('public_pages_ns'));
                            foreach($files as $file) {
                                if (is_file($this->dataDir.'/pages/'.$this->getConf('public_pages_ns').'/'.$file)) {
                                    // ACL on templates will be managed another way
                                    if (strpos($file, 'userhomepage_p') !== 0) {
                                        // @ALL
                                        if ($this->getConf('acl_all_public') != 'noacl') {
                                            $where = $this->getConf('public_pages_ns').':'.substr($file, 0, -4);
                                            $who = '@ALL';
                                            $perm = $this->getConf('acl_all_public');
                                            if (!in_array("$where\t$who\t$perm\n", $existingLines)) { $newLines[] = array('where' => $where, 'who' => $who, 'perm' => $perm); }
                                        }
                                        // @user
                                        if ($this->getConf('acl_user_public') != 'noacl') {
                                            $where = $this->getConf('public_pages_ns').':'.substr($file, 0, -4);
                                            $who = '@user';
                                            $perm = $this->getConf('acl_user_public');
                                            if (!in_array("$where\t$who\t$perm\n", $existingLines)) { $newLines[] = array('where' => $where, 'who' => $who, 'perm' => $perm); }
                                        }
                                    }
                                }
                            }
                        // Otherwise we just need to give the right permission to each group on public pages namespace
                        } else {
                            // @ALL
                            if ($this->getConf('acl_all_public') != 'noacl') {
                                $where = cleanID(str_replace(':%NAME%:%START%', '', $this->getConf('public_pages_ns'))).':*';
                                $who = '@ALL';
                                $perm = $this->getConf('acl_all_public');
                                if (!in_array("$where\t$who\t$perm\n", $existingLines)) { $newLines[] = array('where' => $where, 'who' => $who, 'perm' => $perm); }
                            }
                            // @user
                            if ($this->getConf('acl_user_public') != 'noacl') {
                                $where = cleanID(str_replace(':%NAME%:%START%', '', $this->getConf('public_pages_ns'))).':*';
                                $who = '@user';
                                $perm = $this->getConf('acl_user_public');
                                if (!in_array("$where\t$who\t$perm\n", $existingLines)) { $newLines[] = array('where' => $where, 'who' => $who, 'perm' => $perm); }
                            }
                        }
                    }
                } // end for public pages acl
                // On templates if they're in data/pages
                if (strpos($this->getConf('templates_path'),'/pages') !== false) {
                    // For @ALL
                    if (($this->getConf('acl_all_templates') != 'noacl') && (($this->getConf('create_private_ns')) or ($this->getConf('create_public_page')))) {
                        $where = end(explode('/',$this->getConf('templates_path'))).':userhomepage_private';
                        $who = '@ALL';
                        $perm = (int)$this->getConf('acl_all_templates');
                        if (!in_array("$where\t$who\t$perm\n", $existingLines)) { $newLines[] = array('where' => $where, 'who' => $who, 'perm' => $perm); }
                        $where = end(explode('/',$this->getConf('templates_path'))).':userhomepage_public';
                        $who = '@ALL';
                        $perm = (int)$this->getConf('acl_all_templates');
                        if (!in_array("$where\t$who\t$perm\n", $existingLines)) { $newLines[] = array('where' => $where, 'who' => $who, 'perm' => $perm); }
                    }
                    // For @user
                    if (($this->getConf('acl_user_templates') != 'noacl') && ($this->getConf('acl_user_templates') !== $this->getConf('acl_all_templates')) && (($this->getConf('create_private_ns')) or ($this->getConf('create_public_page')))) {
                        $where = end(explode('/',$this->getConf('templates_path'))).':userhomepage_private';
                        $who = '@user';
                        $perm = (int)$this->getConf('acl_user_templates');
                        if (!in_array("$where\t$who\t$perm\n", $existingLines)) { $newLines[] = array('where' => $where, 'who' => $who, 'perm' => $perm); }
                        $where = end(explode('/',$this->getConf('templates_path'))).':userhomepage_public';
                        $who = '@user';
                        $perm = (int)$this->getConf('acl_user_templates');
                        if (!in_array("$where\t$who\t$perm\n", $existingLines)) { $newLines[] = array('where' => $where, 'who' => $who, 'perm' => $perm); }
                    }
                } // end of templates acl
                $i = count($newLines);
                if ($i > 0) {
                    msg($this->getLang('aclupdate').' '.$i, 1);
                    foreach($newLines as $line) {
                        if (($line['where'] != null) && ($line['who'] != null)) {
                            // delete potential ACL rule with same scope (aka 'where') and same user (aka 'who')
                            $acl->deleteACL($line['where'], $line['who']);
                            $acl->addOrUpdateACL($line['where'], $line['who'], $line['perm']);
                        }
                    }
                }
            }
        } else {
            return false;
        }
    }

    function copyFile($source = null, $target_dir = null, $target_file = null) {
        if (!is_file($this->dataDir.DIRECTORY_SEPARATOR.$target_dir.DIRECTORY_SEPARATOR.$target_file)) {
            if(!is_dir($this->dataDir.DIRECTORY_SEPARATOR.$target_dir)){
                io_mkdir_p($this->dataDir.DIRECTORY_SEPARATOR.$target_dir) || msg($this->getLang('mkdirfailure').' '.$target_dir,-1);
            }
            $source = str_replace('/', DIRECTORY_SEPARATOR, $source);
            copy($source, $this->dataDir.DIRECTORY_SEPARATOR.$target_dir.DIRECTORY_SEPARATOR.$target_file);
            if (is_file($this->dataDir.DIRECTORY_SEPARATOR.$target_dir.DIRECTORY_SEPARATOR.$target_file)) {
                msg($this->getLang('copysuccess').' ('.$source.' > '.$this->dataDir.DIRECTORY_SEPARATOR.$target_dir.DIRECTORY_SEPARATOR.$target_file.')', 1);
            } else {
                msg($this->getLang('copyerror').' ('.$source.' > '.$this->dataDir.DIRECTORY_SEPARATOR.$target_dir.DIRECTORY_SEPARATOR.$target_file.')', -1);
            }
        } else {
            msg($this->getLang('copynotneeded').' ('.$source.' > '.$this->dataDir.DIRECTORY_SEPARATOR.$target_dir.DIRECTORY_SEPARATOR.$target_file.')', 0);
        }
    }

    function privateNamespace() {
        if ( $this->getConf('use_name_string')) {
            global $INFO;
            $raw_string = cleanID($INFO['userinfo']['name']);
            // simon_delage
            return $raw_string;
        } else {
            // simon
            return strtolower($_SERVER['REMOTE_USER']);
        }
    }

    function privateStart() {
        if ($this->getConf('use_start_page')) {
            global $conf;
            return cleanID($conf['start']);
        } else {
            return $this->privateNamespace();
        }
    }

    function applyTemplate($type) {
        global $conf;
        if ($type == 'private') {
            $content = io_readFile($this->private_page_template, false);
        } elseif ($type == 'public') {
            $content = io_readFile($this->public_page_template, false);
        } else {
            $content = io_readFile($type, false);
        }
        // If template is absolutely empty (ie. is NULL), target file will not be created but plugin will pretend it did and will keep trying over next page load
        if ($content == NULL) {
            // fill empty template with a simple space to work around the problem
            $content = " ";
        }

        $content = str_replace('@TARGETPRIVATEPAGE@', $this->helper->getPrivateID(), $content);
        $content = str_replace('@TARGETPRIVATENS@', cleanID(str_replace(':'.$conf['start'], '', $this->helper->getPrivateID())), $content);
        $content = str_replace('@TARGETPUBLICPAGE@', $this->helper->getPublicID(), $content);
        $content = str_replace('@TARGETPUBLICNS@', cleanID(str_replace(':'.$conf['start'], '', $this->helper->getPublicID())), $content);
        // Improved template process to use standard replacement patterns from https://www.dokuwiki.org/namespace_templates based on code proposed by Christian Nancy
        // Build a fake data structure for the parser
        $data = array('tpl' => $content, 'id' => $this->private_page);
        // Use the built-in parser
        $content = parsePageTemplate($data);
        return $content;
    }

    function replaceUserLink(&$event, $param) {
        global $INFO;
        global $conf;

        // If there's no conflict between private and public space
        if ($this->multiNsOk()) {
            if (($conf['showuseras'] == "username_link") and ($this->getConf('userlink_replace'))) {
                $classes = $this->getConf('userlink_classes');
                $classes = str_replace(',', ' ', $classes);
                //if ($this->getConf('userlink_fa')) {
                //    $classes = str_replace('interwiki', '', $classes);
                //}
                $this->username = $event->data['username'];
                $this->name = $event->data['name'];
                $this->link = $event->data['link'];
                $this->userlink = $event->data['userlink'];
                $this->textonly = $event->data['textonly'];
                // Logged in as...
                if (strpos($this->name, '<bdi>') !== false) {
                    $privateId = $this->helper->getPrivateID();
                    $publicId = $this->helper->getPublicID();
                    if ((page_exists($privateId)) && (page_exists($publicId))) {
                        if ($this->getConf('userlink_fa')) {
                            $return = '<a href="'.wl($privateId).'" class="'.$classes.' uhp_fa" rel="nofollow" title="'.$this->getLang('privatenamespace').' ('.$privateId.')'.'"><bdi><i class="fa fa-user-secret"></i>'.$INFO['userinfo']['name'].'</bdi></a> (<a href="'.wl($publicId).'" class="'.$classes.' uhp_fa" rel="nofollow" title="'.$this->getLang('publicpage').' ('.$publicId.')'.'"><bdi><i class="fa fa-user"></i>'.$_SERVER['REMOTE_USER'].'</bdi></a>)';
                        } else {
                            $return = '<a href="'.wl($privateId).'" class="'.$classes.' uhp_private" rel="nofollow" title="'.$this->getLang('privatenamespace').' ('.$privateId.')'.'"><bdi>'.$INFO['userinfo']['name'].'</bdi></a> (<a href="'.wl($publicId).'" class="'.$classes.' uhp_public" rel="nofollow" title="'.$this->getLang('publicpage').' ('.$publicId.')'.'"><bdi>'.$_SERVER['REMOTE_USER'].'</bdi></a>)';
                        }
                    } elseif (page_exists($publicId)) {
                        if ($this->getConf('userlink_fa')) {
                            $return = '</a> (<a href="'.wl($publicId).'" class="'.$classes.' uhp_fa" rel="nofollow" title="'.$this->getLang('publicpage').'('.$publicId.')'.'"><bdi><i class="fa fa-user"></i>'.$_SERVER['REMOTE_USER'].'</bdi></a>)';
                        } else {
                            $return = '<bdi>'.$INFO['userinfo']['name'].'</bdi> (<a href="'.wl($publicId).'" class="'.$classes.' uhp_public" rel="nofollow" title="'.$this->getLang('publicpage').' ('.$publicId.')'.'"><bdi>'.$_SERVER['REMOTE_USER'].'</bdi></a>)';
                        }
                    } elseif (page_exists($privateId)) {
                        if ($this->getConf('userlink_fa')) {
                            $return = '<a href="'.wl($privateId).'" class="'.$classes.' uhp_fa" rel="nofollow" title="'.$this->getLang('privatenamespace').' ('.$privateId.')'.'"><bdi><i class="fa fa-user-secret"></i>'.$INFO['userinfo']['name'].'</bdi></a>';
                        } else {
                            $return = '<a href="'.wl($privateId).'" class="'.$classes.' uhp_private" rel="nofollow" title="'.$this->getLang('privatenamespace').' ('.$privateId.')'.'"><bdi>'.$INFO['userinfo']['name'].'</bdi></a> (<bdi>'.$_SERVER['REMOTE_USER'].'</bdi>)';
                        }
                    } else {
                        $return = null;
                    }
                // ... or Last modified...
                } else {
                    // No change for this right now
                    $return = null;
                }
                if ($return != null) {
                    $event->data = array(
                        'username' => $this->username,
                        'name' => $this->name,
                        'link' => $this->link,
                        'userlink' => $return,
                        'textonly' => $this->textonly
                    );
                }
            }
        } else {
            return false;
        }
    }

    function multiNsOk($msg=false) {
        // Error: Public page switched to namespace and is in conflict with Private namespace
        if (strpos($this->getConf('public_pages_ns'),':%NAME%:%START%') !== false) {
            $PublicNS = str_replace(':%NAME%:%START%', '', $this->getConf('public_pages_ns'));
            $PublicNS = str_replace(':', '', $PublicNS);
            $PrivateNS = str_replace(':', '', $this->getConf('users_namespace'));
            if ($PublicNS == $PrivateNS) {
                if ($msg) {
                    msg($this->getLang('settingsconflict'), -1);
                }
                return false;
            } else {
                return true;
            }
        } else {
            return true;
        }
    }

    function userOk($check = null) {
        global $INFO;

        // Proceed only if named user is connected...
        if ($_SERVER['REMOTE_USER'] != null) {
            // Check if user is member of a group in 'groups_private' or 'groups_public' (depending on $check)
            if (($check == 'private') or ($check == 'public')) {
                // Stop if 'groups_private' is set and and user is not member of at least one of said groups
                $groups = $this->getConf('groups_'.$check);
                $groups = str_replace(' ','', $groups);
                $groups = explode(',', $groups);
                $userGroups = $INFO['userinfo']['grps'];
                // If UHP is set to check user's group(s)
                if (($groups != null) and ($groups[0] != null) and ($userGroups != null)) {
                    $test = array_intersect($groups, $userGroups);
                    // Proceed if user is member of at least one group set UHP's corresponding setting
                    if (count($test) > 0) {
                        return true;
                    } else {
                        return false;
                    }
                // If UHP isn't set to ckeck user's group(s) we can proceed
                } else {
                    return true;
                }
            // If $check is null, we only need to know that a named user is connected (wich we allready know if we went that far)
            } else {
                return true;
            }
        // ... else stop
        } else {
            return false;
        }
    }

}
