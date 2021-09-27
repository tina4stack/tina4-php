// Webcode
// Will set the height of an iframe to his content
// If the attribute is not set
window.addEventListener("load", function () {

    // Select the iframe element with the class webCode
    const webCodeIFrames = document.querySelectorAll("iframe.webcode-combo");

    // Set the height of the iframe to be the height of the internal iframe
    if (webCodeIFrames != null) {
        for (let i = 0; i < webCodeIFrames.length; i++) {
            const webCodeIFrame = webCodeIFrames[i];
            const height = webCodeIFrame.getAttribute('height');
            if (height == null) {
                let htmlIFrameElement = webCodeIFrame.contentWindow.document.querySelector("html");
                let calculatedHeight = htmlIFrameElement.offsetHeight;
                let defaultHtmlElementHeight = 150;
                if (calculatedHeight === defaultHtmlElementHeight) {
                    // body and not html because html has a default minimal height of 150
                    calculatedHeight = webCodeIFrame.contentWindow.document.querySelector("body").offsetHeight;
                    // After setting the height, there is a recalculation and the padding of a descendant phrasing content element
                    // may ends up in the html element. The below code corrects that
                    requestAnimationFrame(function() {
                        if (calculatedHeight !== htmlIFrameElement.offsetHeight) {
                            webCodeIFrame.height = htmlIFrameElement.offsetHeight;
                        }
                    });
                }
                webCodeIFrame.height = calculatedHeight;
            }
        }
    }


});
