function processFilesForDB(directory) {
    $.ajax({
        type: "POST",
        url: "php/processFilesForDB.php",
        dataType: "json",
        data: {
            directory: directory,
        },
    }).always(function(response) {
        handleProcessFilesForDBResult(response);
        //$("#loading-spinner").css("display", "none");
    });
}

function handleProcessFilesForDBResult(response) {
    $("#mode").css("display", "none");
    $(".ui-grid-viewport").css("height", "100px");
    $(".ui-grid").css("height", "100px");
    // $(".grid").css("overflow-y", "hidden");
    $("#directory-results").css("display", "block");

    totalCount = response.length;
    newMovies = 0;
    numduplicates = 0;
    totalSizeNew = 0;
    totalSizeDuplicates = 0;

    for (i = 0; i < response.length; i++) {
        var title = response[i]["title"];
        var titleDimensions = response[i]["titleDimensions"];
        var dimensionsInDB = response[i]["dimensionsInDB"];
        var titleSize = response[i]["titleSize"];
        titleSize = parseFloat(titleSize);
        var titleDuration = response[i]["titleDuration"];
        var durationInDB = response[i]["durationInDB"];
        var isLarger = response[i]["isLarger"];
        var sizeInDB = parseFloat(response[i]["sizeInDB"]);
        sizeInDB = parseFloat(sizeInDB);
        var dateCreatedInDB = response[i]["dateCreatedInDB"];
        var path = response[i]["titlePath"];
        var id = response[i]["id"];

        if (title.length > 80) {
            title = title.substring(0, 80);
        }

        var markup = "";

        if (response[i]["duplicate"]) {
            ++numduplicates;
            totalSizeDuplicates += titleSize;
            toolTipSizeHTML = "";
            toolTipDimensionsHTML = "";
            toolTipDurationHTML = "";
            updateBtn = "";
            if (response[i]["isLarger"]) {
                toolTipSizeHTML =
                    '<a href="#" data-toggle="tooltip" data-placement="top" title="' +
                    formatSize(sizeInDB) +
                    '"><i class="fas fa-angle-double-up"></i></a>';
                toolTipDimensionsHTML =
                    '<a href="#" data-toggle="tooltip" data-placement="top" title="' +
                    formatSize(dimensionsInDB) +
                    '"><i class="fas fa-angle-double-up"></i></a>';
                toolTipDurationHTML =
                    '<a href="#" data-toggle="tooltip" data-placement="top" title="' +
                    formatSize(durationInDB) +
                    '"><i class="fas fa-angle-double-up"></i></a>';

                updateBtn =
                    '<button class="btn btn-warning btn-update-with-result" type="button">' +
                    '<i class="fas fa-copy"></i>Update</i></button>';
            }
            markup =
                '"<tr><td class="' +
                id +
                '">' +
                '<a href="#">' +
                title +
                '</a></td><td class="' +
                titleDimensions +
                '">' +
                titleDimensions +
                toolTipDimensionsHTML +
                '</td><td class="' +
                titleSize +
                '">' +
                formatSize(titleSize) +
                toolTipSizeHTML +
                '</td><td class="' +
                titleDuration +
                '">' +
                formatTitleDuration(titleDuration) +
                toolTipDurationHTML +
                "</td><td>" +
                dateCreatedInDB +
                '</td><td class="dup-not-new">D</td><td>' +
                updateBtn;
            // '<button class="btn btn-success btn-play"><i class="fas fa-play"></i></button></td></tr>';
        } else {
            ++newMovies;
            totalSizeNew += titleSize;

            markup =
                '<tr><td><a href="#">' +
                title +
                "</a></td><td>" +
                titleDimensions +
                "</td><td>" +
                formatSize(titleSize) +
                "</td><td>" +
                formatTitleDuration(titleDuration) +
                '</td><td></td><td class="new-not-dup">N</td><td><!--<button class="btn btn-warning btn-update-with-result" type="button"><i class="fas fa-copy"></i>Update</button>--><!--<button class="btn btn-default btn-delete"><i class="fa fa-trash"></i></button>-->' +
                '<!--<button class="btn btn-success btn-play"><i class="fas fa-play"></i></button>--></td></tr>';
        }
        $("#directory-results table").append(markup);
    }

    $("#directory-results #totals").html(
        '<span>Total: <span class="num-span">' +
        totalCount +
        " (" +
        formatSize(totalSizeNew + totalSizeDuplicates) +
        ')</span></span><span>New: <span class="num-span">' +
        newMovies +
        " (" +
        formatSize(totalSizeNew) +
        ')</span></span><span>Duplicates: <span class="num-span">' +
        numduplicates +
        " (" +
        formatSize(totalSizeDuplicates) +
        ")</span></span>"
    );
    angular.element($("#movie-controller")).scope().refreshData();

    $("#directory-results .col-lg-2").html(
        '<button class="btn btn-success btn-refresh" type="button">Refresh</button>'
    );
}