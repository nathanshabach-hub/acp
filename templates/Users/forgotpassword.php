<script type="text/javascript">
$(document).ready(function () {
	$("#forgot_password_frm").validate();
});
</script>

<section class="position-relative" style="background: linear-gradient(135deg, #1c2452 0%, #0f1633 60%, #1a3a5c 100%); background-size: 300% 300%; animation: hp-grad-shift 12s ease infinite;">
	<canvas id="hp-particles" aria-hidden="true" style="position:absolute;top:0;left:0;width:100%;height:100%;z-index:1;pointer-events:none;"></canvas>
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
							<?php echo $this->Form->create($users, ['id'=>'forgot_password_frm', 'type' => 'file']); ?>
							<h2 class="mb-2">Forgot Password</h2>
                            <p class="text-dark mb-3">An email reset link will be sent to your inbox.</p>
							<div>
								<div class="lables">
									<span class="mb-0 me-1 col-4">Email Address</span>
									<?php echo $this->Form->input('Users.email_address', ['label'=>false, 'type'=>'text', 'autocomplete'=>'off', 'div'=>false, 'class'=>'form-control required email', 'placeholder'=>'Email Address']); ?>
								</div>
								
								<div class="lables">
									<span class="mb-0 me-1 col-4">Choose Type</span>
									<?php echo $this->Form->select('Users.user_type', $loginUserTypes, ['id' => 'user_type', 'label' => false, 'div' => false, 'class' => 'form-control required', 'autocomplete' => 'off', 'empty' => 'Choose']); ?>
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