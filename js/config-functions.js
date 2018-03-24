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
        //console.log("handleConfigFileState data: ", data);
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

function updateConfigFile(newState) {

    return $.ajax({
        async: true,
        type: "POST",
        url: "updateConfigFile.php",
        dataType: "json",
        data: "newState=" + newState
    }).always(function (data) {
        handleConfigFileUpdate(data);
    })

    function handleConfigFileUpdate(data) {
        console.log("data: ", data);
        getConfigFileState();
        return;
    }
}

$('#db-setting').on("click", '#btn-test-db', function (event) {
    event.preventDefault();
    var newState = "testDB";
    updateConfigFile(newState);
});

$('#db-setting').on("click", '#btn-prod-db', function (event) {
    event.preventDefault();
    var newState = "prodDB";
    updateConfigFile(newState);
});
