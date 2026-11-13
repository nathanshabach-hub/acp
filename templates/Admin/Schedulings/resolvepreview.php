<div class="content-wrapper admin-sched-category-page">
    <section class="content-header">
      <h1>
        Conflict Repair Preview - [Convention - <?php echo h($conventionSD->Conventions['name'] ?? ''); ?>]&nbsp;&nbsp;&nbsp;&nbsp;
        [Season Year - <?php echo h($conventionSD->season_year); ?>]
      </h1>
      <ol class="breadcrumb">
          <li><?php echo $this->Html->link('<i class="fa fa-dashboard"></i> <span>Dashboard</span> ', ['controller'=>'admins', 'action'=>'dashboard'], ['escape'=>false]);?></li>
          <li><?php echo $this->Html->link('<i class="fa fa-bars"></i> Conventions ', ['controller'=>'conventions', 'action'=>'index'], ['escape'=>false]);?></li>
          <li><?php echo $this->Html->link('<i class="fa fa-bars"></i> Seasons ', ['controller'=>'conventions', 'action'=>'seasons',$convention_slug], ['escape'=>false]);?></li>
          <li><?php echo $this->Html->link('<i class="fa fa-bars"></i> Schedule Category ', ['controller'=>'schedulings', 'action'=>'schedulecategory', $convention_season_slug], ['escape'=>false]);?></li>
          <li class="active">Conflict Repair Preview</li>
      </ol>
    </section>

    <section class="content">
        <div class="box box-info admin-data-box">
            <div class="box-header with-border">
                <h3 class="box-title">Dry run preview</h3>
            </div>
            <div class="ersu_message"><?php echo $this->Flash->render() ?></div>
            <div class="box-body">
                <p>This preview does not change the timetable. Review proposed moves and unresolved bookings, then confirm apply.</p>
                <table class="table table-bordered">
                    <tr><th>Issues before</th><td><?php echo (int)($plan['before']['total_issues'] ?? 0); ?></td></tr>
                    <tr><th>Issues after (preview)</th><td><?php echo (int)($plan['after']['total_issues'] ?? 0); ?></td></tr>
                    <tr><th>Proposed moves</th><td><?php echo count($plan['moves'] ?? []); ?></td></tr>
                    <tr><th>Unresolved</th><td><?php echo count($plan['unresolved'] ?? []); ?></td></tr>
                </table>

                <?php echo $this->Form->create(null, ['url' => ['action' => 'resolveapply', $convention_season_slug]]); ?>
                <?php echo $this->Form->hidden('token', ['value' => $preview['token'] ?? '']); ?>
                <?php echo $this->Form->hidden('_repair_token', ['value' => $repairFormToken ?? '']); ?>
                <div class="checkbox">
                    <label>
                        <input type="checkbox" name="confirm_apply" value="1"> I reviewed this preview and want to apply these changes.
                    </label>
                </div>
                <button class="btn btn-warning" type="submit">Apply conflict repair</button>
                <?php echo $this->Html->link('Refresh Preview', ['action' => 'resolvepreview', $convention_season_slug], ['class' => 'btn btn-default']); ?>
                <?php echo $this->Form->end(); ?>
            </div>
        </div>

        <?php if (!empty($plan['moves'])): ?>
        <div class="box box-success admin-data-box">
            <div class="box-header with-border"><h3 class="box-title">Proposed moves</h3></div>
            <div class="box-body table-responsive">
                <table class="table table-bordered">
                    <tr>
                        <th>Rows</th><th>Booking</th><th>From</th><th>To</th><th>How</th><th>Why</th>
                    </tr>
                    <?php foreach ($plan['moves'] as $move): ?>
                    <tr>
                        <td><?php echo h(implode(', ', $move['rows'] ?? [])); ?></td>
                        <td><?php echo h($move['booking'] ?? ''); ?></td>
                        <td><?php echo h(($move['from']['day'] ?? '').' '.substr((string)($move['from']['start'] ?? ''), 0, 5).' - '.($move['from']['room'] ?? '')); ?></td>
                        <td><?php echo h(($move['to']['day'] ?? '').' '.substr((string)($move['to']['start'] ?? ''), 0, 5).' - '.($move['to']['room'] ?? '')); ?></td>
                        <td><?php echo h($move['how'] ?? ''); ?></td>
                        <td><?php echo h(implode('; ', $move['reasons'] ?? [])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($plan['unresolved'])): ?>
        <div class="box box-danger admin-data-box">
            <div class="box-header with-border"><h3 class="box-title">Unresolved</h3></div>
            <div class="box-body table-responsive">
                <table class="table table-bordered">
                    <tr><th>Rows</th><th>Booking</th><th>Blocked by</th></tr>
                    <?php foreach ($plan['unresolved'] as $row): ?>
                    <tr>
                        <td><?php echo h(implode(', ', $row['rows'] ?? [])); ?></td>
                        <td><?php echo h($row['booking'] ?? ''); ?></td>
                        <td><?php echo h(implode('; ', $row['blocked_by'] ?? [])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($history)): ?>
        <div class="box box-default admin-data-box">
            <div class="box-header with-border"><h3 class="box-title">Recent repair runs</h3></div>
            <div class="box-body table-responsive">
                <table class="table table-bordered">
                    <tr><th>Run</th><th>Created</th><th>Before</th><th>After</th><th>Moves</th><th>Status</th></tr>
                    <?php foreach ($history as $run): ?>
                    <tr>
                        <td><?php echo $this->Html->link(h($run['run_id']), ['action' => 'resolvereport', $convention_season_slug, $run['run_id']]); ?></td>
                        <td><?php echo h($run['created_at']); ?></td>
                        <td><?php echo (int)$run['total_issues_before']; ?></td>
                        <td><?php echo (int)$run['total_issues_after']; ?></td>
                        <td><?php echo (int)$run['moves']; ?></td>
                        <td><?php echo !empty($run['reverted']) ? 'Reverted' : 'Applied'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </section>
</div>
