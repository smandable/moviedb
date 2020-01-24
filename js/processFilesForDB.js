function processFilesForDB(directory) {
    $("#loading-spinner").css("display", "inline-block");
    $.ajax({
        type: "POST",
        url: "php/processFilesForDB.php",
        dataType: "json",
        data: {
            directory: directory
        }
    }).always(function(response) {
        handleProcessFilesForDBResult(response);
        $("#loading-spinner").css("display", "none");
    });
}
function handleProcessFilesForDBResult(response) {
    $("#mode").css("display", "none");
    $("#directory-results").css("display", "block");

    totalCount = response.data.length;
    newMovies = 0;
    numDuplicates = 0;
    totalSizeNew = 0;
    totalSizeDuplicates = 0;

    for (i = 0; i < response.data.length; i++) {
        var name = response.data[i]["Title"];
        var dimensions = response.data[i]["Dimensions"];
        var size = response.data[i]["Size"];
        var duration = response.data[i]["Duration"];
        //var durationNoMS = duration.split(".")[0];
        var durationInDB = response.data[i]["DurationInDB"];
        //var durationInDBNoMS = durationInDB.split(".")[0];
        var isDuplicate = response.data[i]["Duplicate"];
        var isLarger = response.data[i]["isLarger"];
        var sizeInDB = response.data[i]["SizeInDB"];
        var dateCreated = response.data[i]["DateCreatedInDB"];
        var path = response.data[i]["Path"];
        // var newID = response.data[i]['NewID'];
        var id = response.data[i]["ID"];
        //console.info("response.data: ", response.data);
        if (name.length > 80) {
            name = name.substring(0, 80);
        }
        var markup = "";
        if (response.data[i]["Duplicate"] == false) {
            ++newMovies;
            totalSizeNew += size;

            markup =
                "<tr><td>" +
                id +
                '</td><td><a href="#">' +
                name +
                "</a></td><td>" +
                dimensions +
                "</td><td>" +
                formatSize(size) +
                '<span class="tsize">' +
                size +
                "</span></td><td>" +
                formatDuration(duration) +
                '<span class="tduration">' +
                duration +
                "</span>" +
                '</td><td></td><td class="new-not-dup">New</td><td><button class="btn btn-warning btn-update-with-result" type="button"><i class="fas fa-copy"></i>Update DB</button><!--button class="btn btn-default btn-delete"><i class="fa fa-trash"></i>Del</button>-->' +
                '<button class="btn btn-success btn-play"><i class="fas fa-play"></i>Play</button></td></tr>';
        } else if (response.data[i]["Duplicate"] == true) {
            ++numDuplicates;
            totalSizeDuplicates += size;

            if (response.data[i]["isLarger"] == true) {
                markup =
                    "<tr><td>" +
                    id +
                    '</td><td><a href="#">' +
                    name +
                    "</a></td><td>" +
                    dimensions +
                    "</td><td>" +
                    formatSize(size) +
                    '<span class="tsize">' +
                    size +
                    '</span><a href="#" data-toggle="tooltip" data-placement="top" title="' +
                    formatSize(sizeInDB) +
                    '"><i class="fas fa-angle-double-up"></i></a></td><td>' +
                    formatDuration(duration) +
                    '<span class="tduration">' +
                    duration +
                    "</span></td><td>" +
                    dateCreated +
                    '</td><td class="dup-not-new">Duplicate</td><td><button class="btn btn-warning btn-update-with-result" type="button">' +
                    '<i class="fas fa-copy"></i>Update DB</i></button><!-- button class="btn btn-default btn-delete"><i class="fa fa-trash"></i>Del</button>--><button class="btn btn-success btn-play"><i class="fas fa-play"></i>Play</button></td></tr>';
            } else {
                markup =
                    "<tr><td>" +
                    id +
                    '</td><td><a href="#">' +
                    name +
                    "</a></td><td>" +
                    dimensions +
                    "</td><td>" +
                    formatSize(size) +
                    '<span class="tsize">' +
                    size +
                    "</span></td><td>" +
                    formatDuration(duration) +
                    '<span class="tduration">' +
                    duration +
                    "</span></td>" +
                    "<td>" +
                    dateCreated +
                    '</td><td class="dup-not-new">Duplicate</td><td><button class="btn btn-warning btn-update-with-result" type="button"><i class="fas fa-copy"></i>Update DB</button>' +
                    '<!--button class="btn btn-default btn-delete"><i class="fa fa-trash"></i>Del</button>--><button class="btn btn-success btn-play"><i class="fas fa-play"></i>Play</button></td></tr>';
            }
        }
        $("#directory-results table").append(markup);
    }

    $("#directory-results #totals").html(
        '<span>Total: <span class="num-span">' +
            totalCount +
            '</span></span><span>New: <span class="num-span">' +
            newMovies +
            " (" +
            formatSize(totalSizeNew) +
            ')</span></span><span>Duplicates: <span class="num-span">' +
            numDuplicates +
            " (" +
            formatSize(totalSizeDuplicates) +
            ")</span></span>"
    );
    angular
        .element($("#movie-controller"))
        .scope()
        .refreshData();

    $("#directory-results .col-lg-2").html(
        '<button class="btn btn-success btn-refresh" type="button">Refresh</button>'
    );
}
