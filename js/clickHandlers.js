$("input[type=radio]").one("change", function () {
    $(".collapse")
        .not($("div." + $(this).attr("class")))
        .slideUp();
    $(".collapse." + $(this).attr("class")).slideDown();
    $("#single-title-input").css("border", "1px solid #ccc");
});

$(".btn-add-single-title").one("click", function (event) {
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

$(".btn-process-dir-database-ops").one("click", function (event) {
    event.preventDefault();
    $("#progressbar").css("display", "block");

    document.getElementById("loadarea").src =
        "php/updateSessionWithDimensionAndDuration.php";

    interval = numFiles * 300; // 500?

    setTimeout(function run() {
        processFilesForDB(directory);
    }, interval);
});
