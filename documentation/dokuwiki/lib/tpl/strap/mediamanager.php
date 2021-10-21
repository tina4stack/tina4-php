<?php

use ComboStrap\TplUtility;
require_once(__DIR__.'/class/TplUtility.php');

// must be run from within DokuWiki
if (!defined('DOKU_INC')) die();
header('X-UA-Compatible: IE=edge,chrome=1');
global $lang;
global $conf;

?><!DOCTYPE html>
<html lang="<?php echo $conf['lang']?>" dir="<?php echo $lang['direction'] ?>" class="popup no-js">
<head>
    <meta charset="utf-8" />
    <title>
        <?php echo hsc($lang['mediaselect'])?>
        [<?php echo strip_tags($conf['title'])?>]
    </title>
    <script>(function(H){H.className=H.className.replace(/\bno-js\b/,'js')})(document.documentElement)</script>
    <?php tpl_metaheaders()?>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <?php TplUtility::renderFaviconMetaLinks() ?>
    <?php tpl_includeFile('meta.html') ?>
</head>

<body>
    <!--[if lte IE 7 ]><div id="IE7"><![endif]--><!--[if IE 8 ]><div id="IE8"><![endif]-->
    <div id="media__manager" class="dokuwiki">
        <?php html_msgarea() ?>
        <div id="mediamgr__aside"><div class="pad">
            <h1><?php echo hsc($lang['mediaselect'])?></h1>

            <?php /* keep the id! additional elements are inserted via JS here */?>
            <div id="media__opts"></div>

            <?php tpl_mediaTree() ?>
        </div></div>

        <div id="mediamgr__content"><div class="pad">
            <?php tpl_mediaContent() ?>
        </div></div>
    </div>
    <!--[if ( lte IE 7 | IE 8 ) ]></div><![endif]-->
</body>
</html>
