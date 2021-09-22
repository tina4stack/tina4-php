<?php
/**
 * DokuWiki Information about a page in JSON format
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Sascha Leib <sascha@leib.be>
 */

header('Content-Type: application/json');

//ini_set('display_errors', '1');

/* connect to DokuWiki: */
if(!defined('NOSESSION')) define('NOSESSION',true); // we do not use a session or authentication here (better caching)
if (!defined('DOKU_INC')) { define('DOKU_INC', __DIR__ . '/../../../../'); }
require_once(DOKU_INC . 'inc/init.php');

/* get the output style (can be 'preview' or 'all') */
$style = strtolower($_GET['v']);
if ($style !== 'preview') { $style = 'all'; }

/* initialize the storage: */
$result = [
	'type'	=> 'error'
];

/* find the page ID */
$id = $_GET['id'];

if ($id !== null) {
	
	/* get all metadata; */
	$meta = p_get_metadata($id);
	
	if ($meta['title'] !== null) {

		if ($style == 'preview') {
			$result['type'] = 'preview';
		} else {
			$result['type'] = 'standard';
			$result['pageid'] = $id;
			$result['lang'] = $conf['lang'];
		}

		$result['title'] = $meta['title'];

		/* The page URL(s) */
		$url = wl($id);
		
		if ($style == 'preview') {
			$result['content_urls'] = [
				'desktop' => [
					'page' => wl($id)
				]
			];
		} else {
			$url = $conf['baseurl'] . wl($id);
			$set = [
				'page' => $url,
				'revisions' => $url . '?do=revisions',
				'edit' => $url . '?do=edit'
			];
			$result['content_urls'] = [
				'desktop' => $set,
				'mobile' => $set
			];
		}

		/* extract the first paragraph:*/
		$parts = explode("\n", $meta['description']['abstract']);
		$result['extract'] = $parts[2];
		$result['extract_html'] = '<p>'.$parts[2].'</p>';
	
	} else {
		$result['extract'] = 'Error: page does not exist.';
		$result['extract_html'] = '<p><strong>' . $result['extract'] . '</strong></p>';
	}
	 $result['conf'] = $conf;
	// $result['meta'] = $meta;
} 

/* output the result: */
echo json_encode($result);