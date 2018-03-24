var fname = "";
var lines = [];
var dirLines = [];
var linesLength = 0;
var currentLine = 0;
var currentLineIndex = 0;
var numDupes = 0;
var cleanedNamesDeDuped = [];
var numTitlesFromDirectory = 0;


function deleteRow(value) {
    var id = value;

    function deleteIt() {

        return $.ajax({
            async: true,
            type: "POST",
            url: "deleteRow.php",
            dataType: "json",
            data: { id: id },
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

$('#refreshButton').on("click", function (event) {
    event.preventDefault();

    angular.element($('#movie-controller')).scope().refreshData();
});

function bs_input_file() {

    $(".input-file").before(function () {
        var element = $("<input type='file' style='visibility:hidden; height:0' class='fname-input'>");
        element.attr("name", $(this).attr("name"));
        element.change(function () {
            element.next(element).find('input').val((element.val()).split('\\').pop());
        });
        $(this).find("button.btn-choose-file").click(function () {
            $('.btn-start-processing-file').removeAttr("disabled");
            element.click();
        });
        // $(this).find("button.btn-choose-dir").click(function () {
        //     $('.btn-start-processing-dir').removeAttr("disabled");
        //     element.click();
        // });
        $(this).find('input').css("cursor", "pointer");
        $(this).find('input').mousedown(function () {
            $('.btn-start').removeAttr("disabled");
            $(this).parents('.input-file').prev().click();
            return false;
        });
        fname = element;
        return fname;
    });
}

$(function () {
    bs_input_file();
});

$('input[type=radio]').on('change', function () {

    if (!this.checked) return

    $('.collapse').not($('div.' + $(this).attr('class'))).slideUp();
    $('.collapse.' + $(this).attr('class')).slideDown();

    $('#single-title-input').css('border', '1px solid #ccc');
});

$('.btn-add-single-title').on("click", function (event) {
    event.preventDefault();
    var stl = $.trim($("#single-title-input").val());
    if (stl) {
        var nameToAdd = $.trim($('#single-title-input').val());
        var dimensions = $.trim($('#single-title-input-dimensions').val());
        var size = $.trim($('#single-title-input-size').val());
        size = size * 1073741824;
        addMovie(nameToAdd, dimensions, size);
    } else {
        $('#single-title-input').css('border', '1px solid red');
    }
});

$('.btn-start-processing-file').on("click", function (event) {
    event.preventDefault();
    console.log('btn-start-processing-file click handler');
    var ifl = $.trim($("#input-file-input").val());

    if (ifl) {
        var inputFileName = $('#input-file-input').val();
        console.log("inputFileName: ", inputFileName);
        getListOfNames(inputFileName);

    } else {
        $('#input-title-input').css('border', '1px solid red');
    }

    function getListOfNames(inputFileName) {

        return $.ajax({
            async: true,
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

$('.btn-add-title').on("click", function (event) {
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

$('#input-directory').val("/Volumes/Recorded 1/test/");

$('.btn-start-processing-dir').on("click", function (event) {
    event.preventDefault();

    dirName = $('#input-directory').val();
    console.log('dirName: ', dirName);

    // processFileNames(fullNames);
    // var theFiles = event.target.files;
    // var fullNames = [];
    //
    // for (i = 0; i < theFiles.length; i++) {
    //     fullNames[i] = theFiles[i].name;
    //     //console.log('theFiles[i]: ', theFiles[i]);
    // }
    processFileNames(dirName);
});

// function processFileNames(fullNames) {
//     var cleanedNames = [];
//     var cleanedNamesDeBlanked = [];
//     var newStr;
//     var pattern1 = '\..*';
//     var p1 = new RegExp(pattern1, "i");
//
//     for (i = 0; i < fullNames.length; i++) {
//
//         newStr = fullNames[i];
//
//         newStr = newStr.substring(0, newStr.lastIndexOf(' -'));
//
//         newStr = newStr.substring(0, newStr.lastIndexOf('.'));
//
//         newStr = newStr.replace(/ - Scene.*/i, '');
//
//         newStr = newStr.replace(/ - CD.*/i, '');
//
//         cleanedNames[i] = newStr;
//     }
//
//     cleanedNamesDeBlanked = _.compact(cleanedNames);
//     cleanedNamesDeDuped = _.uniq(cleanedNamesDeBlanked);
//     console.log(cleanedNamesDeDuped.sort());
//
//     openModal(cleanedNamesDeDuped.sort());
// }

function openModal(cleanedNamesDeDuped) {
    numTitlesFromDirectory = cleanedNamesDeDuped.length;

    for (i = 0; i < cleanedNamesDeDuped.length; i++) {
        $('#directoryAddModal .modal-body #from-directory')
            .append('<div class="input-group input-text"><input type="text" class="form-control filename-text" id="input-dir-record-name" value="' + cleanedNamesDeDuped[i] + '"/>' +
                '<span class="input-group-btn"><button class="btn btn-primary btn-add-title-modal" type="button">Add to database</button>' +
                '<span class="input-group-btn"><button class="btn btn-danger btn-remove-title-modal" type="button">Delete</button></span></div>');
    }

    $('#directoryAddModal').modal('show');
};

function incrementLines() {

    currentLineIndex = currentLineIndex + 1;
    $('#import-records .record-name').val(lines[currentLineIndex]);
}

$('#duplicates').on("click", ".btn-copy-title", function (event) {

    clipboard.writeText($('.duplicate-text').val());
    $(this).closest('.input-group').remove();
});

$('#from-directory').on("click", ".btn-copy-title", function (event) {

    clipboard.writeText($('.duplicate-text').val());
    $(this).closest('.input-group').remove();
    numTitlesFromDirectory--;

    if (numTitlesFromDirectory == 0) {
        $('#directoryAddModal').modal('hide');
    }
});

$('.ui-grid-cell').on("click", ".btn-copy-title", function (event) {

    clipboard.writeText($(this).closest('.ui-grid-coluiGrid-0005 .ui-grid-cell-contents').val());
});

$(document).on("click", '.btn-add-title-modal', function (event) {
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

$(document).on("click", '.btn-remove-title-modal', function (event) {

    $(this).closest('.input-group').remove();
});

$(document).on("click", '.btn-add-all-modal', function (event) {
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
        async: true,
        type: "POST",
        url: "addMovie.php",
        dataType: "json",
        data: { title: nameToAdd, dimensions: dimensions, filesize: size },
    }).always(function (data) {
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

$('#duplicates').on("click", ".btn-find-file", function (event) {
    var fileName = $('.duplicate-text').val();
    findFile(fileName);
});

function findFile(fileName) {
    console.log('in findFile');
    console.log('fileName: ', fileName);

    // var id = fileName;

    function findTheFile() {

        return $.ajax({
            async: true,
            type: "POST",
            url: "findFile.php",
            dataType: "json",
            data: { fileName: fileName },
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
            async: true,
            type: "POST",
            url: "deleteFile.php",
            dataType: "json",
            data: { id: id },
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
