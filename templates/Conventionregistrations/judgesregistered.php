<div class="container-fluid p-0">
    <div class="row">
        <?= $this->element('user_left_menu') ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="ersu_message"><?= $this->Flash->render() ?></div>
            <div class="teachers-top-heading"><span>Judges Registered</span></div>
            <p>Judges from your school registered for the selected convention.</p>
            <div class="panel-body">
                <?php if ($judges->isEmpty()): ?>
                    <p>No judges from your school are registered for this convention.</p>
                <?php else: ?>
                    <div class="tbl-resp-listing">
                        <table class="table table-bordered table-striped">
                            <thead><tr><th>Judge</th></tr></thead>
                            <tbody>
                                <?php foreach ($judges as $judge): ?>
                                    <tr><td><?= h(trim(implode(' ', array_filter([
                                        $judge->first_name, $judge->middle_name, $judge->last_name,
                                    ], static fn($part) => $part !== null && $part !== '')))) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>
