//var fname = "";
var lines = [];
var dirLines = [];
var linesLength = 0;
var currentLine = 0;
var currentLineIndex = 0;
var numDupes = 0;
var cleanedNamesDeDuped = [];
var numTitlesFromDirectory = 0;
var copyResultRowValues = {};

function updateRecord(id, dataType, dataToUpdate) {

    return $.ajax({
        async: true,
        type: "POST",
        url: "editRow.php",
        dataType: "json",
        data: {
            id: id,
            dataType: dataType,
            dataToUpdate: dataToUpdate
        },
        success: handleResponse
    })

    function handleResponse(data) {
        refreshData();
        return;
    }
}

// var field = $('.ui-grid-coluiGrid-0005').find('input[type=text]');
//
// $(field).donetyping(function () {
//     //$('#example-output').text('Event last fired @ ' + (new Date().toUTCString()));
//     var tst = $('.ui-grid-coluiGrid-0005').find('input[type=text]').val();
//     $('.recent-terms ul').append('<li>' + tst + '</li>');
// });

// $(document).on('focus', $('.ui-grid-coluiGrid-0005').find('input[type=text]'), function (event) {
//
//     //$('.ui-grid-coluiGrid-0005').find('input[type=text]').on('change', function () {
//     // var tst = $('.ui-grid-coluiGrid-0005').find('input[type=text]').val();
//     //console.log(tst);
//     setTimeout(function () {
//         //console.log(tst);
//         // $('.recent-terms ul').append('<li><a href="' + tst + '">' + tst + '</a></li>');
//         var tst = $('.ui-grid-coluiGrid-0005').find('input[type=text]').val();
//         $('.recent-terms ul').append('<li>' + tst + '</li>');
//     }, 5000);
// })

// var field = $('.ui-grid-coluiGrid-0005').find('input[type=text]');
// var field = $('.ui-grid-coluiGrid-0005').find('input[type=text]');
// console.log('field ', field);
//
// $('.ui-grid-coluiGrid-0005').find('input[type=text]').keypress(_.debounce(function () {
//     var tst = $('.ui-grid-coluiGrid-0005').find('input[type=text]').val();
//     console.log('tst: ', tst);
//     $('.recent-terms ul').append('<li>' + tst + '</li>');
// }, 500));

// angular.element(document).ready(function () {

$(document).ready(function() {
    var intervalId;

    function copy_search_term(e) {
        intervalId = setTimeout(function() {
            var tst = $.trim($('.ui-grid-coluiGrid-0005').find('input[type=text]').val());
            if (tst) {
                $('.recent-terms ul').append('<li>' + tst + '</li>');
            }
        }, 2000);
    }

    $('.ui-grid-coluiGrid-0005').find('input[type=text]').on('keydown', _.debounce(copy_search_term, 1300));
    // });

    $('.recent-terms ul').on('click', 'li', function(event) {
        var recentTerm = $(this).text();
        //console.log(recentTerm);
        // $('.ui-grid-coluiGrid-0005').find('input[type=text]').val(recentTerm);
        var input = $('.ui-grid-coluiGrid-0005').find('input[type=text]');
        $(input).val(recentTerm);
        input.focus();

        function dispatchTheEvent(event) {
            input.dispatchEvent(new KeyboardEvent('keypress', {
                'key': '32'
            }));
        }
        input.addEventListener('keypress', dispatchTheEvent);
        //el.dispatchEvent(new KeyboardEvent('keypress', { 'key': '32' }));
    })

    $('.recent-terms button').on("click", function(event) {
        $('.recent-terms ul').empty();
    })

    // $('.row').on("click", ".btn-copy-result", function (event) {
    //     console.log("clicked");
    //     var row = $(this).closest("tr");
    //     var tds = row.find("td");
    //     $.each(tds, function () {
    //         console.log($(this).text());
    //     });
    // });

});

function deleteRow(id) {

    function deleteIt() {

        return $.ajax({
            type: "POST",
            url: "deleteRow.php",
            dataType: "json",
            data: {
                id: id
            },
            success: handleResponse
        })
    }

    function handleResponse(data) {
        // angular.element(document.getElementById('MoviesCtrl')).scope().refreshData();
        angular.element($('#movie-controller')).scope().refreshData();
        return;
    }
    deleteIt();
}

function pasteResults(id) {

    delete copyResultRowValues['Title'];
    delete copyResultRowValues['Dimensions'];
    delete copyResultRowValues['Size'];

    function pasteIt() {
        var row = $(this).closest(".ui-grid-row");

        var title = copyResultRowValues['Title'];
        row.find(".cell-title").val(title);

        var dimensions = copyResultRowValues['Dimensions'];
        row.find(".cell-dimensions").val(dimensions);

        var size = copyResultRowValues['Size'];
        row.find(".cell-size").val(size);

        copyResultRowValues = JSON.stringify(copyResultRowValues);

        return $.ajax({
            type: "POST",
            url: "pasteRow.php",
            dataType: "json",
            data: {
                id: id,
                copyResultRowValues: copyResultRowValues
            },
            success: handleResponse
        })
    }

    function handleResponse(data) {
        // copyResultRowValues = {};
        angular.element($('#movie-controller')).scope().refreshData();
        return;
    }
    pasteIt();
}

// $(document).ready(function () {
$('#directory-results').on("click", ".btn-copy-result", function(event) {

    // delete copyResultRowValues['Title'];
    // delete copyResultRowValues['Dimensions'];
    // delete copyResultRowValues['Size'];

    var row = $(this).closest("tr");
    copyResultRowValues['Title'] = row.find("td:nth-child(2)").text();
    copyResultRowValues['Dimensions'] = row.find("td:nth-child(3)").text();
    copyResultRowValues['Size'] = row.find("td:nth-child(4) .tsize").text();
    //copyResultRowValues = [row.find("td:nth-child(2)"), row.find("td:nth-child(3)"), row.find("td:nth-child(4)")];
});

$(document).ready(function() {
    $('.ui-grid-row .cell-size').on("click", ".ui-grid-cell-contents", function(event) {
        console.log("cell-size handler");
        var size = $.trim($('.cell-size').val());
        size = size.replace(new RegExp(",", "g"), "");
        size = parseInt(size, 10);
        $('.cell-size').val(size);
        console.log("size: ", size);
    })
});

$('input[type=radio]').on('change', function() {
    //if (!this.checked) return

    $('.collapse').not($('div.' + $(this).attr('class'))).slideUp();
    $('.collapse.' + $(this).attr('class')).slideDown();

    $('#single-title-input').css('border', '1px solid #ccc');
});

$('.btn-add-single-title').on("click", function(event) {
    event.preventDefault();
    var stl = $.trim($("#single-title-input").val());
    if (stl) {
        var nameToAdd = $.trim($('#single-title-input').val());
        var dimensions = $.trim($('#single-title-input-dimensions').val());

        var size = $.trim($('#single-title-input-size').val());
        size = size.replace(new RegExp(",", "g"), "");
        size = parseInt(size, 10);
        //$('#single-title-input-size').val(size);
        addMovie(nameToAdd, dimensions, size);
    } else {
        $('#single-title-input').css('border', '1px solid red');
    }
});

$('.btn-start-processing-file').on("click", function(event) {
    event.preventDefault();
    //console.log('btn-start-processing-file click handler');
    var ifl = $.trim($("#input-file-input").val());

    if (ifl) {
        var inputFileName = $('#input-file-input').val();
        //console.log("inputFileName: ", inputFileName);
        getListOfNames(inputFileName);

    } else {
        $('#input-title-input').css('border', '1px solid red');
    }

    function getListOfNames(inputFileName) {
        return $.ajax({
            type: "POST",
            url: "processNamesFixedFile.php",
            dataType: "json",
            data: "fname=" + inputFileName,
            success: handleNames
        })
    }

    function handleNames(data) {
        linesLength = data.length;
        console.log("linesLength in handleNames function: ", linesLength);
        for (var i = 0; i < data.length; i++) {
            lines[i] = data[i];
        }
        $('#import-records .input-group.input-file').css('display', 'none');
        $('#import-records .input-group.input-text').css('display', 'block');
        $('#import-records .record-name').val(lines[0]);
        return lines.sort();
    }
});

$('.btn-add-title').on("click", function(event) {
    event.preventDefault();
    var mtl = $.trim($("#input-file-record-name").val());
    if (mtl) {
        for (i = 0; i < lines.length; i++) {
            var nameToAdd = $.trim($('#input-file-record-name').val());
            addMovie(nameToAdd);
            incrementLines();
        }
    } else {
        $('#input-file-record-name').css('border', '1px solid red');
    }
});

//default
//$('#input-directory').val("/Users/sean/Download/names fixed/");
$('#input-directory').val("/Volumes/Recorded 1/test/");
//$('#input-directory').val("/Volumes/Tmp/recorded//");

$('.btn-start-processing-dir').on("click", function(event) {
    event.preventDefault();

    dirName = $('#input-directory').val();
    //console.log('dirName: ', dirName);
    processFilesForDB(dirName);
});

function processFilesForDB(dirName) {
    // Show loading spinner.
    $("#loading-spinner").css('display', 'inline-block');
    $.ajax({
        type: "POST",
        url: "processFilesForDB.php",
        dataType: "json",
        data: {
            dirName: dirName
        },
    }).always(function(response) {
        handleProcessFilesForDBResult(response);
        // Hide loading spinner regardless of whether call succeeded or failed.
        $("#loading-spinner").css('display', 'none');
    })
}

function handleProcessFilesForDBResult(response) {
    // console.log('in handleProcessFilesForDBResult');
    //console.log('response: ', response);
    //console.log('response.data: ', response.responseText);

    $('#mode').css('display', 'none');
    $('#directory-results').css('display', 'block');

    totalCount = response.data.length;
    newMovies = 0;
    numDuplicates = 0;
    //console.log('data.length: ', response.data['length']);

    //$timeout(function () {
    //angular.element($('#movie-controller')).scope().resultsGridOptions.data = response.data;
    //});


    for (i = 0; i < response.data.length; i++) {
        var name = response.data[i]['Name'];
        //console.log("data[i]['Name']: ", response.data[i]['Name']);
        var dimensions = response.data[i]['Dimensions'];
        var size = response.data[i]['Size'];
        //size = formatSize(size);
        var isDuplicate = response.data[i]['Duplicate'];
        var isLarger = response.data[i]['Larger'];
        var dateCreated = response.data[i]['Date Created'];
        var path = response.data[i]['Path'];

        if (name.length > 80) {
            name = name.substring(0, 80);
        }

        if (response.data[i]['Duplicate'] == false) {
            ++newMovies;

            var markup = '<tr><td></td><td>' + name + '</td><td>' + dimensions + '</td><td>' + formatSize(size) + '<span class="tsize">' + size + '</span></td><td></td><td class="new-not-dup">New</td><td><button class="btn btn-warning btn-copy-result" type="button"><i class="fas fa-copy"></i>Copy</button></td></tr>';
        } else if (response.data[i]['Duplicate'] == true) {
            ++numDuplicates;

            if (response.data[i]['Larger'] == true) {
                var markup = '<tr><td></td><td>' + name + '</td><td>' + dimensions + '</td><td>' + formatSize(size) + '  <i class="fas fa-angle-double-up"></i><span class="tsize">' + size + '</span></td><td>' + dateCreated + '</td><td class="dup-not-new">Duplicate</td><td><button class="btn btn-warning btn-copy-result" type="button"><i class="fas fa-copy"></i>Copy</i></button></td></tr>';
            } else {
                var markup = '<tr><td></td><td>' + name + '</td><td>' + dimensions + '</td><td>' + formatSize(size) + '<span class="tsize">' + size + '</span></td><td>' + dateCreated + '</td><td class="dup-not-new">Duplicate</td><td><button class="btn btn-warning btn-copy-result" type="button"><i class="fas fa-copy"></i>Copy</button></td></tr>';
            }
        }
        // $("#directory-results table").append(markup);
        $("#directory-results table").append(markup);
        // $("#directory-results table").append(markupDuplicateTitlesLarger);
        // $("#directory-results table").append(markupDuplicateTitles);
    }
    // $("#directory-results table").append(markupNewTitles);
    // $("#directory-results table").append(markupDuplicateTitlesLarger);
    // $("#directory-results table").append(markupDuplicateTitles);

    $("#directory-results #totals").html('<span>Total: <span class="num-span">' + totalCount + '</span></span><span>New: <span class="num-span">' + newMovies + '</span></span><span>Duplicates: <span class="num-span">' + numDuplicates + '</span></span>');
    angular.element($('#movie-controller')).scope().refreshData();

    $("#directory-results .col-xs-3").html('<button class="btn btn-default btn-refresh" type="button">Refresh</button>');
}

$('#directory-results').on("click", ".btn-refresh", function(event) {
    $("#directory-results table tr").remove();
    $('#mode').css('display', 'block');
    $('#directory-results').css('display', 'none');
});


function openModal(cleanedNamesDeDuped) {
    numTitlesFromDirectory = cleanedNamesDeDuped.length;

    for (i = 0; i < cleanedNamesDeDuped.length; i++) {
        $('#directoryAddModal .modal-body #from-directory')
            .append('<div class="input-group input-text"><input type="text" class="form-control filename-text" id="input-dir-record-name" value="' + cleanedNamesDeDuped[i] + '"/>' +
                '<span class="input-group-btn"><button class="btn btn-primary btn-add-title-modal" type="button">Add to database</button></span>' +
                '<span class="input-group-btn"><button class="btn btn-danger btn-remove-title-modal" type="button">Delete</button></span></div>');
    }

    $('#directoryAddModal').modal('show');
};

function incrementLines() {

    currentLineIndex = currentLineIndex + 1;
    $('#import-records .record-name').val(lines[currentLineIndex]);
}

$('#duplicates').on("click", ".btn-paste-results", function(event) {

    clipboard.writeText($('.duplicate-text').val());
    $(this).closest('.input-group').remove();
});

$('#from-directory').on("click", ".btn-copy-title", function(event) {

    clipboard.writeText($('.duplicate-text').val());
    $(this).closest('.input-group').remove();
    numTitlesFromDirectory--;

    if (numTitlesFromDirectory == 0) {
        $('#directoryAddModal').modal('hide');
    }
});

$('.ui-grid-cell').on("click", ".btn-copy-title", function(event) {

    clipboard.writeText($(this).closest('.ui-grid-coluiGrid-0005 .ui-grid-cell-contents').val());
});

$(document).on("click", '.btn-add-title-modal', function(event) {
    event.preventDefault();

    var motl = $(this).parents('.input-text').children("#input-dir-record-name");
    var motlVal = $.trim($(motl).val());
    if (motlVal) {
        var nameToAdd = motlVal;
        addMovie(nameToAdd);
        $(this).closest('.input-group').remove();
    } else {
        $('#input-dir-record-name').css('border', '1px solid red');
    }
    if (numTitlesFromDirectory == 0) {
        $('#directoryAddModal').modal('hide');
    }
});

$(document).on("click", '.btn-remove-title-modal', function(event) {

    $(this).closest('.input-group').remove();
});

$(document).on("click", '.btn-add-all-modal', function(event) {
    // event.preventDefault();
    while (numTitlesFromDirectory > 0) {

        $(document).click('.btn-add-title-modal');
        console.log("clicked");
        // var motl = $(this).parents('.input-text').children("#input-dir-record-name");
        // var motlVal = $.trim($(motl).val());
        // if (motlVal) {
        //     var nameToAdd = motlVal
        ;
        //     addMovie(nameToAdd);
        //     $(this).closest('.input-group').remove();
        numTitlesFromDirectory--;
        // } else {
        //     $('#input-dir-record-name').css('border', '1px solid red');
        // }
    }
    if (numTitlesFromDirectory == 0) {
        $('#directoryAddModal').modal('hide');
    }
});

function addMovie(nameToAdd, dimensions, size) {
    $.ajax({
        type: "POST",
        url: "addMovie.php",
        dataType: "json",
        data: {
            title: nameToAdd,
            dimensions: dimensions,
            filesize: size
        },
    }).always(function(data) {
        handleResult(data);
    })
}

function handleResult(data) {
    var isDupe = data.responseText;
    if (~isDupe.indexOf("Duplicate")) {
        var duplicateTitle = isDupe.split(': ')[1];
        $('#duplicates').prepend('<div class="input-group input-text"><input type="text" class="form-control duplicate-text" value="' + duplicateTitle + '" disabled/>' +
            '<span class="input-group-btn"><button class="btn btn-warning btn-copy-title" type="button">Copy to clipboard</button><button class="btn btn-danger btn-find-file" type="button">Find file</button></span></div>');
        $('#single-title-input').css('border', '1px solid red');
        numDupes++;
        $('.num-dupes span').text(numDupes);
    } else {
        $('#single-title-input').css('border', '1px solid green');
    }
    angular.element($('#movie-controller')).scope().refreshData();
}

$('#duplicates').on("click", ".btn-find-file", function(event) {
    var fileName = $('.duplicate-text').val();
    findFile(fileName);
});

function findFile(fileName) {
    console.log('in findFile');
    console.log('fileName: ', fileName);

    function findTheFile() {
        return $.ajax({
            type: "POST",
            url: "findFile.php",
            dataType: "json",
            data: {
                fileName: fileName
            },
            success: handleFindResult
        })
    }

    function handleFindResult(data) {
        // angular.element(document.getElementById('MoviesCtrl')).scope().refreshData();
        angular.element($('#movie-controller')).scope().refreshData();
        console.log('in handleFindResult');

        return;
    }
    findTheFile();
}

function deleteFile(fileName) {
    var id = fileName;

    function deleteTheFile() {
        return $.ajax({
            type: "POST",
            url: "deleteFile.php",
            dataType: "json",
            data: {
                id: id
            },
            success: handleDelete
        })
    }

    function handleDelete(data) {
        // angular.element(document.getElementById('MoviesCtrl')).scope().refreshData();
        console.log('in handleDelete');
        angular.element($('#movie-controller')).scope().refreshData();
        return;
    }
    deleteTheFile();
}


function formatSize(size) {
    if (size >= 1073741824) {
        size = size / 1073741824;
        size = number_format(size, 2, '.', '') + ' GB';
    } else if (size >= 1048576) {
        size = size / 1048576;
        size = number_format(size, 2, '.', '') + ' MB';
    } else if (size >= 1024) {
        size = size / 1024;
        size = number_format(size, 2, '.', '') + ' KB';
    }
    return size;
}

function number_format(number, decimals, dec_point, thousands_sep) {
    // Strip all characters but numerical ones.
    number = (number + '').replace(/[^0-9+\-Ee.]/g, '');
    var n = !isFinite(+number) ? 0 : +number,
        prec = !isFinite(+decimals) ? 0 : Math.abs(decimals),
        sep = (typeof thousands_sep === 'undefined') ? ',' : thousands_sep,
        dec = (typeof dec_point === 'undefined') ? '.' : dec_point,
        s = '',
        toFixedFix = function(n, prec) {
            var k = Math.pow(10, prec);
            return '' + Math.round(n * k) / k;
        };
    // Fix for IE parseFloat(0.55).toFixed(0) = 0;
    s = (prec ? toFixedFix(n, prec) : '' + Math.round(n)).split('.');
    if (s[0].length > 3) {
        s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep);
    }
    if ((s[1] || '').length < prec) {
        s[1] = s[1] || '';
        s[1] += new Array(prec - s[1].length + 1).join('0');
    }
    return s.join(dec);
}
