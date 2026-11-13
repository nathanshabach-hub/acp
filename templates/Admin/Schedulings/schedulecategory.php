<?php
use Cake\ORM\TableRegistry;
$this->Schedulingtimings = TableRegistry::getTableLocator()->get('Schedulingtimings');
?>
<script type="text/javascript">
    $(document).ready(function() {
        $("#adminForm").validate();
    });

</script>
<div class="content-wrapper admin-sched-category-page">
    <section class="content-header">
      <h1>
        Schedule Category - [Convention - <?php echo $conventionSD->Conventions['name']; ?>]&nbsp;&nbsp;&nbsp;&nbsp;
		  [Season Year - <?php echo $conventionSD->season_year; ?>]
      </h1>
      <ol class="breadcrumb">
          <li><?php echo $this->Html->link('<i class="fa fa-dashboard"></i> <span>Dashboard</span> ', ['controller'=>'admins', 'action'=>'dashboard'], ['escape'=>false]);?></li>
          <li><?php echo $this->Html->link('<i class="fa fa-bars"></i> Conventions ', ['controller'=>'conventions', 'action'=>'index'], ['escape'=>false]);?></li>
          <li><?php echo $this->Html->link('<i class="fa fa-bars"></i> Seasons ', ['controller'=>'conventions', 'action'=>'seasons',$convention_slug], ['escape'=>false]);?></li>
          <li class="active">Schedule Category </li>
      </ol>
    </section>

	<section class="content">
	 <div class="box box-info admin-data-box">
            <div class="box-header with-border">
				<h3 class="box-title admin-sched-note">
				Note: Schedulings for all categories will be done when you press "Start Scheduling" button.
				<br /><br />
				If this button is not visible, it might be possible that there is no event found in any of these below 4 categories.
				<br /><br />
				After pressing "Start Scheduling" button, please sit back and relax. Scheduling process took some time.
				<br /><br />
				After pressing "Start Scheduling" button, all previous scheduling will reset and start from scratch for this convention season.
				</h3>
            </div>
            <div class="ersu_message"> <?php echo $this->Flash->render() ?> </div>
			
			
			
			<div class="admin-sched-category-summary">
				<h2>Schedule Category
				 
				<?php
				if(count($arrEventsC1) > 0 && count($arrEventsC2) > 0 && count($arrEventsC3) > 0 && count($arrEventsC4) > 0)
				{
					echo $this->Html->link('Start Scheduling', ['controller'=>'schedulingtimings', 'action' => 'startschedulec1',$convention_season_slug], ['class'=>'btn btn-primary canlcel_le admin-sched-btn', 'id' => 'admin-sched-start', 'data-done-url' => $this->Url->build(['controller'=>'schedulings', 'action' => 'schedulecategory', $convention_season_slug])]);
				}

				$hasSchedule = $this->Schedulingtimings->find()->where([
					'Schedulingtimings.conventionseasons_id' => $conventionSD->id,
					'Schedulingtimings.convention_id' => $conventionSD->convention_id,
					'Schedulingtimings.season_id' => $conventionSD->season_id,
					'Schedulingtimings.season_year' => $conventionSD->season_year,
					'Schedulingtimings.start_time IS NOT' => null,
				])->first();
				if ($hasSchedule) {
					echo $this->Html->link('Check & Resolve Conflicts', ['controller'=>'schedulings', 'action' => 'resolvepreview', $convention_season_slug], ['class'=>'btn btn-warning canlcel_le admin-sched-btn']);
				}
				?>
				</h2> 
				<div class="admin-sched-progress" id="admin-sched-progress" hidden aria-live="polite">
					<div class="admin-sched-progress-head">
						<strong id="admin-sched-progress-label">Preparing scheduling...</strong>
						<span id="admin-sched-progress-time">0:00</span>
					</div>
					<div class="admin-sched-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
						<div class="admin-sched-progress-bar" id="admin-sched-progress-bar"></div>
					</div>
					<ol class="admin-sched-progress-steps" id="admin-sched-progress-steps">
						<li data-step="startschedulec1">Category 1</li>
						<li data-step="startschedulec2">Category 2</li>
						<li data-step="startschedulec3">Category 3</li>
						<li data-step="startschedulec4">Category 4</li>
						<li data-step="fillgroupuserids">Team members</li>
						<li data-step="listconflicts">Conflict check</li>
					</ol>
					<p class="admin-sched-progress-note">Keep this page open until scheduling finishes.</p>
				</div>
				<table class="table table-bordered admin-sched-category-table">
					<tr>
						<th>#</th>
						<th>Needs Schedule</th>
						<th>Group Event</th>
						<th>Event Kind ID</th>
						<th>Has To Be Consecutive</th>
						<th>Number of events found</th>
						<th>View Scheduling</th>
					</tr>
					
					<tr data-sched-category="1">
						<td>1.</td>
						<td>Yes</td>
						<td>Yes</td>
						<td>Sequential</td>
						<td>Yes</td>
						<td><?php echo count($arrEventsC1); ?></td>
						<td>
							<?php
							if(count($arrEventsC1) > 0)
							{
								// now check if there is any scheduling already done
								$checkScheduling = $this->Schedulingtimings->find()->where(['Schedulingtimings.schedule_category' => 1,'Schedulingtimings.conventionseasons_id' => $conventionSD->id,'Schedulingtimings.convention_id' => $conventionSD->convention_id,'Schedulingtimings.season_id' => $conventionSD->season_id,'Schedulingtimings.season_year' => $conventionSD->season_year])->first();
								
								if($checkScheduling)
								{
									echo $this->Html->link('View Scheduling', ['controller'=>'schedulingtimings', 'action' => 'viewscheduling',$convention_season_slug,1], ['class'=>'btn btn-success canlcel_le admin-sched-btn']);
								}
								else
								{
									echo '<span class="precheck-empty">Schedulings not yet done</span>';
								}
							}
							?>
						</td>
					</tr>
					
					<tr data-sched-category="2">
						<td>2.</td>
						<td>Yes</td>
						<td>No</td>
						<td>Elimination</td>
						<td>No</td>
						<td><?php echo count($arrEventsC2); ?></td>
						<td>
							<?php
							if(count($arrEventsC2) > 0)
							{
								// now check if there is any scheduling already done
								$checkScheduling = $this->Schedulingtimings->find()->where(['Schedulingtimings.schedule_category' => 2,'Schedulingtimings.conventionseasons_id' => $conventionSD->id,'Schedulingtimings.convention_id' => $conventionSD->convention_id,'Schedulingtimings.season_id' => $conventionSD->season_id,'Schedulingtimings.season_year' => $conventionSD->season_year])->first();
								
								if($checkScheduling)
								{
									echo $this->Html->link('View Scheduling', ['controller'=>'schedulingtimings', 'action' => 'viewscheduling',$convention_season_slug,2], ['class'=>'btn btn-success canlcel_le admin-sched-btn']);
								}
								else
								{
									echo '<span class="precheck-empty">Schedulings not yet done</span>';
								}
							}
							?>
						</td>
					</tr>
					
					
					<!-- Category 3 is similar to category 2 -->
					<tr data-sched-category="3">
						<td>3.</td>
						<td>Yes</td>
						<td>Yes</td>
						<td>Elimination</td>
						<td>No</td>
						<td><?php echo count($arrEventsC3); ?></td>
						<td>
							<?php
							if(count($arrEventsC3) > 0)
							{
								// now check if there is any scheduling already done
								$checkScheduling = $this->Schedulingtimings->find()->where(['Schedulingtimings.schedule_category' => 3,'Schedulingtimings.conventionseasons_id' => $conventionSD->id,'Schedulingtimings.convention_id' => $conventionSD->convention_id,'Schedulingtimings.season_id' => $conventionSD->season_id,'Schedulingtimings.season_year' => $conventionSD->season_year])->first();
								
								if($checkScheduling)
								{
									echo $this->Html->link('View Scheduling', ['controller'=>'schedulingtimings', 'action' => 'viewscheduling',$convention_season_slug,3], ['class'=>'btn btn-success canlcel_le admin-sched-btn']);
								}
								else
								{
									echo '<span class="precheck-empty">Schedulings not yet done</span>';
								}
							}
							?>
						</td>
					</tr>
					
					
					
					<!-- Category 4 is similar to category 1 -->
					<tr data-sched-category="4">
						<td>4.</td>
						<td>Yes</td>
						<td>No</td>
						<td>Sequential</td>
						<td>Yes</td>
						<td><?php echo count($arrEventsC4); ?></td>
						<td>
							<?php
							if(count($arrEventsC4) > 0)
							{
								// now check if there is any scheduling already done
								$checkScheduling = $this->Schedulingtimings->find()->where(['Schedulingtimings.schedule_category' => 4,'Schedulingtimings.conventionseasons_id' => $conventionSD->id,'Schedulingtimings.convention_id' => $conventionSD->convention_id,'Schedulingtimings.season_id' => $conventionSD->season_id,'Schedulingtimings.season_year' => $conventionSD->season_year])->first();
								
								if($checkScheduling)
								{
									echo $this->Html->link('View Scheduling', ['controller'=>'schedulingtimings', 'action' => 'viewscheduling',$convention_season_slug,4], ['class'=>'btn btn-success canlcel_le admin-sched-btn']);
								}
								else
								{
									echo '<span class="precheck-empty">Schedulings not yet done</span>';
								}
							}
							?>
						</td>
					</tr>
					
					<?php
						/* echo $this->Html->link('Remove Overlapping', ['controller'=>'schedulingtimings', 'action' => 'removeoverlapping',$convention_season_slug], ['class'=>'btn btn-warning canlcel_le', 'confirm' => 'Are you sure you want to start process to remove overlapping?']); */
						?>
					
					
					
					
				</table>
			</div>
			
			
			
             
                <div class="form-horizontal">
					<div class="box-body">
					<div class="box-footer admin-sched-category-actions">
                        <label class="col-sm-2 control-label" for="inputPassword3">&nbsp;</label>
						
						<?php
						echo $this->Html->link('<< Back To Pre-check', ['controller'=>'schedulings', 'action' => 'precheck',$convention_season_slug], ['class'=>'btn btn-default canlcel_le admin-sched-btn']);
						?>
						
						
                    </div>
                  </div>
                </div> 
          </div>
    </section>
<script type="text/javascript">
(function () {
	var startBtn = document.getElementById('admin-sched-start');
	var panel = document.getElementById('admin-sched-progress');
	if (!startBtn || !panel || !window.fetch) {
		return;
	}
	var label = document.getElementById('admin-sched-progress-label');
	var timeEl = document.getElementById('admin-sched-progress-time');
	var bar = document.getElementById('admin-sched-progress-bar');
	var track = panel.querySelector('.admin-sched-progress-track');
	var stepEls = Array.prototype.slice.call(panel.querySelectorAll('[data-step]'));
	var steps = stepEls.map(function (el) { return el.getAttribute('data-step'); });
	var labels = {
		startschedulec1: 'Scheduling category 1 of 4...',
		startschedulec2: 'Scheduling category 2 of 4...',
		startschedulec3: 'Scheduling category 3 of 4...',
		startschedulec4: 'Scheduling category 4 of 4...',
		fillgroupuserids: 'Assigning team members...',
		listconflicts: 'Checking for participant conflicts...',
		resolveconflicts: 'Resolving participant conflicts...',
		resolveconflictsgroup: 'Resolving team conflicts...'
	};
	var doneUrl = startBtn.getAttribute('data-done-url');
	var running = false;
	var startedAt = 0;
	var timer = null;

	function actionOf(url) {
		var parts = new URL(url, window.location.href).pathname.split('/');
		var i = parts.indexOf('schedulingtimings') >= 0 ? parts.indexOf('schedulingtimings') : parts.indexOf('schedulings');
		return i >= 0 ? (parts[i + 1] || '').toLowerCase() : '';
	}

	function setProgress(action) {
		var index = steps.indexOf(action);
		if (action === 'resolveconflicts' || action === 'resolveconflictsgroup') {
			index = steps.indexOf('listconflicts');
		}
		stepEls.forEach(function (el, i) {
			el.classList.toggle('is-done', i < index);
			el.classList.toggle('is-active', i === index);
		});
		document.querySelectorAll('[data-sched-category]').forEach(function (row) {
			var n = parseInt(row.getAttribute('data-sched-category'), 10);
			row.classList.toggle('admin-sched-row-done', n - 1 < index);
			row.classList.toggle('admin-sched-row-active', n - 1 === index);
		});
		var pct = Math.round((Math.max(index, 0) / steps.length) * 100);
		var stepPct = Math.round(((Math.max(index, 0) + 0.9) / steps.length) * 100);
		// Jump to the step start, then creep towards its end so long steps still show movement.
		bar.style.transition = 'width 0.4s ease';
		bar.style.width = Math.max(pct, 2) + '%';
		track.setAttribute('aria-valuenow', String(pct));
		window.setTimeout(function () {
			if (running) {
				bar.style.transition = 'width 60s cubic-bezier(0.1, 0.6, 0.3, 1)';
				bar.style.width = stepPct + '%';
			}
		}, 450);
		label.textContent = labels[action] || 'Working...';
	}

	function tick() {
		var secs = Math.floor((Date.now() - startedAt) / 1000);
		timeEl.textContent = Math.floor(secs / 60) + ':' + ('0' + (secs % 60)).slice(-2);
	}

	function finish(url) {
		running = false;
		clearInterval(timer);
		window.location.href = url;
	}

	function fail(message) {
		running = false;
		clearInterval(timer);
		panel.classList.add('is-error');
		bar.style.transition = 'none';
		bar.style.width = getComputedStyle(bar).width;
		label.textContent = message;
		startBtn.classList.remove('disabled');
		startBtn.removeAttribute('aria-disabled');
	}

	function runStep(url) {
		var action = actionOf(url);
		if (steps.indexOf(action) < 0 && !labels[action]) {
			// The chain ended or was redirected elsewhere (for example after a validation error).
			bar.style.transition = 'width 0.4s ease';
			bar.style.width = '100%';
			stepEls.forEach(function (el) { el.classList.remove('is-active'); el.classList.add('is-done'); });
			label.textContent = 'Scheduling finished. Loading results...';
			finish(url);
			return;
		}
		setProgress(action);
		fetch(url, {
			credentials: 'same-origin',
			headers: { 'X-Schedule-Progress': '1', 'Accept': 'application/json' }
		}).then(function (response) {
			var type = response.headers.get('Content-Type') || '';
			if (!response.ok) {
				throw new Error('Scheduling stopped at "' + (labels[action] || action).replace('...', '') + '" (HTTP ' + response.status + '). Check the error log, then try again.');
			}
			if (type.indexOf('application/json') < 0) {
				finish(response.url || doneUrl);
				return null;
			}
			return response.json();
		}).then(function (data) {
			if (data === null) {
				return;
			}
			if (!data || !data.redirect) {
				throw new Error('Scheduling returned an unexpected response.');
			}
			runStep(data.redirect);
		}).catch(function (err) {
			fail(err.message || 'Scheduling stopped because of a network error.');
		});
	}

	startBtn.addEventListener('click', function (event) {
		event.preventDefault();
		if (running || !window.confirm('Are you sure you want to start scheduling?')) {
			return;
		}
		running = true;
		startedAt = Date.now();
		panel.hidden = false;
		panel.classList.remove('is-error');
		startBtn.classList.add('disabled');
		startBtn.setAttribute('aria-disabled', 'true');
		tick();
		timer = setInterval(tick, 1000);
		panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		runStep(startBtn.href);
	});

	window.addEventListener('beforeunload', function (event) {
		if (running) {
			event.preventDefault();
			event.returnValue = '';
		}
	});
})();
</script>
  </div>