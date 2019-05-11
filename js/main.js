var lines = [];
var dirLines = [];
var linesLength = 0;
var currentLine = 0;
var currentLineIndex = 0;
var numDupes = 0;
var cleanedNamesDeDuped = [];
var numTitlesFromDirectory = 0;
var copyResultRowValues = [];

var dbOpsButton = $('.btn-process-dir-database-ops');
var transitionEnd2 = 'webkitTransitionEnd msTransitionEnd transitionend';

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

	return $.ajax({
		type: "POST",
		url: "deleteRow.php",
		dataType: "json",
		data: {
			id: id
		},
		success: handleResponse
	})

	function handleResponse(data) {
		angular.element($('#movie-controller')).scope().refreshData();
		return;
	}
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

$(document).ready(function() {
	$('#movie-controller').on("click", ".cell-size .ui-grid-cell-contents", function(event) {
		var size = $(this).val();
		size = size.replace(new RegExp(",", "g"), "");
		size = parseFloat(size);
		$(this).val(size);
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

$(document).ready(function() {
	//default
	//$('#input-directory').val("/Users/sean/Download/names fixed/");
	//$('#input-directory').val("/Volumes/Misc 1/to move/");
	$('#input-directory').val("/Users/sean/Download/test/");
	//$('#input-directory').val("/Volumes/Recorded 1/test/");
	//$('#input-directory').val("/Volumes/Bi-Gay-TS/Recorded overflow/");

	dirName = $('#input-directory').val();
});

$('.btn-start-processing-dir').on("click", function(event) {
	event.preventDefault();
	console.log("dirName in click handler: " + dirName + "\n");
	//processFilesForDB(dirName);
	processFiles(dirName);

});

$('.btn-process-dir-database-ops').on("click", function(event) {
	event.preventDefault();
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

var dbOpsBtnWrapper = $('.db-ops-btn-wrapper');
var transitionEnd = 'webkitTransitionEnd msTransitionEnd transitionend';

function processFiles(dirName) {
	$("#loading-spinner").css('display', 'inline-flex');
	$.ajax({
		type: "POST",
		url: "normalize.php",
		dataType: "json",
		data: {
			dirName: dirName
		},
	}).always(function(response) {
		handleProcessFilesResult(response);
		$("#loading-spinner").css('display', 'none');
	})
}

function handleProcessFilesResult(response) {
	//console.log("response: ", response);
	var totalCount = response.length;

	$("#file-data tbody ~ tbody").empty();
	// var tbody = $('#file-results table').children('tbody:first');
	var tbody = $('#file-results table').children('tbody:nth-of-type(2)');
	var table = tbody.length ? tbody : $('#file-results table');

	// var row = '<tr><td>{{path}}</td><td>{{originalFileName}}</td><td class="{{conflictsClass}} {{fileAlreadyExistsClass}}"><a>{{fileNameDisplayed}}</a></span></td><td>{{size}}</td><td>{{dimensions}}</td><td>{{durationNoMS}}</td></tr>';

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
			var fileAlreadyExistsClass = 'file-does-not-exist';
		}
		if (fileRenameConflict == true) {
			var conflictsClass = 'file-has-conflicts';
			//fileNameDisplayed = originalFileName;
			fileNameDisplayed = newFileName;
		} else if (fileRenameConflict == false) {
			var conflictsClass = 'file-has-no-conflicts';
			fileNameDisplayed = newFileName;
		}

		var row = '<tr><td>' + path + '</td><td>' + originalFileName + '</td><td class="' + conflictsClass + fileAlreadyExistsClass + '"><a>' + fileNameDisplayed + '</a></span></td><td>' + size + '</td><td>' + dimensions + '</td><td>' + durationNoMS + '</td></tr>';
		// $('div.modal-body').append(row);
		$(tbody).append(row);
		// table.append(row.compose({
		// 	'path': path,
		// 	'originalFileName': originalFileName,
		// 	'conflictsClass': conflictsClass,
		// 	'fileAlreadyExistsClass': fileAlreadyExistsClass,
		// 	'fileNameDisplayed': fileNameDisplayed,
		// 	'size': size,
		// 	'dimensions': dimensions,
		// 	'durationNoMS': durationNoMS,
		// }));
	}
	if (!$('#myModal').hasClass('in')) {
		$('#normalizeModal').modal('show');
	}
}

$('#normalizeModal').on('hidden.bs.modal', function() {
	//renameTheFiles();
	dbOpsBtnWrapper.addClass('inline-flex').outerWidth();
	dbOpsBtnWrapper.addClass('fade-in').one(transitionEnd, function() {

	});
})

$('#modal-rename-files-btn').on("click", function() {
	renameTheFiles();
});

function renameTheFiles() {
	$.ajax({
		type: "POST",
		url: "renameFiles.php",
		dataType: "json",
	}).always(function(response) {
		handleRenameTheFilesResult(response);
	})
}

function handleRenameTheFilesResult(response) {
	//processFiles(dirName);
	console.log("response: ", response);
}
$(document).ready(function() {
	$.fn.editable.defaults.mode = 'inline';

	$("#file-results table").on("click", "a", function(e) {
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
			url: "renameSingleFile.php",
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

$('#file-results table').on("click", "th", function(event) {

	var table = $(this).parents('table').eq(0)
	var rows = table.find('tr:gt(0)').toArray().sort(comparer($(this).index()))
	this.asc = !this.asc
	if (!this.asc) {
		rows = rows.reverse()
	}
	for (var i = 0; i < rows.length; i++) {
		table.append(rows[i])
	}
})

function comparer(index) {
	return function(a, b) {
		var valA = getCellValue(a, index),
			valB = getCellValue(b, index)
		return $.isNumeric(valA) && $.isNumeric(valB) ? valA - valB : valA.toString().localeCompare(valB)
	}
}

function getCellValue(row, index) {
	return $(row).children('td').eq(index).text()
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
