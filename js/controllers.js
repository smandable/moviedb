app.controller('HeaderCtrl', function($scope, $location, $http) {
	$scope.isActive = function(viewLocation) {
		return viewLocation === $location.path();
	};
});

app.controller('Home', ['$scope', function($scope) {
	$scope.home = "Home";
}])
app.controller('UtilitiesCtrl', ['$scope', function($scope) {
	$scope.config = "Utilities";
}])

app.controller('ConfigCtrl', ['$scope', function($scope) {
	$scope.config = "Config";
}])

app.controller('NormalizeCtrl', ['$scope', '$http', '$timeout', '$q', '$interval', '$httpParamSerializer', '$location',
	function($scope, $http, $timeout, $q, $interval, $httpParamSerializer, $location) {
		$scope.normalize = "Normalize";
	}
])

app.controller('MoviesCtrl', ['$scope', '$http', '$timeout', 'uiGridConstants', '$q', '$interval', '$httpParamSerializer', '$location',
	function($scope, $http, $timeout, uiGridConstants, $q, $interval, $httpParamSerializer, $location) {

		$scope.movies = "Movies";

		$scope.myData = [];
		$scope.rowsToDelete = [];
		$scope.sizeOfDeletedTitles = 0;

		$scope.gridOptions = {
			enableColumnResizing: true,
			enableFiltering: true,
			showGridFooter: true,
			showColumnFooter: false,
			gridFooterTemplate: 'partials/grid-footer-template.html',
			excessRows: 20,
			onRegisterApi: function(gridApi) {
				$scope.gridApi = gridApi;
				$scope.gridApi.edit.on.afterCellEdit($scope, function(rowEntity, colDef, newValue) {
					updateRecord(rowEntity.id, colDef.name, newValue);
				});
			},
			columnDefs: [{
					name: 'ID',
					field: 'id',
					width: 65,
					enableFiltering: false,
					enableCellEdit: false,
					cellClass: 'cell-id'
				},
				{
					name: 'Title',
					field: 'title',
					width: 520,
					enableFiltering: true,
					enableCellEdit: true,
					cellClass: 'cell-title'
				},
				{
					name: 'Dimensions',
					field: 'dimensions',
					width: 110,
					enableFiltering: false,
					enableCellEdit: true,
					cellClass: 'cell-dimensions'
				},
				{
					name: 'Size',
					field: 'filesize',
					width: 80,
					enableFiltering: false,
					enableCellEdit: true,
					cellClass: 'cell-size',
					cellFilter: 'sizeFilter'
				},
				{
					name: 'Duration',
					field: 'duration',
					width: 90,
					enableFiltering: false,
					enableCellEdit: true,
					cellClass: 'cell-size',
					cellFilter: 'durationFilter'
				},
				{
					name: 'Added',
					field: 'date_created',
					width: 90,
					enableFiltering: false,
					enableCellEdit: false,
					type: 'date',
					cellClass: 'cell-date-created'
				},
				{
					name: 'Controls',
					width: 180,
					cellTemplate: 'partials/cell-controls-template.html',
					enableFiltering: false,
					enableCellEdit: false,
					cellClass: 'cell-controls'
				}
			]
		};

		var getData = function() {
			$.ajax({
				url: "php/getAllMovies.php",
				type: 'GET',
				dataType: "json",
				success: function(response) {

					$timeout(function() {
						$scope.gridOptions.data = response.data;
					});
				}
			});
		};
		getData();

		$scope.deleteButtonClickHandler = {
			onClick: function(id) {
				deleteRow(id);
				$scope.refreshData();
			}
		};

		$scope.pasteResultsButtonClickHandler = {
			onClick: function(id) {
				pasteResults(id);
				$scope.refreshData();
			}
		};

		$scope.playButtonClickHandler = {
			onClick: function(path) {
				playMovie(path);
			}
		};

		$scope.rowCheckboxHandler = {
			onClick: function(id, size) {

				chkbx = $(event.target).closest('.ui-grid-cell-contents').find('.row-select-checkbox');
				size = parseInt(size, 10);
				if ($(chkbx).is(':checked')) {
					$scope.rowsToDelete.push(id);
					$scope.sizeOfDeletedTitles += size;
					$('#footer-btns').css('display', 'inline-block');
				} else {
					$scope.rowsToDelete.pop(id);
					$scope.sizeOfDeletedTitles -= size;
				}
				$('.total-size-results').html(formatSize($scope.sizeOfDeletedTitles) + '<br><div class="unformatted">' + $scope.sizeOfDeletedTitles + '</div>');
				if ($scope.rowsToDelete.length == 0) {
					$('#footer-btns').css('display', 'none');
				}
			}
		};


		$('#footer-btns').on("click", ".btn-get-checked-sizes", function(event) {
			//console.log('clicked');
			clipboard.writeText($('.unformatted').val());
		});

		$scope.multipleDeleteButtonClickHandler = {
			onClick: function() {
				for (i = 0; i < $scope.rowsToDelete.length; i++) {
					// console.log('rowsToDelete[i]: ', $scope.rowsToDelete[i]);
					deleteRow($scope.rowsToDelete[i]);
				}

				$scope.rowsToDelete = [];

				$('#footer-btns').css('display', 'none');
				$('.row-select-checkbox').prop('checked', false);
				$scope.refreshData();
			}
		};

		$scope.multipleSizesClickHandler = {
			onClick: function() {
				clipboard.writeText(sizeOfDeletedTitles);
				$scope.rowsToDelete = [];

				$('#footer-btns').css('display', 'none');
				$('.row-select-checkbox').prop('checked', false);
				$scope.refreshData();
			}
		};

		$scope.numTitlesAdded = 0;
		$scope.updateNumTitlesAdded = function() {
			// console.log("$scope.numTitlesAdded: ", $scope.numTitlesAdded);

			$scope.numTitlesAdded++;
		};

		$scope.refreshData = function() {
			$scope.gridOptions.myData = [];
			getData();
		};

	}
])

// app.controller('ModalCtrl', ['$scope', 'createDialog', function($scope, createDialogService) {
//
// 	$scope.launchComplexModal = function() {
// 		createDialogService('../partials/modal.html', {
// 			id: 'normalizeModal',
// 			title: 'A Complex Modal Dialog',
// 			backdrop: true,
// 			controller: 'ComplexModalController',
// 			success: {
// 				label: 'Yay',
// 				fn: function() {
// 					console.log('Successfully closed complex modal');
// 				}
// 			}
// 		}, {
// 			myVal: 15,
// 			assetDetails: {
// 				name: 'My Asset',
// 				description: 'A Very Nice Asset'
// 			}
// 		});
// 	};
// }]);

app.controller('ModeCtrl', ['$scope', '$http', '$timeout', '$q', '$interval', '$httpParamSerializer', '$route', '$routeParams', '$location',
		function($scope, $http, $timeout, $q, $interval, $httpParamSerializer, $route, $routeParams, $location) {
			// $scope.mode = "Mode";
			// $scope.$route = $route;
			// $scope.$location = $location;
			// $scope.$routeParams = $routeParams;

			// $('.btn-start-processing-dir').on("click", function(event) {
			// 	//event.preventDefault();
			//
			// 	dirName = $('#input-directory').val();
			// 	//processFilesForDB(dirName);
			// 	console.log('clicked');
			// 	$location.path('/normalize');
			// });
			// $scope.launchComplexModal = function() {
			// 	createDialogService('partials/modal.html', {
			// 		id: 'normalizeModal',
			// 		title: 'A Complex Modal Dialog',
			// 		backdrop: true,
			// 		controller: 'ComplexModalController',
			// 		success: {
			// 			label: 'Yay',
			// 			fn: function() {
			// 				console.log('Successfully closed complex modal');
			// 			}
			// 		}
			// 	}, {
			// 		myVal: 15,
			// 		assetDetails: {
			// 			name: 'My Asset',
			// 			description: 'A Very Nice Asset'
			// 		}
			// 	});
			// };
		}
	])
	// 	.factory('StupidFactory', function() {
	// 		return {
	// 			stupid: function() {
	// 				console.log('This is stupid');
	// 			}
	// 		};
	// 	})
	// app.controller('ComplexModalController', ['$scope', 'StupidFactory', 'myVal', 'assetDetails',
	// 		function($scope, StupidFactory, myVal, assetDetails) {
	// 			$scope.myVal = myVal;
	// 			$scope.asset = assetDetails;
	// 			StupidFactory.stupid();
	// 		}
	// 	])

	.filter('sizeFilter', function() {
		return function(value) {
			if (value != 0) {
				return formatSize(value);
			} else {
				// $(this).addClass('invisible-zero');
			}
		};
	})
	.filter('durationFilter', function() {
		return function(value) {
			if (value != null) {
				return formatDuration(value);
			}
		};
	})
