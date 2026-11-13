<?php if (!empty($combinedTeams)): ?>
<div class="panel-body">
    <h2>Combined team requests</h2>
    <div class="tbl-resp-listing">
        <table class="table table-bordered">
            <thead><tr><th>Convention</th><th>Event</th><th>Schools</th><th>Students</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($combinedTeams as $team): ?>
                <tr>
                    <td><?= h($team['convention_name']) ?></td>
                    <td><?= h($team['event_id_number'] . ' - ' . $team['event_name']) ?></td>
                    <td><?= h($team['source_school'] . ' + ' . $team['partner_school']) ?></td>
                    <td>
                        <?php foreach ($team['members'] as $member): ?>
                            <div><?= h($member['student_name'] . ' (' . $member['school_name'] . ')') ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td><?= h([0 => 'Declined', 1 => 'Approved', 2 => 'Pending'][(int)$team['status']]) ?></td>
                    <td>
                        <?php if (!empty($adminCombinedTeams) && (int)$team['status'] === 2): ?>
                            <?= $this->Form->postLink('Approve entire team', ['prefix' => 'Admin', 'controller' => 'Combinerequests', 'action' => 'decideteam', $team['id'], 'approve'], ['confirm' => 'Approve all selected students and create one shared team submission?', 'class' => 'btn btn-success']) ?>
                            <?= $this->Form->postLink('Decline entire team', ['prefix' => 'Admin', 'controller' => 'Combinerequests', 'action' => 'decideteam', $team['id'], 'decline'], ['confirm' => 'Decline this combined team?', 'class' => 'btn btn-default']) ?>
                        <?php elseif ((int)$team['status'] === 1): ?>
                            <span>One shared submission. Either school can upload from its event registration.</span>
                        <?php else: ?>
                            <span>No action required from the partner school.</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
