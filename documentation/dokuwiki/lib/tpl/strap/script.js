// Disable the highlight function of pages.js
dw_page.sectionHighlight = function () {
    jQuery('form.btn_secedit')
        .on('mouseover', function () {
            let $tgt = jQuery(this).parent(),
                nr = $tgt.attr('class').match(/(\s+|^)editbutton_(\d+)(\s+|$)/)[2];

            // Walk the dom tree in reverse to find the sibling which is or contains the section edit marker
            while ($tgt.length > 0 && !($tgt.hasClass('sectionedit' + nr) || $tgt.find('.sectionedit' + nr).length)) {
                $tgt.addClass("strap_section_highlight");
                $tgt = $tgt.prev();
            }

        })
        .on('mouseout', function () {
            let $tgt = jQuery(this).parent(),
                nr = $tgt.attr('class').match(/(\s+|^)editbutton_(\d+)(\s+|$)/)[2];

            // Walk the dom tree in reverse to find the sibling which is or contains the section edit marker
            while ($tgt.length > 0 && !($tgt.hasClass('sectionedit' + nr) || $tgt.find('.sectionedit' + nr).length)) {
                $tgt.removeClass("strap_section_highlight");
                $tgt = $tgt.prev();
            }
        });
};
