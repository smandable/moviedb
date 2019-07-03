var fname = "";
// var lines = [];
// var dirLines = [];
// var linesLength = 0;
// var currentLine = 0;
// var currentLineIndex = 0;
// var numDupes = 0;
// var cleanedNamesDeDuped = [];
// var numTitlesFromDirectory = 0;

// function deleteRow(value) {
//     var id = value;
//
//     function deleteIt() {
//
//         return $.ajax({
//             type: "POST",
//             url: "php/deleteRow.php",
//             dataType: "json",
//             data: { id: id },
//             success: handleResponse
//         })
//     }
//
//     function handleResponse(data) {
//         // angular.element(document.getElementById('MoviesCtrl')).scope().refreshData();
//         angular.element($('#movie-controller')).scope().refreshData();
//         return;
//     }
//     deleteIt();
// }

// $('#refreshButton').on("click", function (event) {
//     event.preventDefault();
//
//     angular.element($('#movie-controller')).scope().refreshData();
// });

function bs_input_file() {

	$(".input-file").before(function() {
		var element = $("<input type='file' style='visibility:hidden; height:0' class='fname-input'>");
		element.attr("name", $(this).attr("name"));
		element.change(function() {
			element.next(element).find('input').val((element.val()).split('\\').pop());
		});
		$(this).find("button.btn-choose-file").click(function() {
			$('.btn-start-processing-file').removeAttr("disabled");
			element.click();
		});
		// $(this).find("button.btn-choose-dir").click(function () {
		//     $('.btn-start-processing-dir').removeAttr("disabled");
		//     element.click();
		// });
		$(this).find('input').css("cursor", "pointer");
		$(this).find('input').mousedown(function() {
			$('.btn-start').removeAttr("disabled");
			$(this).parents('.input-file').prev().click();
			return false;
		});
		fname = element;
		return fname;
	});
}

$(function() {
	bs_input_file();
});

// $('input[type=radio]').on('change', function () {
//
//     if (!this.checked) return
//
//     $('.collapse').not($('div.' + $(this).attr('class'))).slideUp();
//     $('.collapse.' + $(this).attr('class')).slideDown();
//
//     $('#single-title-input').css('border', '1px solid #ccc');
// });
//
// $('.btn-add-single-title').on("click", function (event) {
//     event.preventDefault();
//     var stl = $.trim($("#single-title-input").val());
//     if (stl) {
//         var nameToAdd = $.trim($('#single-title-input').val());
//         var dimensions = $.trim($('#single-title-input-dimensions').val());
//
//         var size = $.trim($('#single-title-input-size').val());
//         size = size.replace(new RegExp(",", "g"), "");
//         size = parseInt(size, 10);
//
//         addMovie(nameToAdd, dimensions, size);
//     } else {
//         $('#single-title-input').css('border', '1px solid red');
//     }
// });
//
// $('.btn-start-processing-file').on("click", function (event) {
//     event.preventDefault();
//     //console.log('btn-start-processing-file click handler');
//     var ifl = $.trim($("#input-file-input").val());
//
//     if (ifl) {
//         var inputFileName = $('#input-file-input').val();
//         //console.log("inputFileName: ", inputFileName);
//         getListOfNames(inputFileName);
//
//     } else {
//         $('#input-title-input').css('border', '1px solid red');
//     }
//
//     function getListOfNames(inputFileName) {
//         return $.ajax({
//             type: "POST",
//             url: "php/processNamesFixedFile.php",
//             dataType: "json",
//             data: "fname=" + inputFileName,
//             success: handleNames
//         })
//     }
//
//     function handleNames(data) {
//         linesLength = data.length;
//         console.log("linesLength in handleNames function: ", linesLength);
//         for (var i = 0; i < data.length; i++) {
//             lines[i] = data[i];
//         }
//         $('#import-records .input-group.input-file').css('display', 'none');
//         $('#import-records .input-group.input-text').css('display', 'block');
//         $('#import-records .record-name').val(lines[0]);
//         return lines.sort();
//     }
// });
//
// $('.btn-add-title').on("click", function (event) {
//     event.preventDefault();
//     var mtl = $.trim($("#input-file-record-name").val());
//     if (mtl) {
//         for (i = 0; i < lines.length; i++) {
//             var nameToAdd = $.trim($('#input-file-record-name').val());
//             addMovie(nameToAdd);
//             incrementLines();
//         }
//     } else {
//         $('#input-file-record-name').css('border', '1px solid red');
//     }
// });
//
// //default
// // $('#input-directory').val("/Users/sean/Download/tmp/names fixed/");
// $('#input-directory').val("/Users/sean/Download/tmp/names fixed/");
//
// $('.btn-start-processing-dir').on("click", function (event) {
//     event.preventDefault();
//
//     directory = $('#input-directory').val();
//     //console.log('directory: ', directory);
//     processFilesForDB(directory);
// });
//
// function processFilesForDB(directory) {
//     //console.log('in processFilesForDB');
//     $.ajax({
//         type: "POST",
//         url: "php/processFilesForDB.php",
//         dataType: "json",
//         data: { directory: directory },
//     }).always(function (response) {
//         handleProcessFilesForDBResult(response);
//     })
// }
//
// function handleProcessFilesForDBResult(response) {
//     // console.log('in handleProcessFilesForDBResult');
//     console.log('response: ', response);
//     //console.log('response.data: ', response.responseText);
//
//     $('#mode').css('display', 'none');
//     $('#directory-results').css('display', 'block');
//
//     totalCount = response.data.length;
//     newMovies = 0;
//     numDuplicates = 0;
//     //console.log('data.length: ', response.data['length']);
//
//     for (i = 0; i < response.data.length; i++) {
//         var name = response.data[i]['Name'];
//         //console.log("data[i]['Name']: ", response.data[i]['Name']);
//         var dimensions = response.data[i]['Dimensions'];
//         var size = response.data[i]['Size'];
//         size = formatSize(size);
//         var isDuplicate = response.data[i]['Duplicate'];
//         var isLarger = response.data[i]['Larger'];
//         var path = response.data[i]['Path'];
//
//         if (response.data[i]['Duplicate'] == false) {
//             ++newMovies;
//
//             var markup = "<tr><td></td><td>" + name + "</td><td>" + dimensions + "</td><td>" + size + "</td><td></td><td></td></tr>";
//         } else if (response.data[i]['Duplicate'] == true) {
//             ++numDuplicates;
//
//             if (response.data[i]['Larger'] == true) {
//                 var markup = "<tr><td></td><td>" + name + "</td><td>" + dimensions + "</td><td>" + size + "</td><td>Larger</td><td>Duplicate</td></tr>";
//             } else {
//                 var markup = "<tr><td></td><td>" + name + "</td><td>" + dimensions + "</td><td>" + size + "</td><td></td><td>Duplicate</td></tr>";
//             }
//         }
//         $("#directory-results table").append(markup);
//     }
//     $("#directory-results #totals").html('<span>Total: <span class="num-span">' + totalCount + '</span></span><span>New: <span class="num-span">' + newMovies + '</span></span><span>Duplicates: <span class="num-span">' + numDuplicates + '</span></span>');
//     angular.element($('#movie-controller')).scope().refreshData();
// }
//
// function openModal(cleanedNamesDeDuped) {
//     numTitlesFromDirectory = cleanedNamesDeDuped.length;
//
//     for (i = 0; i < cleanedNamesDeDuped.length; i++) {
//         $('#directoryAddModal .modal-body #from-directory')
//             .append('<div class="input-group input-text"><input type="text" class="form-control filename-text" id="input-dir-record-name" value="' + cleanedNamesDeDuped[i] + '"/>' +
//                 '<span class="input-group-btn"><button class="btn btn-primary btn-add-title-modal" type="button">Add to database</button>' +
//                 '<span class="input-group-btn"><button class="btn btn-danger btn-remove-title-modal" type="button">Delete</button></span></div>');
//     }
//
//     $('#directoryAddModal').modal('show');
// };
//
// function incrementLines() {
//
//     currentLineIndex = currentLineIndex + 1;
//     $('#import-records .record-name').val(lines[currentLineIndex]);
// }
//
// $('#duplicates').on("click", ".btn-copy-title", function (event) {
//
//     clipboard.writeText($('.duplicate-text').val());
//     $(this).closest('.input-group').remove();
// });
//
// $('#from-directory').on("click", ".btn-copy-title", function (event) {
//
//     clipboard.writeText($('.duplicate-text').val());
//     $(this).closest('.input-group').remove();
//     numTitlesFromDirectory--;
//
//     if (numTitlesFromDirectory == 0) {
//         $('#directoryAddModal').modal('hide');
//     }
// });
//
// $('.ui-grid-cell').on("click", ".btn-copy-title", function (event) {
//
//     clipboard.writeText($(this).closest('.ui-grid-coluiGrid-0005 .ui-grid-cell-contents').val());
// });
//
// $(document).on("click", '.btn-add-title-modal', function (event) {
//     event.preventDefault();
//
//     var motl = $(this).parents('.input-text').children("#input-dir-record-name");
//     var motlVal = $.trim($(motl).val());
//     if (motlVal) {
//         var nameToAdd = motlVal;
//         addMovie(nameToAdd);
//         $(this).closest('.input-group').remove();
//     } else {
//         $('#input-dir-record-name').css('border', '1px solid red');
//     }
//     if (numTitlesFromDirectory == 0) {
//         $('#directoryAddModal').modal('hide');
//     }
// });
//
// $(document).on("click", '.btn-remove-title-modal', function (event) {
//
//     $(this).closest('.input-group').remove();
// });
//
// $(document).on("click", '.btn-add-all-modal', function (event) {
//     // event.preventDefault();
//     while (numTitlesFromDirectory > 0) {
//
//         $(document).click('.btn-add-title-modal');
//         console.log("clicked");
//         // var motl = $(this).parents('.input-text').children("#input-dir-record-name");
//         // var motlVal = $.trim($(motl).val());
//         // if (motlVal) {
//         //     var nameToAdd = motlVal
//         ;
//         //     addMovie(nameToAdd);
//         //     $(this).closest('.input-group').remove();
//         numTitlesFromDirectory--;
//         // } else {
//         //     $('#input-dir-record-name').css('border', '1px solid red');
//         // }
//     }
//     if (numTitlesFromDirectory == 0) {
//         $('#directoryAddModal').modal('hide');
//     }
// });
//
// function addMovie(nameToAdd, dimensions, size) {
//     $.ajax({
//         type: "POST",
//         url: "php/addMovie.php",
//         dataType: "json",
//         data: { title: nameToAdd, dimensions: dimensions, filesize: size },
//     }).always(function (data) {
//         handleResult(data);
//     })
// }
//
// function handleResult(data) {
//     var isDupe = data.responseText;
//     if (~isDupe.indexOf("Duplicate")) {
//         var duplicateTitle = isDupe.split(': ')[1];
//         $('#duplicates').prepend('<div class="input-group input-text"><input type="text" class="form-control duplicate-text" value="' + duplicateTitle + '" disabled/>' +
//             '<span class="input-group-btn"><button class="btn btn-warning btn-copy-title" type="button">Copy to clipboard</button><button class="btn btn-danger btn-find-file" type="button">Find file</button></span></div>');
//         $('#single-title-input').css('border', '1px solid red');
//         numDupes++;
//         $('.num-dupes span').text(numDupes);
//     } else {
//         $('#single-title-input').css('border', '1px solid green');
//     }
//     angular.element($('#movie-controller')).scope().refreshData();
// }
//
// $('#duplicates').on("click", ".btn-find-file", function (event) {
//     var fileName = $('.duplicate-text').val();
//     findFile(fileName);
// });
//
// function findFile(fileName) {
//     console.log('in findFile');
//     console.log('fileName: ', fileName);
//
//     function findTheFile() {
//         return $.ajax({
//             type: "POST",
//             url: "php/findFile.php",
//             dataType: "json",
//             data: { fileName: fileName },
//             success: handleFindResult
//         })
//     }
//
//     function handleFindResult(data) {
//         // angular.element(document.getElementById('MoviesCtrl')).scope().refreshData();
//         angular.element($('#movie-controller')).scope().refreshData();
//         console.log('in handleFindResult');
//
//         return;
//     }
//     findTheFile();
// }
//
// function deleteFile(fileName) {
//     var id = fileName;
//
//     function deleteTheFile() {
//         return $.ajax({
//             type: "POST",
//             url: "php/deleteFile.php",
//             dataType: "json",
//             data: { id: id },
//             success: handleDelete
//         })
//     }
//
//     function handleDelete(data) {
//         // angular.element(document.getElementById('MoviesCtrl')).scope().refreshData();
//         console.log('in handleDelete');
//         angular.element($('#movie-controller')).scope().refreshData();
//         return;
//     }
//     deleteTheFile();
// }
