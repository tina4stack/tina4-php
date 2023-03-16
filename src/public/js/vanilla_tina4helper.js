/**
 * @description Asynchronous fetch helper to load html from a route.
 * @param loadURL
 * @param targetDiv
 * @returns {Promise<void>}
 */
async function loadPage(loadURL, targetDiv) {
    if (targetDiv === undefined) targetDiv = 'content';
    console.log('LOADING', loadURL);
    await fetch(loadURL)
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const htmlData = parser.parseFromString(html, 'text/html');
            const body = htmlData.querySelector('body');
            const scripts = body.querySelectorAll('script');
            // remove the script tags
            body.querySelectorAll('script').forEach(script => script.remove());
            document.getElementById(targetDiv).replaceChildren(...body.children);
            return scripts;
        })
        .then(scripts => {
            // if there were any script tags add them back and run them
            if (scripts) {
                scripts.forEach(script => {
                    const newScript = document.createElement("script");
                    newScript.type = 'text/javascript';
                    newScript.async = true;
                    newScript.textContent = script.innerText;
                    document.getElementById(targetDiv).append(newScript)
                });
            }
        })
        .catch(error => {
            console.error(error)
        });
}
