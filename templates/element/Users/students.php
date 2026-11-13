<?php
$groupLabels = ['active' => 'Active', 'archive' => 'Archive', 'inactive' => 'Inactive'];
$canArchiveStudents = $this->request->getSession()->read('user_type') === 'School';
?>
<div class="admin_loader" id="loaderID"><?php echo $this->Html->image('loader_large_blue.gif');?></div>
<?php if ($canArchiveStudents): ?>
    <div class="dashboard-form">
        <h3>Set up student logins in bulk</h3>
        <p>Student IDs stay the same. Each student receives their own single-use setup code. You can print or download the list after issuing the codes.</p>
        <?= $this->Form->create(null, ['url' => ['controller' => 'Users', 'action' => 'bulkstudentaccess']]) ?>
        <input type="hidden" name="mode" value="without_password">
        <button type="submit" class="btn btn-primary">Set up all active students without a password</button>
        <p class="small mt-2 mb-0">Includes eligible students across every page, not just those displayed below. Students who already have a password are excluded. You will review and confirm before codes are issued.</p>
        <?= $this->Form->end() ?>
    </div>
<?php endif; ?>
<?php foreach ($groupLabels as $group => $label) {
    $users = $studentGroups[$group];
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
        <?php $bulkArchive = $canArchiveStudents && $group !== 'archive'; ?>
        <?php if ($bulkArchive): ?>
            <?= $this->Form->create(null, ['url' => ['controller' => 'Users', 'action' => 'archivestudents'], 'class' => 'student-archive-form']) ?>
            <div class="mb-3">
                <button type="submit" class="btn btn-primary">Archive selected</button>
                <?php if ($group === 'active'): ?>
                    <button type="submit" class="btn btn-secondary"
                        formaction="<?= h($this->Url->build(['controller' => 'Users', 'action' => 'bulkstudentaccess'])) ?>"
                        name="mode" value="selected">Setup codes for selected</button>
                <?php endif; ?>
                <span class="ms-2">Select students on this page, then choose an action.</span>
            </div>
        <?php endif; ?>
        <div class="tbl-resp-listing">
            <table class="table table-bordered table-striped table-condensed cf">
                <thead class="cf ajshort">
                    <tr>
                        <?php if ($bulkArchive): ?>
                            <th><input type="checkbox" class="student-select-all" aria-label="Select all <?= h(strtolower($label)) ?> students on this page"></th>
                        <?php endif; ?>
                        <th class="sorting_paging">First Name</th>
                        <th class="sorting_paging">Middle Name</th>
                        <th class="sorting_paging">Last Name</th>
                        <th class="sorting_paging">Birth Year</th>
                        <th class="sorting_paging">Age</th>
                        <th class="sorting_paging">Gender</th>
                        <th class="sorting_paging">Sign Up Date</th>
                        <th class="sorting_paging">Status</th>
                        <th>Account</th>
                        <th class="action_dvv"><i class=" fa fa-gavel"></i> Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $datarecord) { ?>
                        <?php //pr($datarecord); exit;?> 
                        <tr>
                            <?php if ($bulkArchive): ?>
                                <td data-title="Select"><input type="checkbox" name="student_ids[]" value="<?= (int)$datarecord->id ?>" aria-label="Select <?= h($datarecord->first_name . ' ' . $datarecord->last_name) ?>"></td>
                            <?php endif; ?>
                            <td data-title="First Name"><?php echo $datarecord->first_name;?></td>
                            <td data-title="Middle Name"><?php echo $datarecord->middle_name ? $datarecord->middle_name : 'N/A'; ?></td>
                            <td data-title="Last Name"><?php echo $datarecord->last_name;?></td>
                            <td data-title="Birth Year"><?php echo $datarecord->birth_year;?></td>
                            <td data-title="Age"><?php echo date("Y")-$datarecord->birth_year;?></td>
                            
                            <td data-title="Gender"><?php echo $datarecord->gender ? $datarecord->gender : 'N/A'; ?></td>
                            <td data-title="Sign Up Date">
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
                            <td data-title="Account">
                                <span><?= h($datarecord->portal_login_id ?: 'Not assigned') ?></span>
                                <?php if ($datarecord->portal_first_login): ?>
                                    <span style="color: #198754" title="Student has signed in" aria-label="Student has signed in">&#10004;</span>
                                <?php else: ?>
                                    <span style="color: #dc3545" title="Student has not yet signed in" aria-label="Student has not yet signed in">&#10008;</span>
                                <?php endif; ?>
                                <?php if ($canArchiveStudents && (int)$datarecord->status === 1): ?>
                                    <div><?= $this->Html->link('Setup / reset code', ['controller' => 'users', 'action' => 'studentaccess', $datarecord->slug]) ?></div>
                                <?php endif; ?>
                            </td>
                            <td data-title="Action">
                                <?php if ($canArchiveStudents && (int)$datarecord->status === 1 && (int)$datarecord->activation_status === 1): ?>
                                    <button type="submit" class="btn btn-link btn-sm p-0 border-0 align-baseline"
                                        formaction="<?= h($this->Url->build(['controller' => 'Users', 'action' => 'openstudentportal'])) ?>"
                                        name="student_slug" value="<?= h($datarecord->slug) ?>"
                                        title="Login as student" aria-label="Login as <?= h(trim($datarecord->first_name . ' ' . $datarecord->last_name)) ?>">
                                        <i class="fa fa-sign-in" aria-hidden="true"></i>
                                    </button>
                                <?php endif; ?>
                                <?php
                                if($canArchiveStudents && $datarecord->status != 2)
                                {
                                    echo $this->Html->link('<i class="fa fa-pencil"></i>', ['controller' => 'users', 'action' => 'editstudent',$datarecord->slug], [ 'escape' => false, 'title' => 'Edit', 'class'=>'']);
                                }
                                // to show only to school admin only
                                if($this->request->getSession()->read("user_id") >0 && ($this->request->getSession()->read("user_type") == "School"))
                                {
                                    if($datarecord->status == 2)
                                    {
                                        echo $this->Html->link('<i class="fa fa-retweet"></i>', ['controller' => 'users', 'action' => 'restorestudent', $datarecord->slug], [ 'escape' => false, 'title' => 'Restore', 'class' => 't', 'confirm' => 'Are you sure you want to restore this student?']);
                                    }
                                    else
                                    {
                                        echo $this->Html->link('<i class="fa fa-trash-o"></i>', ['controller' => 'users', 'action' => 'archivestudent',$datarecord->slug], [ 'escape' => false, 'title' => 'Archive', 'class'=>'', 'confirm' => 'Are you sure you want to archive this student ?']);
                                    }
                                }
                                ?>
                                <?php //echo $this->Html->link('<i class="fa fa-list"></i>', ['controller' => 'mediafiles', 'action' => 'viewlist',$datarecord->slug], [ 'escape' => false, 'title' => 'Pictures', 'class'=>'']); ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php if ($bulkArchive): ?>
            <?= $this->Form->end() ?>
        <?php endif; ?>
        <?php if ($paging['pageCount'] > 1) {
            $queryParams = $this->request->getQueryParams();
            ?>
            <nav class="supervisor-pagination" aria-label="<?= h($label) ?> student pages">
                <?php if ($users->hasPrevPage()) {
                    $queryParams[$group] = ['page' => $paging['currentPage'] - 1];
                    $url = ['controller' => 'Users', 'action' => 'students', '?' => $queryParams];
                    if (!empty($keyword)) {
                        $url[] = 'keyword:' . $keyword;
                    }
                    echo $this->Html->link('Previous', $url, ['class' => 'btn btn-outline-primary']);
                } ?>
                <span>Page <?= (int)$paging['currentPage'] ?> of <?= (int)$paging['pageCount'] ?></span>
                <?php if ($users->hasNextPage()) {
                    $queryParams[$group] = ['page' => $paging['currentPage'] + 1];
                    $url = ['controller' => 'Users', 'action' => 'students', '?' => $queryParams];
                    if (!empty($keyword)) {
                        $url[] = 'keyword:' . $keyword;
                    }
                    echo $this->Html->link('Next', $url, ['class' => 'btn btn-outline-primary']);
                } ?>
            </nav>
        <?php } ?>
        <?php } else { ?>
            <p class="supervisor-accordion-empty">No <?= h(strtolower($label)) ?> students found.</p>
        <?php } ?>
    </div>
    </details>
<?php } ?>
