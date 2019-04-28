var app = angular.module('app', ['ngRoute', 'ui.grid', 'ui.grid.edit']);

app.config(function($routeProvider) {
	$routeProvider
		.when('/', {
			// title: 'Movies',
			templateUrl: 'partials/movies.html'
		})
		.when('/movies', {
			templateUrl: 'partials/movies.html'
		})
		.when('/normalize', {
			templateUrl: 'partials/normalize.html',
			controller: 'NormalizeCtrl'
		})
		.when('/utilities', {
			templateUrl: 'partials/utilities.php',
			controller: 'UtilitiesCtrl'
		})
		.when('/config', {
			templateUrl: 'partials/config.html',
			controller: 'ConfigCtrl'
		});

});
//Prior angular-route behaviour
app.config(['$locationProvider', function($locationProvider) {
	$locationProvider.hashPrefix('');
}]);
