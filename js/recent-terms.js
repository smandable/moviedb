$(".ui-grid-filter-button").one("click", function(event) {
    event.preventDefault();
    $("#recent-terms").prepend(
        '<li class="recent-term">' +
        $(".ui-grid-filter-input-0").val() +
        "</li>"
    );
});

$(".recent-terms-hdr").one("click", function(event) {
    event.preventDefault();
    $(".ui-grid-filter-input-0").val(this);
});