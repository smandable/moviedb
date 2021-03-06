function includeExternalScripts(file) {
    var script = document.createElement("script");
    script.src = file;
    script.type = "text/javascript";
    script.defer = true;

    document.getElementsByTagName("head").item(0).appendChild(script);
}
includeExternalScripts("js/formattersAndFilters.js");
includeExternalScripts("js/clickHandlers.js");
includeExternalScripts("js/checkFileNamesToNormalize.js");
includeExternalScripts("js/processFilesForDB.js");

var numDupes = 0;
var copyResultRowValues = [];
var numFiles = 0;
var dbOpsButton = $(".btn-process-dir-database-ops");
var transitionEnd2 = "webkitTransitionEnd msTransitionEnd transitionend";

$("#input-directory").val("/run/media/sean/Recorded 4/test/");
//$("#input-directory").val("f:\\fixed\\");

$(".btn-start-processing-dir").one("click", function (event) {
    event.preventDefault();
    directory = $("#input-directory").val();

    countFiles(directory);
});

function countFiles(directory) {
    $("#progressbar").html("<span>Counting number of files...</span>");
    $.ajax({
        type: "POST",
        url: "php/countFiles.php",
        dataType: "json",
        async: false,
        data: {
            directory: directory,
        },
    }).done(function (response) {
        $("#progressbar").empty();
        numFiles = response;
        getFileNamesAndSizes(response, directory);
    });
}

function getFileNamesAndSizes(numFiles, directory) {
    $("#progressbar").css("display", "block");
    $.ajax({
        type: "POST",
        url: "php/getFileNamesAndSizes.php",
        dataType: "json",
        async: false,
        data: {
            numFiles: numFiles,
            directory: directory,
        },
    }).always(function (response) {
        handleGetFileNamesAndSizesResult(response);
    });
}

function handleGetFileNamesAndSizesResult(response) {
    checkFileNamesToNormalize();
}

$(function () {
    var intervalID;
    function copy_search_term(e) {
        var tst = $.trim(
            $(".ui-grid-coluiGrid-0005").find("input[type=text]").val()
        );
        if (tst) {
            var exists = $(".recent-terms ul li:contains(" + tst + ")").length;
            if (!exists) {
                $(".recent-terms ul").prepend("<li>" + tst + "</li>");
            }
        }
    }

    $(".ui-grid-coluiGrid-0005").one(
        "keydown",
        _.debounce(copy_search_term, 800)
    );

    $(".recent-terms ul").one("click", "li", function (event) {
        var recentTerm = $(this).text();
        var input = $(".ui-grid-coluiGrid-0005").find("input[type=text]");

        $(input).val(recentTerm);
        input.focus();
    });

    $(".recent-terms button").one("click", function (event) {
        $(".recent-terms ul").empty();
    });
});

function editCurrentRow(id, columnToUpdate, valueToUpdate) {
    return $.ajax({
        async: true,
        type: "POST",
        url: "php/editCurrentRow.php",
        dataType: "json",
        data: {
            id: id,
            columnToUpdate: columnToUpdate,
            valueToUpdate: valueToUpdate,
        },
        success: handleEditCurrentRowResponse,
    });

    function handleEditCurrentRowResponse(data) {
        angular.element($("#movie-controller")).scope().refreshData();
        return;
    }
}

function deleteRow(id) {
    return $.ajax({
        type: "POST",
        url: "php/deleteRow.php",
        dataType: "json",
        data: {
            id: id,
        },
        success: handleDeleteRowResponse,
    });

    function handleDeleteRowResponse(data) {
        angular.element($("#movie-controller")).scope().refreshData();
        return;
    }
}

function playMovie(path) {
    $.ajax({
        type: "POST",
        url: "php/playMovie.php",
        dataType: "json",
        data: {
            path: path,
        },
        success: handlePlayMovieResponse,
    });

    function handlePlayMovieResponse(data) {}
}

$("#directory-results table").on(
    "click",
    ".btn-update-with-result",
    function () {
        copyResultRowValues = [];
        var row = $(this).closest("tr");

        copyResultRowValues = [
            row.find("td:nth-child(1)").attr("class"),
            row.find("td:nth-child(2)").attr("class"),
            row.find("td:nth-child(3)").attr("class"),
            row.find("td:nth-child(4)").attr("class"),
        ];

        updateExistingRecord(copyResultRowValues);
    }
);

function updateExistingRecord(copyResultRowValues) {
    return $.ajax({
        type: "POST",
        url: "php/updateExistingRecord.php",
        data: {
            copyResultRowValues: copyResultRowValues,
        },
        success: handleUpdateExistingRecordResponse(),
    });
}

function handleUpdateExistingRecordResponse() {
    angular.element($("#movie-controller")).scope().refreshData();
}

$(function () {
    $("#movie-controller").one(
        "click",
        ".cell-size .ui-grid-cell-contents",
        function (event) {
            var size = $(this).val();
            size = size.replace(new RegExp(",", "g"), "");
            size = parseFloat(size);
            $(this).val(size);
        }
    );
});

$(function () {
    $('[data-toggle="tooltip"]').tooltip();

    $.fn.editable.defaults.mode = "inline";

    $("#directory-results table").one("click", "a", function (e) {
        e.preventDefault();

        var pk = $(this).closest("tr").find("td:nth-of-type(1)").text();

        $(this).editable({
            type: "text",
            pk: pk,
            name: "title",
            url: "php/editRowInResultsTable.php",
            success: function (response) {},
        });
    });
});

$("#directory-results").one("click", ".btn-refresh", function (event) {
    $("#directory-results table tr").remove();
    $(".ui-grid-viewport").css("height", "65vh");
    $(".ui-grid").css("height", "auto");

    $("#mode").css("display", "block");
    $("#directory-results").css("display", "none");
    $(".btn-process-dir-database-ops").css("display", "none");
    //  $(".btn-process-dir-database-ops").prop('disabled', true);
    $("#information").css("display", "none");
    $("#progressbar").css("display", "none");
    angular.element($("#movie-controller")).scope().refreshData();
});

$("#duplicates").one("click", ".btn-paste-results", function (event) {
    clipboard.writeText($(".duplicate-text").val());
    $(this).closest(".input-group").remove();
});

$(".ui-grid-cell").one("click", ".btn-copy-title", function (event) {
    clipboard.writeText(
        $(this).closest(".ui-grid-coluiGrid-0005 .ui-grid-cell-contents").val()
    );
});

function addMovie(nameToAdd, dimensions, size) {
    $.ajax({
        type: "POST",
        url: "php/addMovie.php",
        dataType: "json",
        data: {
            title: nameToAdd,
            dimensions: dimensions,
            filesize: size,
        },
    }).always(function (data) {
        handleAddMovieResult(data);
    });
}

function handleAddMovieResult(data) {
    var isDupe = data.responseText;
    if (~isDupe.indexOf("Duplicate")) {
        var duplicateTitle = isDupe.split(": ")[1];
        $("#duplicates").prepend(
            '<div class="input-group input-text"><input type="text" class="form-control duplicate-text" value="' +
                duplicateTitle +
                '" disabled/>' +
                '<span class="input-group-btn"><button class="btn btn-warning btn-copy-title" type="button">Copy to clipboard</button><button class="btn btn-danger btn-find-file" type="button">Find file</button></span></div>'
        );
        $("#single-title-input").css("border", "1px solid red");
        numDupes++;
        $(".num-dupes span").text(numDupes);
    } else {
        $("#single-title-input").css("border", "1px solid green");
    }
    angular.element($("#movie-controller")).scope().refreshData();
}

$("#duplicates").one("click", ".btn-find-file", function (event) {
    var fileName = $(".duplicate-text").val();
    findFile(fileName);
});

var dbOpsBtnWrapper = $(".db-ops-btn-wrapper");
var transitionEnd = "webkitTransitionEnd msTransitionEnd transitionend";

$(function () {
    $.fn.editable.defaults.mode = "inline";

    $("#file-results table").one("click", "a", function (e) {
        e.preventDefault();

        var path = $(this).closest("tr").children("td:first-of-type").text();
        path = path + "/";
        var pk = $(this).closest("tr").find("td:nth-of-type(2)").text();
        var originalFileName = $(this)
            .closest("tr")
            .find("td:nth-of-type(2)")
            .text();

        $(this).editable({
            type: "text",
            pk: pk,
            name: "title",
            params: function (params) {
                params.path = path;
                params.originalFileName = originalFileName;
                return params;
            },
            url: "php/renameSingleFile.php",
            success: function (response) {
                if (response == "fail") {
                    $(this)
                        .closest("tr")
                        .find("td:nth-of-type(2)")
                        .text(originalFileName);
                    $(this)
                        .closest("tr")
                        .find("td:nth-of-type(3)")
                        .html("<a>" + originalFileName + "</a>");
                } else {
                    $(this)
                        .closest("tr")
                        .find("td:nth-of-type(3)")
                        .removeClass("file-has-conflicts");
                    $(this)
                        .closest("tr")
                        .find("td:nth-of-type(3)")
                        .removeClass("file-does-not-exist");
                    $(this)
                        .closest("tr")
                        .find("td:nth-of-type(2)")
                        .text(response);
                    $(this)
                        .closest("tr")
                        .find("td:nth-of-type(3)")
                        .html("<a>" + response + "</a>");

                    getFileNamesAndSizes(directory);
                }
            },
        });
    });
});

$("#file-results table").on("click", "th", function (event) {
    var table = $(this).parents("table").eq(0);
    var rows = table
        .find("tr:gt(0)")
        .toArray()
        .sort(comparer($(this).index()));
    this.asc = !this.asc;
    if (!this.asc) {
        rows = rows.reverse();
    }
    for (var i = 0; i < rows.length; i++) {
        table.append(rows[i]);
    }
});
$("#directory-results table").on("click", "th", function (event) {
    var table = $(this).parents("table").eq(0);
    var rows = table
        .find("tr:gt(0)")
        .toArray()
        .sort(comparer($(this).index()));
    this.asc = !this.asc;
    if (!this.asc) {
        rows = rows.reverse();
    }
    for (var i = 0; i < rows.length; i++) {
        table.append(rows[i]);
    }
});
