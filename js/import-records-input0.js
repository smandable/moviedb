var fname = "";

function bs_input_file()
{
    $(".input-file").before(
        function()
        {
            // if ( ! $(this).prev().hasClass('input-ghost') ) {
            var element = $("<input type='file' style='visibility:hidden; height:0' class='fname-input'>");
            element.attr("name", $(this).attr("name"));
            element.change(function()
            {
                element.next(element).find('input').val((element.val()).split('\\').pop());
            });
            $(this).find("button.btn-choose").click(function()
            {
                element.click();
            });
            $(this).find("button.btn-reset").click(function()
            {
                element.val(null);
                $(this).parents(".input-file").find('input').val('');
            });
            $(this).find('input').css("cursor", "pointer");
            $(this).find('input').mousedown(function()
            {
                $(this).parents('.input-file').prev().click();
                return false;
            });
            // console.log('element: ', element);
            // console.log('name: ', element.attr("name", $(this).attr("name")))
            fname = element;
            return fname;
        }
        // }
    );
}
$(function()
{
    bs_input_file();
});


$('.btn-start').on("click", function(event)
{
    event.preventDefault();

	if($('#import-records input').val() == ''){
			var textFileName = 'testFile.txt';
	} else {
		var textFileName = fname[0].files[0].name;
	}

    $.ajax(
    {
		async: true,
        type: "POST",
        url: "processNamesFixedFile.php",
		dataType: "json",
        data: "fname=" + textFileName,
		success: function(data) {
			console.log("success: ");

            for (var i = 0; i < data.length; i++) {
                var line = data[i];
                console.log("line: ", line);
            }
            $('#import-records .input-group.input-file').css('display','none');
            $('#import-records .input-group.input-text').css('display', 'block');
			$('#import-records .record-name').val(data[0]);
        }

    });
});


$('.add-record-btn').on("click", function(event) {
    event.preventDefault();
	var nameToAdd = $('#import-records input').val();
	console.log("nameToAdd: ", nameToAdd);

});
