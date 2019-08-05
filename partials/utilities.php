<div class="jumbotron">
    <div id="utilities">
        <script type="text/javascript" src="js/utility-functions.js"></script>
        <div class="container">
          <div class="row">
              <div class="col-xs-12">
                <ul class="nav nav-tabs">
                  <li class="nav-item">
                    <a class="nav-link active" id="normalizeLink">Normalize DB</a>
                  </li>
                  <li class="nav-item">
                    <a class="nav-link" id="findDuplicatesLink">Search DB for Duplicates</a>
                  </li>
               </ul>
                  <div id="normalize-db">
                      <div id="chkbox-options">
                        <div class="form-check form-check-inline">
                          <input class="form-check-input" type="checkbox" id="utils-chkbx-move-duplicates" value="moveDuplicates" checked>
                          <label class="form-check-label" for="utils-chkbx-move-duplicates">Move duplicates</label>
                       </div>
                       <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="utils-chkbx-update-size" value="updateSize" checked>
                        <label class="form-check-label" for="utils-chkbx-update-size">Update size in DB</label>
                       </div>
                       <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="utils-chkbx-update-dimensions" value="updateDimensions" checked>
                        <label class="form-check-label" for="utils-chkbx-update-dimensions">Update dimensions in DB</label>
                       </div>
                       <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="utils-chkbx-update-duration" value="updateDuration" checked>
                        <label class="form-check-label" for="utils-chkbx-update-duration">Update duration in DB</label>
                       </div>
                       <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="utils-chkbx-update-path" value="updatePath" checked>
                        <label class="form-check-label" for="utils-chkbx-update-path">Update path in DB</label>
                       </div>
                       <div class="form-check form-check-inline">
                         <input class="form-check-input" type="checkbox" id="utils-chkbx-move-recorded" value="moveRecorded" checked>
                         <label class="form-check-label" for="utils-chkbx-move-recorded">Move recorded</label>
                      </div>
                     </div>
                    <table id="utils-paths" class="table">
                      <thead>
                    <th>Path to process</th><th>Files</th><th>Include</th>
                    </thead>
                    <tbody></tbody>
                    </table>
                      <button type="button" class="btn btn-success btn-sm" id="btn-normalize">Run</button>
                        <img id="loading-spinner" src="img/ajax-loader.gif" alt="Loading spinner" />
                  </div>
                  <div id="find-duplicates">

                    <table id="duplicates-list">
                      <thead>
                        <tr>
                            <th>Id</th>
                            <th>Title</th>
                            <th>Dimensions</th>
                            <th id="size">Size</th>
                            <th>Duration</th>
                            <th>Added</th>
                            <th></th>
                        </tr>
                      </thead>
                        <tbody></tbody>
                    </table>
                      <button type="button" class="btn btn-success btn-sm" id="btn-find-duplicates">Run</button>
                        <img id="loading-spinner" src="img/ajax-loader.gif" alt="Loading spinner" />
                  </div>
              </div>
          </div>
            <!-- <div class="row">
                <div class="col-xs-12">
                    <div ng-controller="UtilitiesCtrl" id="utilities-controller">
                        <div class="col-xs-3">
                            <div id="fix-names">
                                <h2>
                                    Fix Names
                                </h2>
                                <hr/>
                                <div class="input-group input-text" name="">
                                    <input type="text" class="form-control text-pattern" id="text-pattern-to-append"/>
                                    <span class="input-group-btn">
                                        <button class="btn btn-warning btn-start-appending" type="button">Start</button>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-xs-9">
                            <div class="phpinfo-inner">


                            <?php
                            // ob_start();
                            // phpinfo();
                            //
                            // preg_match('%<style type="text/css">(.*?)</style>.*?<body>(.*?)</body>%s', ob_get_clean(), $matches);
                            //
                            // # $matches [1]; # Style information
                            // # $matches [2]; # Body information
                            //
                            // echo "<div class='phpinfodisplay'><style type='text/css'>\n",
                            //     join(
                            //         "\n",
                            //         array_map(
                            //             create_function(
                            //                 '$i',
                            //                 'return ".phpinfodisplay " . preg_replace( "/,/", ",.phpinfodisplay ", $i );'
                            //                 ),
                            //             preg_split('/\n/', trim(preg_replace("/\nbody/", "\n", $matches[1])))
                            //             )
                            //         ),
                            //     "</style>\n",
                            //     $matches[2],
                            //     "\n</div>\n";
                            ?>
                        </div>
                        </div>
                    </div>
                </div>
            </div> -->
        </div>
        <div class="container results-container">
            <div class="row" id="directory-results">
                <div class="col-xs-12 col-lg-10">
                    <div id="totals"></div>
                    <div id="table-wrap">
                        <table>
                            <tr>
                                <th>Id</th>
                                <th>Title</th>
                                <th>Dimensions</th>
                                <th id="size">Size</th>
                                <th>Duration</th>
                                <th>Added</th>
                                <th>New?</th>
                            </tr>
                        </table>
                    </div>
                </div>
                <div class="col-lg-2 hidden-xs hidden-sm hidden-md"></div>
            </div>
        </div>
    </div>
</div>
