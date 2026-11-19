<div class="container-fluid p-0 cr-permforms-page">
	<div class="row">
		<?php echo $this->element('user_left_menu'); ?>
		<main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 cr-permforms-main">

			<div class="ersu_message">
				<?php echo $this->Flash->render() ?>
			</div>

			<div class="cr-permforms-hero">
				<div>
					<span class="cr-permforms-kicker">Documentation</span>
					<h1 class="cr-permforms-title">Permission Forms</h1>
					<span class="cr-permforms-subtitle">Access and print the required convention permission documents for students, schools, and adults.</span>
				</div>
			</div>

			<div class="cr-permforms-grid">
				<div class="cr-permforms-card">
					<div class="cr-permforms-card-head">
						<h2>HSSP Students Form</h2>
					</div>
					<p>Permission for Participation Form for HSSP students and parents/guardians.</p>
					<div class="cr-permforms-actions">
						<?php echo $this->Html->link('<i class="fa fa-file-text"></i> Open Fillable Form', ['controller' => 'conventionregistrations', 'action' => 'permissionsformhssp'], ['escape' => false, 'class' => 'btn btn-outline-primary btn-sm js-permform-popup', 'data-title' => 'HSSP Students Form']); ?>
					</div>
				</div>

				<div class="cr-permforms-card">
					<div class="cr-permforms-card-head">
						<h2>Schools Form</h2>
					</div>
					<p>School-level participation permission form covering all attending students.</p>
					<div class="cr-permforms-actions">
						<?php echo $this->Html->link('<i class="fa fa-file-text"></i> Open Fillable Form', ['controller' => 'conventionregistrations', 'action' => 'permissionsformschools'], ['escape' => false, 'class' => 'btn btn-outline-primary btn-sm js-permform-popup', 'data-title' => 'Schools Form']); ?>
					</div>
				</div>

				<div class="cr-permforms-card">
					<div class="cr-permforms-card-head">
						<h2>Adult Registration Form</h2>
					</div>
					<p>Preview registered supervisors and their assigned students. Approval and submission are not yet available.</p>
					<div class="cr-permforms-actions">
						<?php echo $this->Html->link('<i class="fa fa-file-text"></i> Open Preview', ['controller' => 'conventionregistrations', 'action' => 'permissionsformadult'], ['escape' => false, 'class' => 'btn btn-outline-primary btn-sm js-permform-popup', 'data-title' => 'Adult Registration Form']); ?>
					</div>
				</div>
			</div>

			<section class="cr-permforms-upload">
				<div>
					<h2>Submit a Signed Permission Form</h2>
					<p>Upload the completed and signed form as a PDF. The Events team will review it for this convention registration.</p>
				</div>
				<?php echo $this->Form->create(null, [
					'type' => 'file',
					'url' => ['controller' => 'conventionregistrations', 'action' => 'permissionsformsubmit'],
				]); ?>
					<div class="cr-permforms-upload-fields">
						<?php echo $this->Form->select('form_type', [
							'hssp_students' => 'HSSP Students Form',
							'schools' => 'Schools Form',
							'adult_registration' => 'Adult Registration Form',
						], [
							'empty' => 'Choose form type',
							'class' => 'form-control',
							'required' => true,
							'label' => false,
						]); ?>
						<?php echo $this->Form->file('permission_form', [
							'class' => 'form-control',
							'accept' => 'application/pdf,.pdf',
							'required' => true,
						]); ?>
						<?php echo $this->Form->button('<i class="fa fa-upload"></i> Submit Permission Form', [
							'type' => 'submit',
							'class' => 'btn btn-primary',
							'escapeTitle' => false,
						]); ?>
					</div>
				<?php echo $this->Form->end(); ?>

				<?php if (!empty($permissionFormSubmissions)): ?>
					<ul class="cr-permforms-history">
						<?php foreach ($permissionFormSubmissions as $submission): ?>
							<li>
								<strong><?php echo h($submission['form_label'] ?? 'Permission Form'); ?></strong>
								<span>Submitted <?php echo h(date('j M Y, g:i a', strtotime((string)$submission['submitted_at']))); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
		</main>
	</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
	var links = document.querySelectorAll('.js-permform-popup');
	links.forEach(function (link) {
		link.addEventListener('click', function (event) {
			event.preventDefault();
			var href = link.getAttribute('href');
			var title = (link.getAttribute('data-title') || 'Permission Form').replace(/\s+/g, '_');
			var popup = window.open(
				href,
				title,
				'width=1100,height=900,menubar=no,toolbar=no,location=no,status=no,resizable=yes,scrollbars=yes'
			);
			if (popup && popup.focus) {
				popup.focus();
			}
		});
	});

});
</script>

<style>
.cr-permforms-page {
	background:
		radial-gradient(circle at 10% 0%, rgba(28, 36, 82, 0.08), transparent 30%),
		linear-gradient(180deg, #f5f8fc 0%, #eef3f8 100%);
	min-height: 100vh;
}

.cr-permforms-page > .row {
	align-items: stretch;
	min-height: 100vh;
}

.cr-permforms-page #sidebarMenu {
	min-height: 100%;
}

.cr-permforms-main {
	padding-top: 14px;
	padding-bottom: 16px;
}

.cr-permforms-hero {
	background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(247, 250, 255, 0.98) 100%);
	border: 1px solid #d8e3ef;
	border-radius: 18px;
	box-shadow: 0 12px 30px rgba(19, 53, 90, 0.08);
	margin: 8px 0 16px;
	padding: 18px;
}

.cr-permforms-kicker {
	color: #6d7f98;
	display: block;
	font-size: 12px;
	font-weight: 700;
	letter-spacing: 0.08em;
	margin: 0 0 6px;
	text-transform: uppercase;
}

.cr-permforms-title {
	color: #16355a;
	font-size: 32px;
	font-weight: 800;
	line-height: 1.15;
	margin: 0;
}

.cr-permforms-subtitle {
	color: #2f4f73;
	display: block;
	font-size: 14px;
	line-height: 1.5;
	margin: 8px 0 0;
	max-width: 760px;
}

.cr-permforms-grid {
	display: grid;
	gap: 14px;
	grid-template-columns: repeat(3, minmax(0, 1fr));
}

.cr-permforms-card {
	background: #fff;
	border: 1px solid #d8e3ef;
	border-radius: 16px;
	box-shadow: 0 10px 24px rgba(19, 53, 90, 0.07);
	padding: 16px;
}

.cr-permforms-upload {
	background: #fff;
	border: 1px solid #d8e3ef;
	border-radius: 16px;
	box-shadow: 0 10px 24px rgba(19, 53, 90, 0.07);
	margin-top: 16px;
	padding: 18px;
}

.cr-permforms-upload h2 {
	color: #17355a;
	font-size: 20px;
	font-weight: 700;
	margin: 0 0 6px;
}

.cr-permforms-upload p {
	color: #314f72;
	margin: 0 0 14px;
}

.cr-permforms-upload-fields {
	align-items: center;
	display: grid;
	gap: 10px;
	grid-template-columns: minmax(180px, 0.8fr) minmax(240px, 1.3fr) auto;
}

.cr-permforms-history {
	border-top: 1px solid #d8e3ef;
	list-style: none;
	margin: 16px 0 0;
	padding: 12px 0 0;
}

.cr-permforms-history li {
	align-items: center;
	display: flex;
	justify-content: space-between;
	padding: 4px 0;
}

.cr-permforms-history span {
	color: #60748d;
	font-size: 12px;
}

.cr-permforms-card-head h2 {
	color: #17355a;
	font-size: 18px;
	font-weight: 700;
	margin: 0 0 8px;
}

.cr-permforms-card p {
	color: #314f72;
	font-size: 13px;
	line-height: 1.5;
	margin: 0 0 12px;
}

.cr-permforms-submission-count {
	background: #f0f5ff;
	border: 1px solid #d7e4f7;
	border-radius: 999px;
	color: #1d4c82;
	display: inline-block;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.02em;
	margin: 0 0 12px;
	padding: 4px 10px;
}

.cr-permforms-submission-count.has-submission {
	background: #e9f9ef;
	border-color: #bfe6ce;
	color: #246b3a;
}

.cr-permforms-actions .btn {
	border-radius: 8px;
	font-weight: 600;
}

.cr-permforms-actions .btn.btn-outline-primary {
	background: #eef5ff;
	border-color: #2f7fbc;
	color: #1b5785 !important;
	text-decoration: none;
}

.cr-permforms-actions .btn.btn-outline-primary:hover,
.cr-permforms-actions .btn.btn-outline-primary:focus {
	background: #2f7fbc;
	border-color: #2f7fbc;
	color: #ffffff !important;
}

@media (max-width: 991px) {
	.cr-permforms-title {
		font-size: 28px;
	}

	.cr-permforms-grid {
		grid-template-columns: 1fr;
	}

	.cr-permforms-upload-fields {
		grid-template-columns: 1fr;
	}
}

@media (max-width: 575px) {
	.cr-permforms-title {
		font-size: 24px;
	}

	.cr-permforms-hero,
	.cr-permforms-card {
		padding: 12px;
	}
}
</style>
