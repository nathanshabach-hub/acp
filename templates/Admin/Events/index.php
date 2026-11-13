<?php echo $this->Html->script('ajax-pagging.js'); ?>
<div class="content-wrapper admin-list-page admin-events-page">
    <section class="content-header">
      <h1>
         Manage Global Events
      </h1>
      <ol class="breadcrumb">
          <li><?php echo $this->Html->link('<i class="fa fa-dashboard"></i> <span>Dashboard</span> ', ['controller'=>'admins', 'action'=>'dashboard'], ['escape'=>false]);?></li>
          <li class="active">Global Events List</li>
      </ol>
    </section>

    <section class="content">
        <div class="box box-info">
            <div class="ersu_message"> <?php echo $this->Flash->render() ?></div>
            <div class="admin_search">
                <?php echo $this->Form->create(null, ['id'=>'adminSearch']); ?>
                    <div class="form-group align_box dtpickr_inputs search_frm">
                       <span class="hints">Search Event</span>
                       <span class="hint list_sel hint--keyword">
                           <?php echo $this->Form->input('Events.keyword', ['label'=>false, 'type'=>'text',  'div'=>false, 'class'=>'form-control', 'placeholder'=>'Search by Event Name Or Number', 'value' => isset($keyword) ? $keyword : '']); ?>
                       </span>
					   <span class="hint list_sel hint--division">
                           <?php echo $this->Form->select('Events.division_id', $divisionDD, ['id' => 'division_id', 'label' => false, 'div' => false, 'class' => 'form-control required', 'autocomplete' => 'off', 'empty' => 'Choose Division', 'value' => isset($division_id) ? $division_id : '']); ?>
                       </span>
                      
                       <div class="admin_asearch">
                            <div class="ad_s ajshort"> <?php echo $this->Form->button('Search', ['class'=>'btn btn-info admin_ajax_search', 'type'=>'button']); ?></div>
                            <div class="ad_cancel"> <?php echo $this->Html->link('Clear Search', ['controller'=>'events', 'action'=>'index'], ['escape'=>false, 'class'=>'btn btn-default canlcel_le']);?></div>
                       </div>
                    </div>
                <?php echo $this->Form->end(); ?>
                <div class="add_new_record"><?php echo $this->Html->link('<i class="fa fa-plus"></i> Add Event', ['controller'=>'events', 'action'=>'add'], ['escape'=>false, 'class'=>'btn btn-default']);?></div>
            </div>
            <div class="m_content" id="listID">
                <?php echo $this->element("Admin/Events/index"); ?>
            </div>
            
        </div>
    </section>
</div>
<script>
    $(document).ready(function() {
        $('#division_id').select2();
    });
</script>
