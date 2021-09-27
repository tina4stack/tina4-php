/*
 * Copyright (c) 2021. ComboStrap, Inc. and its affiliates. All Rights Reserved.
 *
 * This source code is licensed under the GPL license found in the
 * COPYING  file in the root directory of this source tree.
 *
 * @license  GPL 3 (https://www.gnu.org/licenses/gpl-3.0.en.html)
 * @author   ComboStrap <support@combostrap.com>
 *
 */

/**
 * Created by NicolasGERARD on 11/18/2015.
 */
let WEBCODE = {
    appendLine: function (text) {
        let webConsoleLine = document.createElement("p");
        webConsoleLine.className = "webCodeConsoleLine";
        webConsoleLine.innerHTML = text;
        WEBCODE.appendChild(webConsoleLine);
    },
    appendChild: function (element) {
        document.querySelector("#webCodeConsole").appendChild(element);
    },
    print: function (v) {
        if (typeof v === 'undefined') {
            return "(Undefined)"; // Undefined == null, therefore it must be in first position
        } else if (Array.isArray(v)) {
            if (v.length === 0) {
                return "(Empty Array)";
            } else {
                return v;
            }
        } else if (typeof v === 'string') {
            if (v.length === 0) {
                return "(Empty String)"
            } else {
                return v;
            }
        } else if (v === null) {
            return "(null)";
        } else {
            return v;
        }
    },
    htmlEntities: function(str) {
        // from https://css-tricks.com/snippets/javascript/htmlentities-for-javascript/
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/ /g, '&nbsp;');
    }
};


window.console.log = function (input) {
    let s = "";
    if (typeof input === "object") {
        s = "{\n";
        let keys = Object.keys(input);
        for (let i = 0; i < keys.length; i++) {
            s += "  " + keys[i] + " : " + input[keys[i]] + ";\n";
        }
        s += "}\n";
    } else {
        s = String(input);
    }
    s = WEBCODE.htmlEntities(s);
    // the BR replacement must be after the htmlEntities function ...
    s = s.replace(/\n/g, '<BR>')
    WEBCODE.appendLine(s);
};

// Console table implementation
// https://developer.mozilla.org/en-US/docs/Web/API/Console/table
window.console.table = function (input) {
    if (Array.isArray(input) !== true) {

        WEBCODE.appendLine("The variable of the function console.table must be an array.");

    } else {
        if (input.length <= 0) {

            WEBCODE.appendLine("The variable of the console.table has no elements.");

        } else {
            // HTML Headers
            let tableElement = document.createElement("table");
            let theadElement = document.createElement("thead");
            let tbodyElement = document.createElement("tbody");
            let trHeadElement = document.createElement("tr");

            tableElement.appendChild(theadElement);
            tableElement.appendChild(tbodyElement);
            theadElement.appendChild(trHeadElement);


            for (let i = 0; i < input.length; i++) {

                let element = input[i];

                // First iteration, we pick the headers
                if (i === 0) {

                    if (typeof element === 'object') {
                        for (let prop in element) {
                            let thElement = document.createElement("th");
                            thElement.innerHTML = WEBCODE.print(prop);
                            trHeadElement.appendChild(thElement);
                        }
                    } else {
                        // Header
                        let thElement = document.createElement("th");
                        thElement.innerHTML = "Values";
                        trHeadElement.appendChild(thElement);
                    }

                }

                let trBodyElement = document.createElement("tr");
                tbodyElement.appendChild(trBodyElement);

                if (typeof input[0] === 'object') {
                    for (let prop in element) {
                        let tdElement = document.createElement("td");
                        tdElement.innerHTML = WEBCODE.print(element[prop]);
                        trBodyElement.appendChild(tdElement);
                    }
                } else {
                    let tdElement = document.createElement("td");
                    tdElement.innerHTML = WEBCODE.print(element);
                    let trElement = document.createElement("tr");
                    trElement.appendChild(tdElement);
                }

            }
            WEBCODE.appendChild(tableElement);

        }
    }
};

