jQuery(function(){
    var $prev = jQuery('div.preview');
    if(!$prev.length) return;

    var out = document.createElement('div');
    out.id = 'plugin__readability';
    $prev.append(out);

    jQuery(out).load(
        DOKU_BASE + 'lib/plugins/readability/calc.php',
        {
            html: $prev.html()
        }
    );

});

