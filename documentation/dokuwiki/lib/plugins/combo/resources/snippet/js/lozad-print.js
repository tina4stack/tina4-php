window.onbeforeprint = function() {

    const observer = lozad();
    observer.observe();

    document.querySelectorAll('.lazy-combo').forEach(element => {
            observer.triggerLoad(element);
        }
    )

}
