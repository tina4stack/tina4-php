// http://pavlyukpetr.github.io/motion-css//documentation.html
// https://api.jquery.com/scroll/
// https://developer.mozilla.org/en-US/docs/Web/API/Document/scroll_event
// https://cssanimation.rocks/scroll-animations/
jQuery(window).scroll(function () {
    jQuery('[data-combo-animate]').each(function () {

        // Position of the element
        const elPosition = jQuery(this).offset().top;
        // Height of the element
        const elHeight = jQuery(this).height();
        // Top of the window
        const windowTop = jQuery(window).scrollTop();
        // Height of the window
        const windowHeight = jQuery(window).height();


        // adds the class when the element is fully in the visible area of the window
        if (elPosition < windowTop + windowHeight - elHeight) {
            jQuery(this).addClass("animation fade-in");
        }
        // removes the class when the element is not visible in the window
        if (elPosition > windowTop + windowHeight) {
            jQuery(this).removeClass("animation fade-in");
        }
        // removes the class when the element is not visible in the window
        if (elPosition + elHeight < windowTop) {
            jQuery(this).removeClass("animation fade-in");
        }
    });
});


// https://developer.mozilla.org/en-US/docs/Web/API/Intersection_Observer_API
const callback = function(entries) {
    entries.forEach(entry => {
        entry.target.classList.toggle("is-visible");
    });
};

let options = {
    root: document.querySelector('#scrollArea'),
    rootMargin: '0px',
    threshold: 1.0
}
const observer = new IntersectionObserver(callback, options);

const targets = document.querySelectorAll(".show-on-scroll");
targets.forEach(function(target) {
    observer.observe(target);
});


