
window.addEventListener("load", function(event) {
// https://scrollmagic.io/docs/index.html
// http://scrollmagic.io/examples/basic/reveal_on_scroll.html
    const controller = new ScrollMagic.Controller();

// animate__animated animate__fadeInLeft
    const animatedElements = document.getElementsByClassName("animate__animated");

// duration: 100 // the scene should last for a scroll distance of 100px
// offset: 50 // start this scene after scrolling for 50px
// reverse when scrolling past it by supplying a duration
    for (let i = 0; i < animatedElements.length; i++) { // create a scene for each element
        let revealElement = animatedElements[i];
        let $dataAnimatedClass = revealElement.getAttribute("data-animated-class")
        new ScrollMagic.Scene({
            triggerElement: revealElement, // y value not modified, so we can use element as trigger as well
            triggerHook: 0.8, //0.9, // show, when scrolled 10% into view (or "onEnter")
            offset: 0,	// start a little later
            duration: "80%", // hide 10% before exiting view (80% + 10% from bottom)
            // reverse: false // only do once, stay in state once triggered,
        })
            .setClassToggle(revealElement, $dataAnimatedClass) // add class toggle
            .addIndicators({name: "animation " + (i + 1)}) // add indicators (requires plugin)
            .addTo(controller);
    }
});

