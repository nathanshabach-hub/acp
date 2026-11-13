<?php echo $this->Html->script('ajax-pagging.js'); ?>
<div class="content-wrapper admin-list-page admin-rooms-page">
    <section class="content-header">
      <h1>Manage Global Rooms</h1>
      <ol class="breadcrumb">
          <li><?php echo $this->Html->link('<i class="fa fa-dashboard"></i> <span>Dashboard</span> ', ['controller'=>'admins', 'action'=>'dashboard'], ['escape'=>false]);?></li>
          <li class="active">Manage Global Rooms</li>
      </ol>
    </section>

    <section class="content">
        <div class="box box-info">
            <div class="ersu_message"> <?php echo $this->Flash->render() ?></div>
            <div class="admin_search">
                <?php echo $this->Form->create(null, ['type' => 'get']); ?>
                <div class="form-group align_box dtpickr_inputs search_frm">
                    <span class="hints">Search by Room Name</span>
                    <span class="hint list_sel">
                        <?php echo $this->Form->input('keyword', ['label'=>false, 'type'=>'text', 'div'=>false, 'class'=>'form-control', 'placeholder'=>'Search by keyword', 'value' => $keyword]); ?>
                    </span>
                    <div class="admin_asearch">
                        <div class="ad_s"><?php echo $this->Form->button('Search', ['class'=>'btn btn-info', 'type'=>'submit']); ?></div>
                        <div class="ad_cancel"><?php echo $this->Html->link('Clear Search', ['controller'=>'conventions', 'action'=>'globalrooms'], ['escape'=>false, 'class'=>'btn btn-default canlcel_le']);?></div>
                    </div>
                </div>
                <?php echo $this->Form->end(); ?>
                <div class="add_new_record">
                    <?php echo $this->Html->link('<i class="fa fa-plus"></i> Add Global Room', ['controller'=>'conventions', 'action'=>'addglobalroom'], ['escape'=>false, 'class'=>'btn btn-default']);?>
                </div>
            </div>

            <div class="panel-body">
                <?php if (!$globalRooms->isEmpty()) { ?>
                    <section id="no-more-tables" class="lstng-section">
                        <div class="topn">
                            <div class="topn_left">Global Rooms List</div>
                            <div class="topn_right ajshort" id="pagingLinks" align="right">
                                <?php
                                    echo $this->Paginator->counter(['model' => 'Conventionrooms', 'format' => '{{page}} of {{pages}} &nbsp;']);
                                    echo $this->Paginator->prev('« Prev');
                                    echo $this->Paginator->numbers();
                                    echo $this->Paginator->next('Next »');
                                ?>
                            </div>
                        </div>
                        <div class="tbl-resp-listing">
                            <table class="table table-bordered table-striped table-condensed cf room-list-table">
                                <thead class="cf ajshort">
                                    <tr>
                                        <th># DB ID</th>
                                        <th>Room Name</th>
                                        <th>Short Description</th>
                                        <th class="action_dvv"><i class="fa fa-gavel"></i> Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($globalRooms as $datarecord) { ?>
                                    <tr>
                                        <td><?php echo $datarecord->id; ?></td>
                                        <td><?php echo h($datarecord->room_name); ?></td>
                                        <td><?php echo h($datarecord->short_description); ?></td>
                                        <td class="room-action-cell">
                                            <?php
                                                echo $this->Html->link('<i class="fa fa-pencil"></i>', ['controller' => 'conventions', 'action' => 'editglobalroom', $datarecord->slug], ['escape' => false, 'title' => 'Edit', 'class' => 'btn btn-info btn-xs']);
                                                echo $this->Html->link('<i class="fa fa-trash-o"></i>', ['controller' => 'conventions', 'action' => 'deleteglobalroom', $datarecord->slug], ['escape' => false, 'title' => 'Delete', 'class' => 'btn btn-danger btn-xs action-list delete-list', 'confirm' => 'Are you sure you want to Delete ?']);
                                            ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php } else { ?>
                    <div class="admin_no_record admin-rooms-empty">Sorry, no global room record found.</div>
                <?php } ?>
            </div>
        </div>
    </section>
</div>
