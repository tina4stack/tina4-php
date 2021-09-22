<?php
/**
 * Template footer, included in the main and detail files
 */

// must be run from within DokuWiki
if (!defined('DOKU_INC')) die();
 ?>			<div id="ftLicenseButtons">
				<hr class="noprint" />
				<p class="license">
					<?php tpl_license('', false, false, false); tpl_includeFile('licenseinfo.html'); ?>
				</p>
<?php if($conf['license'] != null): ?>				<div class="buttons noprint">
					<?php
						tpl_license('button', true, false, false);
					
						$target = ($conf['target']['extern']) ? 'target="'.$conf['target']['extern'].'"' : '';
					?>
					<a href="https://www.dokuwiki.org/donate" title="Donate" <?php echo $target?>><img src="<?php echo tpl_basedir(); ?>images/button-donate.gif" width="80" height="15" alt="Donate" /></a>
					<a href="https://php.net" title="Powered by PHP" <?php echo $target?>><img src="<?php echo tpl_basedir(); ?>images/button-php.gif" width="80" height="15" alt="Powered by PHP" /></a>
					<a href="//validator.w3.org/check/referer" title="Valid HTML5" <?php echo $target?>><img src="<?php echo tpl_basedir(); ?>images/button-html5.png" width="80" height="15" alt="Valid HTML5" /></a>
					<a href="//jigsaw.w3.org/css-validator/check/referer?profile=css3" title="Valid CSS" <?php echo $target?>><img src="<?php echo tpl_basedir(); ?>images/button-css.png" width="80" height="15" alt="Valid CSS" /></a>
					<a href="https://dokuwiki.org/" title="Driven by DokuWiki" <?php echo $target?>><img src="<?php echo tpl_basedir(); ?>images/button-dw.png" width="80" height="15" alt="Driven by DokuWiki" /></a>
				</div><?php endif; ?>

			</div>
