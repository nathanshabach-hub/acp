<script type="text/javascript">
$(document).ready(function () {
	$("#reset_password_frm").validate();
    function loadResetToken() {
        var token = window.location.hash.slice(1);
        var valid = /^[a-f0-9]{64}$/.test(token);
        $('#reset_token').val(valid ? token : '');
        $('#reset_password_frm button[type=submit]').prop('disabled', !valid);
        $('#reset_link_error').prop('hidden', valid);
        if (valid) {
            window.history.replaceState(null, '', window.location.pathname + window.location.search);
        }
    }
    $(window).on('hashchange', loadResetToken);
    loadResetToken();
});
</script>

<section class="position-relative">
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
				<div class="ryt-box bg-white w-100">
					<div class="ryt-box-text form-group">
						<div class="ersu_message"> <?php echo $this->Flash->render() ?> </div>
							<?php echo $this->Form->create($users, ['id'=>'reset_password_frm', 'type' => 'file']); ?>
							<h2 class="mb-4">Reset Password</h2>
                            <p id="reset_link_error" role="alert" hidden>The reset link is missing or invalid. Request a new link.</p>
                            <noscript>JavaScript is required to use the secure reset link.</noscript>
                            <input type="hidden" name="token" id="reset_token">
                            <p>Choose a password of at least 6 characters and no more than 72 bytes.</p>
							<div>
								<div class="lables d-flex align-items-center mb-3
									justify-content-between">
									<span class="mb-0 me-1 col-4">New Password</span>
									<?php echo $this->Form->input('Users.password', ['minlength' => '6', 'type' => 'password', 'maxlength' => '72', 'autocomplete' => 'new-password', 'label' => false, 'div' => false, 'id' => 'password', 'class' => "form-control required w-75", 'placeholder' => 'New Password']); ?>
								</div>
								
								<div class="lables d-flex align-items-center mb-3
									justify-content-between">
									<span class="mb-0 me-1 col-4">Confirm Password</span>
									<?php echo $this->Form->input('Users.confirm_password', ['type' => 'password', 'label' => false, 'div' => false, 'autocomplete' => 'new-password', 'equalTo' => '#password', 'class' => "form-control required w-75", 'placeholder' => 'Confirm Password']); ?>
								</div>
								
								<div class="btns col-8 float-end">
									 
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