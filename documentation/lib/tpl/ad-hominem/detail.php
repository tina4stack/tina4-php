<?php
/**
 * Ad Hominem Template
 *
 * @link     https://ad.hominem.info/
 * @author   Sascha Leib <sascha@leib.be>
 * @author   Anika Henke <anika@selfthinker.org>
 * @author   Clarence Lee <clarencedglee@gmail.com>
 * @license  GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */
 
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

if (!defined('DOKU_INC')) die(); /* must be run from within DokuWiki */

require_once('my_template.php');

$htmlLang = ' lang="' . $conf['lang'] . ( $lang['direction'] != 'ltr' ? '" dir="'. $lang['direction'] : '') . '"';

?><!DOCTYPE html>
<html<?php echo $htmlLang ?>>
<head>
	<meta charset="UTF-8" />
	<title><?php echo hsc(tpl_img_getTag('IPTC.Headline',$IMG)) ?> &ndash; <?php echo str_replace(' ', ' ', strip_tags($conf['title'])) ?></title>
<?php my_metaheaders() ?>
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<?php my_favicons() ?>
<?php tpl_includeFile('meta.html') ?>
</head>
<body class="mediadetail <?php echo trim(tpl_classes()); ?>">
	<div id="skip-link">
		<a href="#main-content"><?php echo $lang['skip_to_content']; ?></a>
	</div>
	<div id="header-layout">
		<header>
			<div id="siteLogo">
				<?php
					// get logo either out of the template images folder or data/media folder
					$logoSize = array();
					$logo = tpl_getMediaFile(array(':wiki:logo.png', ':logo.png', 'images/sitelogo.svg'), false, $logoSize);
					tpl_link( wl(),
						'<img src="'.$logo.'" ' . $logoSize[3] . ' alt="' . htmlentities($conf['title']) . '" />', 'accesskey="h" title="[H]" class="logo"');
				?>

				<h2 class="title"><?php tpl_link( wl(), htmlentities($conf['title']), ''); ?></h2>
				<p class="claim"><?php echo $conf['tagline']; ?></p>
			</div>
			<div id="globalTools">
				<div id="gUserTools">
					<h3 class="sronly"><?php echo $lang['user_tools']; ?></h3>
					<ul>
<?php my_userinfo(str_repeat(chr(9),6)); ?>
					</ul>
				</div>
			</div>
			<div id="phSearch">
<?php include('tpl_searchform.php'); ?>
			</div>
			<div id="phTools"><!-- placeholder for additional tools --></div>
			<div id="phInclude"><?php tpl_includeFile('header.html') ?></div>
		</header>
	</div>
    <div id="main-layout">
		<div id="sidebar" class="toggle hide">
			<button class="tg_button" title="<?php echo $lang['sidebar'] ?>"><span><?php echo $lang['sidebar'] ?></span></button>
			<div class="tg_content">
				<nav id="sbNavigation">
<!-- - - - - - - - - SIDEBAR CONTENT - - - - - - - -->
<?php
					tpl_flush();
					tpl_includeFile('sidebarheader.html');
					tpl_include_page($conf['sidebar'], true, true);
					tpl_includeFile('sidebarfooter.html');
?>				</nav>
<!-- - - - - - - - - END OF SIDEBAR CONTENT  - - - - - - - -->
				<div id="sbBreadcrumbs">
<?php		if($conf['breadcrumbs']) { my_breadcrumbs(str_repeat(chr(9),4)); } ?>
				</div>
			</div>
		</div>
		<main id="dokuwiki__top">
			<header>
<?php			my_toc(str_repeat(chr(9),4)); 
				tpl_flush();
				tpl_includeFile('pageheader.html');
				if($conf['youarehere']) { my_youarehere(str_repeat(chr(9),4)); }
?>			</header>
			<article id="main-content">
<!-- - - - - - - - - MEDIA DETAIL CONTENT - - - - - - - -->
<?php

					html_msgarea();

					tpl_flush();
					tpl_includeFile('pageheader.html');

					if($ERROR): ?>
				<h1><?php echo $ERROR; ?></h1>
<?php				else:
						if($REV) echo p_locale_xhtml('showrev');
?>				<figure class="print-wide">
					<h1><?php echo nl2br(hsc(tpl_img_getTag('simple.title'))); ?></h1>
					
					<p class="center"><?php tpl_img(1088,800); /* parameters: maximum width, maximum height (and more) */ ?></p>
					<figcaption>

						<table class="img_detail">
							<tbody>
						<?php my_img_meta(str_repeat(chr(9),8)); ?>
								<tr><th><?php echo $lang['reference']; ?></th>
									<td><ul><?php
										$media_usage = ft_mediause($IMG,true);
										if(count($media_usage) > 0){
											foreach($media_usage as $path){
												echo '<li>'.html_wikilink($path).'</li>';
											}
										}else{
											echo '<li>'.$lang['nothingfound'].'</li>';
										}
									?></ul>
								</td></tr>
								<tr><td></td><td><small><?php echo $lang['media_acl_warning']; ?></small></td></tr>
							</tbody>
						</table>
						<?php //Comment in for Debug// dbg(tpl_img_getTag('Simple.Raw'));?>
					<?php endif; ?>
					</figcaption>
				</figure><?php tpl_flush() ?>	
			</article>
			<footer>
<?php tpl_includeFile('pagefooter.html') ?>
			</footer>
		</main>
	</div>
	<div id="footer-layout">
		<footer>
			<div id="ftPlaceholder" class="ftSection"></div>
			<div id="ftInclude" class="ftSection">
<?php tpl_includeFile('footer.html'); ?>
			</div>
			<div id="ftSiteTools" class="ftSection">
				<h4><?php echo $lang['site_tools']; ?></h4>
				<ul>
					<?php echo (new \dokuwiki\Menu\SiteMenu())->getListItems('action ', false); ?>

				</ul>
			</div>
			<div id="ftPageTools" class="ftSection">
				<h4><?php echo $lang['page_tools']; ?></h4>
				<ul>
					<?php echo (new \dokuwiki\Menu\DetailMenu())->getListItems('', false); ?>

				</ul>
			</div>
<?php include('tpl_footer.php') ?>
		</footer>
	</div>
	<div class="no"><?php tpl_indexerWebBug() /* provide DokuWiki housekeeping, required in all templates */ ?></div>
	<div id="screen__mode" class="no"></div><?php /* helper to detect CSS media query in script.js */ ?>
</body>
</html>