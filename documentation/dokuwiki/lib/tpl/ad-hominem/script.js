/**
 *  Page scripts for Ad Hominem Info Template
 *
 * @author     Sascha Leib <sascha@leib.be>
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

/* everything is contained in the $p namespace: */
$p = {
	
	/* called to initialize the entire script */
	init:	function() {
		
		$p.linkinfo.init();
		$p.search.init();
		$p.togglers.init();

	},
	
	/* link information */
	linkinfo: {
		init:	function() {
			
			/* find all links in the main section */
			var main = document.getElementsByTagName("main")[0];
			var al = main.getElementsByTagName("a");
			Array.prototype.forEach.call(al, function (a) {
				
				Object.entries($p.linkinfo._restURLs).forEach((c) => {
					var cls = c[0];
					if (a.classList.contains(cls)) {
						a.addEventListener('mouseover', $p.linkinfo._linkHoverCallback);
					}
				});
			});
		},
		
		/* list of REST API URLs for different sites. */
		/* variables are enclosed in %, allowed vars are: */
		/* - basedir = this site's basedir (e.g. "/"), */
		/* - id = the data id of the link (internal only) */
		/* - ln = the link name (e.g. for Wikipedia links) */
		_restURLs : {
				'wikilink1'	: '%basedir%lib/tpl/ad-hominem/rest/pageinfo.php?id=%id%&v=preview',
				'iw_wp'		: 'https://en.wikipedia.org/api/rest_v1/page/summary/%ln%',
				'iw_wpfr' 	: 'https://fr.wikipedia.org/api/rest_v1/page/summary/%ln%',
				'iw_wpde' 	: 'https://de.wikipedia.org/api/rest_v1/page/summary/%ln%',
				'iw_wpes' 	: 'https://es.wikipedia.org/api/rest_v1/page/summary/%ln%',
				'iw_wppl' 	: 'https://pl.wikipedia.org/api/rest_v1/page/summary/%ln%',
				'iw_wpja' 	: 'https://it.wikipedia.org/api/rest_v1/page/summary/%ln%',
				'iw_wpru' 	: 'https://ru.wikipedia.org/api/rest_v1/page/summary/%ln%',
				'iw_meta' 	: 'https://meta.wikipedia.org/api/rest_v1/page/summary/%ln%'
		},
		
		/* callback for the onhover event of links: */
		_linkHoverCallback: function() {

			/* TODO: remove jQuery dependency! */

			var a = jQuery(this);
			var hi = jQuery.data(this, 'has-info');
			var url = null;
			
			/* only if the info hasn't been set yet: */
			if (hi == undefined || hi == '') {
				
				// remember that we are now working on it:
				jQuery.data(this, 'has-info', '0');

				for (var cls in $p.linkinfo._restURLs) {
					if (a.hasClass(cls)) {
						url = $p.linkinfo._restURLs[cls];
						break;
					}
				};

				if (url !== null) {
				
					/* modify the URLs: */
					var href = jQuery(this).attr('href');
					
					var rp = {
						'basedir': BASEDIR,
						'id': jQuery(this).data('wiki-id'),
						'ln': href.substring(href.lastIndexOf('/')+1)
					};
					
					for (var p in rp) {
						url = url.replace('%'+p+'%', rp[p]);
					}

					/* load the page info */
					jQuery.ajax({
						url:		url,
						context:	a,
						dataType:	'json',
						error:		function(xhr, msg, e) {
										console.error(msg);
									},
						success:	function(data, msg, xhr) {
										// build the new title for the element:
										jQuery(this).attr('title', data.title + "\n" + data.extract);
										jQuery.data(this, 'has-info', '1')
									},
						complete:	function() {
										if (jQuery.data(this, 'has-info') == '0') {
											jQuery.removeData(this, 'has-info');
										}
									}
					});
				}
			}
		}
	},

	/* anything related to the search */
	search: {
		
		/* initializer */
		init: function() {
			$p.search.gui.init();
		},
		
		/* the search gui */
		gui: {
			
			_container: null,
			_elements: { field: null, clear: null, search: null },
						
			/* init the gui */
			init: function() {
				
				try {
				
					/* find all the search elements: */
					var form = document.getElementById('dw__search');
					
					
					var div = form.getElementsByClassName('search-field')[0];
					$p.search.gui._container = div;
					
					var field = div.getElementsByTagName('input')[0];
					$p.search.gui._elements.field = field;
					field.addEventListener('focus', $p.search.gui.__elementFocus);
					field.addEventListener('blur', $p.search.gui.__elementBlur);

					var buttons = div.getElementsByTagName('button');
					Array.prototype.forEach.call(buttons, function(b) {
						var type = b.getAttribute('type');
						if (type == 'reset') { 
							$p.search.gui._elements.clear = b;
						} else if (type == 'submit') { 
							$p.search.gui._elements.search = b;
						}
						b.addEventListener('focus', $p.search.gui.__elementFocus);
						b.addEventListener('blur', $p.search.gui.__elementBlur);
					});
					
				} catch (e) {
					console.warn("Can’t initialize search form.");
					console.error(e);
				}
			},
			
			/* call back for fields */
			__elementFocus: function() {
				$p.search.gui._container.classList.add("focus"); 
			},
			__elementBlur: function() {
				$p.search.gui._container.classList.remove("focus"); 

			}
		}
	},

	/* expaning sections, for menus, etc. */
	togglers: {
		
		/* initialize togglers */
		init:	function() {

			const togglers = document.getElementsByClassName("toggle");

			Array.prototype.forEach.call(togglers, function(t) {
			
				/* add default state  */
				if (!(t.classList.contains('show') || (t.classList.contains('hide')))) {
					t.classList.add('auto');
				}

				/* add a callback to the toggler buttons */
				var btn = t.getElementsByClassName('tg_button');
				Array.prototype.forEach.call(btn, function(b) {
					b.addEventListener('click', $p.togglers._buttonCallback);
					b.classList.add('active');
				});

			});
		},
		
		/* callback for the toggler button click */
		_buttonCallback: function() {
			
			var t = this.parentNode;
			
			/* current state of the toggler: */
			var state = 'auto';
			if (t.classList.contains('show')) state = 'show';
			if (t.classList.contains('hide')) state = 'hide';
			if (t.classList.contains('alt')) state = 'alt';
		
			/* set new state: */
			var newState = 'alt';
			if (state == 'show') { newState = 'hide' }
			else if (state == 'hide') { newState = 'show' }
			else if (state == 'alt') { newState = 'auto' }
			
			t.classList.remove(state);
			t.classList.add(newState);

		}
	}
};

/* load the script when the DOM is ready */ 

window.addEventListener("DOMContentLoaded", $p.init);
