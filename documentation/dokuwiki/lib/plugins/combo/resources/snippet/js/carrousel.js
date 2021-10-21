// https://github.com/ganlanyuan/tiny-slider#responsive-options
window.addEventListener('load', function () {
    const slider = tns({
        container: '.carrousel-combo',
        autoplayButton: ".combo-carrousel-play-button",
        slideBy: "page",
        mouseDrag: true,
        swipeAngle: false,
        speed: 400,
        navPosition: "bottom",
        controlsPosition: "bottom",
        responsive: {
            0: {
                items: 2
            },
            576: {
                items: 2
            },
            768: {
                items: 3
            },
            992: {
                items: 3
            },
            1200: {
                items: 3
            },
            1400: {
                items: 3
            }
        }
    });
});
