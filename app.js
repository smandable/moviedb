var app = angular.module('app', ['ngRoute', 'ui.bootstrap', 'ui.grid', 'ui.grid.edit']);

app.config(function ($routeProvider) {
    $routeProvider
        .when('/', {
            // title: 'Movies',
            templateUrl: 'partials/movies.html',
            controller: 'MoviesCtrl'
        })
        .when('/movies', {
            templateUrl: 'partials/movies.html',
            controller: 'MoviesCtrl'
        })
        .when('/utilities', {
            templateUrl: 'partials/utilities.html',
            controller: 'UtilitiesCtrl'
        })
        .when('/config', {
            templateUrl: 'partials/config.html',
            controller: 'ConfigCtrl'
        });
});
//Prior angular-route behaviour
app.config(['$locationProvider', function ($locationProvider) {
    $locationProvider.hashPrefix('');
}]);
