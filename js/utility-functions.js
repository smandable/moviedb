//Normalize DB
var directory;
var options = [];
var directories = [];
var startingFile = 0;
var endingFile;

$("#normalizeLink").one("click", function(event) {
    $("#normalize-db").css("display", "block");
    $("#find-duplicates").css("display", "none");
    $("#utils-paths tbody").empty();
    getOptionsAndPathsFromFile();

});
$("#findDuplicatesLink").one("click", function(event) {
    $("#normalize-db").css("display", "none");
    $("#find-duplicates").css("display", "block");

});

getOptionsAndPathsFromFile();

$(document).ready(function() {
    //getOptionsAndPathsFromFile();
    var numPaths = $('#utils-paths tbody tr').length;

    $('#utils-paths tbody tr').each(function() {
        directories.push($(this).find("td:first-of-type").html());
    });


    $('#btn-normalize').one("click", function(event) {
        event.preventDefault();

        $('#utils-paths tbody tr').each(function() {
            if ($(this).find('[type="checkbox"]').prop("checked")) {
                var directory = ($(this).find("td:first-of-type").html());
                normalizeDB(options, directory);
            }
        });
    });
});

$('#chkbox-options input[type=checkbox]').one("change", function(event) {
    event.preventDefault();
    var key = $(this).attr('value');
    if ($(this).prop("checked")) {
        state = "true;";
    } else {
        state = "false;";
    }
    setOptionsAndPathsFile(key, state);
    return;
});

function normalizeDB(options, directory) {
    $("#loading-spinner").css('display', 'inline-block');
    $.ajax({
        type: "POST",
        url: "php/processFilesForDB.php",
        dataType: "json",
        data: {
            options: options,
            directory: directory
        },
    }).always(function(response) {
        handleProcessFilesForDBResult(response);
        $("#loading-spinner").css('display', 'none');
    });
}

function handleProcessFilesForDBResult(response) {

    $("#loading-spinner").css('display', 'none');
    $('#directory-results').css('display', 'block');

    totalCount = response.data.length;
    newMovies = 0;
    numDuplicates = 0;
    totalSizeNew = 0;
    totalSizeDuplicates = 0;


    for (i = 0; i < response.data.length; i++) {
        var name = response.data[i].Title;
        var dimensions = response.data[i].Dimensions;
        var size = response.data[i].Size;
        var duration = response.data[i].Duration;
        //var durationNoMS = duration.split(".")[0];
        var durationInDB = response.data[i].DurationInDB;
        //var durationInDBNoMS = durationInDB.split(".")[0];
        var isDuplicate = response.data[i].Duplicate;
        var isLarger = response.data[i].isLarger;
        var sizeInDB = response.data[i].SizeInDB;
        var dateCreated = response.data[i].DateCreatedInDB;
        var path = response.data[i].Path;
        // var newID = response.data[i].NewID;
        var id = response.data[i].ID;
        //console.info("response.data: ", response.data);

        if (name.length > 80) {
            name = name.substring(0, 80);
        }
        var markup = '';
        if (response.data[i].Duplicate == false) {
            ++newMovies;
            totalSizeNew += size;

            markup = '<tr><td>' + id + '</td><td><a href="#">' + name + '</a></td><td>' + dimensions + '</td><td>' + formatSize(size) + '<span class="tsize">' + size + '</span></td><td>' + formatDuration(duration) +
                '</td><td></td><td class="new-not-dup">New</td></tr>';
        } else if (response.data[i].Duplicate == true) {
            ++numDuplicates;
            totalSizeDuplicates += size;

            if (response.data[i].isLarger == true) {
                markup = '<tr><td>' + id + '</td><td><a href="#">' + name + '</a></td><td>' + dimensions + '</td><td>' + formatSize(size) + '  <a href="#" data-toggle="tooltip" data-placement="top" title="' + formatSize(sizeInDB) +
                    '"><i class="fas fa-angle-double-up"></i></a></td><td>' + formatDuration(duration) + '</td><td>' + dateCreated + '</td><td class="dup-not-new">Duplicate</td></tr>';
            } else {
                markup = '<tr><td>' + id + '</td><td><a href="#">' + name + '</a></td><td>' + dimensions + '</td><td>' + formatSize(size) + '<span class="tsize">' + size + '</span></td><td>' + formatDuration(duration) + '</td>' +
                    '<td>' + dateCreated + '</td><td class="dup-not-new">Duplicate</td></tr>';
            }
        }
        $("#directory-results table").append(markup);
    }

    $("#directory-results #totals").html('<span>Total: <span class="num-span">' + totalCount + '</span></span><span>New: <span class="num-span">' + newMovies + ' (' + formatSize(totalSizeNew) + ')</span></span><span>Duplicates: <span class="num-span">' + numDuplicates + ' (' + formatSize(totalSizeDuplicates) + ')</span></span>');


    $("#directory-results .col-lg-2").html('<button class="btn btn-success btn-refresh" type="button">Refresh</button>');
}

function setOptionsAndPathsFile(key, state) {
    console.log(key, state);

    $.ajax({
        type: "POST",
        url: "php/setOptionsAndPathsFile.php",
        dataType: "json",
        data: {
            key: key,
            state: state
        }
    }).always(function(response) {
        handleSetOptionsAndPathsFileResult(response);
    });
}

function handleSetOptionsAndPathsFileResult(response) {

    console.log(response);

}


function getOptionsAndPathsFromFile() {

    $.ajax({
        type: "POST",
        url: "php/getOptionsAndPathsFromFile.php",
        dataType: "json",
    }).always(function(response) {
        handleGetOptionsAndPathsFromFileResult(response);
    });
}

function handleGetOptionsAndPathsFromFileResult(response) {

    console.log(response);

    for (i = 0; i < response.pathsToProcess.length; i++) {
        // var pathsToProcess = '<tr><td class="utils-path">' + response.pathsToProcess[i] + '</td><td><span class="count">' + countFiles(response.pathsToProcess[i]) + '</span></td><td><input class="" type="checkbox" value="" checked></td>/tr>';
        var pathsToProcess = '<tr><td class="utils-path">' + response.pathsToProcess[i] + '</td><td><span class="count"></span></td><td><input class="" type="checkbox" value="" checked></td>/tr>';
        // var pathsToProcess = '<tr><td>' + response.pathsToProcess[i] + '</td><td></td><td><input class="" type="checkbox" value="" checked></td>/tr>';
        $("#utilities #utils-paths tbody").append(pathsToProcess);
    }
    options.push(response.moveDuplicates, response.updateDimensionsInDB, response.updateDurationInDB, response.updatePathInDB, response.updateSizeInDB, response.moveRecorded);
    for (i = 0; i < options.length; i++) {
        //console.log('options: ' + i + ' ' + options[i]);
    }
}

$(document).ready(function() {

    directories = [];

    $("#utilities #utils-paths tbody td.utils-path").each(function() {

        directories.push($(this).text());
    });

    $.each(directories, function(index, value) {
        //console.log("value", value);
        // console.log("(directories[index]", directories[index]);

        console.log(directories[index]);
        //var count = countFiles(directories[index]);
        //console.log(countFiles(directories[index]));
        //$(this).parent().find('.count').text(count);
        //console.log(countFiles(directories[index]));
        //$("#utilities #utils-paths tbody td.count").text(count);
        // var cnt = countFiles(directories[index]);
        // console.log("cnt", cnt);
    });


    // countFiles(directory);

});

function countFiles(directory) {
    var count = 0;
    var data;
    $.ajax({
        type: "POST",
        url: "php/countFiles.php",
        dataType: "json",
        data: {
            directory: directory
        }
        // async: true


        // $("#utilities #utils-paths tbody td.count").text("count");
        // $("#utilities #utils-paths tbody td").text(count);
        // var parent = $("#utils-paths tbody tr").find('.utils-path').text(directory);
        // console.log("parent()", parent);
        //$("#utils-paths tbody tr").find('.utils-path').text(directory).parent().children('.count').text(count);
        // console.log(("#utils-paths td.utils-path").val());
        //$("#utils-paths tbody tr td span").html(count);
        // 	}
        // });

    }).always(function(data) {
        console.log("data", data);
        // rd = data;
        // var countTxt = $(".count").val();
        // if (!jQuery.trim(countTxt).length > 0) {
        // 	//$("#utils-paths tbody tr").find('.utils-path').text(directory).parent().find('.count').text(data);
        // }

        //var parent = $("#utils-paths tbody tr").find('.utils-path').text(directory).parent().find('.count').text(data);
        //	console.log("parent", parent);
        // return data;
        //return rd;
        // $("#loading-spinner").css('display', 'none');
    });
    return data;
}

//END Normalize DB

//Check DB for Duplicates

$('#btn-find-duplicates').one("click", function(event) {
    event.preventDefault();
    checkDBForDuplicates();

});

function checkDBForDuplicates() {
    $("#loading-spinner").css('display', 'inline-block');
    $.ajax({
        type: "POST",
        url: "php/checkDBForDuplicates.php",
        dataType: "json",

    }).always(function(response) {
        handleCheckDBForDuplicatesResult(response);
        $("#loading-spinner").css('display', 'none');
    });
}

function handleCheckDBForDuplicatesResult(response) {

    // $('#mode').css('display', 'none');
    // $('#directory-results').css('display', 'block');

    totalCount = response.data.length;
    // newMovies = 0;
    numDuplicates = 0;
    totalSizeNew = 0;
    totalSizeDuplicates = 0;


    for (i = 0; i < response.data.length; i++) {
        var name = response.data[i].title;
        var dimensions = response.data[i].dimensions;
        var size = response.data[i].size;
        var duration = response.data[i].duration;
        var path = response.data[i].path;
        var id = response.data[i].id;
        console.info("response.data: ", response.data);
        if (name.length > 80) {
            name = name.substring(0, 80);
        }

        // if (response.data[i].Duplicate == false) {
        // 	++newMovies;
        // 	totalSizeNew += size;

        var markup = '<tr><td>' + id + '</td><td><a href="#">' + name + '</a></td><td>' + dimensions + '</td><td>' + formatSize(size) + '<span class="tsize">' + size + '</span></td><td>' + formatDuration(duration) +
            '</td><td></td><td><button class="btn btn-warning btn-copy-values" type="button"><i class="fas fa-copy"></i>Copy</button><button class="btn btn-success btn-paste-values"><i class="fas fa-play"></i>Paste</button>' +
            '<button class="btn btn-default btn-delete"><i class="fa fa-trash"></i>Del</button></td></tr>';
        // } else if (response.data[i].Duplicate == true) {
        // 	++numDuplicates;
        // 	totalSizeDuplicates += size;
        //
        // 	if (response.data[i].isLarger == true) {
        // 		var markup = '<tr><td>' + id + '</td><td><a href="#">' + name + '</a></td><td>' + dimensions + '</td><td>' + formatSize(size) + '  <a href="#" data-toggle="tooltip" data-placement="top" title="' + formatSize(sizeInDB) +
        // 			'"><i class="fas fa-angle-double-up"></i></a></td><td>' + formatDuration(duration) + '</td><td>' + dateCreated + '</td><td class="dup-not-new">Duplicate</td><td><button class="btn btn-warning btn-update-with-result" type="button">' +
        // 			'<i class="fas fa-copy"></i>Update DB</i></button><!-- button class="btn btn-default btn-delete"><i class="fa fa-trash"></i>Del</button>--><button class="btn btn-success btn-play"><i class="fas fa-play"></i>Play</button></td></tr>';
        // 	} else {
        // 		var markup = '<tr><td>' + id + '</td><td><a href="#">' + name + '</a></td><td>' + dimensions + '</td><td>' + formatSize(size) + '<span class="tsize">' + size + '</span></td><td>' + formatDuration(duration) + '</td>' +
        // 			'<td>' + dateCreated + '</td><td class="dup-not-new">Duplicate</td><td><button class="btn btn-warning btn-update-with-result" type="button"><i class="fas fa-copy"></i>Update DB</button>' +
        // 			'<!--button class="btn btn-default btn-delete"><i class="fa fa-trash"></i>Del</button>--><button class="btn btn-success btn-play"><i class="fas fa-play"></i>Play</button></td></tr>';
        // 	}
        //	}
        $("table#duplicates-list").append(markup);
    }

    // $("#directory-results #totals").html('<span>Total: <span class="num-span">' + totalCount + '</span></span><span>New: <span class="num-span">' + newMovies + ' (' + formatSize(totalSizeNew) + ')</span></span><span>Duplicates: <span class="num-span">' + numDuplicates + ' (' + formatSize(totalSizeDuplicates) + ')</span></span>');
    // angular.element($('#movie-controller')).scope().refreshData();
    //
    // $("#directory-results .col-lg-2").html('<button class="btn btn-success btn-refresh" type="button">Refresh</button>');
}

$('#directory-results').one("click", ".btn-delete", function(event) {
    var path = $(this).closest('tr').children('td:first-of-type').text();
    var fileName = $(this).closest('tr').children('td:nth-of-type(3)').text();
    var fileNameAndPath = path + "/" + fileName;
    deleteFile(fileNameAndPath);
    $(this).closest('tr').remove();
});

function deleteFile(fileNameAndPath) {

    function deleteIt() {

        return $.ajax({
            type: "POST",
            url: "php/deleteFile.php",
            dataType: "json",
            data: {
                fileNameAndPath: fileNameAndPath
            },
            success: handleResponse
        });
    }

    function handleResponse(data) {
        // console.log("response: ", data);
        return;
    }
    deleteIt();
}
//END Check DB for Duplicates