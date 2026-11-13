<div class="content-wrapper admin-list-page admin-rooms-page">
    <section class="content-header">
      <h1>
         Manage Rooms -  <?php echo $conventionD->name; ?>
      </h1>
      <ol class="breadcrumb">
          <li><?php echo $this->Html->link('<i class="fa fa-dashboard"></i> <span>Dashboard</span> ', ['controller'=>'admins', 'action'=>'dashboard'], ['escape'=>false]);?></li>
          <li><?php echo $this->Html->link('<i class="fa fa-bars"></i> Conventions ', ['controller'=>'conventions', 'action'=>'index'], ['escape'=>false]);?></li>
          <li class="active">Manage Rooms (<?php echo $conventionD->name; ?>) </li>
      </ol>
    </section>

    <section class="content">
        <div class="box box-info">
            <div class="ersu_message"> <?php echo $this->Flash->render() ?></div>
            <div class="box-body">
                <p class="rooms-help-text">Select global rooms for this convention. Manage the master room list from <?php echo $this->Html->link('Manage Global Events → Manage Rooms', ['controller' => 'conventions', 'action' => 'globalrooms']); ?>.</p>
                <?php echo $this->Form->create(null, ['method' => 'post']); ?>
                <div class="rooms-toolbar">
                    <div class="rooms-toolbar-actions">
                        <button type="button" class="btn btn-default btn-sm" id="rooms-select-all">Select All</button>
                        <button type="button" class="btn btn-default btn-sm" id="rooms-clear-all">Clear All</button>
                        <?php if (empty($unlockMode) && !empty($lockedGlobalRoomIds)) { ?>
                            <?php echo $this->Html->link('Unlock Rooms', ['controller' => 'conventions', 'action' => 'rooms', $slug, '?' => ['unlock' => 1]], ['class' => 'btn btn-warning btn-sm']); ?>
                        <?php } ?>
                        <?php if (!empty($unlockMode)) { ?>
                            <?php echo $this->Html->link('Cancel Unlock', ['controller' => 'conventions', 'action' => 'rooms', $slug], ['class' => 'btn btn-default btn-sm']); ?>
                        <?php } ?>
                    </div>
                    <div class="rooms-toolbar-search">
                        <input type="text" id="rooms-filter" class="form-control input-sm" placeholder="Filter rooms by name">
                    </div>
                    <div class="rooms-toolbar-count">
                        <span id="rooms-selected-count"><?php echo count((array)$selectedGlobalRoomIds); ?></span> selected
                    </div>
                </div>
                <?php if (!empty($unlockMode)) { ?>
                    <div class="alert alert-info rooms-lock-note">
                        Unlock mode is active. Update selections and click Save Room Selection to lock the new selection.
                    </div>
                <?php } elseif (!empty($lockedGlobalRoomIds)) { ?>
                    <div class="alert alert-warning rooms-lock-note">
                        Saved rooms are locked. Click Unlock Rooms to edit the selection.
                    </div>
                <?php } ?>
                <div class="form-group rooms-checkbox-grid" id="rooms-checkbox-grid">
                    <?php if (!empty($globalRooms)) { ?>
                        <?php foreach ($globalRooms as $globalRoom) { ?>
                            <?php
                                $roomId = (int)$globalRoom->id;
                                $isChecked = in_array($roomId, (array)$selectedGlobalRoomIds, true);
                                $isLocked = in_array($roomId, (array)$lockedGlobalRoomIds, true);
                                $shortDescription = trim((string)$globalRoom->short_description);
                                $displayShortDescription = $shortDescription !== '' && strcasecmp($shortDescription, (string)$globalRoom->room_name) !== 0;
                            ?>
                            <div class="checkbox room-checkbox-item<?php echo $isLocked ? ' room-checkbox-item--locked' : ''; ?>" data-room-name="<?php echo h(strtolower((string)$globalRoom->room_name)); ?>">
                                <label class="room-checkbox-label">
                                    <input type="checkbox" name="global_room_ids[]" value="<?php echo $roomId; ?>" <?php echo $isChecked ? 'checked' : ''; ?> <?php echo $isLocked ? 'disabled' : ''; ?>>
                                    <strong><?php echo h($globalRoom->room_name); ?></strong>
                                    <?php if ($displayShortDescription) { ?>
                                        <span class="room-description"><?php echo h($shortDescription); ?></span>
                                    <?php } ?>
                                    <?php if ($isLocked) { ?>
                                        <span class="room-lock-tag">Locked</span>
                                    <?php } ?>
                                </label>
                                <?php if ($isLocked) { ?>
                                    <input type="hidden" name="global_room_ids[]" value="<?php echo $roomId; ?>">
                                <?php } ?>
                            </div>
                        <?php } ?>
                    <?php } else { ?>
                        <div class="alert alert-info">No global rooms found. Please add rooms first in Manage Global Events → Manage Rooms.</div>
                    <?php } ?>
                </div>
                <div class="box-footer" style="padding-left:0;">
                    <?php echo $this->Form->button('Save Room Selection', ['type' => 'submit', 'class' => 'btn btn-info']); ?>
                    <?php echo $this->Html->link('Back to Conventions', ['controller' => 'conventions', 'action' => 'index'], ['class' => 'btn btn-default canlcel_le']); ?>
                </div>
                <?php echo $this->Form->end(); ?>
            </div>
        </div>
    </section>
</div>
<script>
    (function ($) {
        $(function () {
            var $grid = $('#rooms-checkbox-grid');
            var $checkboxes = $grid.find('input[type="checkbox"][name="global_room_ids[]"]');
            var $editableCheckboxes = $checkboxes.not(':disabled');
            var $count = $('#rooms-selected-count');
            var $filter = $('#rooms-filter');

            function updateSelectedCount() {
                $count.text($checkboxes.filter(':checked').length);
            }

            function applyFilter() {
                var keyword = ($filter.val() || '').toString().toLowerCase().trim();
                $grid.find('.room-checkbox-item').each(function () {
                    var roomName = ($(this).data('room-name') || '').toString();
                    $(this).toggle(keyword === '' || roomName.indexOf(keyword) !== -1);
                });
            }

            $('#rooms-select-all').on('click', function () {
                $editableCheckboxes.prop('checked', true);
                updateSelectedCount();
            });

            $('#rooms-clear-all').on('click', function () {
                $editableCheckboxes.prop('checked', false);
                updateSelectedCount();
            });

            $checkboxes.on('change', updateSelectedCount);
            $filter.on('input', applyFilter);
            updateSelectedCount();
        });
    })(jQuery);
</script>
