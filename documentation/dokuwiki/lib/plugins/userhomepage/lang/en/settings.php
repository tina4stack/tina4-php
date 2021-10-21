<?php
/**
 * English settings file for Userhomepage plugin
 * Previous authors: James GuanFeng Lin, Mikhail I. Izmestev, Daniel Stonier
 * @author   Simon DELAGE <sdelage@gmail.com>
 * @license: CC Attribution-Share Alike 3.0 Unported <http://creativecommons.org/licenses/by-sa/3.0/>
 */

    $lang['create_private_ns'] = 'Create user\'s private namespace (double-check all options before enabling)?';
    $lang['use_name_string'] = 'Use user\'s full name instead of login for his private namespace.';
    $lang['use_start_page'] = 'Use the wiki\'s start page name for the start page of each private namespace (otherwise, the private namespace name will be used).';
    $lang['users_namespace'] = 'Namespace under which user namespaces are created.';
    $lang['group_by_name'] = 'Group users\' namespaces by the first character of user name?';
    $lang['edit_before_create'] = 'Allow users to edit the start page of their private namespace on creation (will only work if a public page isn\'t generated at the same time).';
    $lang['acl_all_private'] = 'Permissions for @ALL group on Private Namespaces';
    $lang['acl_all_private_o_0'] = 'None (Default)';
    $lang['acl_all_private_o_1'] = 'Read';
    $lang['acl_all_private_o_2'] = 'Edit';
    $lang['acl_all_private_o_4'] = 'Create';
    $lang['acl_all_private_o_8'] = 'Upload';
    $lang['acl_all_private_o_16'] = 'Delete';
    $lang['acl_all_private_o_noacl'] = 'No automatic ACL';
    $lang['acl_user_private'] = 'Permissions for @user group on Private Namespaces';
    $lang['acl_user_private_o_0'] = 'None (Default)';
    $lang['acl_user_private_o_1'] = 'Read';
    $lang['acl_user_private_o_2'] = 'Edit';
    $lang['acl_user_private_o_4'] = 'Create';
    $lang['acl_user_private_o_8'] = 'Upload';
    $lang['acl_user_private_o_16'] = 'Delete';
    $lang['acl_user_private_o_noacl'] = 'No automatic ACL';
    $lang['groups_private'] = 'Comma separated list of user groups concerned by Private Namespace creation (leave empty to apply above settings to all users).';
    $lang['create_public_page'] = 'Create a user\'s public page?';
    $lang['public_pages_ns'] = 'Namespace under wich public pages are created.';
    $lang['acl_all_public'] = 'Permissions for @ALL group on Public Pages';
    $lang['acl_all_public_o_0'] = 'None';
    $lang['acl_all_public_o_1'] = 'Read (Default)';
    $lang['acl_all_public_o_2'] = 'Edit';
    $lang['acl_all_public_o_noacl'] = 'No automatic ACL';
    $lang['acl_user_public'] = 'Permissions for @user group on Public Pages';
    $lang['acl_user_public_o_0'] = 'None';
    $lang['acl_user_public_o_1'] = 'Read (Default)';
    $lang['acl_user_public_o_2'] = 'Edit';
    $lang['acl_user_public_o_noacl'] = 'No automatic ACL';
    $lang['groups_public'] = 'Comma separated list of user groups concerned by Public Page creation (leave empty to apply above settings to all users).';
    $lang['templates_path'] = 'Relative path from [<code>savedir</code>] where templates will be stored (userhomepage_private.txt and userhomepage_public.txt). Examples: <code>./pages/user</code> or <code>../lib/plugins/userhomepage</code>.';
    $lang['templatepath'] = 'Template path from version 3.0.4. If this file exists, it will be used as default source for new private namespace start page template (clear the path if you don\'t want to).';
    $lang['acl_all_templates'] = 'Permissions for @ALL group on templates (if they are stored in <code>data/pages...</code>)';
    $lang['acl_all_templates_o_0'] = 'None';
    $lang['acl_all_templates_o_1'] = 'Read (Default)';
    $lang['acl_all_templates_o_2'] = 'Edit';
    $lang['acl_all_templates_o_noacl'] = 'No automatic ACL';
    $lang['acl_user_templates'] = 'Permissions for @user group on templates (if they are stored in <code>data/pages...</code>)';
    $lang['acl_user_templates_o_0'] = 'None';
    $lang['acl_user_templates_o_1'] = 'Read (Default)';
    $lang['acl_user_templates_o_2'] = 'Edit';
    $lang['acl_user_templates_o_noacl'] = 'No automatic ACL';
    $lang['no_acl'] = 'No automated ACL setting at all but you\'ll have to remove those created so far manually. Don\'t forget to set some ACL on templates.';
    $lang['redirection'] = 'Enable redirection (even if disabled, it will still occur on pages creation).';
    $lang['action'] = 'Action on first redirection to public page after it\'s creation (or private namespace start page).';
    $lang['action_o_edit'] = 'Edit (Default)';
    $lang['action_o_show'] = 'Show';
    $lang['userlink_replace'] = 'Enable replacement of [<code>Logged in as</code>] interwiki link, depending on pages created by Userhomepage (only works if <code>showuseras</code> option is set to interwiki link).';
    $lang['userlink_classes'] = 'Space separated list of CSS classes to apply to [<code>Logged in as</code>] interwiki links (default: <code>interwiki iw_user wikilink1</code>).';
    $lang['userlink_fa'] = 'Use Fontawesome icons instead of images (Fontawesome has to be installed by template or a plugin) ?';
