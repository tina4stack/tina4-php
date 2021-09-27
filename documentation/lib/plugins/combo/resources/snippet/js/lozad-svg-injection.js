window.addEventListener("load", function (event) {
    // lazy loads elements with default selector as '.lozad'
    const svgObserver = lozad('.lazy-svg-injection-combo', {
        load: function (el) {
            // SvgInjector leaves a src blank
            // creating a broken image
            // we cache the image and display it again after
            let displayValue = el.style.getPropertyValue('display');
            let displayPriority = el.style.getPropertyPriority('display');
            // important is important because other css styling rule may also use it
            el.style.setProperty('display', 'none', 'important');
            // SVGInjector takes over and load the svg element
            // in place of lozad
            SVGInjector(el, {
                    each: function (svg) {
                        // Style copy (max width)
                        // If any error, svg is a string with the error
                        // Example: `Unable to load SVG file: http://doku/_media/ui/preserveaspectratio.svg`
                        if (typeof svg === 'object') {
                            if (el.hasOwnProperty("style")) {
                                svg.style.cssText = el.style.cssText;
                            }
                            if (el.hasOwnProperty("dataset")) {
                                let dataSet = el.dataset;
                                if (dataSet.hasOwnProperty("class")) {
                                    dataSet.class.split(" ").forEach(e => svg.classList.add(e));
                                }
                            }
                        }
                        // remove the none display or set the old value back
                        if (displayValue === "") {
                            svg.style.removeProperty("display");
                        } else {
                            svg.style.setProperty("display", displayValue, displayPriority);
                        }
                    },
                }
            )
        },
        loaded: function (el) {
            // Custom implementation on a loaded element
            el.classList.add('loaded-combo');
        }
    });
    svgObserver.observe();
});
