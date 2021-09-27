window.addEventListener("load", function (event) {
    // lazy loads elements with default selector as '.lozad'
    const svgObserver = lozad('.lazy-svg-combo', {
        loaded: function (el) {
            // Custom implementation on a loaded element
            el.classList.add('loaded-combo');
        }
    });
    svgObserver.observe();
});
