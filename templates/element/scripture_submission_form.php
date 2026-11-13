<?php
$awardId = (int)$award['id'];
$fileId = 'scripture-document-' . $awardId;
$bookId = 'scripture-books-' . $awardId;
$searchId = 'scripture-search-' . $awardId;
$statusId = 'scripture-status-' . $awardId;
$preselectBooks = in_array(strtolower(trim($award['event_name'])), [
    'golden apple award open', 'golden lamb award open', 'golden harp award open',
    'christian soldier award open', 'christian worker award open',
], true);
?>
<section class="dashboard-form cr-es-submit-card cr-scripture-upload">
    <h2 class="form-title">Submit New Event</h2>
    <?= $this->Form->create(null, ['type' => 'file', 'url' => ['controller' => 'Scripture', 'action' => 'submit', $awardId]]) ?>
    <?= $this->Form->hidden('conventionseason_id', ['value' => $context['conventionseason_id']]) ?>
    <div class="form-group cr-es-submit-row">
        <label>Event</label>
        <div class="input-multiple cr-es-readonly"><?= h($award['display_name']) ?></div>
    </div>
    <div class="form-group cr-es-submit-row">
        <label>Participant</label>
        <div class="input-multiple cr-es-readonly"><?= h($context['participant_name']) ?></div>
    </div>
    <div class="form-group cr-es-submit-row">
        <label for="<?= h($fileId) ?>">Upload File</label>
        <div class="input-multiple">
            <input id="<?= h($fileId) ?>" class="form-control" type="file" name="document" required accept=".mp3,.mp4,.mpeg,.mov,.avi,.amr,.ape,.nmf,.3gp,.pdf,.doc,.docx,.png,.jpg,.jpeg,.ppt,.pptx">
            <span class="help_text cr-es-help">mp3, mp4, mpeg, mov, avi, amr, ape, nmf, 3gp, pdf, doc, docx, png, jpg, jpeg, ppt and pptx files allowed.</span>
        </div>
    </div>
    <div class="form-group cr-es-submit-row">
        <label for="<?= h($searchId) ?>">Choose Book(s)</label>
        <div class="input-multiple">
            <input id="<?= h($searchId) ?>" type="search" class="form-control cr-scripture-book-search" placeholder="Search books" autocomplete="off" aria-controls="<?= h($bookId) ?>" aria-describedby="<?= h($statusId) ?>">
            <div id="<?= h($bookId) ?>" class="cr-scripture-book-list" role="group" aria-label="Books">
                <?php foreach ($books as $book): ?>
                    <label class="cr-scripture-book-item">
                        <input type="checkbox" name="book_ids[]" value="<?= (int)$book['id'] ?>"<?= $preselectBooks ? ' checked' : '' ?>>
                        <span><?= h($book['book_name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p id="<?= h($statusId) ?>" class="cr-scripture-book-status" aria-live="polite"><?= count($books) ?> books available; <?= $preselectBooks ? count($books) : 0 ?> selected.</p>
            <p class="cr-scripture-book-status cr-scripture-book-empty" hidden>No books match your search.</p>
            <span class="cr-scripture-book-error" role="alert" hidden>Please select at least one book.</span>
            <?php if (!$books): ?>
                <span class="cr-scripture-book-error" role="alert">No books are configured for this award. Please contact support.</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="form-group form-btns cr-es-submit-actions">
        <button class="btn btn-secondary cr-es-submit-btn" type="submit"<?= !$books ? ' disabled' : '' ?>>Submit Event</button>
        <?php if (!$inModal): ?>
            <?= $this->Html->link('Back', ['controller' => 'Scripture', 'action' => 'index'], ['class' => 'btn btn-secondary']) ?>
        <?php endif; ?>
    </div>
    <?= $this->Form->end() ?>
</section>
<script>
    (function () {
        var list = document.getElementById(<?= json_encode($bookId) ?>);
        var search = document.getElementById(<?= json_encode($searchId) ?>);
        var status = document.getElementById(<?= json_encode($statusId) ?>);
        var items = Array.from(list.querySelectorAll('.cr-scripture-book-item'));
        var error = list.parentElement.querySelector('.cr-scripture-book-error[hidden]');
        var empty = list.parentElement.querySelector('.cr-scripture-book-empty');
        function updateBooks() {
            var query = search.value.trim().toLowerCase();
            var visible = 0;
            var selected = 0;
            items.forEach(function (item) {
                var match = item.textContent.toLowerCase().indexOf(query) !== -1;
                item.hidden = !match;
                if (match) visible++;
                if (item.querySelector('input').checked) selected++;
            });
            status.textContent = visible + ' of ' + items.length + ' books shown; ' + selected + ' selected.';
            empty.hidden = items.length === 0 || visible !== 0;
            if (selected) {
                error.hidden = true;
            }
        }
        search.addEventListener('input', updateBooks);
        list.addEventListener('change', updateBooks);
        search.form.addEventListener('submit', function (event) {
            if (!list.querySelector('input:checked')) {
                event.preventDefault();
                error.hidden = false;
                search.focus();
            }
        });
    })();
</script>
