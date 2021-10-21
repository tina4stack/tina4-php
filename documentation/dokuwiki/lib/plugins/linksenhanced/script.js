jQuery(function() {

    jQuery('div.dokuwiki a.plugin_linksenhanced_pending').each(function() {
        var $link = jQuery(this).prop('href');
        if(!$link) 
            return;
        var $obj = jQuery(this);
        // If we are still here, we found our target link
        jQuery.post(
            DOKU_BASE + 'lib/exe/ajax.php',
            {
                call: 'plugin_linksenhanced',
                url: $link,
                action: 'check',
                sectok: JSINFO.plugin.linksenhanced['sectok'],
            },
            function(data)
            {
                var result = data['result'];
                if(result === true)
                {
                    $obj.removeClass('plugin_linksenhanced_pending');
                    $obj.addClass('plugin_linksenhanced_online');
                }
                else
                {
                    $obj.removeClass('plugin_linksenhanced_pending');
                    $obj.addClass('plugin_linksenhanced_offline');
                }
            }
        );
    });
});
