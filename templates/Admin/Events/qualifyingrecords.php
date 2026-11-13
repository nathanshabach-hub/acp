<div class="content-wrapper">
    <section class="content-header">
      <h1>Qualifying Times &amp; Records</h1>
      <ol class="breadcrumb">
          <li><?php echo $this->Html->link('<i class="fa fa-dashboard"></i> <span>Dashboard</span> ', ['controller'=>'admins', 'action'=>'dashboard'], ['escape'=>false]);?></li>
          <li><?php echo $this->Html->link('Manage Global Events', ['controller'=>'events', 'action'=>'index']);?></li>
          <li class="active">Qualifying Times &amp; Records</li>
      </ol>
    </section>

    <section class="content">
        <div class="box box-info">
            <div class="ersu_message"><?php echo $this->Flash->render() ?></div>
            <div class="box-body">
                <p>Changes apply to this event in every convention season and are copied to future seasons when the event is added.</p>
                <div class="tbl-resp-listing">
                    <table class="table table-bordered table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>Event #</th>
                                <th>Event Name</th>
                                <th>Judging Type</th>
                                <th>Qualifying Value</th>
                                <th>Current Record</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                                <?php
                                $qualifyingValue = match ($event->event_judging_type) {
                                    'times' => $event->qualifying_time_score,
                                    'distances' => $event->qualifying_distance,
                                    'scores' => $event->qualifying_score,
                                };
                                if (is_object($qualifyingValue) && method_exists($qualifyingValue, 'format')) {
                                    $qualifyingValue = $qualifyingValue->format('H:i:s.u');
                                    if (str_contains($qualifyingValue, '.')) {
                                        [$wholeTime, $fraction] = explode('.', $qualifyingValue, 2);
                                        $qualifyingValue = $wholeTime.'.'.rtrim($fraction, '0');
                                        $qualifyingValue = rtrim($qualifyingValue, '.');
                                    }
                                }
                                ?>
                                <tr>
                                    <td><?php echo h($event->event_id_number); ?></td>
                                    <td><?php echo h($event->event_name); ?></td>
                                    <td><?php echo h(ucfirst($event->event_judging_type)); ?></td>
                                    <td><?php echo ($qualifyingValue !== null && $qualifyingValue !== '') ? h((string)$qualifyingValue) : '<span class="text-muted">&mdash;</span>'; ?></td>
                                    <td><?php echo ($event->current_record !== null && $event->current_record !== '') ? h($event->current_record) : '<span class="text-muted">&mdash;</span>'; ?></td>
                                    <td><?php echo $this->Html->link('<i class="fa fa-pencil"></i> Edit', ['controller'=>'events', 'action'=>'qualifyingdata', $event->slug], ['escape'=>false, 'class'=>'btn btn-primary btn-xs']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="text-right">
                    <?php
                    echo $this->Paginator->counter(['format' => 'Page {{page}} of {{pages}}']);
                    echo ' &nbsp; '.$this->Paginator->prev('« Prev');
                    echo ' '.$this->Paginator->numbers();
                    echo ' '.$this->Paginator->next('Next »');
                    ?>
                </div>
            </div>
        </div>
    </section>
</div>
