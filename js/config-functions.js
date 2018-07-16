function getConfigFileState() {
    $.ajax({
        async: true,
        type: "GET",
        url: "readConfigFile.php",
        dataType: "json"
    }).always(function (data) {
        handleConfigFileState(data);
    })

    function handleConfigFileState(data) {
        if (data.responseText == "movieLibraryTEST") {
            $('#btn-prod-db').removeClass('btn-active');
            $('#btn-test-db').addClass('btn-active');
        } else if (data.responseText == "movieLibrary") {
            $('#btn-test-db').removeClass('btn-active');
            $('#btn-prod-db').addClass('btn-active');
        }

        return;
    }
}

getConfigFileState();

function updateConfigFile(configUpdate) {
    return $.ajax({
        async: true,
        type: "POST",
        url: "updateConfigFile.php",
        dataType: "json",
        data: "configUpdate=" + configUpdate
    }).always(function (data) {
        handleConfigFileUpdate(data);
    })

    function handleConfigFileUpdate(data) {
        getConfigFileState();
        return;
    }
}

// $('#db-setting').on("click", '#btn-test-db', function (event) {
//     event.preventDefault();
//     var newDBState = "testDB";
//     updateConfigFile(newDBState);
// });
//
// $('#db-setting').on("click", '#btn-prod-db', function (event) {
//     event.preventDefault();
//     var newDBState = "prodDB";
//     updateConfigFile(newDBState);
// });

$('#db-setting .btn-wrapper').click(function (e) {
    //e.preventDefault();
    var btnClicked = $(this).find('.btn').attr('id');
    console.log("btnClicked: ", btnClicked);
    if (btnClicked == 'btn-prod-db') {
        var configUpdate = "prodDB";
        updateConfigFile(configUpdate);
    } else if (btnClicked == 'btn-test-db') {
        var configUpdate = "testDB";
        updateConfigFile(configUpdate);
    }
    //$('#output').append($('<div>').html('clicked ' + name));
})


$('#tbl-setting .btn-wrapper').click(function (e) {
    //e.preventDefault();
    var btnClicked = $(this).find('.btn').attr('id');
    console.log("btnClicked: ", btnClicked);
    if (btnClicked == 'btn-het') {
        var configUpdate = "movies_het";
        updateConfigFile(configUpdate);
        console.log("configUpdate: ", configUpdate);
    } else if (btnClicked == 'btn-bi') {
        var configUpdate = "movies_bi";
        updateConfigFile(configUpdate);
        console.log("configUpdate: ", configUpdate);
    } else if (btnClicked == 'btn-gay') {
        var configUpdate = "movies_gay";
        updateConfigFile(configUpdate);
        console.log("configUpdate: ", configUpdate);
    } else if (btnClicked == 'btn-misc') {
        var configUpdate = "movies_misc";
        updateConfigFile(configUpdate);
        console.log("configUpdate: ", configUpdate);
    }
    //$('#output').append($('<div>').html('clicked ' + name));
})
