$("input[type=radio]").one("change", function() {
    $(".collapse")
        .not($("div." + $(this).attr("class")))
        .slideUp();
    $(".collapse." + $(this).attr("class")).slideDown();
    $("#single-title-input").css("border", "1px solid #ccc");
});

$(".btn-add-single-title").one("click", function(event) {
    event.preventDefault();
    var stl = $.trim($("#single-title-input").val());
    if (stl) {
        var nameToAdd = $.trim($("#single-title-input").val());
        var dimensions = $.trim($("#single-title-input-dimensions").val());
        var size = $.trim($("#single-title-input-size").val());
        size = size.replace(new RegExp(",", "g"), "");
        size = parseInt(size, 10);
        addMovie(nameToAdd, dimensions, size);
    } else {
        $("#single-title-input").css("border", "1px solid red");
    }
});

$(".btn-process-dir-database-ops").one("click", function(event) {
    event.preventDefault();
    $("#progressbar").css("display", "block");

    document.getElementById('loadarea').src = 'php/updateSessionWithDimensionAndDuration.php';

    interval = numFiles * 100; // 500?

    setTimeout(function run() {
        processFilesForDB(directory);
    }, interval);

});

// document.getElementById('loadarea').src = 'php/updateSessionWithDimensionAndDuration.php';

$(document).ready(function() {
    //console.log("process completed length: ", $('#information div:contains("Process completed")').length);

    // if ($('#information div:contains("Process completed")').length > 0) {
    //     //processFilesForDB(directory);
    //     console.log("process completed");
    // }
});


// function updateSessionWithDimensionAndDuration() {
//     //  $("#progressbar").css("display", "block");
//     $.ajax({
//         type: "POST",
//         url: "php/updateSessionWithDimensionAndDuration.php",
//         dataType: "json",
//         async :false,
//     }).done(function () {
//         document.getElementById('loadarea').src = 'php/updateSessionWithDimensionAndDuration.php';
//       processFilesForDB(directory);
//     });
// }