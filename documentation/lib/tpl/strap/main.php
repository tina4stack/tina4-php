<?php


//Library of template function

use Combostrap\TplUtility;
use dokuwiki\Extension\Event;
use dokuwiki\Menu\PageMenu;
use dokuwiki\Menu\SiteMenu;
use dokuwiki\Menu\UserMenu;

require_once(__DIR__ . '/class/TplUtility.php');


if (!defined('DOKU_INC')) die(); /* must be run from within DokuWiki */


TplUtility::setHttpHeader();

global $ID;
global $lang;
global $ACT;
global $conf;


/**
 * The Content first because it contains
 * also the front matter that may influence the other bars
 *
 * The content: Show, Edit, ....
 */
$mainHtml = TplUtility::tpl_content($prependTOC = false);

/**
 * Sidebar
 */
$sidebarName = $conf['sidebar'];

$hasSidebar = page_findnearest($sidebarName);
$showSideBar = $hasSidebar && ($ACT == 'show');
if ($showSideBar) {
    /**
     * Even if there is no sidebar
     * the rendering may output
     * debug information in the form of
     * an HTML comment
     */
    $sideBarHtml = TplUtility::renderBar($sidebarName);
}


/**
 * Sidekickbar
 */
$sideKickPageName = TplUtility::getSideKickSlotPageName();
$hasRightSidebar = page_findnearest($sideKickPageName);
$showSideKickBar = $hasRightSidebar && ($ACT == 'show');
if ($showSideKickBar) {
    $sideKickBarHtml = TplUtility::renderBar($sideKickPageName);
}

/**
 * Headerbar
 */
$headerBar = TplUtility::getHeader();

/**
 * Footerbar
 */
$footerBar = TplUtility::getFooter();


/**
 * Grid
 */
$gridColumns = tpl_getConf(TplUtility::CONF_GRID_COLUMNS);
/**
 * Layout
 *
 * See also: https://1linelayouts.glitch.me/ and https://www.cssportal.com/layout-generator/layout.php
 *
 * Two basic layouts for the web: fixed or liquid
 * A liquid design (also referred to as a fluid or dynamic design) fills the entire browser window by using percentages
 * rather than fixed pixel values to define the width / height
 *
 * dimension =
 *   "fluid" = max-width / min-height
 *   "contained" =
 *
 * In fluid web design, the widths of page elements are set proportional to the width of the screen or browser window.
 * A fluid website expands or contracts based on the width of the current viewport.
 *
 * Contained (ie fixed)
 * https://getbootstrap.com/docs/5.0/layout/containers/
 *
 */
// for the identity forms
global $ACT;
if (in_array($ACT, ["login", "resendpwd", "register", "profile"])) {
    $layout = "median";
} else {
    $layout = p_get_metadata($ID, "layout");
}
if ($layout === "median") {
    $maximalWidthMain = 8;
} else {
    $maximalWidthMain = $gridColumns;
}
$sidebarScale = 3;
$sideKickBarScale = 3;
if ($showSideBar) {
    $mainGridScale = $showSideKickBar ? $gridColumns - $sidebarScale - $sideKickBarScale : $gridColumns - $sidebarScale;
} else {
    $mainGridScale = $showSideKickBar ? $gridColumns - $sideKickBarScale : $maximalWidthMain;
}

/**
 * Landing page
 */
$mainIsContained = true;
if ($ACT != "show") {
    $mainIsContained = true;
} else {
    if ($layout == "landing") {
        $mainIsContained = false;
        // No gutter on x otherwise there is a overflow on the right
        $landingPageGutter = "style=\"--bs-gutter-x: 0\"";
    }
}
if ($mainIsContained) {
    $mainContainedClasses = "container mb-3";
}

/**
 * Bootstrap meta-headers
 */
TplUtility::registerHeaderHandler();

/**
 * Default rem font size
 */
$rootStyle = "";
$htmlRem = tpl_getConf(TplUtility::CONF_REM_SIZE, null);
if ($htmlRem != null) {
    $rootStyle = "style=\"font-size:{$htmlRem}px\"";
}

/**
 * The output buffer should be empty
 */
TplUtility::outputBufferShouldBeEmpty();


?>

<?php // DocType Required: https://getbootstrap.com/docs/5.0/getting-started/introduction/#html5-doctype ?>
<!DOCTYPE html >
<?php
/**
 * Lang for a page
 *
 * https://www.w3.org/International/questions/qa-html-language-declarations
 *   * Always use a language attribute on the html element.
 *   * When serving XHTML 1.x (ie. using a MIME type such as application/xhtml+xml),
 * use both the lang attribute and the xml:lang attribute together
 *
 * See also {@link \ComboStrap\Lang::processLangAttribute()} for the localization of an element
 *
 * @var $javascriptRTL - put the button to the end when the page has a language direction of rtl
 */
$javascriptRTL = "";
if ($lang['direction'] == "rtl") {
    $javascriptRTL = <<<EOF
<script>
document.addEventListener('DOMContentLoaded', () => {
    Array.from(document.getElementsByClassName("secedit")).forEach(e=>e.classList.add('float-end'));
    }
);
</script>
EOF;

}
?>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="<?php echo $conf['lang'] ?>" lang="<?php echo $conf['lang'] ?>"
      dir="<?php echo $lang['direction'] ?>" <?php echo $rootStyle ?>>
<head>

    <?php // Avoid using character entities in your HTML, provided their encoding matches that of the document (generally UTF-8) ?>
    <meta charset="utf-8">

    <?php // Responsive meta tag ?>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>

    <?php // Headers ?>
    <?php tpl_metaheaders() ?>

    <title><?php TplUtility::renderPageTitle() ?></title>


    <?php // Favicon ?>
    <?php echo TplUtility::renderFaviconMetaLinks() ?>

    <?php
    /**
     * In case of a fix bar
     */
    echo TplUtility::getHeadStyleNodeForFixedTopNavbar();
    ?>

    <?php
    /**
     * In case of a RTL lang,
     * we put the secedit button to the left
     */
    echo $javascriptRTL;
    ?>

    <?php
    /**
     * When we have a landing page, the page tools bar
     * which is by default on the right side is not visible
     * This setting will set up inside and make it visible alongside the page
     */
    if ($layout == "landing" & $ACT == "show") { ?>
        <style>
            #dokuwiki__pagetools {
                right: 44px !important;
            }
        </style>
    <?php } ?>

    <?php
    /**
     * To be above the first h1 heading
     * Otherwise when using a fix top bar,
     * you can't click on them
     */
    if (!$conf['breadcrumbs']) { ?>
        <style>#breadcrumb li {
                z-index: 100
            }</style>
    <?php } ?>

</head>
<?php
// * tpl_classes will add the dokuwiki class. See https://www.dokuwiki.org/devel:templates#dokuwiki_class
// * dokuwiki__top ID is needed for the "Back to top" utility
// * used also by some plugins
?>
<body class="dokuwiki" style="padding-top: <?php echo TplUtility::getPaddingTop() ?>px;">


<?php
echo $headerBar

// Relative positioning is important for the positioning of the pagetools
?>
<div class="<?php echo $mainContainedClasses ?> <?php echo tpl_classes() ?> " style="position: relative">


    <?php // To go at the top of the page, style is for the fix top page --> ?>
    <div id="dokuwiki__top"></div>


    <?php
    // The global message array
    html_msgarea()
    ?>


    <?php
    //  A trigger to show content on the top part of the website
    $data = "";// Mandatory
    Event::createAndTrigger('TPL_PAGE_TOP_OUTPUT', $data);
    ?>


    <?php
    global $ID;
    global $conf;
    if ($ID != $conf["start"]) {
        TplUtility::renderTrailBreadcrumb();
    }
    ?>

    <div class="row justify-content-md-center" <?php echo($landingPageGutter) ?>>


        <?php
        // SIDE BAR
        if ($showSideBar): ?>
            <div role="complementary"
                 class="col-md-<?php echo($sidebarScale) ?> order-last order-md-first d-print-none">

                <nav class="bs-docs-sidebar hidden-prints">


                    <?php echo $sideBarHtml ?>

                </nav>

            </div>
        <?php endif; ?>


        <main class="col-md-<?php echo($mainGridScale) ?> order-first">

            <?php
            // Add a p around the content to enable the reader view in Mozilla
            // https://github.com/mozilla/readability
            // But Firefox close the P because they must contain only inline element ???
            echo $mainHtml;
            ?>

        </main>


        <?php
        // SIDE BAR
        if ($showSideKickBar): ?>

            <div role="complementary"
                 class="col-md-<?php echo($sideKickBarScale) ?> order-xs-2 order-md-last d-print-none">

                <nav class="bs-docs-sidebar hidden-prints">

                    <?php tpl_flush() ?>

                    <?php
                    echo $sideKickBarHtml

                    // <a class="back-to-top" href="#dokuwiki__top"> Back to top </a>
                    ?>

                </nav>


                <?php
                // A trigger to show content on the sidebar part of the website
                $data = "";// Mandatory
                Event::createAndTrigger('TPL_SIDEBAR_BOTTOM_OUTPUT', $data);
                ?>

            </div>
        <?php
            // end content
        endif;
        ?>

    </div>


    <?php
    // PAGE/USER/SITE ACTIONS
    if (!(tpl_getConf('privateToolbar') === 1 && empty($_SERVER['REMOTE_USER']))) { ?>
        <div id="dokuwiki__pagetools" style="z-index: 1030;" class="d-none d-md-block">
            <div class="tools">
                <ul>
                    <?php echo (new PageMenu())->getListItems(); ?>
                    <?php echo (new UserMenu())->getListItems('action'); ?>
                    <?php echo (new SiteMenu())->getListItems('action'); ?>
                    <?php // FYI: for all menu in mobile: echo (new \dokuwiki\Menu\MobileMenu())->getDropdown($lang['tools']); ?>
                </ul>
            </div>
        </div>
    <?php } ?>

</div>


<?php
// Footer
echo $footerBar;
// Powered By
echo TplUtility::getPoweredBy();
// The stylesheet (before indexer work and script at the end)
TplUtility::addPreloadedResources();
?>


<div class="d-none">
    <?php
    // Indexer (Background tasks)
    tpl_indexerWebBug()
    ?>
</div>


</html>
