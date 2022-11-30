function sendRequest (url, request, method, callback) {
    if (url === undefined) {
        url = "";
    }
    if (request === undefined) {
        request = null;
    }
    if (method === undefined) {
        method = 'GET';
    }

    const xhr = new XMLHttpRequest();
    xhr.open(method, url, true);

    xhr.onload = function () {
        let content = xhr.response;
        try {
            content = JSON.parse(content);
            callback(content);
        } catch (exception) {
            callback (content);
        }
    };

    if (method === 'POST') {
        xhr.send(request);
    } else {
        xhr.send(null);
    }
}

function getFormData(formName) {
    let data = new FormData();
    let elements = document.querySelectorAll("#" + formName + " select, #" + formName + " input, #" + formName + " textarea");
    console.log(elements);
    for (let ie = 0; ie < elements.length; ie++ )
    {
        let element = elements[ie];
        console.log('Element',element.name);
        if (element.name) {
            if (element.type === 'file') {
                console.log('Adding File', element.name);
                for (i = 0; i < element.files.length; i++) {
                    let fileData = element.files[i];
                    let elementName = element.name;
                    if (fileData !== undefined) {
                        if (element.files.length > 1 && !elementName.includes('[')) {
                            elementName = elementName + '[]';
                        }
                        data.append(elementName, fileData, fileData.name);
                    }
                }
            } else if (element.type === 'checkbox' || element.type === 'radio') {
                if (element.checked) {
                    data.append(element.name, element.value)
                } else {
                    if (element.type !== 'radio') {
                        data.append(element.name, 0)
                    }
                }
            } else {
                if (element.value === '') {
                    element.value = null;
                }
                data.append(element.name, element.value);
            }
        }
    }
    return data;
}


function loadPage(loadURL, targetDiv) {
    if (targetDiv === undefined) targetDiv = 'content';
    console.log('LOADING', loadURL);
    $.ajax({
        method: 'GET',
        url: loadURL,
    }).done(function (data) {
        $('#' + targetDiv).html(data);
    });
}

function showForm(action, loadURL, targetDiv) {
    console.log(action, loadURL, targetDiv);
    if (targetDiv === undefined) targetDiv = 'form';

    if (action == 'create') action = 'GET';
    if (action == 'edit') action = 'GET';
    if (action == 'delete') action = 'DELETE';

    $.ajax({
        method: action,
        url: loadURL
    }).done(function (data) {
        //console.log (data);
        if (data.message !== undefined) {
            $('#' + targetDiv).html(data.message);
        } else {
            $('#' + targetDiv).html(data);
        }
    });
}

/**
 * Post URL posts data to a specific url
 * @param url
 * @param data
 * @param targetDiv
 */
function postUrl(url, data, targetDiv) {
    sendRequest(url, data, 'POST', function(data) {
        if (data.message !== undefined) {
            document.getElementById('#' + targetDiv).innerHTML = (data.message);
        } else {
            if (document.getElementById('#' + targetDiv) !== null) {
                document.getElementById('#' + targetDiv).innerHTML = data;
            } else {
                console.log('TINA4 - define targetDiv for postUrl', data);
            }
        }
    });
}

function saveForm(formName, targetURL, targetDiv) {
    if (targetDiv === undefined) targetDiv = 'message';
    //compile a data model
    let data = getFormData(formName);

    postUrl(targetURL, data, targetDiv);
}

function showMessage(message) {
    document.getElementById('message').innerHTML = '<div class="alert alert-info alert-dismissible fade show"><strong>Info</strong> ' + message + '</div>';
}

function setCookie(name, value, days) {
    let expires = "";
    if (days) {
        let date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        expires = "; expires=" + date.toUTCString();
    }
    document.cookie = name + "=" + (value || "") + expires + "; path=/";
}

function getCookie(name) {
    let nameEQ = name + "=";
    let ca = document.cookie.split(';');
    for (let i = 0; i < ca.length; i++) {
        var c = ca[i];
        while (c.charAt(0) == ' ') c = c.substring(1, c.length);
        if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length, c.length);
    }
    return null;
}

//https://stackoverflow.com/questions/4068373/center-a-popup-window-on-screen
const popupCenter = ({url, title, w, h}) => {
    // Fixes dual-screen position                             Most browsers      Firefox
    const dualScreenLeft = window.screenLeft !== undefined ? window.screenLeft : window.screenX;
    const dualScreenTop = window.screenTop !== undefined ? window.screenTop : window.screenY;

    const width = window.innerWidth ? window.innerWidth : document.documentElement.clientWidth ? document.documentElement.clientWidth : screen.width;
    const height = window.innerHeight ? window.innerHeight : document.documentElement.clientHeight ? document.documentElement.clientHeight : screen.height;

    const systemZoom = width / window.screen.availWidth;
    const left = (width - w) / 2 / systemZoom + dualScreenLeft
    const top = (height - h) / 2 / systemZoom + dualScreenTop
    const newWindow = window.open(url, title,
        `
      directories=no,toolbar=no,location=no,status=no,menubar=no,scrollbars=no,resizable=no,
      width=${w / systemZoom}, 
      height=${h / systemZoom}, 
      top=${top}, 
      left=${left}
      `
    )

    if (window.focus) newWindow.focus();
    return newWindow;
}

function openReport(sreport){
    if (sreport.indexOf("No data available") < 0){
        open(sreport, "content", "target=_blank, toolbar=no, scrollbars=yes, resizable=yes, width=800, height=600, top=0, left=0");
    }
    else {
        window.alert("Sorry , unable to print a report according to your selection!");
    }
}

function getRoute(loadURL, callback) {
    $.ajax({
        method: 'GET',
        url: loadURL,
    }).done(function (data) {
        callback(data);
    });
}