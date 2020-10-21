var fname = "";

function bs_input_file() {
    $(".input-file").before(function() {
        var element = $(
            "<input type='file' style='visibility:hidden; height:0' class='fname-input'>"
        );
        element.attr("name", $(this).attr("name"));
        element.change(function() {
            element
                .next(element)
                .find("input")
                .val(element.val().split("\\").pop());
        });
        $(this)
            .find("button.btn-choose-file")
            .click(function() {
                $(".btn-start-processing-file").removeAttr("disabled");
                element.click();
            });

        $(this).find("input").css("cursor", "pointer");
        $(this)
            .find("input")
            .mousedown(function() {
                $(".btn-start").removeAttr("disabled");
                $(this).parents(".input-file").prev().click();
                return false;
            });
        fname = element;
        return fname;
    });
}

$(function() {
    bs_input_file();
});