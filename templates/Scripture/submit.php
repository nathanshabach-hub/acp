<div class="container-fluid p-0">
    <div class="row">
        <?= $this->element('user_left_menu') ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="ersu_message"><?= $this->Flash->render() ?></div>
            <div class="portal-page-heading"><h1>Submit Scripture</h1></div>
            <?= $this->element('scripture_submission_form', ['award' => $award, 'books' => $books, 'context' => $context, 'inModal' => false]) ?>
        </main>
    </div>
</div>
