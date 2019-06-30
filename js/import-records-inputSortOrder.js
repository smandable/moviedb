var fname = "";
var lines = [];
linesLength = 0;
var currentLine = 0;
currentLineIndex = 0;
numDupes = 0;

function deleteRow(value) {
	var id = value;

	function deleteIt() {

		return $.ajax({
			async: true,
			type: "POST",
			url: "php/deleteRow.php",
			dataType: "json",
			data: {
				id: id
			},
			success: handleResponse
		})
	}

	function handleResponse(data) {
		angular.element(document.getElementById('MoviesCtrl')).scope().refreshData();
		return;
	}
	deleteIt();
}
$('#refreshButton').on("click", function(event) {
	event.preventDefault();

	angular.element($('#movie-controller')).scope().refreshData();
});

function bs_input_file() {

	$(".input-file").before(function() {
		var element = $("<input type='file' style='visibility:hidden; height:0' class='fname-input'>");
		element.attr("name", $(this).attr("name"));
		element.change(function() {
			element.next(element).find('input').val((element.val()).split('\\').pop());
		});
		$(this).find("button.btn-choose-file").click(function() {
			$('.btn-start').removeAttr("disabled");
			element.click();
		});
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

$('input[type=radio]').on('change', function() {

	if (!this.checked) return

	$('.collapse').not($('div.' + $(this).attr('class'))).slideUp();
	$('.collapse.' + $(this).attr('class')).slideDown();
});

$('.btn-start-processing-file').on("click", function(event) {
	event.preventDefault();

	if ($('#import-records.import-file input').val() == "") {
		console.log("Need to choose a file");
	} else {
		var textFileName = $('.input-file-textfield').val();
		console.log("textFileName: ", textFileName);
	}

	function getListOfNames() {

		return $.ajax({
			async: true,
			type: "POST",
			url: "php/processNamesFixedFile.php",
			dataType: "json",
			data: "fname=" + textFileName,
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
		return lines;
	}

	getListOfNames();
});

$('.btn-add-single-title').on("click", function(event) {
	event.preventDefault();

	if ($('.single-title').val()) {
		var nameToAdd = $.trim($('#title').val());
		addMovie(nameToAdd);
	}
});

$('.btn-add-title').on("click", function(event) {
	event.preventDefault();
	console.log('btn-add-title');
	for (i = 0; i < lines.length; i++) {
		if (!lines.length - 1) {
			var nameToAdd = $.trim($('#import-records .record-name').val());
			addMovie(nameToAdd);
			incrementLines();
		}
	}
});

// $('.multi-from-file-nc .btn-add-title').on("click", function (event) {
//     event.preventDefault();
//     console.log('btn-start-nc');
//     for (i = 0; i < lines.length; i++) {
//         if (!lines.length - 1) {
//             var nameToAdd = $.trim($('#import-records .record-name').val());
//             addMovie(nameToAdd);
//             incrementLines();
//         }
//     }
// });

function addMovie(nameToAdd) {
	return $.ajax({
		async: true,
		type: "POST",
		url: "addMovie.php",
		dataType: "json",
		// data: "title=" + nameToAdd,
		data: {
			title: nameToAdd
		},
		success: handleResult
	});
}

function handleResult(data) {

	if ($.trim(data)) {
		$('#duplicates').prepend('<div class="input-group input-text"><input type="text" class="form-control duplicate-text" value="' + data + '" disabled/><span class="input-group-btn"><button class="btn btn-danger btn-copy-title" type="button">Copy to clipboard</button></span></div>');
		numDupes++;
		$('.num-dupes span').val(numDupes);
	}
	var sortOrder = "IDDESC";
	angular.element($('#movie-controller')).scope().refreshData(sortOrder);
}

function incrementLines() {

	currentLineIndex = currentLineIndex + 1;
	$('#import-records .record-name').val(lines[currentLineIndex]);
}
$('#duplicates').on("click", ".btn-copy-title", function(event) {

	clipboard.writeText($('.duplicate-text').val());
	$(this).closest('.input-group').remove();
});
