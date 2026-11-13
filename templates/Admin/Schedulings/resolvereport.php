<div class="content-wrapper admin-sched-category-page">
    <section class="content-header">
      <h1>Conflict Repair Report</h1>
      <ol class="breadcrumb">
          <li><?php echo $this->Html->link('<i class="fa fa-dashboard"></i> <span>Dashboard</span> ', ['controller'=>'admins', 'action'=>'dashboard'], ['escape'=>false]);?></li>
          <li><?php echo $this->Html->link('<i class="fa fa-bars"></i> Schedule Category ', ['controller'=>'schedulings', 'action'=>'schedulecategory', $convention_season_slug], ['escape'=>false]);?></li>
          <li class="active">Conflict Repair Report</li>
      </ol>
    </section>

    <section class="content">
        <div class="box box-info admin-data-box">
            <div class="box-header with-border"><h3 class="box-title">Run history</h3></div>
            <div class="ersu_message"><?php echo $this->Flash->render() ?></div>
            <div class="box-body table-responsive">
                <table class="table table-bordered">
                    <tr><th>Run</th><th>Created</th><th>Before</th><th>After</th><th>Moves</th><th>Status</th><th>Action</th></tr>
                    <?php foreach (($history ?? []) as $item): ?>
                    <tr>
                        <td><?php echo $this->Html->link(h($item['run_id']), ['action' => 'resolvereport', $convention_season_slug, $item['run_id']]); ?></td>
                        <td><?php echo h($item['created_at']); ?></td>
                        <td><?php echo (int)$item['total_issues_before']; ?></td>
                        <td><?php echo (int)$item['total_issues_after']; ?></td>
                        <td><?php echo (int)$item['moves']; ?></td>
                        <td><?php echo !empty($item['reverted']) ? 'Reverted' : 'Applied'; ?></td>
                        <td>
                            <?php if (empty($item['reverted'])): ?>
                                <?php echo $this->Form->create(null, ['url' => ['action' => 'resolverevert', $convention_season_slug, $item['run_id']]]); ?>
                                <?php echo $this->Form->hidden('_repair_token', ['value' => $repairFormToken ?? '']); ?>
                                <button class="btn btn-xs btn-danger" type="submit" onclick="return confirm('Revert this conflict repair run?');">Revert</button>
                                <?php echo $this->Form->end(); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

        <?php if (!empty($run)): ?>
        <div class="box box-success admin-data-box">
            <div class="box-header with-border"><h3 class="box-title">Selected run: <?php echo h($runId); ?></h3></div>
            <div class="box-body">
                <table class="table table-bordered">
                    <tr><th>Issues before</th><td><?php echo (int)($run['before']['total_issues'] ?? 0); ?></td></tr>
                    <tr><th>Issues after</th><td><?php echo (int)($run['after']['total_issues'] ?? 0); ?></td></tr>
                    <tr><th>Moves</th><td><?php echo count($run['moves'] ?? []); ?></td></tr>
                    <tr><th>Unresolved</th><td><?php echo count($run['unresolved'] ?? []); ?></td></tr>
                    <?php if (!empty($run['reverted'])): ?>
                    <tr><th>Reverted</th><td><?php echo h($run['reverted_at'] ?? ''); ?> &mdash; restored <?php echo count($run['revert']['restored'] ?? []); ?>, skipped <?php echo count($run['revert']['skipped'] ?? []); ?></td></tr>
                    <?php endif; ?>
                </table>

                <h4>Validation after apply</h4>
                <table class="table table-bordered table-condensed">
                    <tr><th>Check</th><th>Before</th><th>After</th></tr>
                    <?php foreach ($labels as $type => $label): ?>
                    <tr>
                        <td><?php echo h($label); ?></td>
                        <td><?php echo (int)($run['before']['counts'][$type] ?? 0); ?></td>
                        <td><?php echo (int)($run['after']['counts'][$type] ?? 0); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <?php if (!empty($run['moves'])): ?>
                <h4>Moved bookings</h4>
                <table class="table table-bordered table-condensed">
                    <tr><th>Rows</th><th>Booking</th><th>From</th><th>To</th><th>Reason</th></tr>
                    <?php foreach ($run['moves'] as $move): ?>
                    <tr>
                        <td><?php echo h(implode(', ', $move['rows'] ?? [])); ?></td>
                        <td><?php echo h($move['booking'] ?? ''); ?></td>
                        <td><?php echo h(($move['from']['day'] ?? '').' '.substr((string)($move['from']['start'] ?? ''), 0, 5).' - '.($move['from']['room'] ?? '')); ?></td>
                        <td><?php echo h(($move['to']['day'] ?? '').' '.substr((string)($move['to']['start'] ?? ''), 0, 5).' - '.($move['to']['room'] ?? '')); ?></td>
                        <td><?php echo h(implode('; ', $move['reasons'] ?? [])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php endif; ?>

                <?php if (!empty($run['revert']['skipped'])): ?>
                <h4>Not reverted (changed after the repair)</h4>
                <ul>
                    <?php foreach ($run['revert']['skipped'] as $skip): ?>
                    <li>Row <?php echo (int)$skip['row_id']; ?>: <?php echo h($skip['reason']); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </section>
</div>
