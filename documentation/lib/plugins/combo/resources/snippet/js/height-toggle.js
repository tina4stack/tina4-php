window.addEventListener("load", function (event) {
    [...document.querySelectorAll('.height-toggle-combo')].forEach(element => {
        let parent = element.parentElement;
        let parentBorderColor = window.getComputedStyle(parent).getPropertyValue("color");
        if (parentBorderColor != null) {
            element.style.color = parentBorderColor;
        }
    });
});
