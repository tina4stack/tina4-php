window.addEventListener("DOMContentLoaded", function () {
    let navigation = JSINFO["navigation"];
    let acronymPageProtection = "pp";
    let acronymLowQualityPage = "lqpp";
    let acronymLatePublication = "lpp";
    document.querySelectorAll(`[data-${acronymPageProtection}-link="warning"], [data-${acronymPageProtection}-link="login"]`).forEach(element => {
        let tooltipHtml = "";
        let linkType = element.dataset.ppLink;
        let protectionSourceType = element.dataset.ppSource;
        let showTooltip = false;
        switch (linkType) {
            case "warning":
                if (protectionSourceType === acronymLowQualityPage) {
                    showTooltip = true;
                    tooltipHtml = `<h4>Warning: Low Quality Page</h4>
<p>This page has been detected as being of low quality.</p>`;
                    if (element.hasAttribute("title")) {
                        tooltipHtml += "<p>Description: " + element.getAttribute("title") + "</p>";
                    }
                }
                break
            case "login":
                if (navigation === "anonymous") {
                    showTooltip = true;
                    element.addEventListener('click', function (event) {
                        // not pointer-events: none because we need to show a tooltip
                        event.preventDefault();
                    });
                    switch (protectionSourceType) {
                        case acronymLowQualityPage:
                            tooltipHtml = `<h4>Login Required</h4>
<p>This page has been detected as being of low quality. To follow this link, you need to log in.</p>`;
                            break;
                        case acronymLatePublication:
                            tooltipHtml = `<h4>Login Required</h4>
<p>To follow this link, you need to log in (${acronymLatePublication})</p>`;
                            break;
                    }

                }
                break;

        }
        if (showTooltip) {
            // An element may already have a tooltip
            let tooltip = bootstrap.Tooltip.getInstance(element);
            if (tooltip != null) {
                tooltip.dispose();
            }
            element.setAttribute("title", tooltipHtml);
            new bootstrap.Tooltip(element, {
                html: true,
                placement: "top",
                customClass: acronymPageProtection
            });
        }
    });
});
