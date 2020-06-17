$('#input-directory').val("/Volumes/Misc 1/to move/");
//$('#input-directory').val("/Users/sean/Downloads/test/");

$('.btn-start-processing-dir').one("click", function(event) {
    event.preventDefault();

    directory = $('#input-directory').val();
    // $("#file-data tr").not(':first').not(':last').empty();
    //$("#file-data tr").not(':first').empty();
    $("#file-data tbody ~ tbody").empty();
    processFiles(directory);
});

/////////
// var $dbOpsButton = $('.btn-process-dir-database-ops');
// var transitionEnd = 'webkitTransitionEnd msTransitionEnd transitionend';
//
// function processFiles(directory) {
// 	$("#loading-spinner").css('display', 'inline-flex');
// 	$.ajax({
// 		type: "POST",
// 		url: "php/normalizeFiles.php",
// 		dataType: "json",
// 		data: {
// 			directory: directory
// 		},
// 	}).always(function(response) {
// 		handleProcessFilesResult(response);
// 		$("#loading-spinner").css('display', 'none');
// 		$processPatterns.addClass('inline-flex').outerWidth();
// 		$processPatterns.addClass('fade-in').one(transitionEnd, function() {
//
// 		});
// 	})
// }

///////////////

var dbOpsButton = $('.btn-process-dir-database-ops');
var transitionEnd2 = 'webkitTransitionEnd msTransitionEnd transitionend';

$('.btn-process-dir-rename-files').one("click", function(event) {
    event.preventDefault();

    $.ajax({
        type: "POST",
        url: "php/renameSingleFile.php",
        dataType: "json",
    }).always(function(response) {
        handlerenameSingleFileResult(response);
    });
});

function handlerenameSingleFileResult(response) {
    directory = $('#input-directory').val();
    processFiles(directory);
    dbOpsButton.addClass('inline-flex').outerWidth();
    dbOpsButton.addClass('fade-in').one(transitionEnd2, function() {

    });
}

$('#file-data').one("click", ".btn-default", function(event) {

    if ($(this).hasClass("btn-show-original-filename")) {
        $(this).closest('tr').children('td:nth-of-type(2)').css("display", "table-cell");
        $(this).closest('tr').children('td:nth-of-type(3)').css("display", "none");
        $(this).removeClass("btn-show-original-filename");
        $(this).addClass("btn-show-new-filename");
        $(this).text("Show New");
    } else if ($(this).hasClass("btn-show-new-filename")) {
        $(this).closest('tr').children('td:nth-of-type(2)').css("display", "none");
        $(this).closest('tr').children('td:nth-of-type(3)').css("display", "table-cell");
        $(this).removeClass("btn-show-new-filename");
        $(this).addClass("btn-show-original-filename");
        $(this).text("Show Original");
    }

});

$('#file-data').one("click", ".btn-revert", function(event) {
    var path = $(this).closest('tr').children('td:first-of-type').text();
    path = path + "/";
    var originalFileName = $(this).closest("tr").find("td:nth-of-type(2)").text();
    var newFileName = $(this).closest('tr').children('td:nth-of-type(3)').text();
    revertFile(path, originalFileName, newFileName);
});

function revertFile(path, originalFileName, newFileName) {

    function revertIt() {

        return $.ajax({
            type: "POST",
            url: "php/renameSingleFile.php",
            dataType: "json",
            data: {
                path: path,
                originalFileName: originalFileName,
                newFileName: newFileName
            },
            success: handleResponse
        });
    }

    function handleResponse(data) {
        //console.log("response: ", data);
        $(this).closest("tr").find("td:nth-of-type(3)").html('<a>' + response + '</a>');
    }
    revertIt();
}

var $processPatterns = $('#process-patterns');
var transitionEnd = 'webkitTransitionEnd msTransitionEnd transitionend';

function processFiles(directory) {
    $("#loading-spinner").css('display', 'inline-flex');
    $.ajax({
        type: "POST",
        url: "php/normalizeFiles.php",
        dataType: "json",
        data: {
            directory: directory
        },
    }).always(function(response) {
        handleProcessFilesResult(response);
        $("#loading-spinner").css('display', 'none');
        $processPatterns.addClass('inline-flex').outerWidth();
        $processPatterns.addClass('fade-in').one(transitionEnd, function() {

        });
    });
}

function handleProcessFilesResult(response) {
    //console.log("response: ", response);
    var totalCount = response.length;

    // markupTh = '<tr><th>Path</th><th>Original Filename</th><th>New Filename</th><th id="size">Size</th><th id="dimensions">Dimensions</th><th id="duration">Duration</th><th></th></tr>';
    //
    // $("#file-results table").append(markupTh);
    $("#file-data tbody ~ tbody").empty();
    // var tbody = $('#file-results table').children('tbody:first');
    var tbody = $('#file-results table').children('tbody:nth-of-type(2)');
    var table = tbody.length ? tbody : $('#file-results table');

    // var row = '<tr><td>{{path}}</td><td>{{originalFileName}}</td><td class="{{conflictsClass}} {{fileAlreadyExistsClass}}"><a>{{fileNameDisplayed}}</a></span></td><td>{{size}}</td><td>{{dimensions}}</td><td>{{durationNoMS}}</td><td><button class="btn btn-default btn-show-original-filename"><i class="fa fa-trash"></i>Show Original</button></td></tr>';
    var row = '<tr><td>{{path}}</td><td>{{originalFileName}}</td><td class="{{conflictsClass}} {{fileAlreadyExistsClass}}"><a>{{fileNameDisplayed}}</a></span></td><td>{{size}}</td><td>{{dimensions}}</td><td>{{durationNoMS}}</td></tr>';

    for (i = 0; i < response.length; i++) {

        var path = response[i]['Path'];
        var fileNameAndPath = response[i]['fileNameAndPath'];
        var originalFileName = response[i]['originalFileName'];
        var fileWillBeRenamed = response[i]['fileWillBeRenamed'];
        var newFileName = response[i]['newFileName'];
        var size = response[i]['Size'];
        var dimensions = response[i]['Dimensions'];
        var duration = response[i]['Duration'];
        var durationNoMS = duration.split(".")[0];
        var fileRenameConflict = response[i]['fileRenameConflict'];
        var fileAlreadyExists = response[i]['fileAlreadyExists'];
        var fileNameDisplayed = "";

        if (fileAlreadyExists == true) {
            var fileAlreadyExistsClass = 'file-already-exists';
        } else if (fileAlreadyExists == false) {
            fileAlreadyExistsClass = 'file-does-not-exist';
        }
        if (fileRenameConflict == true) {
            conflictsClass = 'file-has-conflicts';
            //fileNameDisplayed = originalFileName;
            fileNameDisplayed = newFileName;
        } else if (fileRenameConflict == false) {
            conflictsClass = 'file-has-no-conflicts';
            fileNameDisplayed = newFileName;
        }
        // markup = '<tr><td>' + path + '</td><td>' + originalFileName + '</td><td class="' + conflictsClass + ' ' + fileAlreadyExistsClass + '"><a>' + fileNameDisplayed + '</a></span></td><td>' + size + '</td><td>' + dimensions + '</td><td>' + durationNoMS + '</td><td><button class="btn btn-default btn-show-original-filename"><i class="fa fa-trash"></i>Show Original</button></td></tr>';
        //markup = '<tr><td>' + path + '</td><td>' + originalFileName + '</td><td class="' + conflictsClass + ' ' + fileAlreadyExistsClass + '"><a>' + fileNameDisplayed + '</a></span></td><td>' + size + '</td><td>' + dimensions + '</td><td>' + durationNoMS + '</td><td><button class="btn btn-default btn-show-original-filename"><i class="fa fa-trash"></i>Show Original</button></td></tr>';

        // $("#file-data tr").not(':first').empty();
        table.append(row.compose({
            'path': path,
            'originalFileName': originalFileName,
            'conflictsClass': conflictsClass,
            'fileAlreadyExistsClass': fileAlreadyExistsClass,
            'fileNameDisplayed': fileNameDisplayed,
            'size': size,
            'dimensions': dimensions,
            'durationNoMS': durationNoMS,
        }));


        //$("#file-data tr").not(':first').empty();
        //$("#file-results table").append(markup);
        //$("#file-data tr").not(':first').

    }
    //totalsRow = '<tr><td></td><td>Total: {{totalCount}}</td><td></td><td></td><td></td><td></td></tr>';
    //$("#file-results table").append(markupTc);

    // table.append(totalsRow.compose({
    // 	'totalCount': totalCount
    // }));

    // markupDr = '<tr><td></td><td></td><td><button class="btn btn-success btn-process-dir-rename-files" type="button">Rename Files</button></td><td></td><td></td><td></td><td></td></tr>';
    // $("#file-results table").append(markupDr);

}
String.prototype.compose = (function() {
    var re = /\{{(.+?)\}}/g;
    return function(o) {
        return this.replace(re, function(_, k) {
            return typeof o[k] != 'undefined' ? o[k] : '';
        });
    };
}());
$(document).ready(function() {
    $.fn.editable.defaults.mode = 'inline';

    $("#file-results table").one("click", "a", function(e) {
        e.preventDefault();

        var path = $(this).closest('tr').children('td:first-of-type').text();
        path = path + "/";
        var pk = $(this).closest("tr").find("td:nth-of-type(2)").text();
        var originalFileName = $(this).closest("tr").find("td:nth-of-type(2)").text();

        $(this).editable({
            type: 'text',
            pk: pk,
            name: 'title',
            params: function(params) {
                params.path = path;
                params.originalFileName = originalFileName;
                return params;
            },
            url: "php/renameSingleFile.php",
            success: function(response) {
                if (response == "fail") {
                    $(this).closest("tr").find("td:nth-of-type(2)").text(originalFileName);
                    $(this).closest("tr").find("td:nth-of-type(3)").html('<a>' + originalFileName + '</a>');
                } else {
                    $(this).closest("tr").find("td:nth-of-type(3)").removeClass('file-has-conflicts');
                    $(this).closest("tr").find("td:nth-of-type(3)").removeClass('file-does-not-exist');
                    $(this).closest("tr").find("td:nth-of-type(2)").text(response);
                    $(this).closest("tr").find("td:nth-of-type(3)").html('<a>' + response + '</a>');
                }
            }
        });
    });
});

$('#file-results table').one("click", "th", function(event) {

    var table = $(this).parents('table').eq(0);
    var rows = table.find('tr:gt(0)').toArray().sort(comparer($(this).index()));
    this.asc = !this.asc;
    if (!this.asc) {
        rows = rows.reverse();
    }
    for (var i = 0; i < rows.length; i++) {
        table.append(rows[i]);
    }
});

function comparer(index) {
    return function(a, b) {
        var valA = getCellValue(a, index),
            valB = getCellValue(b, index);
        return $.isNumeric(valA) && $.isNumeric(valB) ? valA - valB : valA.toString().localeCompare(valB);
    };
}

function getCellValue(row, index) {
    return $(row).children('td').eq(index).text();
}