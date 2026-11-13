<?php echo $this->Html->script('ajax-pagging.js'); ?>
<div class="content-wrapper">
    <section class="content-header">
      <h1>
            Scripture Awards List - <?php echo $conventionD->name; ?> - <?php echo $conventionSD->season_year; ?>
      </h1>
      <ol class="breadcrumb">
          <li><?php echo $this->Html->link('<i class="fa fa-dashboard"></i> <span>Dashboard</span> ', ['controller'=>'admins', 'action'=>'dashboard'], ['escape'=>false]);?></li>
          <li><?php echo $this->Html->link('<i class="fa fa-bars"></i> Conventions ', ['controller'=>'conventions', 'action'=>'index'], ['escape'=>false]);?></li>
		  <li><?php echo $this->Html->link('<i class="fa fa-bullhorn"></i> Seasons ', ['controller'=>'conventions', 'action'=>'seasons',$slug_convention], ['escape'=>false]);?></li>
		  <li class="active">Scripture Awards List <?php echo $conventionSD->season_year; ?></li>
      </ol>
    </section>

    <section class="content">
        <div class="box box-info">
            <div class="ersu_message"> <?php echo $this->Flash->render() ?></div>
            <div class="admin_search" style="display:none;">
                <?php echo $this->Form->create(Null, ['id'=>'adminSearch']); ?>
                    <div class="form-group align_box dtpickr_inputs">
                       <span class="hints">Search by Season Name or Year</span>
                       <span class="hint">
                           <?php echo $this->Form->input('Seasons.keyword', ['label'=>false, 'type'=>'text',  'div'=>false, 'class'=>'form-control', 'placeholder'=>'Search by Season Name or Year']); ?>
                       </span>
                      
                       <div class="admin_asearch">
                            <div class="ad_s ajshort"> <?php echo $this->Form->button('Search', ['class'=>'btn btn-info admin_ajax_search', 'type'=>'button']); ?></div>
                            <div class="ad_cancel"> <?php echo $this->Html->link('Clear Search', ['controller'=>'conventions', 'action'=>'index'], ['escape'=>false, 'class'=>'btn btn-default canlcel_le']);?></div>
                       </div>
                    </div>
                <?php echo $this->Form->end(); ?>
                <div class="add_new_record"><?php echo $this->Html->link('<i class="fa fa-plus"></i> Add Season', ['controller'=>'seasons', 'action'=>'add'], ['escape'=>false, 'class'=>'btn btn-default']);?></div>
            </div>
			<?php if (count($finalSchoolsList)) { ?>
            <div class="m_content" id="listID">
                <?php echo $this->element("Admin/Conventions/scriptureawardslist"); ?>
            </div>
			<?php
			}
			elseif (!$adultScriptureSubmissions)
			{
			?>
				<div id="listingJS" style="display: none;" class="alert alert-success alert-block fade in"></div>
				<div class="admin_no_record">No record found.</div>
			<?php
			}
			?>
            <?php if ($adultScriptureSubmissions): ?>
                <div class="panel-body">
                    <h4>Supervisor and Judge Scripture Submissions</h4>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead><tr><th>Name</th><th>Role</th><th>Award</th><th>Submitted</th><th>Submission</th><th>Certificate</th></tr></thead>
                            <tbody>
                            <?php foreach ($adultScriptureSubmissions as $submission): ?>
                                <tr>
                                    <td><?= h(trim($submission['first_name'] . ' ' . $submission['middle_name'] . ' ' . $submission['last_name'])) ?></td>
                                    <td><?= $submission['user_type'] === 'Judge' ? 'Judge' : 'Supervisor' ?></td>
                                    <td><?= h($submission['event_name']) ?></td>
                                    <td><?= h($submission['created']) ?></td>
                                    <td><?= $this->Html->link('Download', ['action' => 'adultscripturefile', $submission['id']], ['class' => 'btn btn-xs btn-default']) ?></td>
                                    <td><?= $this->Html->link('Print certificate', ['action' => 'adultscripturecertificate', $submission['id']], ['class' => 'btn btn-xs btn-danger', 'target' => '_blank']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
            
        
    </section>
</div>

