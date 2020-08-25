function getFormData(formName) {
    var data = new FormData();
    console.log ('Getting form data for '+formName);
    $("#"+formName+" select, #"+formName+" input, #"+formName+" textarea").each(function(key, element){
        if (element.name) {
            if (element.type == 'file') {
                let fileData = element.files[0];
                if (fileData !== undefined) {
                    data.append (element.name, fileData, fileData.name);
                }
            } else
            if (element.type == 'checkbox') {
                if (element.checked) { data.append(element.name, element.value) } else { data.append(element.name, 0) };
            } else {
                data.append(element.name, element.value);
            }
        }
    });
    return data;
}


function loadPage (loadURL, targetDiv) {
    if (targetDiv === undefined) targetDiv = 'content';
    console.log ('LOADING', loadURL);
    $.ajax({
        method: 'GET',
        url: loadURL,
    }).done(function( data ) {
        $('#'+targetDiv).html( data );
    });
}

function showForm(action, loadURL, targetDiv) {
    console.log (action, loadURL, targetDiv);
    if (targetDiv === undefined) targetDiv = 'form';

    if (action == 'create') action = 'GET';
    if (action == 'edit') action = 'GET';
    if (action == 'delete') action = 'DELETE';

    $.ajax({
        method: action,
        url: loadURL
    }).done(function( data ) {
        //console.log (data);
        if (data.message !== undefined) {
            $('#' + targetDiv).html(data.message);
        } else {
            $('#' + targetDiv).html(data);
        }
    });
}

function postUrl(url, data, targetDiv) {
    $.ajax({
        method: 'POST',
        url: url,
        data: data,
        processData: false,
        contentType: false
    }).done(function( data ) {
        if (data.message !== undefined) {
            $('#' + targetDiv).html(data.message);
        } else {
            $('#' + targetDiv).html(data);
        }
    });
}

function saveForm(formName, postURL, targetDiv) {
    if (targetDiv === undefined) targetDiv = 'message';
    //compile a data model
    let data = getFormData(formName);

    postURL(postURL, data, targetDiv);
}

function showMessage (message) {
    $('#message').html('<div class="alert alert-info alert-dismissible fade show"><strong>Info</strong> '+message+'</div>');
}
