$('#directory-results').on("click", ".btn-delete", function (event) {
    var path = $(this).closest('tr').children('td:first-of-type').text();
    var fileName = $(this).closest('tr').children('td:nth-of-type(3)').text();
    var fileNameAndPath = path + "/" + fileName;
    deleteFile(fileNameAndPath);
    $(this).closest('tr').remove();
});
function deleteFile(fileNameAndPath) {
    function deleteIt() {
        return $.ajax({
            type: "POST",
            url: "php/deleteFile.php",
            dataType: "json",
            data: {
                fileNameAndPath: fileNameAndPath
            },
            success: handleResponse
        });
    }
    function handleResponse(data) {
        // console.log("response: ", data);
        return;
    }
    deleteIt();
}
