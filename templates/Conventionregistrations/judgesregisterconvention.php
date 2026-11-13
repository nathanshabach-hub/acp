<script type="text/javascript">
	$(document).ready(function () {
		$("#judgeregister").validate();
		var jrPageSize = 20;
		$('.jr-accordion').each(function () {
			var section = $(this);
			var currentPage = 1;

			function renderRows() {
				var query = $.trim(section.find('.jr-search').val()).toLowerCase();
				var rows = section.find('.jr-table tbody tr');
				var filtered = rows.filter(function () {
					return query === '' || $.trim($(this).find('td:eq(1)').text()).toLowerCase().indexOf(query) !== -1
						|| $.trim($(this).find('td:eq(2)').text()).toLowerCase().indexOf(query) !== -1;
				});
				var total = filtered.length;
				var pages = Math.max(1, Math.ceil(total / jrPageSize));
				currentPage = Math.min(currentPage, pages);
				var start = (currentPage - 1) * jrPageSize;
				rows.hide();
				filtered.slice(start, start + jrPageSize).show();
				section.find('.jr-no-results').toggle(total === 0);
				section.find('.jr-prev').prop('disabled', currentPage === 1 || total === 0);
				section.find('.jr-next').prop('disabled', currentPage >= pages || total === 0);
				section.find('.jr-page-info').text(total === 0 ? 'Showing 0 of 0'
					: 'Showing ' + (start + 1) + '-' + Math.min(start + jrPageSize, total) + ' of ' + total);
			}

			section.find('.jr-search').on('input', function () {
				currentPage = 1;
				renderRows();
			});
			section.find('.jr-prev').on('click', function () {
				if (currentPage > 1) { currentPage--; renderRows(); }
			});
			section.find('.jr-next').on('click', function () {
				currentPage++;
				renderRows();
			});
			renderRows();
		});

		$('#judgeregister').on('submit', function () {
			$(this).find('.jr-generated-event').remove();
			$('.jr-judge-choice:checked').each(function () {
				$('<input>', {
					type: 'hidden',
					name: 'Conventionregistrations[judges_event_ids][]',
					value: $(this).data('eventId'),
					class: 'jr-generated-event'
				}).appendTo('#judgeregister');
			});
		});
	});
</script>
<style>
.jr-page {
	padding-top: 14px;
	padding-bottom: 18px;
	padding-left: 8px;
	padding-right: 8px;
}
.jr-header {
	background: linear-gradient(135deg, #f7fbff 0%, #edf4ff 100%);
	border: 1px solid #dce8f8;
	border-radius: 14px;
	padding: 18px 20px;
	margin-bottom: 16px;
	box-shadow: 0 8px 20px rgba(30, 70, 120, 0.08);
}
.user-portal .jr-title {
	margin: 0;
	font-size: 24px;
	line-height: 1.25;
	color: #18314f;
	font-weight: 700;
}
.jr-subtitle {
	margin-top: 6px;
	font-size: 14px;
	color: #4a6380;
}
.jr-form-card {
	background: #ffffff;
	border: 1px solid #dfe6ef;
	border-radius: 14px;
	padding: 18px 18px 16px;
	box-shadow: 0 10px 24px rgba(16, 42, 67, 0.08);
	width: 100%;
	max-width: 100%;
}
.jr-form-card .form-group {
	width: 100%;
}
.jr-form-card .form-group > label {
	float: none;
	width: 100%;
	display: block;
	text-align: left;
	margin-bottom: 8px;
}
.jr-form-card .form-group .input {
	float: none;
	width: 100%;
	max-width: 100%;
}
.jr-form-card .form-btns > label {
	display: none;
}
.jr-section-label {
	display: block;
	font-size: 13px;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	font-weight: 700;
	color: #5d7290;
	margin-bottom: 8px;
}
.jr-toolbar {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 10px;
}
.jr-accordion {
	border: 1px solid #dfe6ee;
	border-radius: 8px;
	margin-bottom: 12px;
	width: 100%;
	min-width: 0;
	max-width: 100%;
	overflow: hidden;
	background: #fff;
}
.jr-accordion > summary {
	cursor: pointer;
	padding: 11px 14px;
	font-weight: 700;
	color: #1f3a56;
	background: #f6f9fc;
	border-bottom: 1px solid #e4ebf3;
	list-style: none;
	transition: background-color .3s ease, color .3s ease;
}
.jr-accordion > summary::-webkit-details-marker {
	display: none;
}
.jr-accordion > summary::after {
	content: '+';
	float: right;
	font-weight: 700;
	transition: transform .3s ease, color .3s ease;
}
.jr-accordion[open] > summary::after {
	content: '-';
}
.jr-accordion > summary:hover {
	background: #eaf2fb;
	color: #123458;
}
.jr-accordion > summary:hover::after {
	transform: scale(1.1);
	color: #0f2e4a;
}
.jr-accordion[open] > summary:hover::after {
	transform: rotate(90deg);
}
.jr-accordion > summary:focus-visible {
	outline: 3px solid #4d86bd;
	outline-offset: -3px;
}
.jr-accordion-locked {
	padding: 11px 14px;
	font-weight: 700;
	color: #596b7d;
	background: #f6f9fc;
}
.jr-lock-message {
	margin: 0;
	padding: 10px 14px;
	color: #596b7d;
}
.jr-accordion-content {
	padding: 10px 12px 12px;
}
.jr-search {
	max-width: 360px;
	width: 100%;
	border: 1px solid #cddced;
	border-radius: 10px;
	padding: 10px 12px;
	font-size: 14px;
	color: #1e3857;
	background: #fbfdff;
}
.jr-search:focus {
	outline: none;
	border-color: #4d86bd;
	box-shadow: 0 0 0 3px rgba(77, 134, 189, 0.15);
	background: #fff;
}
.jr-table-wrap {
	border: 1px solid #dfe6ef;
	border-radius: 12px;
	overflow: auto;
	margin-top: 8px;
	background: #fff;
}
.jr-table {
	margin-bottom: 0;
	min-width: 1000px;
	width: 100%;
}
.jr-table thead th {
	background: #eef4fb;
	font-size: 12px;
	font-weight: 700;
	color: #26415f;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	padding: 12px 10px;
	border-bottom: 1px solid #d6e2f0;
}
.jr-table tbody td {
	vertical-align: middle;
	padding: 11px 10px;
	border-color: #e6edf7;
}
.jr-table tbody tr:nth-child(even) {
	background: #fbfdff;
}
.jr-table tbody tr:hover {
	background: #f2f8ff;
}
.jr-event-name {
	font-weight: 600;
	color: #1e3857;
}
.jr-choice-cell {
	text-align: center;
}
.jr-judge-choice {
	width: 16px;
	height: 16px;
	cursor: pointer;
	accent-color: #1f6fb5;
}
.jr-interest-choice {
	width: 17px;
	height: 17px;
	cursor: pointer;
	accent-color: #1f6fb5;
}
.jr-slot-taken {
	display: inline-block;
	width: 14px;
	height: 14px;
	border-radius: 50%;
	background: #5a7695;
	box-shadow: inset 0 0 0 2px #d8e3f0;
}
.jr-table tbody tr.jr-event-full {
	background: #fde8e8;
	box-shadow: inset 4px 0 0 #d9534f;
}
.jr-table tbody tr.jr-event-full:hover {
	background: #fbdede;
}
.jr-table tbody tr.jr-event-full .jr-event-name,
.jr-table tbody tr.jr-event-full td {
	color: #7a1f1a;
	font-weight: 600;
}
.jr-help {
	display: block;
	margin-top: 10px;
	color: #4e647f;
}
.jr-no-results {
	display: none;
	margin-top: 8px;
	font-size: 13px;
	color: #7b4a00;
	background: #fff5df;
	border: 1px solid #f1d59e;
	border-radius: 8px;
	padding: 8px 10px;
}
.jr-pagination {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-top: 10px;
	gap: 12px;
}
.jr-page-info {
	font-size: 13px;
	color: #4e647f;
}
.jr-page-actions {
	display: flex;
	gap: 8px;
}
.jr-page-btn {
	border: 1px solid #c8d9ec;
	background: #f7fbff;
	color: #21466f;
	border-radius: 8px;
	padding: 7px 12px;
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
}
.jr-page-btn:hover {
	background: #eaf3ff;
}
.jr-page-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
	background: #f3f6fa;
}
.jr-actions {
	display: flex;
	gap: 10px;
	align-items: center;
	margin-top: 14px;
}

html[data-portal-theme="dark"] .user-portal .jr-header {
	background: #1b2c38;
	border-color: var(--portal-line);
	box-shadow: none;
}
html[data-portal-theme="dark"] .user-portal .jr-title {
	color: var(--portal-ink);
}
html[data-portal-theme="dark"] .user-portal .jr-subtitle {
	color: var(--portal-muted);
}
html[data-portal-theme="dark"] .user-portal .jr-page .alert-info {
	background: #29434e;
	border-color: #526a7c;
	color: #c1e8ff;
}
html[data-portal-theme="dark"] .user-portal .jr-accordion,
html[data-portal-theme="dark"] .user-portal .jr-table-wrap {
	background: #1b2c38;
	border-color: var(--portal-line);
}
html[data-portal-theme="dark"] .user-portal .jr-accordion > summary,
html[data-portal-theme="dark"] .user-portal .jr-accordion-locked {
	background: #293e4c;
	border-color: var(--portal-line);
	color: var(--portal-ink);
}
html[data-portal-theme="dark"] .user-portal .jr-accordion > summary:hover {
	background: #29434e;
	color: #fff;
}
html[data-portal-theme="dark"] .user-portal .jr-accordion > summary:hover::after {
	color: #fff;
}
html[data-portal-theme="dark"] .user-portal :is(.jr-lock-message, .jr-help, .jr-page-info) {
	color: var(--portal-muted);
}
html[data-portal-theme="dark"] .user-portal .jr-search {
	background: #142530;
	border-color: var(--portal-line);
	color: var(--portal-ink) !important;
}
html[data-portal-theme="dark"] .user-portal .jr-search::placeholder {
	color: var(--portal-muted);
}
html[data-portal-theme="dark"] .user-portal .jr-search:focus {
	border-color: #72d6c6;
}
html[data-portal-theme="dark"] .user-portal .jr-table .jr-event-name {
	color: var(--portal-ink);
}
html[data-portal-theme="dark"] .user-portal .jr-table tbody tr.jr-event-full > td {
	background: #51332f;
	color: #ffd2c9;
	box-shadow: none;
}
html[data-portal-theme="dark"] .user-portal .jr-table tbody tr.jr-event-full:hover > td {
	background: #633d38;
}
html[data-portal-theme="dark"] .user-portal .jr-no-results {
	background: #493d25;
	border-color: #967e4c;
	color: #ffe2a3;
}
html[data-portal-theme="dark"] .user-portal .jr-page-btn {
	background: #293e4c;
	border-color: #8195a5;
	color: var(--portal-ink);
}
html[data-portal-theme="dark"] .user-portal .jr-page-btn:hover:not(:disabled) {
	background: #3b5365;
}

@media (max-width: 767px) {
	.jr-title {
		font-size: 20px;
	}
	.jr-page {
		padding-left: 0;
		padding-right: 0;
	}
	.jr-header,
	.jr-form-card {
		padding: 14px;
	}
	.jr-actions {
		flex-wrap: wrap;
	}
	.jr-toolbar {
		justify-content: stretch;
	}
	.jr-search {
		max-width: 100%;
	}
	.jr-pagination {
		flex-wrap: wrap;
	}
}
</style>
<div class="container-fluid p-0">
	<div class="row">
		<?php echo $this->element('user_left_menu'); ?>
		<main class="col-md-9 ms-sm-auto col-lg-10 px-md-2 jr-page">

			<div class="ersu_message">
				<?php echo $this->Flash->render() ?>
			</div>

			<div class="jr-header">
				<h2 class="jr-title">Register For Convention :: <?php echo $conventionD->name; ?></h2>
				<div class="jr-subtitle">Season: <?php echo $convSeasonD->season_year; ?></div>
			</div>

			<!-- dashboard-section-3 start-->
            <?php if (isset($judgeRegistrationFee)): ?>
                <p class="alert alert-info">Judges Registration: <?= h(CURR) ?> <?= number_format((float)$judgeRegistrationFee, 2) ?>. Saving your events creates an invoice request for admin. An existing request is reused; payment and judge approval remain separate.</p>
            <?php endif; ?>
			<div class="dashboard-form jr-form-card">
				<?php echo $this->Form->create(null, ['id' => 'judgeregister', 'type' => 'file', 'class' => ' ']); ?>

				<div class="form-group">
					<label for="name" class="jr-section-label">Choose Events of Interest</label>
					<?php
					$eventGroups = ['pre' => [], 'in_person' => []];
					foreach ($eventsList as $eventrec) {
						$divisionName = (string)($eventrec->Divisions['name'] ?? '');
						$group = \App\Utility\JudgingEventCategories::isPreJudged($divisionName, (string)$eventrec->event_name) ? 'pre' : 'in_person';
						$eventGroups[$group][] = $eventrec;
					}
					$selectedJudgeEventIds = isset($selectedJudgeEventIds) && is_array($selectedJudgeEventIds) ? $selectedJudgeEventIds : [];
					$selectedInterestEventIds = isset($selectedInterestEventIds) && is_array($selectedInterestEventIds) ? $selectedInterestEventIds : [];
					$eventJudgeCounts = isset($eventJudgeCounts) && is_array($eventJudgeCounts) ? $eventJudgeCounts : [];
					foreach (['pre' => 'Pre-Judged Events', 'in_person' => 'In-Person Events'] as $group => $groupLabel):
					?>
					<?php if ($group === 'in_person' && !$inPersonEventsOpen): ?>
					<div class="jr-accordion">
						<div class="jr-accordion-locked"><?= h($groupLabel) ?> (<?= count($eventGroups[$group]) ?>) - Locked</div>
						<p class="jr-lock-message">The In-Person Events will be available once the schedule has been run.</p>
					</div>
					<?php else: ?>
					<details class="jr-accordion">
						<summary><?= h($groupLabel) ?> (<?= count($eventGroups[$group]) ?>)</summary>
						<div class="jr-accordion-content">
					<div class="jr-toolbar">
						<input type="search" class="jr-search" aria-label="Search <?= h($groupLabel) ?>" placeholder="Search by code or event name" autocomplete="off" />
					</div>
					<div class="input jr-table-wrap">
						<table class="table table-bordered table-striped jr-table">
							<thead>
								<tr>
									<th style="width:52px;">#</th>
									<th style="width:100px;">Code</th>
									<th>Event Name</th>
									<th style="width:140px;">Interest</th>
									<th style="width:160px;">Judge 1</th>
									<th style="width:160px;">Judge 2</th>
									<th style="width:160px;">Judge 3</th>
								</tr>
							</thead>
							<tbody>
							<?php
							$counter = 0;
							foreach ($eventGroups[$group] as $eventrec) {
								$counter++;
								$eid = (int)$eventrec->id;
								$isChecked = in_array($eid, $selectedJudgeEventIds);
								$eventSelectedCount = isset($eventJudgeCounts[$eid]) ? (int)$eventJudgeCounts[$eid] : 0;
								$occupiedSlots = min(3, max(0, $eventSelectedCount));
								$otherOccupiedSlots = $isChecked ? max(0, $occupiedSlots - 1) : $occupiedSlots;
								$currentJudgeSlot = $isChecked ? min(3, $otherOccupiedSlots + 1) : 0;
								$isFullyOccupied = ($occupiedSlots >= 3 && !$isChecked);
							?>
								<tr class="<?php echo $isFullyOccupied ? 'jr-event-full' : ''; ?>">
									<td><?php echo $counter; ?></td>
									<td><?php echo h($eventrec->event_id_number); ?></td>
									<td class="jr-event-name"><?php echo h($eventrec->event_name); ?></td>
									<td class="jr-choice-cell">
										<input type="checkbox" class="jr-interest-choice" name="Conventionregistrations[interest_event_ids][]" value="<?php echo $eid; ?>" <?php echo in_array($eid, $selectedInterestEventIds, true) ? 'checked' : ''; ?> />
									</td>
									<td class="jr-choice-cell">
										<?php if ($otherOccupiedSlots >= 1) { ?>
											<span class="jr-slot-taken" title="Already selected"></span>
										<?php } else { ?>
											<input type="radio" class="jr-judge-choice" name="judge_choice_<?php echo $eid; ?>" value="1" data-event-id="<?php echo $eid; ?>" <?php echo ($currentJudgeSlot === 1) ? 'checked' : ''; ?> />
										<?php } ?>
									</td>
									<td class="jr-choice-cell">
										<?php if ($otherOccupiedSlots >= 2) { ?>
											<span class="jr-slot-taken" title="Already selected"></span>
										<?php } else { ?>
											<input type="radio" class="jr-judge-choice" name="judge_choice_<?php echo $eid; ?>" value="2" data-event-id="<?php echo $eid; ?>" <?php echo ($currentJudgeSlot === 2) ? 'checked' : ''; ?> />
										<?php } ?>
									</td>
									<td class="jr-choice-cell">
										<?php if ($otherOccupiedSlots >= 3) { ?>
											<span class="jr-slot-taken" title="Already selected"></span>
										<?php } else { ?>
											<input type="radio" class="jr-judge-choice" name="judge_choice_<?php echo $eid; ?>" value="3" data-event-id="<?php echo $eid; ?>" <?php echo ($currentJudgeSlot === 3) ? 'checked' : ''; ?> />
										<?php } ?>
									</td>
								</tr>
							<?php } ?>
							</tbody>
						</table>
					</div>
					<div class="jr-no-results">No events match your search.</div>
					<div class="jr-pagination">
						<div class="jr-page-info" aria-live="polite">Showing 0 of 0</div>
						<div class="jr-page-actions">
							<button type="button" class="jr-page-btn jr-prev">Previous</button>
							<button type="button" class="jr-page-btn jr-next">Next</button>
						</div>
					</div>
						</div>
					</details>
					<?php endif; ?>
					<?php endforeach; ?>
					<span class='help_text jr-help'><small>Note: not all events will be allocated to you. Events are allocated on a needs basis and we do take into consideration event workload.</small></span>
				</div>

			<div class="form-group form-btns jr-actions">
				<label></label>
				<button type="submit" class="btn btn-secondary">Save</button>
				<!--<button type="button" class="btn btn-secondary">Cancel</button>-->
				<?php echo $this->Html->link('Cancel', ['controller' => 'conventionregistrations', 'action' => 'myregistrations'], ['class' => 'btn btn-secondary']); ?>
			</div>
			<?php echo $this->Form->end(); ?>
	</div>
	<!-- dashboard-section-3 end-->

	</main>
</div>
</div>