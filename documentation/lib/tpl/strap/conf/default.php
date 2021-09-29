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

use ComboStrap\TplUtility;

/**
 * The default value don't use false but 1
 * if you want to use an on/off
 * because false is the value returned when no configuration is found
 */



/**
 * CDN for anonymous
 * See {@link TplUtility::CONF_USE_CDN}
 */
$conf['useCDN'] = 1;

// Print Debug statement
$conf['debug'] = 1;


/**
 * {@link TplUtility::CONF_BOOTSTRAP_VERSION_STYLESHEET}
 */
$conf["bootstrapVersionStylesheet"] = "5.0.1 - bootstrap";

$conf['gridColumns'] = 12;

$conf['gridColumns'] = 12;


$conf['preloadCss'] = 0;

$conf['preloadCss'] = 0;

/**
 * {@link TplUtility::CONF_PRIVATE_RAIL_BAR}
 */
$conf['privateRailbar'] = 0;
/**
 * {@link TplUtility::CONF_BREAKPOINT_RAIL_BAR}
 */
$conf['breakpointRailbar'] = "large";

/**
 * @see {@link TplUtility::CONF_JQUERY_DOKU}
 */
$conf['jQueryDoku'] = 0;

/**
 * See {@link TplUtility::CONF_DISABLE_BACKEND_JAVASCRIPT}
 */
$conf["disableBackendJavascript"] = 0;




?>
