<?php
/**
 * Configuration defaults file for Userhomepage plugin
 * Previous authors: James GuanFeng Lin, Mikhail I. Izmestev, Daniel Stonier
 * @author   Simon DELAGE <sdelage@gmail.com>
 * @license: CC Attribution-Share Alike 3.0 Unported <http://creativecommons.org/licenses/by-sa/3.0/>
 */

    $conf['create_private_ns'] = 0;
    $conf['use_name_string'] = 0;
    $conf['use_start_page'] = 1;
    $conf['users_namespace'] = 'user';
    $conf['group_by_name'] = 0;
    $conf['edit_before_create'] = 0;
    $conf['acl_all_private'] = '0';
    $conf['acl_user_private'] = '0';
    $conf['groups_private'] = '';
    $conf['create_public_page'] = 0;
    $conf['public_pages_ns'] = 'user';
    $conf['acl_all_public'] = '1';
    $conf['acl_user_public'] = '1';
    $conf['groups_public'] = '';
    $conf['templates_path'] = './pages/user';
    $conf['templatepath'] = 'lib/plugins/userhomepage/_template.txt';
    $conf['acl_all_templates'] = '1';
    $conf['acl_user_templates'] = '1';
    $conf['no_acl'] = 0;
    $conf['redirection'] = 1;
    $conf['action'] = 'edit';
    $conf['userlink_replace'] = 1;
    $conf['userlink_classes'] = 'interwiki iw_user';
    $conf['userlink_fa'] = 0;
