<?php $schoolStudentAccess = $this->request->getSession()->read('school_student_access'); ?>
<?php if (is_array($schoolStudentAccess)): ?>
    <div class="alert alert-warning m-3 d-flex flex-wrap align-items-center justify-content-between gap-3" role="status">
        <div>
            <strong>Viewing as student: <?= h($schoolStudentAccess['student_name']) ?></strong>
            <div>You are using school access, not the student's password. Changes are saved to this student's account. Password changes are blocked.</div>
        </div>
        <?= $this->Form->create(null, ['url' => ['prefix' => false, 'controller' => 'Users', 'action' => 'returntoschool'], 'class' => 'm-0']) ?>
        <button type="submit" class="btn btn-primary">Return to school</button>
        <?= $this->Form->end() ?>
    </div>
<?php endif; ?>
