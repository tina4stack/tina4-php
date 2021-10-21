<?php
/**
 * Overwriting DokuWiki template functions
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Sascha Leib <sascha@leib.be>
 * @author     Andreas Gohr <andi@splitbrain.org>
 */

use dokuwiki\Extension\Event;

/**
 * Print the specific HTML meta headers
 *
 * Overrides the original version by modifying the headers and the way it is printed
 *
 * @author Sascha Leib <sascha@leib.be>
 * @author Andreas Gohr <andi@splitbrain.org>
 *
 * @triggers TPL_METAHEADER_OUTPUT
 * @param  bool $alt Should feeds and alternative format links be added?
 * @return bool
 */
function my_metaheaders($alt = true) {
    global $ID;
    global $REV;
    global $INFO;
    global $JSINFO;
    global $ACT;
    global $QUERY;
    global $lang;
    global $conf;
    global $updateVersion;
    /** @var Input $INPUT */
    global $INPUT;

    // prepare the head array
    $head = array();

    // prepare seed for js and css
    $tseed   = $updateVersion;
    $depends = getConfigFiles('main');
    $depends[] = DOKU_CONF."tpl/".$conf['template']."/style.ini";
    foreach($depends as $f) $tseed .= @filemtime($f);
    $tseed   = md5($tseed);

	// Open Graph information
	$meta = p_get_metadata($ID);
	if ($meta['title'] !== null) {
		$head['meta'][] = array('property' => 'og:title', 'content' => tpl_pagetitle($ID, true));
		$head['meta'][] = array('property' => 'og:site_name ', 'content' => $conf['title']);
		$head['meta'][] = array('property' => 'og:type', 'content' => 'website');
		$head['meta'][] = array('property' => 'og:url', 'content' => wl($ID, '', true, '&'));
	
		$parts = explode("\n", $meta['description']['abstract']);
		$head['meta'][] = array('property' => 'og:description', 'content' => $parts[2]);
	}

    // the usual stuff
    $head['meta'][] = array('name'=> 'generator', 'content'=> 'DokuWiki');
    if(actionOK('search')) {
        $head['link'][] = array(
            'rel' => 'search', 'type'=> 'application/opensearchdescription+xml',
            'href'=> DOKU_BASE.'lib/exe/opensearch.php', 'title'=> $conf['title']
        );
    }

    $head['link'][] = array('rel'=> 'start', 'href'=> DOKU_BASE);
    if(actionOK('index')) {
        $head['link'][] = array(
            'rel'  => 'contents', 'href'=> wl($ID, 'do=index', false, '&'),
            'title'=> $lang['btn_index']
        );
    }

    if (actionOK('manifest')) {
        $head['link'][] = array('rel'=> 'manifest', 'href'=> DOKU_BASE.'lib/exe/manifest.php');
    }

    $styleUtil = new \dokuwiki\StyleUtils();
    $styleIni = $styleUtil->cssStyleini();
    $replacements = $styleIni['replacements'];
    if (!empty($replacements['__theme_color__'])) {
        $head['meta'][] = array('name' => 'theme-color', 'content' => $replacements['__theme_color__']);
    }

    if($alt) {
        if(actionOK('rss')) {
            $head['link'][] = array(
                'rel'  => 'alternate', 'type'=> 'application/rss+xml',
                'title'=> $lang['btn_recent'], 'href'=> DOKU_BASE.'feed.php'
            );
            $head['link'][] = array(
                'rel'  => 'alternate', 'type'=> 'application/rss+xml',
                'title'=> $lang['currentns'],
                'href' => DOKU_BASE.'feed.php?mode=list&ns='.(isset($INFO) ? $INFO['namespace'] : '')
            );
        }
        if(($ACT == 'show' || $ACT == 'search') && $INFO['writable']) {
            $head['link'][] = array(
                'rel'  => 'edit',
                'title'=> $lang['btn_edit'],
                'href' => wl($ID, 'do=edit', false, '&')
            );
        }

        if(actionOK('rss') && $ACT == 'search') {
            $head['link'][] = array(
                'rel'  => 'alternate', 'type'=> 'application/rss+xml',
                'title'=> $lang['searchresult'],
                'href' => DOKU_BASE.'feed.php?mode=search&q='.$QUERY
            );
        }

        if(actionOK('export_xhtml')) {
            $head['link'][] = array(
                'rel' => 'alternate', 'type'=> 'text/html', 'title'=> $lang['plainhtml'],
                'href'=> exportlink($ID, 'xhtml', '', false, '&')
            );
        }

        if(actionOK('export_raw')) {
            $head['link'][] = array(
                'rel' => 'alternate', 'type'=> 'text/plain', 'title'=> $lang['wikimarkup'],
                'href'=> exportlink($ID, 'raw', '', false, '&')
            );
        }
    }

    // setup robot tags apropriate for different modes
    if(($ACT == 'show' || $ACT == 'export_xhtml') && !$REV) {
        if($INFO['exists']) {
            //delay indexing:
            if((time() - $INFO['lastmod']) >= $conf['indexdelay'] && !isHiddenPage($ID) ) {
                $head['meta'][] = array('name'=> 'robots', 'content'=> 'index,follow');
            } else {
                $head['meta'][] = array('name'=> 'robots', 'content'=> 'noindex,nofollow');
            }
            $canonicalUrl = wl($ID, '', true, '&');
            if ($ID == $conf['start']) {
                $canonicalUrl = DOKU_URL;
            }
            $head['link'][] = array('rel'=> 'canonical', 'href'=> $canonicalUrl);
        } else {
            $head['meta'][] = array('name'=> 'robots', 'content'=> 'noindex,follow');
        }
    } elseif(defined('DOKU_MEDIADETAIL')) {
        $head['meta'][] = array('name'=> 'robots', 'content'=> 'index,follow');
    } else {
        $head['meta'][] = array('name'=> 'robots', 'content'=> 'noindex,nofollow');
    }

    // set metadata
    if($ACT == 'show' || $ACT == 'export_xhtml') {
        // keywords (explicit or implicit)
        if(!empty($INFO['meta']['subject'])) {
            $head['meta'][] = array('name'=> 'keywords', 'content'=> join(',', $INFO['meta']['subject']));
        } else {
            $head['meta'][] = array('name'=> 'keywords', 'content'=> str_replace(':', ',', $ID));
        }
    }

    // load stylesheets
    $head['link'][] = array(
        'rel' => 'stylesheet',
        'href'=> DOKU_BASE . 'lib/exe/css.php?t='.rawurlencode($conf['template']).'&tseed='.$tseed
    );

    $script = "var NS='".(isset($INFO)?$INFO['namespace']:'')."';\n\t\t";
    if($conf['useacl'] && $INPUT->server->str('REMOTE_USER')) {
        $script .= "var SIG=".toolbar_signature().";\n\t\t";
    }
	
    if($conf['basedir']) {
        $script .= 'var BASEDIR="'.$conf['basedir']."\";\n\t\t";
    }

    jsinfo();
    $script .= 'var JSINFO = ' . json_encode($JSINFO).';';
    $head['script'][] = array('_data'=> $script);

    // load jquery
    $jquery = getCdnUrls();
    foreach($jquery as $src) {
        $head['script'][] = array(
            /* 'charset' => 'utf-8', -- obsolete */
            '_data' => '',
            'src' => $src,
        ) + ($conf['defer_js'] ? [ 'defer' => 'defer'] : []);
    }

    // load our javascript dispatcher
    $head['script'][] = array(
        /* 'charset'=> 'utf-8', -- obsolete */
		'_data'=> '',
        'src' => DOKU_BASE . 'lib/exe/js.php'.'?t='.rawurlencode($conf['template']).'&tseed='.$tseed,
    ) + ($conf['defer_js'] ? [ 'defer' => 'defer'] : []);

    // trigger event here
    Event::createAndTrigger('TPL_METAHEADER_OUTPUT', $head, '_my_metaheaders_action', true);
    return true;
}

/**
 * prints the array build by my_metaheaders
 *
 * Overrides the original version by adding a tab before each line for neater HTML code
 *
 * @author Sascha Leib <sascha@leib.be>
 * @author Andreas Gohr <andi@splitbrain.org>
 *
 * @param array $data
 */
function _my_metaheaders_action($data) {
    foreach($data as $tag => $inst) {
        foreach($inst as $attr) {
            if ( empty($attr) ) { continue; }
            echo "\t<", $tag, ' ', buildAttributes($attr);
            if(isset($attr['_data']) || $tag == 'script') {
                if($tag == 'script' && $attr['_data'])
                    $attr['_data'] = "/*<![CDATA[*/".
                        $attr['_data'].
                        "\n/*!]]>*/";

                echo '>', $attr['_data'], '</', $tag, '>';
            } else {
                echo '/>';
            }
            echo "\n";
        }
    }
}

/**
 * Print the breadcrumbs trace
 *
 * Cleanup of the original code to create neater and more accessible HTML
 *
 * @author Sascha Leib <sascha@leib.be>
 * @author Andreas Gohr <andi@splitbrain.org>
 *
 * @param string $prefix inserted before each line
 *
 * @return void
 */
function my_breadcrumbs($prefix = '') {
    global $lang;
    global $conf;

    //check if enabled
    if(!$conf['breadcrumbs']) return false;

    $crumbs = breadcrumbs(); //setup crumb trace

	/* begin listing */
	echo $prefix . "<nav id=\"navBreadCrumbs\">\n";
	echo $prefix . "\t<h4>" . $lang['breadcrumb'] . "</h4>\n";
	echo $prefix . "\t<ol reversed>\n";

    $last = count($crumbs);
    $i    = 0;
    foreach($crumbs as $id => $name) {
        $i++;
		echo $prefix . "\t\t<li" . ($i == $last ? ' class="current"' : '') . '><bdi>' . tpl_link(wl($id), hsc($name), '', true) .  "</bdi></li>\n";
    }
	echo $prefix . "\t</ol>\n";
	echo $prefix . "</nav>\n";
}

/**
 * Hierarchical breadcrumbs
 *
 * Cleanup of the original code to create neater and more accessible HTML
 *
 * @author Sascha Leib <sascha@leib.be>
 * @author Andreas Gohr <andi@splitbrain.org>
 * @author Nigel McNie <oracle.shinoda@gmail.com>
 * @author Sean Coates <sean@caedmon.net>
 * @author <fredrik@averpil.com>
 *
 * @param  string $prefix to be added before each line
 *
 */
function my_youarehere($prefix = '') {
    global $conf;
    global $ID;
    global $lang;

    // check if enabled
    if(!$conf['youarehere']) return false;

    $parts = explode(':', $ID);
    $count = count($parts);

	echo $prefix . "<nav id=\"navYouAreHere\">\n";
	echo $prefix . "\t<h4>" . $lang['youarehere'] . "</h4>\n";
	echo $prefix . "\t<ol>\n";

    // always print the startpage
    echo $prefix . "\t\t<li class=\"home\">" . tpl_pagelink(':'.$conf['start'], null, true) . "</li>\n";

    // print intermediate namespace links
    $part = '';
    for($i = 0; $i < $count - 1; $i++) {
        $part .= $parts[$i].':';
        $page = $part;
        if($page == $conf['start']) continue; // Skip startpage

        // output
        echo $prefix . "\t\t<li>" . tpl_pagelink($page, null, true) . "</li>\n";
    }

    // print current page, skipping start page, skipping for namespace index
    /* resolve_pageid('', $page, $exists);
    if ( !(isset($page) && $page == $part.$parts[$i])
		|| !($page == $conf['start']) ) {
		echo "\t\t\t\t\t<li>" . tpl_pagelink($page, null, true) . "</li>\n";
	} */
	
	echo $prefix . "\t</ol>\n";
	echo $prefix . "</nav>\n";
}

/**
 * My implementation of the basic userinfo (in the global banner)
 *
 *
 * @author Sascha Leib <sascha@leib.be>
 *
 * @param  string $prefix to be added before each line
 *
 * @return void
 */
function my_userinfo($prefix = '') {
    global $lang;
    global $INPUT;

	// add login/logout button:
	$items = (new \dokuwiki\Menu\UserMenu())->getItems();
	foreach($items as $it) {
		$typ = $it->getType();
		if ($typ === 'profile') {
			echo $prefix . "<li class=\"action $typ\"><span class=\"sronly\">" . $lang['loggedinas'] . ' </span>' . userlink() . "</li>\n";
		} else {
			echo $prefix . "<li class=\"action $typ\"><a href=\"" . htmlentities($it->getLink()) . '" title="' . $it->getTitle() . '">' . $it->getLabel() . "</a></li>\n";
		}
	}

}

/**
 *Inserts a cleaner version of the TOC
 *
 * This is an update of the original function that renders the TOC directly.
 *
 * @author Sascha Leib <sascha@leib.be>
 * @author Andreas Gohr <andi@splitbrain.org>
 *
 * @param  string $prefix to be added before each line
 *
 * @return void
 */
function my_toc($prefix = '') {
    global $TOC;
    global $ACT;
    global $ID;
    global $REV;
    global $INFO;
    global $conf;
    global $lang;
    $toc = array();

    if(is_array($TOC)) {
        // if a TOC was prepared in global scope, always use it
        $toc = $TOC;
    } elseif(($ACT == 'show' || substr($ACT, 0, 6) == 'export') && !$REV && $INFO['exists']) {
        // get TOC from metadata, render if neccessary
        $meta = p_get_metadata($ID, '', METADATA_RENDER_USING_CACHE);
        if(isset($meta['internal']['toc'])) {
            $tocok = $meta['internal']['toc'];
        } else {
            $tocok = true;
        }
        $toc = isset($meta['description']['tableofcontents']) ? $meta['description']['tableofcontents'] : null;
        if(!$tocok || !is_array($toc) || !$conf['tocminheads'] || count($toc) < $conf['tocminheads']) {
            $toc = array();
        }
    } elseif($ACT == 'admin') {
        // try to load admin plugin TOC
        /** @var $plugin AdminPlugin */
        if ($plugin = plugin_getRequestAdminPlugin()) {
            $toc = $plugin->getTOC();
            $TOC = $toc; // avoid later rebuild
        }
    }

	/* Build the hierarchical list of headline links: */
	if (count($toc) >= intval($conf['tocminheads'])) {
		echo $prefix . "<aside id=\"toc\" class=\"toggle hide\">\n";
		echo $prefix . "\t<h3 class=\"tg_button\" title=\"" . htmlentities($lang['toc']) . '"><span>' . htmlentities($lang['toc']) . "</span></h3>\n" . $prefix . "\t<div class=\"tg_content\">";
		$level = intval("0");
		foreach($toc as $it) {

			$nl = intval($it['level']);
			$cp = ($nl <=> $level);

			if ($cp > 0) {
				echo "\n" . $prefix . str_repeat("\t", $level*2 + 2) . "<ol>\n";
			} else if ($cp < 0) {
				echo "\n" . $prefix . str_repeat("\t", $level*2) . "</ol></li>\n";
			} else {
				echo "</li>\n";
			}
			
			echo $prefix . str_repeat("\t", $nl*2 + 1) . "<li><a href=\"#" . $it['hid'] . '">' . htmlentities($it['title']) . "</a>";
			$level = $nl;
		}
		
		for ($i = $level-1; $i > 0; $i--) {
			echo "</li>\n" . $prefix . str_repeat("\t", $i*2 + 1) . "</ol>";
		}
			
		echo "</li>\n" . $prefix . "\t\t</ol>\n" . $prefix . "\t</div>\n" . $prefix . "</aside>\n";
	}
}

/**
 * Print last change date 
 *
 * @author Sascha Leib <sascha@leib.be>
 *
 * @param  string $prefix to be added before each line
 *
 * @return void
 */
function my_lastchange($prefix = '') {
	
    global $lang;
    global $INFO;

	$format = '%Y-%m-%dT%T%z';	/* e.g. 2021-21-05T16:45:12+02:00 */

	$date = $INFO['lastmod'];

	echo $prefix . '<bdi>' . $lang['lastmod'] . "</bdi>\n";
	echo $prefix . '<time datetime="' . strftime($format, $date) . '">' . dformat($date) . "</time>\n";
	
	/* user name for last change (is this really interesting to the visitor?) */
	/* echo $prefix .'<span class="editorname" tabindex="0">' . $lang['by'] . ' <bdi>' . editorinfo($INFO['editor']) . "</bdi></span>\n"; */
}

/**
 * Returns a description list of the metatags of the current image
 *
 * @return string html of description list
 */
function my_img_meta($prefix = '') {
    global $lang;

	$format = '%Y-%m-%dT%T%z';	/* e.g. 2021-21-05T16:45:12+02:00 */

    $tags = tpl_get_img_meta();

    foreach($tags as $tag) {
        $label = $lang[$tag['langkey']];
        if(!$label) $label = $tag['langkey'] . ':';

        echo $prefix . '<tr><th>'.$label.'</th><td>';
        if ($tag['type'] == 'date') {
            echo '<time datetime="' . strftime($format, $tag['value']) . '">' . dformat($tag['value']) . '</time>';
        } else {
            echo hsc($tag['value']);
        }
        echo "</td></tr>\n";
    }
}

/**
 * Creates the Site logo image link
 *
 */
function my_sitelogo() {
    global $conf;

	// get logo either out of the template images folder or data/media folder
	$logoSize = array();
	$logo = tpl_getMediaFile(array(':logo.svg', ':wiki:logo.svg', ':logo.png', ':wiki:logo.png', 'images/sitelogo.svg'), false, $logoSize);
	tpl_link( wl(),
		'<img src="'.$logo.'" ' . $logoSize[3] . ' alt="' . htmlentities($conf['title']) . '" />', 'accesskey="h" title="[H]" class="logo"');
}

/**
 * Creates the various favicon and similar links:
 *
 * @param  string $color overwrite the theme color.
 *
 * @return null
 */
function my_favicons($color = null) {

	$logoSize = array();

	// Theme color:
	if ($color == null) {
		
		/* get the style config */
		$styleUtil = new \dokuwiki\StyleUtils();
		$styleIni = $styleUtil->cssStyleini();
		$replacements = $styleIni['replacements'];
		$color = $replacements['__theme_color__x'];
		
		if ($color== null) { $color = '#2b73b7'; }
	}
	echo "\t<meta name=\"theme-color\" content=\"" . $color . "\" />\n";

	// get the favicon:
	$link = tpl_getMediaFile(array(':favicon.ico', ':favicon.png', ':favicon.svg', ':wiki:favicon.ico', ':wiki:favicon.png', ':wiki:favicon.svg'), false, $logoSize);
	echo "\t<link rel=\"icon\" href=\"" . $link . "\" />\n";

	// Apple Touch Icon
	$logoSize = array();
	$link = tpl_getMediaFile(array(':apple-touch-icon.png', ':wiki:apple-touch-icon.png', 'images/apple-touch-icon.png'), false, $logoSize);
	echo "\t<link rel=\"apple-touch-icon\" href=\"" . $link . "\" />\n";

}