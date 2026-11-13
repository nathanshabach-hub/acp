 <section class="hp-hero position-relative">

	<!-- animated particle canvas -->
	<canvas id="hp-particles" style="position:absolute;top:0;left:0;width:100%;height:100%;z-index:1;pointer-events:none;"></canvas>
	<!-- background wave decorations -->
	<div class="hp-wave hp-wave-left">
		<?php echo $this->Html->image('front/left-element.png'); ?>
	</div>
	<div class="hp-wave hp-wave-right">
		<?php echo $this->Html->image('front/ryt-element.png'); ?>
	</div>

	<div class="container hp-hero-inner">

		<!-- LEFT: branding + tagline -->
		<?php echo $this->element('Homes/home_left_content'); ?>

		<!-- RIGHT: convention selector card -->
		<div class="hp-card-wrap">
			<div class="hp-card bg-white">

				<p class="hp-card-sub">Select your convention below to register or log in.</hp>

				<div class="ersu_message"> <?php echo $this->Flash->render() ?> </div>

				<?php echo $this->Form->create(NULL, ['id'=>'home_conventions', 'type' => 'file']); ?>

				<input type="hidden" name="hidd_season_id" id="hidd_season_id" value="<?php echo $season_id; ?>" />

				<label class="hp-label" for="convention_id">Convention</label>
				<?php echo $this->Form->select('Events.convention_id', $conventionDD, [
					'id'           => 'convention_id',
					'label'        => false,
					'div'          => false,
					'class'        => 'form-control form-select hp-select required',
					'autocomplete' => 'off',
					'empty'        => 'Choose Convention'
				]); ?>

				<script>
					$(document).ready(function() {
						$('#convention_id').select2({ width: '100%' });
						$('#convention_id').change(function(){
							$("#reg_login_buttons_box").css("display", "none");
							var hidd_season_id = $('#hidd_season_id').val();
							var convention_id  = $('#convention_id').val();
							if(convention_id == "") {
								alert("Please choose convention.");
								return false;
							}
							$.ajax({
								type: 'POST',
								url: "<?php echo $this->Url->build(['controller' => 'Homes', 'action' => 'chooseconvention']); ?>/"+convention_id+"/"+hidd_season_id,
								success: function(result) {
									$("#reg_login_buttons_box").html(result).show();
								}
							});
							return false;
						});
					});
				</script>

				<div class="mt-3" id="reg_login_buttons_box" style="display:none;"></div>

				<?php echo $this->Form->end(); ?>

			</div><!-- /.hp-card -->
		</div><!-- /.hp-card-wrap -->

	</div><!-- /.hp-hero-inner -->
</section>