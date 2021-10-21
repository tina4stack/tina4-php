<?php
/**
 * Copyright (c) 2020. ComboStrap, Inc. and its affiliates. All Rights Reserved.
 *
 * This source code is licensed under the GPL license found in the
 * COPYING  file in the root directory of this source tree.
 *
 * @license  GPL 3 (https://www.gnu.org/licenses/gpl-3.0.en.html)
 * @author   ComboStrap <support@combostrap.com>
 *
 */
require_once(__DIR__.'/../../class/TplUtility.php');


use ComboStrap\TplUtility;

$lang['debug'] = 'Enable the rendering / processing of debug information';

// for the configuration manager
$lang[TplUtility::CONF_SIDEKICK_SLOT_PAGE_NAME] = '<a href="https://combostrap.com/sidekick_slot">Sidekick Slot</a> - The name of the page to search for the sidekick slot (right side)';
$lang[TplUtility::CONF_FOOTER_SLOT_PAGE_NAME] = '<a href="https://combostrap.com/footer_slot">Footer Slot</a> - The name of the page to search for the footer page slot';
$lang[TplUtility::CONF_HEADER_SLOT_PAGE_NAME] = '<a href="https://combostrap.com/header_slot">Header Slot</a> - The name of the page to search for the header page slot';

$lang[TplUtility::CONF_USE_CDN] = '<a href="https://combostrap.com/cdn">CDN</a> - Use a frontend CDN for the Bootstrap files';

$lang[TplUtility::CONF_REM_SIZE] = '<a href="https://combostrap.com/length/scale">Length Scale</a> - This configuration define in pixels the value of 1 rem';



$lang[TplUtility::CONF_GRID_COLUMNS] = '<a href="https://combostrap.com/dynamic/grid">Dynamic Grid</a> - The number of columns in the grid';


$lang['preloadCss'] = '<a href="https://combostrap.com/css#preloadCSS">CSS Optimization</a> - Enable CSS Preloading (!The page rendering will flash - FOUC)';


$lang[TplUtility::CONF_PRIVATE_RAIL_BAR] = '<a href="https://combostrap.com/railbar">Railbar</a> - Enable private railbar';
$lang[TplUtility::CONF_BREAKPOINT_RAIL_BAR] = '<a href="https://combostrap.com/railbar">Railbar</a> - Breakpoint when the railbar toggle from offcanvas to fixed component';

$lang[TplUtility::CONF_BOOTSTRAP_VERSION_STYLESHEET] = '<a href="https://combostrap.com/bootstrap">Bootstrap</a> - The Bootstrap version and a corresponding stylesheet';

$lang[TplUtility::CONF_JQUERY_DOKU] = '<a href="https://combostrap.com/jquery">Jquery</a> - Use the DokuWiki Jquery (Only valid for Bootstrap 4)';

$lang[TplUtility::CONF_DISABLE_BACKEND_JAVASCRIPT] = '<a href="https://combostrap.com/frontend/optimization">FrontEnd Optimization</a> - Delete backend javascript library for public users';

?>
