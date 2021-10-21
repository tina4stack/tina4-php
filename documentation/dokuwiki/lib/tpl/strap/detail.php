<?php
/**
 * DokuWiki Image Detail Page
 *
 */

//Library of template function
use ComboStrap\TplUtility;
use dokuwiki\Extension\Event;

require_once(__DIR__ . '/class/TplUtility.php');

global $lang;
global $conf;
global $IMG;
global $ERROR;
global $REV;

/**
 * Bootstrap meta-headers
 */
TplUtility::registerHeaderHandler();

// must be run from within DokuWiki
if (!defined('DOKU_INC')) die();

/**
 * Should be run before the HTML head function
 * (ie {@link tpl_metaheaders}
 */
$railBar = TplUtility::getRailBar();
$pageHeader = TplUtility::getPageHeader();
$pageFooter = TplUtility::getFooter();

?>
<!DOCTYPE html>
<html lang="<?php echo $conf['lang'] ?>" dir="<?php echo $lang['direction'] ?>" class="no-js">
<head>

    <?php // Avoid using character entities in your HTML, provided their encoding matches that of the document (generally UTF-8) ?>
    <meta charset="utf-8"/>

    <?php // Responsive meta tag ?>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>

    <script>(function (H) {
            H.className = H.className.replace(/\bno-js\b/, 'js')
        })(document.documentElement)
    </script>

    <?php // Headers ?>
    <?php tpl_metaheaders() ?>

    <title>
        <?php echo hsc(tpl_img_getTag('IPTC.Headline', $IMG)) ?>
        [<?php echo strip_tags($conf['title']) ?>]
    </title>

    <?php // Favicon ?>
    <?php TplUtility::renderFaviconMetaLinks() ?>

    <?php // Hook ?>
    <?php tpl_includeFile('meta.html') ?>

</head>

<body>

<?php echo $pageHeader ?>

<div class="site container <?php echo tpl_classes(); ?>" style="position: relative">

    <?php // To go at the top of the page, style is for the fix top page --> ?>
    <div id="dokuwiki__top"></div>


    <?php
    //  A trigger to show content on the top part of the website
    $data = "";// Mandatory
    Event::createAndTrigger('TPL_PAGE_TOP_OUTPUT', $data);
    ?>

    <!-- Must contain One row -->
    <div class="row" style="min-height: 60vh">

        <div role="main" class="col-md-<?php tpl_getConf(TplUtility::CONF_GRID_COLUMNS) ?>">
            <!-- ********** CONTENT ********** -->


            <?php tpl_flush() ?>
            <?php tpl_includeFile('pageheader.html') ?>
            <!-- detail start -->
            <?php
            if ($ERROR):
                echo '<h1>' . $ERROR . '</h1>';
            else: ?>
                <?php if ($REV) echo p_locale_xhtml('showrev'); ?>
                <h1><?php echo nl2br(hsc(tpl_img_getTag('simple.title'))); ?></h1>

                <p>
                    <?php tpl_img(900, 700); /* parameters: maximum width, maximum height (and more) */ ?>
                </p>

                <div class="img_detail">
                    <?php tpl_img_meta(); ?>
                </div>
                <?php //Comment in for Debug// dbg(tpl_img_getTag('Simple.Raw'));?>
            <?php endif; ?>

            <!-- detail stop -->
            <?php tpl_includeFile('pagefooter.html') ?>
            <?php tpl_flush() ?>

            <?php /* doesn't make sense like this; @todo: maybe add tpl_imginfo()? <div class="docInfo"><?php tpl_pageinfo(); ?></div> */ ?>

        </div>

    </div>

    <?php echo $railBar ?>
</div>

<?php echo $pageFooter ?>
<?php echo TplUtility::getPoweredBy() ?>

<?php
// The stylesheet (before indexer work and script at the end)
TplUtility::addPreloadedResources();
?>


</body>
</html>
