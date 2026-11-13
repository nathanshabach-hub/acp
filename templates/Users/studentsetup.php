<section class="py-5" style="background: linear-gradient(135deg, #1c2452 0%, #0f1633 60%, #1a3a5c 100%);">
    <div class="container">
    <div class="bg-white text-dark rounded-3 p-4 p-md-5 mx-auto" style="max-width:640px;">
        <h2 class="text-dark mb-3">Student Password Setup</h2>
        <?= $this->Flash->render() ?>
        <p class="text-dark">Your school must first add you to its Global Students List and give you a Student ID and a single-use setup code. Codes expire after seven days.</p>
        <?= $this->Form->create(null, ['autocomplete' => 'off']) ?>
        <div class="form-group mb-3">
            <label for="student-id">Student ID</label>
            <input id="student-id" name="Student[login_id]" class="form-control" maxlength="6" required autocomplete="username">
        </div>
        <div class="form-group mb-3">
            <label for="setup-code">Single-use setup code</label>
            <input id="setup-code" name="Student[code]" class="form-control" maxlength="16" required autocomplete="off">
        </div>
        <div class="form-group mb-3">
            <label for="setup-password">New password</label>
            <input id="setup-password" name="Student[password]" type="password" class="form-control" minlength="6" required autocomplete="new-password">
        </div>
        <div class="form-group mb-3">
            <label for="setup-confirmation">Confirm password</label>
            <input id="setup-confirmation" name="Student[confirmation]" type="password" class="form-control" minlength="6" required autocomplete="new-password">
        </div>
        <p class="text-dark">Choose at least six characters. Never share your password.</p>
        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-secondary">Save password</button>
            <?= $this->Html->link('Back to login', ['action' => 'login'], ['class' => 'btn btn-light text-dark']) ?>
        </div>
        <?= $this->Form->end() ?>
    </div>
    </div>
</section>
