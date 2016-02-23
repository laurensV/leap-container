<h3>Plugins</h3>
<!-- TODO: add https://github.com/drvic10k/bootstrap-sortable -->
<div class="table-searchable">
    <div class="input-group"> <span class="input-group-addon">Search</span>
        <input type="text" class="form-control search-table" placeholder="search for plugins..">
    </div>
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th colspan="3">Operations</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($plugins as $name => $enabled) {
                    if (!$enabled) {
                        $link = "<a href='" . BASE_URL . "/admin/plugins/enable/" . $name . "'>enable</a>";
                    } else {
                        $link = "<a href='" . BASE_URL . "/admin/plugins/disable/" . $name . "'>disable</a>";
                    }
                    echo "<tr><td class='searchable'>" . $name . "</td><td>TODO: get plugin info</td><td>" . $link . "</td></tr>";
                }
                ?>
                <tr class="no-results" style="display: none">
                    <td colspan="10">No results</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>