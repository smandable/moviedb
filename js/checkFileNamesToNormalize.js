dontRenameThese = [];

function checkFileNamesToNormalize() {
    $("#progressbar").css("display", "block");

    $.ajax({
        type: "POST",
        url: "php/checkFileNamesToNormalize.php",
        dataType: "json",
        data: {
            directory: directory
        }
    }).always(function(response) {
        //  $("#progressbar").css("display", "none");
        checkFileNamesToNormalizeResult(response);

    });
}

function checkFileNamesToNormalizeResult(response) {
    $("#file-data tbody ~ tbody").empty();
    var tbody = $("#file-results table").children("tbody:nth-of-type(2)");

    for (i = 0; i < response.length; i++) {
        var path = response[i]["path"];
        var fileNameAndPath = response[i]["fileNameAndPath"];
        var fileName = response[i]["fileName"];

        newFileName = "";

        if (response[i]["newFileName"]) {
            newFileName = response[i]["newFileName"];
        }

        var row =
            "<tr><td>" +
            path +
            "</td><td><a>" +
            fileName +
            "</a></td><td><a>" +
            newFileName +
            "</a></td><td>" +
            '<input type="checkbox" class="dont-rename"></input>' +
            "</tr>";

        $(tbody).append(row);
    }
    // if (!$('#normalizeModal').hasClass('in')) {
    $("#normalizeModal").modal("show");
}
$("#normalizeModal").one("hidden.bs.modal", function() {
    dbOpsBtnWrapper.addClass("inline-flex").outerWidth();
    dbOpsBtnWrapper.addClass("fade-in").one(transitionEnd, function() {});
});


$(document).ready(function() {

    $("#file-results table").one("change", ":checkbox", function() {

        var originalFileName = $(this)
            .closest("tr")
            .find("td:nth-of-type(2)")
            .text();

        // console.log(originalFileName);

        $.ajax({
            type: "POST",
            url: "php/updateSessionWithRenameProp.php",
            dataType: "json",
            data: {
                originalFileName: originalFileName
            }
        })

    });

});


$("#modal-rename-files-btn").one("click", function() {
    renameTheFilesToNormalize();
});

function renameTheFilesToNormalize() {

    $.ajax({
        type: "POST",
        url: "php/renameTheFilesToNormalize.php",
        dataType: "json",
        data: {
            dontRenameThese: dontRenameThese
        }
    }).always(function(response) {
        handleRenameTheFilesToNormalizeResult(response);
    });
}

function handleRenameTheFilesToNormalizeResult(response) {
    $("#file-data").css("border", "5px solid green");
    $(".modal-body").css("padding", "10px");

    $("#file-data tbody ~ tbody").empty();
    var tbody = $("#file-results table").children("tbody:nth-of-type(2)");

    for (i = 0; i < response.length; i++) {
        var path = response[i]["path"];
        var fileNameAndPath = response[i]["fileNameAndPath"];
        var fileName = response[i]["fileName"];
        var newFileName = response[i]["newFileName"];

        newFileName = "";
        fileExists = "";

        if (response[i]["newFileName"]) {
            newFileName = response[i]["newFileName"];
        }
        if (response[i]["fileExists"]) {
            newFileName = '<span style="color:red;">' + newFileName + "<span>";
        }

        var row =
            "<tr><td>" +
            path +
            "</td><td>" +
            fileName +
            "</td><td><a>" +
            newFileName +
            "</a></td><td>" +
            "</tr>";

        $(tbody).append(row);
    }
}

$("#file-results table").one("change", ":checkbox", function() {

    if ($(this).is(":checked")) {

        dontRenameThese.push($(this)
            .parents("tr")
            .find("td:nth-of-type(3)")
            .text());
    } else {
        // console.log($(this).val() + " is now unchecked");
    }
});
