<?php
$groupLabels = ['active' => 'Active', 'archive' => 'Archive', 'inactive' => 'Inactive'];
?>
<div class="admin_loader" id="loaderID"><?php echo $this->Html->image('loader_large_blue.gif');?></div>
<?php foreach ($groupLabels as $group => $label) {
    $users = $supervisorGroups[$group];
    $paging = $users->pagingParams();
    ?>
    <details class="supervisor-accordion">
        <summary>
            <span><?= h($label) ?></span>
            <span class="supervisor-accordion-count"><?= (int)$paging['totalCount'] ?></span>
            <i class="fa fa-chevron-down supervisor-accordion-chevron" aria-hidden="true"></i>
        </summary>
    <div class="supervisor-accordion-content">
        <?php if (count($users) > 0) { ?>
        <div class="tbl-resp-listing">
            <table class="table table-bordered table-striped table-condensed cf">
                <thead class="cf ajshort">
                    <tr>
                        <th class="sorting_paging">Title</th>
                        <th class="sorting_paging">First Name</th>
                        <th class="sorting_paging">Surname</th>
                        <th class="sorting_paging">Email Address</th>
                        <th class="sorting_paging">Gender</th>
                        <th class="sorting_paging">Judge?</th>
                        <th class="sorting_paging">Created</th>
                        <th class="sorting_paging">Verified</th>
                        <th class="sorting_paging">Status</th>
                        <th class="action_dvv"><i class=" fa fa-gavel"></i> Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $datarecord) { ?>
                        <?php //pr($datarecord); exit;?> 
                        <tr>
                            <td data-title="Title"><?php echo $datarecord->title;?></td>
                            <td data-title="First Name"><?php echo $datarecord->first_name;?></td>
                            <td data-title="Surname"><?php echo $datarecord->last_name;?></td>
                            <td data-title="Email Address"><?php echo $datarecord->email_address;?></td>
                            <td data-title="Gender"><?php echo $datarecord->gender;?></td>
                            <td data-title="Judge?"><?php if($datarecord->is_judge == 1) echo 'Yes'; else echo 'No'; ?></td>
                            
                            <td data-title="Created">
                                <?php
                                $createdDisplay = 'N/A';

                                if (!empty($datarecord->created) && $datarecord->created !== '0000-00-00 00:00:00') {
                                    if (is_object($datarecord->created) && method_exists($datarecord->created, 'format')) {
                                        $createdDisplay = $datarecord->created->format('M d, Y');
                                    } else {
                                        $createdTs = strtotime((string)$datarecord->created);
                                        if ($createdTs !== false && $createdTs > 0) {
                                            $createdDisplay = date('M d, Y', $createdTs);
                                        }
                                    }
                                }

                                echo $createdDisplay;
                                ?>
                            </td>
                            
                            <td data-title="Verified">
                                <?php
                                if($datarecord->status != 2)
                                {
                                    if($datarecord->activation_status)  echo 'Verified'; else  echo 'Not yet verified';
                                }
                                ?>
                            </td>
                            
                            <td data-title="Status">
                                <?php
                                if($datarecord->status == 0) 
                                    echo 'Inactive'; 
                                else
                                if($datarecord->status == 1)
                                    echo 'Active';
                                else
                                if($datarecord->status == 2)
                                    echo 'Archive';
                                ?>
                            </td>
                            
                            <td data-title="Action">
                                <?php
                                if($datarecord->status == 2)
                                {
                                    echo $this->Html->link('<i class="fa fa-retweet"></i>', ['controller' => 'users', 'action' => 'restoreteacher', $datarecord->slug], [ 'escape' => false, 'title' => 'Restore', 'class' => '', 'confirm' => 'Are you sure you want to restore this supervisor?']);
                                }
                                else
                                {
                                    echo $this->Html->link('<i class="fa fa-pencil"></i>', ['controller' => 'users', 'action' => 'editteacher',$datarecord->slug], [ 'escape' => false, 'title' => 'Edit', 'class'=>'']);
                                    
                                    echo $this->Html->link('<i class="fa fa-trash-o"></i>', ['controller' => 'users', 'action' => 'archiveteacher',$datarecord->slug], [ 'escape' => false, 'title' => 'Archive', 'class'=>'', 'confirm' => 'Are you sure you want to archive this supervisor?']);
                                }
                                ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php if ($paging['pageCount'] > 1) {
            $queryParams = $this->request->getQueryParams();
            ?>
            <nav class="supervisor-pagination" aria-label="<?= h($label) ?> supervisor pages">
                <?php if ($users->hasPrevPage()) {
                    $queryParams[$group] = ['page' => $paging['currentPage'] - 1];
                    $url = ['controller' => 'Users', 'action' => 'teachers', '?' => $queryParams];
                    if (!empty($keyword)) {
                        $url[] = 'keyword:' . $keyword;
                    }
                    echo $this->Html->link('Previous', $url, ['class' => 'btn btn-outline-primary']);
                } ?>
                <span>Page <?= (int)$paging['currentPage'] ?> of <?= (int)$paging['pageCount'] ?></span>
                <?php if ($users->hasNextPage()) {
                    $queryParams[$group] = ['page' => $paging['currentPage'] + 1];
                    $url = ['controller' => 'Users', 'action' => 'teachers', '?' => $queryParams];
                    if (!empty($keyword)) {
                        $url[] = 'keyword:' . $keyword;
                    }
                    echo $this->Html->link('Next', $url, ['class' => 'btn btn-outline-primary']);
                } ?>
            </nav>
        <?php } ?>
        <?php } else { ?>
            <p class="supervisor-accordion-empty">No <?= h(strtolower($label)) ?> supervisors found.</p>
        <?php } ?>
    </div>
    </details>
<?php } ?>
