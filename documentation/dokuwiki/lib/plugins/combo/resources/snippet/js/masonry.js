window.addEventListener("load", function (event) {
    document.querySelectorAll('.masonry').forEach(elem => {
        const masonry = new Masonry(elem, {
            percentPosition: true
        });
        masonry.layout()
    })
});
