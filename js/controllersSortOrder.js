app.controller('HeaderCtrl', function($scope, $location, $http) {
	$scope.isActive = function(viewLocation) {
		return viewLocation === $location.path();
	};
});

app.controller('Home', ['$scope', function($scope) {
	$scope.home = "Home";
}]);

app.controller('MoviesCtrl', ['$scope', '$http', '$timeout', 'uiGridConstants', '$q', '$interval', '$httpParamSerializer',
	function($scope, $http, $timeout, uiGridConstants, $q, $interval, $httpParamSerializer) {

		$scope.movies = "Movies";
		$scope.currentMovie = "";
		$scope.myData = [];
		$scope.myTitles = [];
		$scope.formData = {};
		//$scope.sortOrder = "TitleASC";

		function getAllMovies(sortOrder) {

			$scope.gridOptions = {};
			$scope.gridOptions.data = [];
			$scope.gridOptions.enableColumnResizing = true;
			$scope.gridOptions.enableFiltering = true;
			$scope.gridOptions.enableGridMenu = true;
			$scope.gridOptions.showGridFooter = true;
			$scope.gridOptions.showColumnFooter = true;
			$scope.gridOptions.excessRows = 20;
			$scope.gridOptions.columnDefs = [

				{
					name: 'Id',
					field: 'id',
					width: 100,
					enableCellEdit: false
				},
				{
					name: 'Title',
					field: 'title',
					width: 500,
					enableCellEdit: true
				},
				{
					name: 'Date Added',
					field: 'date_created',
					width: 120,
					enableCellEdit: false,
					type: 'date'
				},
				{
					name: 'Notes',
					field: 'notes',
					width: 300,
					enableFiltering: false,
					enableCellEdit: true
				},
				{
					name: 'Controls',
					width: 190,
					enableFiltering: false,
					cellTemplate: '<div><button ng-click="grid.appScope.deleteButtonClickHandler.onClick(row.entity.id)">Delete row</button></div>',
					enableCellEdit: false
				}
			];

			$scope.msg = {};

			$scope.gridOptions.onRegisterApi = function(gridApi) {
				$scope.gridApi = gridApi;
				gridApi.edit.on.afterCellEdit($scope, function(rowEntity, colDef, newValue) {

					updateRecord(rowEntity.id, colDef.name, newValue);
					$scope.gridApi.core.refresh();
					$scope.$apply();
				});
			};

			$scope.callsPending = 0;
			$scope.refreshData = function(sortOrder) {
				// var sortOrder = "byIDASC";
				$scope.gridApi.core.refresh();
				getAllMovies(sortOrder);
			};

			$.ajax({
				url: "php/getAllMovies.php",
				type: 'POST',
				dataType: "json",
				data: {
					sortOrder: sortOrder
				},
				success: function(response) {

					$scope.gridOptions = {};
					$scope.gridOptions.data = [];
					$scope.gridOptions.data.length = 0;
					$timeout(function() {
						$scope.gridOptions.data = response.myData;
					});
				}
			});
		}

		$scope.deleteButtonClickHandler = {
			onClick: function(value) {
				deleteRow(value);
				angular.element($('#movie-controller')).scope().refreshData();
			}
		};

		$scope.numTitlesAdded = 0;
		$scope.updateNumTitlesAdded = function() {
			console.log("$scope.numTitlesAdded: ", $scope.numTitlesAdded);

			$scope.numTitlesAdded++;
		};

		$scope.init = function() {
			var sortOrder = "TitleASC";
			getAllMovies(sortOrder);
		};
	}
]);
