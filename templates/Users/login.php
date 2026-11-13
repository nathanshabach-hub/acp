<script type="text/javascript">
	$(document).ready(function () {
		$("#login_frm").validate();

		function toggleLoginMode() {
			var selectedType = $("#user_type").val();
			var isStudent = selectedType === "Student";

			if (isStudent) {
				$(".js-student-fields").show();
				$(".js-standard-fields").hide();
				$("#users-email-address").removeClass("required email");
				$("#student_code").addClass("required");
			} else {
				$(".js-student-fields").hide();
				$(".js-standard-fields").show();
				$("#users-email-address").addClass("required email");
				$("#users-password").addClass("required");
				$("#student_code").removeClass("required");
			}
            $(".js-password-fields").show();
            $("#users-password").addClass("required");
		}

		$("#user_type").on("change", toggleLoginMode);
		toggleLoginMode();
	});
</script>
<?php $loginUserTypes = $loginUserTypes ?? []; ?>

<section class="position-relative" style="background: linear-gradient(135deg, #1c2452 0%, #0f1633 60%, #1a3a5c 100%); background-size: 300% 300%; animation: hp-grad-shift 12s ease infinite;">
	<canvas id="hp-particles" style="position:absolute;top:0;left:0;width:100%;height:100%;z-index:1;pointer-events:none;"></canvas>
	<div class=" left-element w-25">
		<?php echo $this->Html->image('front/left-element.png'); ?>
	</div>
	<div class=" ryt-element w-25">
		<?php echo $this->Html->image('front/ryt-element.png'); ?>
	</div>
	<div class="container">
		<div class="row center-section align-items-center">
			<?php echo $this->element('Homes/home_left_content'); ?>


			<div class="col-lg-6 ">
				<div class="ryt-box bg-white text-dark w-100 position-relative" style="z-index:2;">
					<div class="ryt-box-text form-group">
						<div class="ersu_message">
							<?php echo $this->Flash->render() ?>
						</div>
						<?php echo $this->Form->create(null, ['id' => 'login_frm', 'type' => 'file']); ?>
						<h2 class="mb-4">Login</h2>
						<div>
							<div class="lables">
								<span class="col-4">Choose Type</span>
								<div class="w-100">
									<?php echo $this->Form->select('Users.user_type', $loginUserTypes, ['id' => 'user_type', 'label' => false, 'div' => false, 'class' => 'form-control required', 'autocomplete' => 'off', 'empty' => 'Choose']); ?>
								</div>
							</div>

							<div class="lables js-standard-fields">
								<span class="col-4">Email Address</span>
								<?php echo $this->Form->input('Users.email_address', ['id' => 'users-email-address', 'label' => false, 'type' => 'text', 'autocomplete' => 'username', 'div' => false, 'class' => 'form-control required email', 'placeholder' => 'Email Address']); ?>
							</div>

							<div class="lables js-student-fields" style="display:none;">
								<span class="col-4">Student ID</span>
								<?php echo $this->Form->input('Users.student_code', ['id' => 'student_code', 'label' => false, 'type' => 'text', 'autocomplete' => 'username', 'div' => false, 'class' => 'form-control', 'maxlength' => 6, 'placeholder' => 'Student ID (e.g. 7K4M9R)']); ?>
							</div>

							<div class="lables js-password-fields">
								<span class="col-4">Password</span>
								<?php echo $this->Form->input('Users.password', ['id' => 'users-password', 'type' => 'password', 'label' => false, 'div' => false, 'class' => "form-control required", 'autocomplete' => 'current-password', 'placeholder' => 'Password']); ?>
							</div>

							<div class="js-student-fields" style="display:none;">
                                <p class="text-dark">First time signing in? <?= $this->Html->link('Create/setup password', ['action' => 'studentsetup'], ['class' => 'text-primary text-decoration-underline']) ?> using the single-use code supplied by your school.</p>
                                <p class="text-dark">Forgotten your student password? Ask your school for a new setup code.</p>
							</div>

							<div class="btns col-8 float-end">
								<span class=" mb-3 w-100">
									<?php echo $this->Html->link('Forgot Password?', ['controller' => 'users', 'action' => 'forgotpassword'], ['escape' => false, 'class' => 'text-primary ms-1 js-standard-fields']); ?>
									<!--<a href="" class="text-primary ms-1">Forgot Password?</a>-->
								</span>
								<button type="submit" class="btn btn-secondary px-3 ms-1">Submit</button>
							</div>
						</div>
						<?php echo $this->Form->end(); ?>
					</div>
				</div>
			</div>


		</div>
	</div>
</section>