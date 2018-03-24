app.controller('HeaderCtrl', function ($scope, $location, $http) {
    $scope.isActive = function (viewLocation) {
        return viewLocation === $location.path();
    };
});

app.controller('Home', ['$scope', function ($scope) {
    $scope.home = "Home";
}])
app.controller('UtilitiesCtrl', ['$scope', function ($scope) {
    $scope.config = "Utilities";
    console.log("UtilitiesCtrl");
    // getConfigFileState();
}])

app.controller('ConfigCtrl', ['$scope', function ($scope) {
    $scope.config = "Config";
    // console.log("ConfigCtrl");
    getConfigFileState();
}])

app.controller('MoviesCtrl', ['$scope', '$http', '$timeout', 'uiGridConstants', '$q', '$interval', '$httpParamSerializer',
        function ($scope, $http, $timeout, uiGridConstants, $q, $interval, $httpParamSerializer) {

            $scope.movies = "Movies";
            $scope.currentMovie = "";
            $scope.myData = [];
            $scope.myTitles = [];
            $scope.formData = {};

            function getAllMovies() {

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
                        width: 65,
                        enableCellEdit: false,
                        cellClass: 'cell-id'
                    },
                    {
                        name: 'Title',
                        field: 'title',
                        width: 375,
                        enableCellEdit: true,
                        cellClass: 'cell-title'
                    },
                    {
                        name: 'Dimensions',
                        field: 'dimensions',
                        width: 110,
                        // enableFiltering: false,
                        enableCellEdit: true,
                        cellClass: 'cell-dimensions'
                    },
                    {
                        name: 'Size',
                        field: 'filesize',
                        width: 90,
                        enableCellEdit: true,
                        cellClass: 'cell-size',
                        cellFilter: 'sizeFilter'
                    },
                    {
                        name: 'Added',
                        field: 'date_created',
                        width: 95,
                        enableCellEdit: false,
                        type: 'date',
                        cellClass: 'cell-date-created'
                    },
                    {
                        name: 'Controls',
                        width: 190,
                        enableFiltering: false,
                        cellTemplate: 'partials/cell-controls-template.html',
                        enableCellEdit: false,
                        cellClass: 'cell-controls'
                    }
                ];

                $scope.msg = {};

                $scope.gridOptions.onRegisterApi = function (gridApi) {
                    $scope.gridApi = gridApi;
                    gridApi.edit.on.afterCellEdit($scope, function (rowEntity, colDef, newValue) {

                        updateRecord(rowEntity.id, colDef.name, newValue);
                        $scope.gridApi.core.refresh();
                        $scope.$apply();
                    });
                };

                $scope.callsPending = 0;
                // $scope.refreshData = function () {
                //
                //     $scope.gridApi.core.refresh();
                //     getAllMovies();
                // };

                $.ajax({
                    url: "getAllMovies.php",
                    type: 'GET',
                    dataType: "json",
                    success: function (response) {

                        $scope.gridOptions = {};
                        $scope.gridOptions.data = [];
                        $scope.gridOptions.data.length = 0;
                        $timeout(function () {
                            $scope.gridOptions.data = response.myData;
                        });
                    }
                });
            };

            $scope.deleteButtonClickHandler = {
                onClick: function (value) {
                    deleteRow(value);
                    angular.element($('#movie-controller')).scope().refreshData();
                }
            };

            $scope.numTitlesAdded = 0;
            $scope.updateNumTitlesAdded = function () {
                console.log("$scope.numTitlesAdded: ", $scope.numTitlesAdded);

                $scope.numTitlesAdded++;
            };

            $scope.refreshData = function () {
                $scope.gridOptions = {};
                $scope.gridOptions.data = [];
                $scope.gridOptions.data.length = 0;
                $scope.gridApi.core.refresh();
                getAllMovies();
            };

            $scope.init = function () {
                getAllMovies();
            };
        }
    ])

    .filter('sizeFilter', function () {
        return function (value) {
            return formatSize(value);
        };
    })
