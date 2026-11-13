<div class="admin_loader" id="loaderID"><?php echo $this->Html->image('loader_large_blue.gif');?></div>
<?php if ($studentList) { ?>

    <div class="panel-body cr-studentevents-panel">
        
        <?php echo $this->Form->create(null, ['id'=>'actionFrom', "method" => "Post"]);  ?>
        <section id="no-more-tables" class="lstng-section">
            

            <div class="tbl-resp-listing cr-studentevents-table-wrap">
                <table id="cr_students_list" class="table table-striped table-bordered cr-studentevents-table" style="width:100%">
                    <thead class="cf ajshort">
                        <tr>
                            <th class="sorting_paging">Season Year</th>
                            <th class="sorting_paging">Student</th>
                            <th class="sorting_paging">Gender</th>
                            <th class="sorting_paging">Age</th>
                            <th class="sorting_paging">Events(s)</th>
                            <th>School review</th>
                            <th class="action_dvv">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
						foreach ($studentList as $datarecord)
						{
                            $review = $eventReviews[(int)$datarecord->id] ?? null;
                            $pendingReview = $review && $review['status'] === 'pending';
                            $approvedChoices = ($review && $review['status'] === 'approved') || (!$review && !empty($datarecord->event_ids));
						?>
                            
                            <tr>
                                <td data-title="Season Year"><?php echo $datarecord->season_year;?></td>
                                <td data-title="Student"><?php echo $datarecord->Students['first_name'].' '.$datarecord->Students['middle_name'].' '.$datarecord->Students['last_name'];?> </td>
								<td data-title="Gender"><?php echo $datarecord->Students['gender']; ?></td>
								<td data-title="Age"><?php echo $datarecord->season_year - $datarecord->Students['birth_year']; ?></td>
                                 
								<td data-title="Events(s)">
								<?php
                                if ($pendingReview)
                                    echo count(array_filter(explode(',', $review['event_ids'])));
                                elseif($datarecord->event_ids)
									echo count(explode(",",$datarecord->event_ids));
								else
									echo 0;
								?>
								</td>
								
								
                                <td data-title="School review">
                                    <?php if ($pendingReview): ?>
                                        <span class="badge bg-warning text-dark">Pending review</span>
                                    <?php elseif ($approvedChoices): ?>
                                        <span class="cr-school-review-approved" aria-label="Approved">&#10004; Approved</span>
                                    <?php else: ?>
                                        <span>No choices saved</span>
                                    <?php endif; ?>
                                </td>
                                <td data-title="Action" class="cr-action-cell">
                                    <?php if ($pendingReview && $userDetails->user_type === 'School'): ?>
                                        <?= $this->Html->link('Review choices', ['action' => 'reviewstudentevents', $datarecord->slug], ['class' => 'cr-action-btn cr-action-manage']) ?>
                                    <?php endif; ?>
                                    <?php
                                    echo $this->Html->link($approvedChoices && $userDetails->user_type === 'Student' ? 'View approved events' : 'Manage Events', ['controller' => 'conventionregistrations', 'action' => 'managestudentevents',$datarecord->slug], [ 'escape' => false, 'title' => 'Manage Student Events', 'class'=>'cr-action-btn cr-action-manage']);
									
									if(in_array($userDetails->user_type, ['School', 'Teacher_Parent'], true))
									{
                                            echo '<button type="submit" class="cr-action-btn cr-action-remove" formaction="' . h($this->Url->build(['action' => 'removestudentevent', $datarecord->slug])) . '" onclick="return confirm(\'Remove all events and pending choices for this student?\')">Remove All</button>';
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
    
    </div>
	
	 
	
<?php } else { ?>
    <div id="listingJS" style="display: none;" class="alert alert-success alert-block fade in"></div>
    <div class="admin_no_record">No student event found.</div>
<?php }
?>

<script>
$(document).ready(function() {
$('#cr_students_list').dataTable({
    //"bPaginate": false,
    "bLengthChange": false,
	"pageLength": 50,
	order: [[1, 'asc']],
    //"bFilter": true,
    //"bInfo": false,
    //"bAutoWidth": false
	});
	/* $('#searchInput').on('keyup', function() {
        $('#cr_students_list').dataTable.search(this.value).draw();
    }); */
});
</script>
<?php echo $this->element("jquery_datatable_code"); ?>
