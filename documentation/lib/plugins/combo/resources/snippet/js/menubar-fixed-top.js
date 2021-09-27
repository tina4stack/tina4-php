window.addEventListener("DOMContentLoaded",function(){
    let fixedNavbar = document.querySelector(".navbar.fixed-top")
    // correct body padding
    let offsetHeight = fixedNavbar.offsetHeight;
    let marginTop = offsetHeight - 16; // give more space at the top (ie 1rem)
    document.body.style.setProperty("padding-top",offsetHeight+"px");
    // correct direct navigation via fragment to heading
    let style = document.createElement("style");
    style.classList.add("menubar-fixed-top")
    style.innerText = `main > h1, main > h2, main > h3, main > h4, main > h5, #dokuwiki__top {
    padding-top: ${offsetHeight}px;
    margin-top: -${marginTop}px;
    z-index: -1;
}`;
    document.head.appendChild(style);
});
