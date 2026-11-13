<script type="text/javascript">
	$(document).ready(function () {
		$("#addstudent").validate();
	});
</script>
<style>
	.cr-addteacher-card .student-picker-list {
		border: 1px solid #c7d7e8;
		border-radius: 10px;
		display: grid;
		grid-template-columns: repeat(4, minmax(0, 1fr));
		margin-top: 8px;
		max-height: 280px;
		overflow-y: auto;
	}
	.cr-addteacher-card .student-picker-list .student-picker-item {
		align-items: center;
		cursor: pointer;
		display: flex;
		gap: 10px;
		margin: 0;
		min-width: 0;
		border-bottom: 1px solid #edf2f7;
		padding: 9px 12px;
		width: 100%;
	}
	.cr-addteacher-card .student-picker-item span {
		overflow-wrap: anywhere;
	}
	@media (max-width: 1199px) {
		.cr-addteacher-card .student-picker-list {
			grid-template-columns: repeat(2, minmax(0, 1fr));
		}
	}
	@media (max-width: 575px) {
		.cr-addteacher-card .student-picker-list {
			grid-template-columns: minmax(0, 1fr);
		}
	}
	.cr-addteacher-card .student-picker-list .student-picker-item[hidden] {
		display: none;
	}
	.dashboard-form .form-group .student-picker-item input[type="checkbox"] {
		flex: 0 0 auto;
		height: 18px;
		margin: 0;
		padding: 0 !important;
		width: 18px;
	}
	.cr-addteacher-card .student-picker-status {
		color: #5d7087;
		font-size: 13px;
		margin: 8px 0 0;
	}
	.cr-addteacher-card .student-picker-error {
		color: #a12622;
		margin: 8px 0 0;
	}
	#local-supervisor {
		display: flow-root;
	}
	#local-supervisor[hidden] {
		display: none;
	}
	#local-supervisor .select2-container {
		margin-bottom: 0;
	}
	.dashboard-form .form-group label.cross-school-toggle {
		align-items: center;
		display: flex;
		gap: 10px;
		margin: 12px 0 0;
		width: 100%;
	}
	.cross-school-toggle span {
		flex: 1;
	}
	.dashboard-form .form-group .cross-school-toggle input[type="checkbox"] {
		flex: 0 0 auto;
		height: 18px;
		margin: 0;
		padding: 0 !important;
		width: 18px;
	}
	.cross-school-preview label { display: block; margin-top: 12px; }
	.user-portal .cross-school-preview select.form-control:focus {
		border-color: #c7d7e8;
		box-shadow: none;
		outline: none;
	}
	.user-portal .cross-school-preview select.form-control:focus-visible {
		outline: 2px solid #1f5f8f;
		outline-offset: 2px;
	}
</style>
<div class="container-fluid p-0 cr-addteacher-page">
	<div class="row">
		<?php echo $this->element('user_left_menu'); ?>
		<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 cr-addteacher-main">

			<div class="ersu_message">
				<?php echo $this->Flash->render() ?>
			</div>

			<!-- dashboard-section-3 start-->
			<div class="dashboard-form cr-addteacher-card">
				<h2 class="form-title">Add Student Info</h2>
				<p><strong>Before assigning students to a supervisor:</strong></p>
				<ul>
					<li><strong>Same gender:</strong> Male students must have a male supervisor, and female students must have a female supervisor.</li>
					<li><strong>Maximum 10 students:</strong> Each supervisor can supervise up to 10 students in total for this convention.</li>
					<li><strong>Check profile details:</strong> Gender must be recorded in both the student's and supervisor's profiles before they can be assigned.</li>
				</ul>
				<?php echo $this->Form->create($conventionregistrationstudents, ['id' => 'addstudent', 'type' => 'file', 'class' => ' ']); ?>

				<div class="form-group">
					<label for="student_search">Choose Student(s)</label>
					<div class="input-multiple">
						<div class="cr-addteacher-quick-actions">
							<button type="button" id="select_all_students" class="btn btn-default btn-sm">Select all students</button>
							<button type="button" id="clear_all_students" class="btn btn-default btn-sm">Clear all students</button>
						</div>
						<input type="search" id="student_search" class="form-control" placeholder="Search students by name or gender" autocomplete="off" aria-describedby="student_picker_status">
						<div class="student-picker-list" role="group" aria-label="Students">
							<?php foreach ($studentSchoolDD as $studentId => $studentLabel): ?>
								<label class="student-picker-item">
									<input type="checkbox" name="Conventionregistrationstudents[student_ids][]" value="<?= (int)$studentId ?>">
									<span><?= h($studentLabel) ?></span>
								</label>
							<?php endforeach; ?>
							<?php if (!$studentSchoolDD): ?>
								<p class="student-picker-status p-2">No students are available to add.</p>
							<?php endif; ?>
						</div>
						<p id="student_picker_status" class="student-picker-status" aria-live="polite"><?= count($studentSchoolDD) ?> students available; 0 selected.</p>
						<p id="student_picker_empty" class="student-picker-status" hidden>No students match your search.</p>
						<p id="student_picker_error" class="student-picker-error" role="alert" hidden>Please select at least one student.</p>
						<em>Age is calculated using the convention year and birth year. Tick students to select them. Select all and Clear all apply to the entire list, including students hidden by search.</em>
						<script>
							$(document).ready(function () {
								var items = $('.student-picker-item');
								var search = $('#student_search');
								var error = $('#student_picker_error');
								function updatePicker() {
									var query = search.val().trim().toLowerCase();
									var visible = 0;
									var selected = 0;
									items.each(function () {
										var item = $(this);
										var matches = item.text().toLowerCase().indexOf(query) !== -1;
										item.prop('hidden', !matches);
										if (matches) visible++;
										if (item.find('input').prop('checked')) selected++;
									});
									$('#student_picker_status').text(visible + ' of ' + items.length + ' students shown; ' + selected + ' selected.');
									$('#student_picker_empty').prop('hidden', items.length === 0 || visible !== 0);
									if (selected) error.prop('hidden', true);
								}
								search.on('input', updatePicker);
								items.find('input').on('change', updatePicker);
								$('#select_all_students').on('click', function () {
									items.find('input').prop('checked', true);
									updatePicker();
								});
								$('#clear_all_students').on('click', function () {
									items.find('input').prop('checked', false);
									updatePicker();
								});
								$('#addstudent').on('submit', function (event) {
									if (items.find('input:checked').length === 0) {
										event.preventDefault();
										error.prop('hidden', false);
										search.trigger('focus');
									}
								});
							});
						</script>
					</div>
				</div>

				<div class="form-group">
					<label for="name">Choose Supervisor</label>
					<div class="input">
						<div id="local-supervisor">
							<?php echo $this->Form->select('Conventionregistrationstudents.teacher_parent_id', $teacherDropDownData, ['id' => 'teacher_parent_id', 'label' => false, 'div' => false, 'class' => 'form-control required', 'autocomplete' => 'off', 'empty' => 'Choose']); ?>
						</div>
						<label class="cross-school-toggle" for="cross_school_preview">
							<input type="checkbox" name="cross_school_preview" id="cross_school_preview" value="1">
							<span>Assign students to another school's sponsor</span>
						</label>
						<div id="cross_school_options" class="cross-school-preview" hidden>
							<p class="student-picker-status"><strong>Pending request:</strong> Students remain registered with your school and have no accepted sponsor. School and supervisor approval are not yet available. The supervisor's capacity is not reserved and must be checked again before an assignment can be accepted.</p>
							<label for="cross_school">Choose school</label>
							<select id="cross_school" name="cross_school_id" class="form-control">
								<option value="">Choose a school</option>
								<?php foreach ($otherSchools as $schoolId => $school): ?>
									<option value="<?= (int)$schoolId ?>"><?= h($school['name']) ?></option>
								<?php endforeach; ?>
							</select>
							<label for="cross_supervisor">Choose supervisor</label>
							<select id="cross_supervisor" name="cross_supervisor_id" class="form-control" disabled>
								<option value="">Choose a school first</option>
							</select>
							<p id="cross_school_message" class="student-picker-status" aria-live="polite">
								<?= $otherSchools ? 'Select a school to see its registered supervisors.' : 'No other active schools are registered for this convention.' ?>
							</p>
						</div>
						<script>
							$(document).ready(function () {
								$('#teacher_parent_id').select2();
								var schools = <?= json_encode($otherSchools, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
								var toggle = $('#cross_school_preview');
								var options = $('#cross_school_options');
								var school = $('#cross_school');
								var supervisor = $('#cross_supervisor');
								function setMode() {
									var preview = toggle.prop('checked');
									options.prop('hidden', !preview);
									$('#local-supervisor').prop('hidden', preview);
									$('#teacher_parent_id').prop('disabled', preview);
								}
								toggle.on('change', setMode);
								school.on('change', function () {
									var selected = schools[school.val()];
									supervisor.empty().append($('<option>').val('').text(selected ? 'Choose a supervisor' : 'Choose a school first'));
									if (selected) {
										Object.keys(selected.supervisors).forEach(function (id) {
											supervisor.append($('<option>').val(id).text(selected.supervisors[id]));
										});
									}
									var count = selected ? Object.keys(selected.supervisors).length : 0;
									supervisor.prop('disabled', count === 0);
									$('#cross_school_message').text(selected && !count ? 'This school has no active registered supervisors.' : 'The request will remain pending until secure approvals are available.');
								});
								setMode();
							});
						</script>
					</div>
				</div>

				<div class="form-group form-btns">
					<label></label>
					<button type="submit" class="btn btn-secondary">Save</button>
					<!--<button type="button" class="btn btn-secondary">Cancel</button>-->
					<?php echo $this->Html->link('Cancel', ['controller' => 'conventionregistrations', 'action' => 'students'], ['class' => 'btn btn-secondary']); ?>
				</div>
				<?php echo $this->Form->end(); ?>
			</div>
			<!-- dashboard-section-3 end-->

		</main>
	</div>
</div>