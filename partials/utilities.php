<div class="jumbotron">
    <div id="utilities">
        <script type="text/javascript" src="js/utility-functions.js"></script>
        <div class="container">
          <div class="row">
              <div class="col-xs-10">
                  <div id="normalize-db">
                      <h2>
                          Normalize DB
                      </h2>
                      <hr />
                      <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="utils-chkbx-dont-move-duplicates" value="dontMoveDuplicates" checked>
                        <label class="form-check-label" for="utils-chkbx-dont-move-duplicates">Don&rsquo;t move duplicates</label>
                    </div>
                    <div class="form-check form-check-inline">
                      <input class="form-check-input" type="checkbox" id="utils-chkbx-dont-update-size" value="dontUpdateSize" checked>
                      <label class="form-check-label" for="utils-chkbx-dont-update-size">Don&rsquo;t update size in DB</label>
                    </div>
                    <div class="form-check form-check-inline">
                      <input class="form-check-input" type="checkbox" id="utils-chkbx-dont-update-dimensions" value="dontUpdateDimensions" checked>
                      <label class="form-check-label" for="utils-chkbx-dont-update-dimensions">Don&rsquo;t update dimensions in DB</label>
                    </div>
                    <div class="form-check form-check-inline">
                      <input class="form-check-input" type="checkbox" id="utils-chkbx-dont-update-duration" value="dontUpdateDuration" checked>
                      <label class="form-check-label" for="utils-chkbx-dont-update-duration">Don&rsquo;t update duration in DB</label>
                    </div>

                    <table id="utils-paths" class="table">
                      <thead>
                    <th>Path to process</th><th>Include</th>
                    </thead>
                    <tbody></tbody>
                    </table>
                      <button type="button" class="btn btn-success btn-sm" id="btn-utils">Run</button>
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
    </div>
</div>
