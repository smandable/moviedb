var lines = [];
var dirLines = [];
var linesLength = 0;
var currentLine = 0;
var currentLineIndex = 0;
var numDupes = 0;
var cleanedNamesDeDuped = [];
var numTitlesFromDirectory = 0;
var copyResultRowValues = [];

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
        angular.element($('#movie-controller')).scope().refreshData();
        return;
    }
}

$(document).ready(function() {
    var intervalId;

    function copy_search_term(e) {
        var tst = $.trim($('.ui-grid-coluiGrid-0005').find('input[type=text]').val());
        if (tst) {
            var exists = $('.recent-terms ul li:contains(' + tst + ')').length;
            if (!exists) {
                $('.recent-terms ul').prepend('<li>' + tst + '</li>');
            }
        }
    }

    $('.ui-grid-coluiGrid-0005').on('keydown', _.debounce(copy_search_term, 800));

    $('.recent-terms ul').on('click', 'li', function(event) {
        var recentTerm = $(this).text();
        var input = $('.ui-grid-coluiGrid-0005').find('input[type=text]');

        $(input).val(recentTerm);
        input.focus();
    })

    $('.recent-terms button').on("click", function(event) {
        $('.recent-terms ul').empty();
    })

});

function deleteRow(id) {

    //function deleteIt() {

    return $.ajax({
        type: "POST",
        url: "deleteRow.php",
        dataType: "json",
        data: {
            id: id
        },
        success: handleResponse
    })
    //}

    function handleResponse(data) {
        angular.element($('#movie-controller')).scope().refreshData();
        return;
    }
    //deleteIt();
}

// $(document).ready(function () {
$('#directory-results table').on("click", ".btn-copy-result", function(event) {

    var row = $(this).closest("tr");
    copyResultRowValues['Title'] = row.find("td:nth-child(2)").text();
    copyResultRowValues['Dimensions'] = row.find("td:nth-child(3)").text();
    copyResultRowValues['Size'] = row.find("td:nth-child(4) .tsize").text();
    //copyResultRowValues = [row.find("td:nth-child(2)"), row.find("td:nth-child(3)"), row.find("td:nth-child(4)")];

    console.log("copyResultRowValues['Title']" + copyResultRowValues['Title'] + "\n");
    console.log("copyResultRowValues['Dimensions']" + copyResultRowValues['Dimensions'] + "\n");
    console.log("copyResultRowValues['Size']" + copyResultRowValues['Size'] + "\n");
});

function pasteResults(id) {

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
        // delete copyResultRowValues['Title'];
        // delete copyResultRowValues['Dimensions'];
        // delete copyResultRowValues['Size'];
    }

    function handleResponse(data) {
        // copyResultRowValues = {};
        angular.element($('#movie-controller')).scope().refreshData();
        return;
    }
    if ((typeof copyResultRowValues['Title'] != "undefined") && (typeof copyResultRowValues['Dimensions'] != "undefined") && (typeof copyResultRowValues['Size'] != "undefined")) {
        pasteIt();
    } else {
        alert("Either Title, Dimensions, or Size is empty");

    }
}

// $('#directory-results #table-wrap table').on("dblclick", "td:nth-of-type(2)", function(event) {

// console.info("clicked");
//
// var newTitle = $(this).text();
// console.info("newTitle: ", newTitle);
//
//
// copyResultRowValues['Title'] = row.find("td:nth-child(2)").text();
// copyResultRowValues['Dimensions'] = row.find("td:nth-child(3)").text();
// copyResultRowValues['Size'] = row.find("td:nth-child(4) .tsize").text();
// //copyResultRowValues = [row.find("td:nth-child(2)"), row.find("td:nth-child(3)"), row.find("td:nth-child(4)")];
//
// console.log("copyResultRowValues['Title']" + copyResultRowValues['Title'] + "\n");
// console.log("copyResultRowValues['Dimensions']" + copyResultRowValues['Dimensions'] + "\n");
// console.log("copyResultRowValues['Size']" + copyResultRowValues['Size'] + "\n");
// });



$(document).ready(function() {
    $('#movie-controller').on("click", ".cell-size .ui-grid-cell-contents", function(event) {

        var size = $(this).val();
        size = size.replace(new RegExp(",", "g"), "");
        size = parseFloat(size);
        $(this).val(size);
        // console.log("typeof size: " + typeof size + "\n");
        // console.log(size);
    })
});

$('input[type=radio]').on('change', function() {

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

//default
$('#input-directory').val("/Users/sean/Download/names fixed/");
//$('#input-directory').val("/Volumes/Recorded 1/test/");
//$('#input-directory').val("/Volumes/Tmp/keep/");

$('.btn-start-processing-dir').on("click", function(event) {
    event.preventDefault();

    dirName = $('#input-directory').val();
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
        $("#loading-spinner").css('display', 'none');
    })
}

function handleProcessFilesForDBResult(response) {

    $('#mode').css('display', 'none');
    $('#directory-results').css('display', 'block');

    totalCount = response.data.length;
    newMovies = 0;
    numDuplicates = 0;
    totalSizeNew = 0;
    totalSizeDuplicates = 0;

    for (i = 0; i < response.data.length; i++) {
        var name = response.data[i]['Name'];
        var dimensions = response.data[i]['Dimensions'];
        var size = response.data[i]['Size'];
        var isDuplicate = response.data[i]['Duplicate'];
        var isLarger = response.data[i]['Larger'];
        var sizeInDB = response.data[i]['Size in DB'];
        var dateCreated = response.data[i]['Date Created'];
        var path = response.data[i]['Path'];
        // var newId = response.data[i]['NewId'];
        var id = response.data[i]['Id'];
        //console.info("response.data: ", response.data);
        if (name.length > 80) {
            name = name.substring(0, 80);
        }

        if (response.data[i]['Duplicate'] == false) {
            ++newMovies;
            totalSizeNew += size;

            var markup = '<tr><td>' + id + '</td><td><a href="#">' + name + '</a></td><td>' + dimensions + '</td><td>' + formatSize(size) + '<span class="tsize">' + size + '</span></td><td></td><td class="new-not-dup">New</td><td><button class="btn btn-warning btn-copy-result" type="button"><i class="fas fa-copy"></i>Copy</button></td></tr>';
        } else if (response.data[i]['Duplicate'] == true) {
            ++numDuplicates;
            totalSizeDuplicates += size;

            if (response.data[i]['Larger'] == true) {
                var markup = '<tr><td>' + id + '</td><td><a href="#">' + name + '</a></td><td>' + dimensions + '</td><td>' + formatSize(size) + '  <a href="#" data-toggle="tooltip" data-placement="top" title="' + formatSize(sizeInDB) + '"><i class="fas fa-angle-double-up"></i></a></td><td>' + dateCreated + '</td><td class="dup-not-new">Duplicate</td><td><button class="btn btn-warning btn-copy-result" type="button"><i class="fas fa-copy"></i>Copy</i></button></td></tr>';
            } else {
                var markup = '<tr><td>' + id + '</td><td><a href="#">' + name + '</a></td><td>' + dimensions + '</td><td>' + formatSize(size) + '<span class="tsize">' + size + '</span></td><td>' + dateCreated + '</td><td class="dup-not-new">Duplicate</td><td><button class="btn btn-warning btn-copy-result" type="button"><i class="fas fa-copy"></i>Copy</button></td></tr>';
            }
        }
        $("#directory-results table").append(markup);
    }

    $("#directory-results #totals").html('<span>Total: <span class="num-span">' + totalCount + '</span></span><span>New: <span class="num-span">' + newMovies + ' (' + formatSize(totalSizeNew) + ')</span></span><span>Duplicates: <span class="num-span">' + numDuplicates + ' (' + formatSize(totalSizeDuplicates) + ')</span></span>');
    angular.element($('#movie-controller')).scope().refreshData();

    $("#directory-results .col-lg-2").html('<button class="btn btn-success btn-refresh" type="button">Refresh</button>');
}
$(document).ready(function() {
    $('[data-toggle="tooltip"]').tooltip();

    $.fn.editable.defaults.mode = 'inline';

    $("#directory-results table").on("click", "a", function(e) {
        e.preventDefault();

        var pk = $(this).closest("tr").find("td:nth-of-type(1)").text();

        $(this).editable({
            type: 'text',
            pk: pk,
            name: 'title',
            url: "editRowInResultsTable.php",
            success: function(response) {
                console.log("response: ", response);
            }
        });
    });

});

$('#directory-results').on("click", ".btn-refresh", function(event) {
    $("#directory-results table tr").remove();
    $('#mode').css('display', 'block');
    $('#directory-results').css('display', 'none');
});

$('#duplicates').on("click", ".btn-paste-results", function(event) {

    clipboard.writeText($('.duplicate-text').val());
    $(this).closest('.input-group').remove();
});

$('.ui-grid-cell').on("click", ".btn-copy-title", function(event) {

    clipboard.writeText($(this).closest('.ui-grid-coluiGrid-0005 .ui-grid-cell-contents').val());
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
