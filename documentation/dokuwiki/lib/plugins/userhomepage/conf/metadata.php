<?php
/**
 * Configuration metadata file for Userhomepage plugin
 * Previous authors: James GuanFeng Lin, Mikhail I. Izmestev, Daniel Stonier
 * @author   Simon DELAGE <sdelage@gmail.com>
 * @license: CC Attribution-Share Alike 3.0 Unported <http://creativecommons.org/licenses/by-sa/3.0/>
 */

    $meta['create_private_ns'] = array('onoff');
    $meta['use_name_string'] = array('onoff');
    $meta['use_start_page'] = array('onoff');
    $meta['users_namespace'] = array('string','_pattern' => '/^(|[a-zA-Z\-:]+)$/');
    $meta['group_by_name'] = array('onoff');
    $meta['edit_before_create'] = array('onoff');
    $meta['acl_all_private'] = array('multichoice','_choices'=>array('0','1','2','4','8','16','noacl'));
    $meta['acl_user_private'] = array('multichoice','_choices'=>array('0','1','2','4','8','16','noacl'));
    $meta['groups_private'] = array('string');
    $meta['create_public_page'] = array('onoff');
    $meta['public_pages_ns'] = array('string','_pattern' => '/^(|[a-zA-Z\-:%]+)$/','_caution' => 'warning');
    $meta['acl_all_public'] = array('multichoice','_choices'=>array('0','1','2','noacl'));
    $meta['acl_user_public'] = array('multichoice','_choices'=>array('0','1','2','noacl'));
    $meta['groups_public'] = array('string');
    $meta['templates_path'] = array('string');
    $meta['templatepath'] = array('string');
    $meta['acl_all_templates'] = array('multichoice','_choices'=>array('0','1','2','noacl'));
    $meta['acl_user_templates'] = array('multichoice','_choices'=>array('0','1','2','noacl'));
    $meta['no_acl'] = array('onoff');
    $meta['redirection'] = array('onoff');
    $meta['action'] = array('multichoice','_choices'=>array('edit','show'));
    $meta['userlink_replace'] = array('onoff');
    $meta['userlink_classes'] = array('string');
    $meta['userlink_fa'] = array('onoff');
