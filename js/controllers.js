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
}])

app.controller('ConfigCtrl', ['$scope', function ($scope) {
    $scope.config = "Config";
    //getConfigFileState();
}])

app.controller('MoviesCtrl', ['$scope', '$http', '$timeout', 'uiGridConstants', '$q', '$interval', '$httpParamSerializer',
        function ($scope, $http, $timeout, uiGridConstants, $q, $interval, $httpParamSerializer) {

            $scope.movies = "Movies";

            $scope.myData = [];
            $scope.rowsToDelete = [];
            $scope.sizeOfDeletedTitles = 0;

            $scope.gridOptions = {
                enableColumnResizing: true,
                enableFiltering: true,
                // enableGridMenu: true,
                showGridFooter: true,
                showColumnFooter: false,
                gridFooterTemplate: "<div class=\"ui-grid-footer-info ui-grid-grid-footer\"><span>{{'search.totalItems' | t}} {{grid.rows.length}}</span><span ng-if=\"grid.renderContainers.body.visibleRowCache.length !== grid.rows.length\" class=\"ngLabel\">({{\"search.showingItems\" | t}} {{grid.renderContainers.body.visibleRowCache.length}})</span><span id = \"footer-btns\"><button class=\"btn btn-danger\" ng-click=\"grid.appScope.multipleDeleteButtonClickHandler.onClick()\"><i class=\"fa fa-edit\"></i>Delete checked</button><button class=\"btn btn-warning btn-get-checked-sizes\">Copy total size</button></span></div>",
                excessRows: 20,
                onRegisterApi: function (gridApi) {
                    // console.log('in onRegisterApi');
                    $scope.gridApi = gridApi;
                    $scope.gridApi.edit.on.afterCellEdit($scope, function (rowEntity, colDef, newValue) {
                        updateRecord(rowEntity.id, colDef.name, newValue);
                    });
                },
                columnDefs: [{
                        name: 'Id',
                        field: 'id',
                        width: 65,
                        enableCellEdit: false,
                        cellClass: 'cell-id'
                    },
                    {
                        name: 'Title',
                        field: 'title',
                        // width: 450,
                        width: 450,
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
                        width: 170,
                        enableFiltering: false,
                        cellTemplate: 'partials/cell-controls-template.html',
                        enableCellEdit: false,
                        cellClass: 'cell-controls'
                    }
                ]
            };

            var getData = function () {
                $.ajax({
                    url: "getAllMovies.php",
                    type: 'GET',
                    dataType: "json",
                    success: function (response) {

                        $timeout(function () {
                            $scope.gridOptions.data = response.data;
                        });
                    }
                    // error: function (response) {
                    //     console.log('no data returned');
                    //     console.log('response: ', response);
                    // }
                });
            };
            getData();

            //Results grid
            // $scope.resultsGridOptions = {
            //     enableColumnResizing: true,
            //     enableFiltering: true,
            //     // enableGridMenu: true,
            //     showGridFooter: false,
            //     showColumnFooter: false,
            //     excessRows: 20,
            //     onRegisterApi: function (resultsGridApi) {
            //         // console.log('in onRegisterApi');
            //         $scope.resultsGridApi = resultsGridApi;
            //         $scope.resultsGridApi.edit.on.afterCellEdit($scope, function (rowEntity, colDef, newValue) {
            //             updateRecord(rowEntity.id, colDef.name, newValue);
            //         });
            //     },
            //     columnDefs: [{
            //             name: 'Id',
            //             field: 'id',
            //             width: 65,
            //             enableCellEdit: false,
            //             cellClass: 'cell-id'
            //         },
            //         {
            //             name: 'Title',
            //             field: 'title',
            //             // width: 450,
            //             width: 400,
            //             enableCellEdit: true,
            //             cellClass: 'cell-title'
            //         },
            //         {
            //             name: 'Dimensions',
            //             field: 'dimensions',
            //             width: 110,
            //             // enableFiltering: false,
            //             enableCellEdit: true,
            //             cellClass: 'cell-dimensions'
            //         },
            //         {
            //             name: 'Size',
            //             field: 'filesize',
            //             width: 90,
            //             enableCellEdit: true,
            //             cellClass: 'cell-size',
            //             cellFilter: 'sizeFilter'
            //         },
            //         {
            //             name: 'Duplicate',
            //             field: 'isDupe',
            //             width: 95,
            //             enableCellEdit: false,
            //             cellClass: 'cell-is-duplicate'
            //         },
            //         {
            //             name: 'Larger',
            //             width: 180,
            //             enableFiltering: false,
            //             enableCellEdit: false,
            //             cellClass: 'cell-is-larger'
            //         }
            //     ]
            // };

            // var getResultsData = function () {
            //     $.ajax({
            //         url: "getAllMovies.php",
            //         type: 'GET',
            //         dataType: "json",
            //         success: function (response) {
            //
            //             $timeout(function () {
            //                 $scope.gridOptions.data = response.data;
            //             });
            //         }
            //         // error: function (response) {
            //         //     console.log('no data returned');
            //         //     console.log('response: ', response);
            //         // }
            //     });
            // };
            //END results grid


            $scope.deleteButtonClickHandler = {
                onClick: function (id) {
                    //console.log('deleteButtonClickHandler');
                    deleteRow(id);
                    $scope.refreshData();
                }
            };

            $scope.pasteResultsButtonClickHandler = {
                onClick: function (id) {
                    // console.log('pasteResultsButtonClickHandler');
                    pasteResults(id);
                    $scope.refreshData();
                }
            };

            $scope.rowCheckboxHandler = {
                onClick: function (id, size) {

                    chkbx = $(event.target).closest('.ui-grid-cell-contents').find('.row-select-checkbox');
                    size = parseInt(size, 10);
                    if ($(chkbx).is(':checked')) {
                        $scope.rowsToDelete.push(id);
                        $scope.sizeOfDeletedTitles += size;
                        $('#footer-btns').css('display', 'inline-block');
                        console.log($scope.rowsToDelete);
                        console.log($scope.sizeOfDeletedTitles);
                    } else {
                        $scope.rowsToDelete.pop(id);
                        $scope.sizeOfDeletedTitles -= size;
                        console.log($scope.sizeOfDeletedTitles);
                        console.log($scope.rowsToDelete);
                    }
                    $('.total-size-results').html(formatSize($scope.sizeOfDeletedTitles) + '<br><div class="unformatted">' + $scope.sizeOfDeletedTitles + '</div>');
                    if ($scope.rowsToDelete.length == 0) {
                        $('#footer-btns').css('display', 'none');
                    }
                }
            };

            $('#footer-btns').on("click", ".btn-get-checked-sizes", function (event) {
                console.log('clicked');
                clipboard.writeText($('.unformatted').val());
            });

            $scope.multipleDeleteButtonClickHandler = {
                onClick: function () {
                    for (i = 0; i < $scope.rowsToDelete.length; i++) {
                        console.log('rowsToDelete[i]: ', $scope.rowsToDelete[i]);
                        deleteRow($scope.rowsToDelete[i]);
                    }

                    $scope.rowsToDelete = [];

                    $('#footer-btns').css('display', 'none');
                    $('.row-select-checkbox').prop('checked', false);
                    $scope.refreshData();
                }
            };

            $scope.multipleSizesClickHandler = {
                onClick: function () {
                    clipboard.writeText(sizeOfDeletedTitles);
                    $scope.rowsToDelete = [];

                    $('#footer-btns').css('display', 'none');
                    $('.row-select-checkbox').prop('checked', false);
                    $scope.refreshData();
                }
            };

            $scope.numTitlesAdded = 0;
            $scope.updateNumTitlesAdded = function () {
                console.log("$scope.numTitlesAdded: ", $scope.numTitlesAdded);

                $scope.numTitlesAdded++;
            };

            $scope.refreshData = function () {
                //console.log('refreshData');
                $scope.gridOptions.myData = [];
                getData();
            };

        }
    ])

    .filter('sizeFilter', function () {
        return function (value) {
            if (value != 0) {
                return formatSize(value);
            } else {
                $(this).addClass('invisible-zero');
            }

        };
    })
