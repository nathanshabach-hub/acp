<?php
$priorityGroups = ['minor' => [], 'major' => [], 'extreme' => []];
foreach ($result['groups'] ?? [] as $group) {
    $priorityGroups[$group['priority']][] = $group;
}
?>
<style>
    .portal-log-columns {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        margin: 16px 0;
    }
    .portal-log-column { min-width: 0; border-top: 4px solid; padding: 12px; background: #f7f8fa; }
    .portal-log-column h3 { margin: 0 0 16px; font-size: 20px; }
    .portal-log-column-minor { border-color: #d3a600; }
    .portal-log-column-major { border-color: #db7a12; }
    .portal-log-column-extreme { border-color: #bd3030; }
    .portal-log-column pre { white-space: pre-wrap; overflow-wrap: anywhere; }
    @media (max-width: 991px) {
        .portal-log-columns { grid-template-columns: 1fr; }
    }
    .portal-log-details > summary {
        display: list-item;
        list-style-position: inside;
        cursor: pointer;
        font-weight: 600;
    }
</style>
<div class="content-wrapper">
    <section class="content-header"><h1>Error Logs</h1></section>
    <section class="content">
        <div class="box box-danger">
            <div class="box-header with-border">
                <h2 class="box-title">Portal error logs</h2>
            </div>
            <div class="box-body">
                <p>Developer access only. Includes current and rotated portal error/CLI logs, not cPanel server logs. Events are shown newest first, with matching lines in their original order inside each event.</p>
                <p>Status labels describe recorded events, not whether an issue is still occurring. Stack-trace lines inherit their event's status, even when searching or paging. Unknown errors require manual investigation. No automatic repairs are currently available.</p>
                <?php echo $this->Html->link('Back to dashboard', ['action' => 'dashboard']); ?>
                <?php if ($files) { ?>
                    <div style="margin:15px 0;">
                        <?= $this->Form->postLink(
                            'Delete all error logs',
                            ['action' => 'deleteerrorlogs'],
                            [
                                'class' => 'btn btn-danger',
                                'confirm' => 'Permanently delete all entries in every current and rotated portal error/CLI log? This cannot be undone.',
                            ]
                        ) ?>
                        <p><small>Clears all portal error/CLI log contents, regardless of the current search. Log files remain available for new errors. Other logs and cPanel server logs are not changed.</small></p>
                    </div>
                <?php } ?>
                <?php if (!$files) { ?>
                    <p>No portal error log files are available.</p>
                <?php } else { ?>
                    <form method="get">
                        <div class="form-group">
                            <label for="log-file">Log file</label>
                            <select id="log-file" name="file" class="form-control">
                                <?php foreach ($files as $file) { ?>
                                    <option value="<?php echo h($file); ?>"<?php echo $file === $selectedFile ? ' selected' : ''; ?>><?php echo h($file); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="log-search">Search log lines</label>
                            <input id="log-search" name="search" type="search" maxlength="200" class="form-control" value="<?php echo h($search); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary">Search / Refresh</button>
                    </form>
                    <p><?php echo (int)$result['total']; ?> matching log lines (not separate errors). Page <?php echo (int)$result['page']; ?> of <?php echo (int)$result['pages']; ?>. Details include only matching lines on this page.</p>
                    <?php if (!$result['rows']) { ?><p>No matching log lines.</p><?php } ?>
                    <p><small>Priority is based on the recorded severity, not confirmed current impact. Minor includes warnings and recognised diagnostics/safety blocks; Major includes errors and unrecognised entries; Extreme includes critical, alert and emergency events. Counts below cover matching events on this page only.</small></p>
                    <div class="portal-log-columns">
                    <?php foreach ($priorityGroups as $priority => $groups) { ?>
                    <section class="portal-log-column portal-log-column-<?= h($priority) ?>" aria-labelledby="log-priority-<?= h($priority) ?>">
                        <h3 id="log-priority-<?= h($priority) ?>"><?= h(ucfirst($priority)) ?> <span class="badge"><?= count($groups) ?></span></h3>
                        <?php if (!$groups) { ?><p>No matching <?= h($priority) ?> events on this page.</p><?php } ?>
                    <?php foreach ($groups as $group) { ?>
                        <article class="panel panel-default">
                            <div class="panel-body">
                                <span class="label label-<?php echo h($group['classification']['style']); ?>"><?php echo h($group['classification']['label']); ?></span>
                                <pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?php echo h($group['header']); ?></pre>
                                <p><?php echo h($group['classification']['guidance']); ?></p>
                                <details class="portal-log-details">
                                    <summary>Show matching log lines / stack trace</summary>
                                    <pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?php
                                        foreach ($group['rows'] as $row) {
                                            echo 'Line ' . (int)$row['number'] . ': ' . h($row['text']) . "\n";
                                        }
                                    ?></pre>
                                </details>
                            </div>
                        </article>
                    <?php } ?>
                    </section>
                    <?php } ?>
                    </div>
                    <?php
                    $query = ['file' => $selectedFile, 'search' => $search];
                    if ($result['page'] > 1) {
                        echo $this->Html->link('Newer lines', ['action' => 'errorlogs', '?' => $query + ['page' => $result['page'] - 1]], ['class' => 'btn btn-default']);
                    }
                    if ($result['page'] < $result['pages']) {
                        echo $this->Html->link('Older lines', ['action' => 'errorlogs', '?' => $query + ['page' => $result['page'] + 1]], ['class' => 'btn btn-default']);
                    }
                    ?>
                <?php } ?>
            </div>
        </div>
    </section>
</div>
