$('.ui-grid-filter-button').on("click", function (event) {
    event.preventDefault();
    console.log("clicked");
    $('#recent-terms').prepend('<li class="recent-term">' + $('.ui-grid-filter-input-0').val() + '</li>');
    angular.element($('#movie-controller')).scope().refreshData();
});

$('.recent-terms-hdr').on("click", function (event) {
    event.preventDefault();
    console.log("clicked");
    $('.ui-grid-filter-input-0').val(this);
});

$('.ui-grid-filter-container').on("change", function (event) {
    event.preventDefault();
    console.log("clicked");
});
