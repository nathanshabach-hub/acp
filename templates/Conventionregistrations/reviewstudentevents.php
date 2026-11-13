<div class="container-fluid p-0">
    <div class="row">
        <?= $this->element('user_left_menu') ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="dashboard-form">
                <h2>Review Student Event Choices</h2>
                <?= $this->Flash->render() ?>
                <p><strong><?= h(trim($entry->Students->first_name . ' ' . $entry->Students->last_name)) ?></strong></p>
                <div class="alert alert-warning">Pending school review. These choices are not registered and the student cannot upload submissions until you approve.</div>
                <table class="table table-bordered">
                    <thead><tr><th>Event number</th><th>Event</th></tr></thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr><td><?= h($event->event_id_number) ?></td><td><?= h($event->event_name) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p>Approval registers these events, unlocks student uploads, and locks the choices for the student. Only the school can change them afterwards.</p>
                <?= $this->Form->create(null) ?>
                <input type="hidden" name="revision" value="<?= (int)$review['revision'] ?>">
                <div class="form-check mb-3">
                    <input class="form-check-input" id="confirm-event-approval" name="confirm_approve" type="checkbox" value="1" required>
                    <label class="form-check-label" for="confirm-event-approval">I have reviewed and approve this student's event choices.</label>
                </div>
                <button class="btn btn-primary" type="submit">Approve event choices</button>
                <?= $this->Html->link('Edit choices instead', ['action' => 'managestudentevents', $entry->slug], ['class' => 'btn btn-secondary']) ?>
                <?= $this->Html->link('Back', ['action' => 'studentevents'], ['class' => 'btn btn-secondary']) ?>
                <?= $this->Form->end() ?>
            </div>
        </main>
    </div>
</div>
