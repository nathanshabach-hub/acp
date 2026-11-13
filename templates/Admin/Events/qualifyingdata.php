<?php
$qualifyingTime = $event->qualifying_time_score;
if (is_object($qualifyingTime) && method_exists($qualifyingTime, 'format')) {
    $formattedTime = $qualifyingTime->format('H:i:s.u');
    if (str_contains($formattedTime, '.')) {
        [$wholeTime, $fraction] = explode('.', $formattedTime, 2);
        $formattedTime = $wholeTime.'.'.rtrim($fraction, '0');
        $formattedTime = rtrim($formattedTime, '.');
    }
} else {
    $formattedTime = (string)($qualifyingTime ?? '');
}
?>
<div class="content-wrapper">
    <section class="content-header">
      <h1>Qualifying Times &amp; Records</h1>
      <ol class="breadcrumb">
          <li><?php echo $this->Html->link('<i class="fa fa-dashboard"></i> <span>Dashboard</span> ', ['controller'=>'admins', 'action'=>'dashboard'], ['escape'=>false]);?></li>
          <li><?php echo $this->Html->link('Qualifying Times & Records', ['controller'=>'events', 'action'=>'qualifyingrecords']);?></li>
          <li class="active"><?php echo h($event->event_name); ?></li>
      </ol>
    </section>

    <section class="content">
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title"><?php echo h($event->event_name); ?> (<?php echo h($event->event_id_number); ?>)</h3>
            </div>
            <div class="ersu_message"><?php echo $this->Flash->render() ?></div>
            <?php echo $this->Form->create(null, ['id'=>'adminForm']); ?>
                <div class="form-horizontal">
                    <div class="box-body">
                        <p class="col-sm-offset-2 col-sm-10">Saving updates this global event and every existing convention season that includes it.</p>
                        <?php if ($event->event_judging_type === 'times'): ?>
                            <div class="form-group">
                                <label class="col-sm-2 control-label" for="qualifying_time_score">Qualifying Time</label>
                                <div class="col-sm-10">
                                    <input class="form-control" type="text" name="qualifying_time_score" id="qualifying_time_score" value="<?php echo h($formattedTime); ?>" placeholder="HH:MM:SS.milliseconds">
                                    <small>Please enter time in format HH:MM:SS or HH:MM:SS.milliseconds.</small>
                                </div>
                            </div>
                        <?php elseif ($event->event_judging_type === 'distances'): ?>
                            <div class="form-group">
                                <label class="col-sm-2 control-label" for="qualifying_distance">Qualifying Distance</label>
                                <div class="col-sm-10">
                                    <input class="form-control" type="number" step="any" name="qualifying_distance" id="qualifying_distance" value="<?php echo h((string)($event->qualifying_distance ?? '')); ?>" placeholder="Distance in meters">
                                    <small>Please enter distance in meters.</small>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="form-group">
                                <label class="col-sm-2 control-label" for="qualifying_score">Qualifying Score</label>
                                <div class="col-sm-10">
                                    <input class="form-control" type="number" step="1" name="qualifying_score" id="qualifying_score" value="<?php echo h((string)($event->qualifying_score ?? '')); ?>">
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="col-sm-2 control-label" for="current_record">Current Record</label>
                            <div class="col-sm-10">
                                <input class="form-control" type="text" name="current_record" id="current_record" maxlength="255" value="<?php echo h((string)($event->current_record ?? '')); ?>" placeholder="Enter current record">
                            </div>
                        </div>

                        <div class="box-footer">
                            <label class="col-sm-2 control-label">&nbsp;</label>
                            <?php echo $this->Form->button('Save', ['type'=>'submit', 'class'=>'btn btn-info']); ?>
                            <?php echo $this->Html->link('Cancel', ['controller'=>'events', 'action'=>'qualifyingrecords'], ['class'=>'btn btn-default']); ?>
                        </div>
                    </div>
                </div>
            <?php echo $this->Form->end(); ?>
        </div>
    </section>
</div>
