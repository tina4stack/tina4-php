// Because we may redirect a canonical, the URL cannot be used to discover the id
window.addEventListener("load", function () {
    let currentId = JSINFO["id"];
    let currentIdParts = currentId.split(":").filter(el => el.length !== 0);
    document.querySelectorAll(".page-explorer-tree-combo").forEach(element => {
        let baseId = element.dataset.wikiId;
        let baseParts = baseId.split(":").filter(el => el.length !== 0);
        let processedIdArray = [];
        for (const [index, currentPart] of currentIdParts.entries()) {
            processedIdArray.push(currentPart);
            if (index < baseParts.length) {
                if (currentPart === baseParts[index]) {
                    continue;
                }
            }
            let processedId = processedIdArray.join(":")
            if (index < currentIdParts.length - 1) {
                let button = element.querySelector(`button[data-wiki-id='${processedId}']`);
                if (button != null) {
                    button.click();
                }
            } else {
                let link = element.querySelector(`a[data-wiki-id='${processedId}']`);
                if (link != null) {
                    link.classList.add("active");
                }
            }
        }
    });
});
