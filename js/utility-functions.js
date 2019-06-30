var dirName;
var options = [];
var directories = [];
var startingFile = 0;
var endingFile;

getOptionsAndPathsFromFile();

$(document).ready(function() {

	var numPaths = $('#utils-paths tbody tr').length;
	$('#utils-paths tbody tr').each(function() {
		directories.push($(this).find("td:first-of-type").html());
	});


	$('#btn-utils').on("click", function(event) {
		event.preventDefault();

		$('#utils-paths tbody tr').each(function() {
			if ($(this).find('[type="checkbox"]').prop("checked")) {
				var dirToProcess = ($(this).find("td:first-of-type").html());
				console.log("dirToProcess: ", dirToProcess);
				normalizeDB(options, dirToProcess);
			}

		})

	});

});

$('#chkbox-options input[type=checkbox]').on("change", function(event) {
	event.preventDefault();
	var key = $(this).attr('value');
	if ($(this).prop("checked")) {
		var state = "true;"
	} else {
		var state = "false;"
	}
	setOptionsAndPathsFile(key, state);
	return;
});

function normalizeDB(options, dirToProcess) {
	// Show loading spinner.
	$("#loading-spinner").css('display', 'inline-block');
	for (i = 0; i < options.length; i++) {
		console.log("options: ", options[i]);
	}
	$.ajax({
		type: "POST",
		url: "php/normalizeDB.php",
		dataType: "json",
		data: {
			options: options,
			dirToProcess: dirToProcess
		},
	}).always(function(response) {
		handleNormalizeDBResult(response);
		$("#loading-spinner").css('display', 'none');
	})
}

function handleNormalizeDBResult(response) {
	//startingFile += 200;
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
	})
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
	})
}

function handleGetOptionsAndPathsFromFileResult(response) {

	console.log(response);

	for (i = 0; i < response.pathsToProcess.length; i++) {
		var pathsToProcess = '<tr><td>' + response.pathsToProcess[i] + '</td><td>' + countFiles(response.pathsToProcess[i]) + '</td><td><input class="" type="checkbox" value="" checked></td>/tr>';
		$("#utilities table tbody").append(pathsToProcess);
	}
	options.push(response.moveDuplicates, response.updateDimensionsInDB, response.updateDurationInDB, response.updatePathInDB, response.updateSizeInDB);
	for (i = 0; i < options.length; i++) {
		console.log('options: ' + i + ' ' + options[i]);
	}
}

function countFiles(dirName) {
	var count = 0;

	$.ajax({
		type: "POST",
		url: "php/countFiles.php",
		dataType: "json",
		data: {
			dirName: dirName
		},
		async: false,
		success: function(data) {
			count = data;
		}
	});
	return count;
}
