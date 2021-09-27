<?php

//configuration metadata describe properties of the settings as used by the Configuration Manager
//https://www.dokuwiki.org/devel:configuration#configuration_metadata

require_once(__DIR__ . '/../class/TplUtility.php');


use ComboStrap\TplUtility;

$meta[TplUtility::CONF_FOOTER_SLOT_PAGE_NAME] = array('string',
    "_caution" => "warning", // Show a warning
    "_pattern" => "/[a-zA-Z0-9]*/" // Only Accept alphanumeric characters
);

$meta[TplUtility::CONF_HEADER_SLOT_PAGE_NAME] = array('string',
    "_caution" => "warning", // Show a warning
    "_pattern" => "/[a-zA-Z0-9]*/" // Only Accept alphanumeric characters
);

$meta[TplUtility::CONF_SIDEKICK_SLOT_PAGE_NAME] = array('string',
    "_caution" => "warning", // Show a warning
    "_pattern" => "/[a-zA-Z0-9]*/" // Only Accept alphanumeric characters
);


/**
 * Do we use CDN when possible
 */
$meta[TplUtility::CONF_USE_CDN] = array('onoff');

/**
 * Do we print debug statement
 */
$meta['debug'] = array('onoff');

/**
 * The size of 1 rem in pixel
 */
$meta[TplUtility::CONF_REM_SIZE] = array('string');


$meta[TplUtility::CONF_GRID_COLUMNS] = array('multichoice', '_choices' => array('12', '16'));

$meta['preloadCss'] = array('onoff');

$meta[TplUtility::CONF_PRIVATE_RAIL_BAR] = array('onoff');
$meta[TplUtility::CONF_BREAKPOINT_RAIL_BAR] = array('multichoice', '_choices' => array(
    TplUtility::BREAKPOINT_EXTRA_SMALL_NAME,
    TplUtility::BREAKPOINT_SMALL_NAME,
    TplUtility::BREAKPOINT_MEDIUM_NAME,
    TplUtility::BREAKPOINT_LARGE_NAME,
    TplUtility::BREAKPOINT_EXTRA_LARGE_NAME,
    TplUtility::BREAKPOINT_EXTRA_EXTRA_LARGE_NAME,
    TplUtility::BREAKPOINT_NEVER_NAME
));


// $meta[TplUtility::CONF_BOOTSTRAP_VERSION] = array('multichoice', '_choices' => array('4.4.1', '4.5.0', '5.0.1'));


$cssFiles = TplUtility::getStylesheetsForMetadataConfiguration();
$meta[TplUtility::CONF_BOOTSTRAP_VERSION_STYLESHEET] = array('multichoice', '_choices' => $cssFiles);

$meta[TplUtility::CONF_JQUERY_DOKU] = array('onoff');

$meta[TplUtility::CONF_DISABLE_BACKEND_JAVASCRIPT] = array('onoff');

?>
