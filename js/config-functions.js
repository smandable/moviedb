function getConfigFileState() {
	$.ajax({
		async: true,
		type: "GET",
		url: "php/readConfigFile.php",
		dataType: "json"
	}).always(function(data) {
		handleConfigFileState(data);
	})

	function handleConfigFileState(data) {
		if (data.currentDB == "movieLibraryPROD") {
			$('#btn-test-db').removeClass('btn-active');
			$('#btn-prod-db').addClass('btn-active');
		} else if (data.currentDB == "movieLibraryTEST") {
			$('#btn-prod-db').removeClass('btn-active');
			$('#btn-test-db').addClass('btn-active');
		}
		if (data.currentTable == "movies_het") {
			$('#btn-bi').removeClass('btn-active');
			$('#btn-gay').removeClass('btn-active');
			$('#btn-ts').removeClass('btn-active');
			$('#btn-misc').removeClass('btn-active');
			$('#btn-het').addClass('btn-active');
		} else if (data.currentTable == "movies_bi") {
			$('#btn-het').removeClass('btn-active');
			$('#btn-gay').removeClass('btn-active');
			$('#btn-ts').removeClass('btn-active');
			$('#btn-misc').removeClass('btn-active');
			$('#btn-bi').addClass('btn-active');
		} else if (data.currentTable == "movies_gay") {
			$('#btn-het').removeClass('btn-active');
			$('#btn-bi').removeClass('btn-active');
			$('#btn-ts').removeClass('btn-active');
			$('#btn-misc').removeClass('btn-active');
			$('#btn-gay').addClass('btn-active');
		} else if (data.currentTable == "movies_ts") {
			$('#btn-het').removeClass('btn-active');
			$('#btn-bi').removeClass('btn-active');
			$('#btn-gay').removeClass('btn-active');
			$('#btn-misc').removeClass('btn-active');
			$('#btn-ts').addClass('btn-active');
		} else if (data.currentTable == "movies_misc") {
			$('#btn-het').removeClass('btn-active');
			$('#btn-bi').removeClass('btn-active');
			$('#btn-gay').removeClass('btn-active');
			$('#btn-ts').removeClass('btn-active');
			$('#btn-misc').addClass('btn-active');
		}
		return;
	}
}

getConfigFileState();

function updateConfigFile(configUpdate) {
	return $.ajax({
		async: true,
		type: "POST",
		url: "php/updateConfigFile.php",
		dataType: "json",
		data: "configUpdate=" + configUpdate
	}).always(function(data) {
		handleConfigFileUpdate(data);
	})

	function handleConfigFileUpdate(data) {
		getConfigFileState();
		return;
	}
}

$('#db-setting .btn-wrapper').click(function(e) {
	//e.preventDefault();
	var btnClicked = $(this).find('.btn').attr('id');
	//console.log("btnClicked: ", btnClicked);
	if (btnClicked == 'btn-prod-db') {
		var configUpdate = "prodDB";
		updateConfigFile(configUpdate);
	} else if (btnClicked == 'btn-test-db') {
		var configUpdate = "testDB";
		updateConfigFile(configUpdate);
	}
	//$('#output').append($('<div>').html('clicked ' + name));
})

$('#tbl-setting .btn-wrapper').click(function(e) {
	//e.preventDefault();
	var btnClicked = $(this).find('.btn').attr('id');
	//console.log("btnClicked: ", btnClicked);
	if (btnClicked == 'btn-het') {
		var configUpdate = "movies_het";
		updateConfigFile(configUpdate);
		//console.log("configUpdate: ", configUpdate);
	} else if (btnClicked == 'btn-bi') {
		var configUpdate = "movies_bi";
		updateConfigFile(configUpdate);
		//console.log("configUpdate: ", configUpdate);
	} else if (btnClicked == 'btn-gay') {
		var configUpdate = "movies_gay";
		updateConfigFile(configUpdate);
		//console.log("configUpdate: ", configUpdate);
	} else if (btnClicked == 'btn-ts') {
		var configUpdate = "movies_ts";
		updateConfigFile(configUpdate);
		//console.log("configUpdate: ", configUpdate);
	} else if (btnClicked == 'btn-misc') {
		var configUpdate = "movies_misc";
		updateConfigFile(configUpdate);
		//console.log("configUpdate: ", configUpdate);
	}
})
