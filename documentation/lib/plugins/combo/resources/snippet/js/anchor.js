document.addEventListener('DOMContentLoaded', (event) => {
    anchors.options = {
        placement: 'right',
        icon: '#',
        class: 'anchor-combo'
    };
    anchors.add("main > h2").add("main > h3").add("main > h4").add("main > h5").add("main > h6");
});
