<div class="admin_loader" id="loaderID"><?php echo $this->Html->image('loader_large_blue.gif');?></div>
<?php if (!$conventionregistrationstudents->isEmpty()) { ?>

    <div class="panel-body cr-students-panel">
        
        <?php echo $this->Form->create(null, ['id'=>'actionFrom', 'url' => ['controller' => 'Conventionregistrations', 'action' => 'unassignsupervisors'], "method" => "Post"]); ?>
        <div class="cr-supervisor-bulk">
            <p>Select students to remove their supervisor assignments only. Their convention registrations and events will stay unchanged.</p>
            <div class="cr-supervisor-bulk-actions">
                <button type="button" class="btn btn-outline-secondary" data-student-selection="all">Select all assigned students</button>
                <button type="button" class="btn btn-outline-secondary" data-student-selection="none">Clear selection</button>
                <span id="supervisor-selection-count" role="status" aria-live="polite">0 selected</span>
            </div>
            <label for="confirm-unassign"><input id="confirm-unassign" type="checkbox" name="confirm_unassign" value="1" required> I confirm: remove supervisors only, keeping students registered.</label>
            <button type="submit" class="btn btn-secondary">Remove selected from supervisors</button>
        </div>
        <section id="no-more-tables" class="lstng-section">
            <div class="topn">
                <!--<div class="topn_left">Ads List</div>-->
                <div class="topn_right ajshort" id="pagingLinks" align="right">
                    <?php
                        $pagingParams = $this->Paginator->params();
                        if (!empty($pagingParams)) {
                            $this->Paginator->options(array('update' => '#listID', 'url' => ['controller'=>'conventionregistrations', 'action'=>'students', $separator]));
                            echo $this->Paginator->counter('{{page}} of {{pages}} &nbsp;');
                            echo $this->Paginator->prev('« Prev');
                            echo $this->Paginator->numbers();
                            echo $this->Paginator->next('Next »');
                        }
                    ?>
                </div>
            </div>   

            <div class="tbl-resp-listing cr-students-table-wrap">
                <table class="table table-bordered table-striped table-condensed cf cr-students-table">
                    <thead class="cf ajshort">
                        <tr>
                            <th scope="col">Select</th>
                            <th class="sorting_paging">Season Year</th>
                            <th class="sorting_paging">Student</th>
                            <th class="sorting_paging">Registration Date</th>
                            <th class="sorting_paging" style="width:24%">Supervisor</th>
                            <th class="action_dvv">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($conventionregistrationstudents as $datarecord)
                        {
                        ?>
                            <tr>
                                <td data-title="Select">
                                    <input type="checkbox" name="student_registration_ids[]" value="<?= (int)$datarecord->id ?>"
                                        class="cr-supervisor-selection" aria-label="Select <?= h($datarecord->Students ? trim($datarecord->Students->first_name . ' ' . $datarecord->Students->last_name) : 'student') ?>"
                                        <?= empty($datarecord->teacher_parent_id) ? 'disabled' : '' ?>>
                                </td>
                                <td data-title="Season Year"><?php echo $datarecord->season_year;?></td>
                                <td data-title="Student"><?php
                                    $student = $datarecord->Students;
                                    $studentName = $student ? trim(implode(' ', array_filter([
                                        $student->first_name,
                                        $student->middle_name,
                                        $student->last_name,
                                    ], static fn($part) => $part !== null && $part !== ''))) : '';
                                    echo $studentName !== '' ? h($studentName) : 'Student not found';
                                ?></td>

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

                                <td data-title="Supervisor">
                                    <?php if (empty($datarecord->teacher_parent_id)): ?>
                                        <p>No supervisor assigned. <?= $this->Html->link('View sponsorship requests', ['controller' => 'users', 'action' => 'crossschoolrequests']) ?></p>
                                    <?php endif; ?>
                                    <?php echo $this->Form->select('Conventionregistrationstudents.teacher_parent_id', $teacherDropDownData, ['id' => 'teacher_parent_id_'.$datarecord->id, 'label' => false, 'div' => false, 'class' => 'form-control', 'autocomplete' => 'off', 'empty' => 'Choose', 'value' => $datarecord->teacher_parent_id]); ?>
                                    <script>
                                    $(document).ready(function() {
                                        $('#teacher_parent_id_<?php echo $datarecord->id; ?>').select2();
                                    });
                                    </script>
                                    
                                    <script type="text/javascript">
                                        $(document).ready(function () {
                                            var supervisorSelect = $('#teacher_parent_id_<?php echo $datarecord->id; ?>');
                                            var savedSupervisor = supervisorSelect.val();
                                            var restoring = false;
                                            supervisorSelect.change(function () {
                                                if (restoring) return;
                                                var teacher_parent_id = supervisorSelect.val();
                                                
                                                if(teacher_parent_id == 0 || teacher_parent_id == "")
                                                {
                                                    alert("Please choose a supervisor.");
                                                    restoring = true;
                                                    supervisorSelect.val(savedSupervisor).trigger('change');
                                                    restoring = false;
                                                    return false;
                                                }
                                                
                                                supervisorSelect.prop('disabled', true);
                                                $.ajax({
                                                    type: 'POST',
                                                    url: <?= json_encode($this->Url->build(['controller' => 'homes', 'action' => 'assignteachertostudent', $datarecord->slug]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> + '/' + encodeURIComponent(teacher_parent_id),
                                                    cache: false,
                                                    complete: function () {
                                                        supervisorSelect.prop('disabled', false);
                                                    },
                                                    success: function (result) {
                                                        savedSupervisor = teacher_parent_id;
                                                        alert(result);
                                                    },
                                                    error: function (xhr) {
                                                        restoring = true;
                                                        supervisorSelect.val(savedSupervisor).trigger('change');
                                                        restoring = false;
                                                        alert(xhr.responseText || 'Unable to assign supervisor. Please try again.');
                                                    }
                                                });
                                                return false;
                                            });
                                        });
                                    </script>
                                </td>
                                
                                <td data-title="Action" class="cr-action-cell">
                                    <?php
                                    if (!empty($resultsReleased))
                                    {
                                        echo $this->Html->link('<i class="fa fa-file-pdf-o"></i>', ['controller' => 'judgeevaluations', 'action' => 'indrespackprint',$datarecord->slug], [ 'escape' => false, 'title' => 'Download Individual Result Package', 'target'=>'_blank', 'class' => 'cr-action-btn cr-action-result']);
                                    }
                                    else
                                    {
                                        if(!$checkStudentPaymentStatus)
                                        {
                                            echo $this->Html->link('Remove Student', ['controller' => 'conventionregistrations', 'action' => 'removestudent',$datarecord->slug], [ 'escape' => false, 'title' => 'Remove', 'class'=>'cr-action-btn cr-action-remove', 'confirm' => 'Are you sure you want to remove this student from this convention registration ?']);
                                        }
                                        else
                                        {
                                            echo '<span class="cr-action-btn cr-action-result" style="opacity:.6;cursor:not-allowed;">Result Locked</span>';
                                        }
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php echo $this->Form->end(); ?>
        <script>
        $(function () {
            var form = document.getElementById('actionFrom');
            var boxes = form.querySelectorAll('.cr-supervisor-selection:not(:disabled)');
            function updateSelectionCount() {
                document.getElementById('supervisor-selection-count').textContent =
                    form.querySelectorAll('.cr-supervisor-selection:checked').length + ' selected';
            }
            $(form).find('[data-student-selection]').on('click', function () {
                var select = this.dataset.studentSelection === 'all';
                boxes.forEach(function (box) { box.checked = select; });
                updateSelectionCount();
            });
            $(boxes).on('change', updateSelectionCount);
            $(form).on('submit', function (event) {
                if (!form.querySelector('.cr-supervisor-selection:checked')) {
                    event.preventDefault();
                    alert('Select at least one assigned student.');
                }
            });
        });
        </script>
    
    </div>
    
    <?php 
    if($checkPriceStructure->price_per_student>0)
    {
        echo $this->Html->link('Proceed to Payment/Invoice', ['controller' => 'transactions', 'action' => 'paymentsummary'], ['escape' => false, 'class' => 'btn btn-success cr-students-footer-btn']);
    }
    else
    {
        echo $this->Html->link('Price Structure', ['controller' => 'conventionregistrations', 'action' => 'pricestructure'], ['escape' => false, 'class' => 'btn btn-info cr-students-footer-btn']);
    }
    ?>
    
<?php } else { ?>
    <div id="listingJS" style="display: none;" class="alert alert-success alert-block fade in"></div>
    <div class="admin_no_record">No student found.</div>
<?php }
?>