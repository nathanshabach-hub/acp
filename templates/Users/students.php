<?php echo $this->Html->script('jquery-2.1.0.min.js'); ?>
<?php echo $this->Html->script('ajax-pagging.js'); ?>
<script>
document.addEventListener('change', function (event) {
    var form = event.target.closest('.student-archive-form');
    if (!form) return;
    var boxes = Array.from(form.querySelectorAll('input[name="student_ids[]"]'));
    var selectAll = form.querySelector('.student-select-all');
    if (event.target === selectAll) {
        boxes.forEach(function (box) { box.checked = selectAll.checked; });
    }
    selectAll.checked = boxes.length > 0 && boxes.every(function (box) { return box.checked; });
    selectAll.indeterminate = boxes.some(function (box) { return box.checked; }) && !selectAll.checked;
});
document.addEventListener('submit', function (event) {
    if (!event.target.matches('.student-archive-form')) return;
    if (event.submitter && event.submitter.name === 'student_slug') {
        if (!confirm('Open this student portal as your school? Password changes will be blocked. Use Return to school when finished.')) {
            event.preventDefault();
        }
        return;
    }
    var count = event.target.querySelectorAll('input[name="student_ids[]"]:checked').length;
    var setupCodes = event.submitter && event.submitter.value === 'selected';
    if (!count) {
        event.preventDefault();
        alert('Select at least one student.');
    } else if (!setupCodes && !confirm('Archive ' + count + ' selected student(s)?')) {
        event.preventDefault();
    }
});
</script>
<div class="container-fluid p-0">
	<div class="row">
		<?php echo $this->element('user_left_menu'); ?>
		<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">

			<div class="ersu_message">
				<?php echo $this->Flash->render() ?>
			</div>
			<div class="teachers-top-heading">
				<span>Students List</span>
                    <?php if ($this->request->getSession()->read('user_type') === 'School'): ?>
					    <?php echo $this->Html->link('+ Add New', ['controller' => 'users', 'action' => 'addstudent'], ['escape' => false, 'class' => 'btn btn-primary']); ?>
                    <?php endif; ?>
			</div>
			<!-- dashboard-section-2 start-->

			<div class="m_content" id="listID">
				<?php echo $this->element("Users/students"); ?>
			</div>


			<!-- dashboard-section-2 end-->

		</main>
	</div>
</div>