var dirName;
var directories = [];

$(document).ready(function() {
	var numPaths = $('#utils-paths tr').length - 1;
	//console.log("numPaths: ", numPaths);

	// for (i = 0; i < numPaths; i++) {
	$('#utils-paths tbody tr').each(function() {
		directories.push($(this).find("td:first").html());
		//directories.push($('#utils-paths tbody td:first-of-type').html());
		console.log($('#utils-paths tbody tr td:first-of-type').html());
		console.log("directories: ", directories[i]);
	});

	$('#btn-utils').on("click", function(event) {
		event.preventDefault();

		directories
		normalizeDB(dirName);
	});
});

function normalizeDB(dirName) {
	// Show loading spinner.
	$("#loading-spinner").css('display', 'inline-block');
	$.ajax({
		type: "POST",
		url: "normalizeDB.php",
		dataType: "json",
		data: {
			dirName: dirName
		},
	}).always(function(response) {
		handleNormalizeDBResult(response);
		$("#loading-spinner").css('display', 'none');
	})
}


function handleNormalizeDBResult(response) {

}


getOptionsAndPathsFromFile();

function getOptionsAndPathsFromFile(filePathAndName) {

	$.ajax({
		type: "POST",
		url: "getOptionsAndPathsFromFile.php",
		dataType: "json",
	}).always(function(response) {
		handleResult(response);
	})
}

function handleResult(response) {
	//console.log(response);

	// if response.moveDuplicates == 'true' {
	// 	$('#utilities .chkbx-dont-move-duplicates').prop('checked', true);
	//
	// }

	for (i = 0; i < response.paths.length; i++) {
		//console.log(response.paths[i]);
		var paths = '<tr><td>' + response.paths[i] + '</td><td></td>/tr>';

		$("#utilities table tbody").append(paths);

	}
	// if files not found, add form control to browse or whatever
	// if(){

	//}

}
