window.addEventListener("load", function (event) {

    let getBsCollapseInstanceForElement = element => {
        return bootstrap.Collapse.getInstance(element) ?
            bootstrap.Collapse.getInstance(element) :
            new bootstrap.Collapse(element, {toggle: false})
    }

    [...document.querySelectorAll('.height-toggle-onclick-combo')].forEach(element => {
            let collapseInstance = getBsCollapseInstanceForElement(element);
            element.addEventListener("click", function () {
                    if (window.getSelection().toString() === "") {
                        collapseInstance.toggle();
                    }
                }
            );
        }
    );

});
