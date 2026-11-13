<?php echo $this->Html->script('facebox.js'); ?>
<?php echo $this->Html->css('facebox.css'); ?>

<!-- Breach Reason Modal -->
<div id="breachReasonModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:8px;width:420px;max-width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.2);overflow:hidden;">
    <div style="background:#f5f5f5;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #ddd;">
      <strong>Breach Reason</strong>
      <span onclick="document.getElementById('breachReasonModal').style.display='none'" style="cursor:pointer;font-size:1.3em;color:#888;">&times;</span>
    </div>
    <div style="padding:20px 24px;">
      <p id="breachReasonText" style="margin:0;color:#555;font-style:italic;"></p>
    </div>
  </div>
</div>
<script>
function showBreachReason(reason) {
    document.getElementById('breachReasonText').innerText = reason || 'No reason provided.';
    document.getElementById('breachReasonModal').style.display = 'flex';
}
</script>
<?php
use Cake\ORM\TableRegistry;
$this->Events = TableRegistry::getTableLocator()->get('Events');
$this->Judgeevaluations = TableRegistry::getTableLocator()->get('Judgeevaluations');
?>

<div class="admin_loader" id="loaderID"><?php echo $this->Html->image('loader_large_blue.gif');?></div>
<?php if (!$eventsubmissions->isEmpty()) { ?> 
    <?php
    $paginationParams = $this->Paginator->params();
    $totalEntries = (int)($paginationParams['count'] ?? count($eventsubmissions));
    ?>
    <div class="panel-body">
        <div class="ersu_message"> <?php echo $this->Flash->render() ?></div>
        <section id="no-more-tables" class="lstng-section">
            
            <div class="tbl-resp-listing">
                <table id="guideline_breach" class="table table-bordered table-striped table-condensed cf dataTable">
                    <thead class="cf ajshort">
                        <tr>
                            <th class="sorting_paging">#ID</th>
                            <th class="sorting_paging">Level</th>
                            <th class="sorting_paging">School</th>
                            <th class="sorting_paging">Convention</th>
                            <th class="sorting_paging">Event</th>
                            <th class="sorting_paging">Group</th>
                            <th class="sorting_paging">Student</th>
                            <th class="sorting_paging">Judge</th>
                            <th class="sorting_paging">Submitted Time</th>
                            <th class="sorting_paging">Status</th>
                            <th class="sorting_paging">Reason</th>
                            <th class="sorting_paging">Events Team Response</th>
                            <th class="sorting_paging">Display</th>
                            <th class="action_dvv"><i class=" fa fa-gavel"></i> Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eventsubmissions as $datarecord) { ?>
                            <tr>
                                <td data-title="#ID"><?php echo $datarecord->id;?></td>
                                <?php
                                $breachLevel = (string)($datarecord->guideline_breach_level ?? '');
                                $breachLevelLabels = [
                                    'minor' => ['Minor', '#b7950b'],
                                    'major' => ['Major', '#e67e22'],
                                    'extreme' => ['Extreme', '#c0392b'],
                                ];
                                $breachLevelDisplay = $breachLevelLabels[$breachLevel] ?? ['Unclassified', '#6c757d'];
                                ?>
                                <td data-title="Level">
                                    <span style="color:<?php echo h($breachLevelDisplay[1]); ?>;font-weight:700;" aria-label="<?php echo h($breachLevelDisplay[0]); ?>">
                                        <i class="fa fa-times" aria-hidden="true"></i> <?php echo h($breachLevelDisplay[0]); ?>
                                    </span>
                                </td>
                                <td data-title="School">
                                    <?php
                                    $schoolName = trim((string)($datarecord->Users['first_name'] ?? '') . ' ' . (string)($datarecord->Users['last_name'] ?? ''));
                                    if ($schoolName === '') {
                                        $schoolName = trim((string)($datarecord->Uploadeduser['first_name'] ?? '') . ' ' . (string)($datarecord->Uploadeduser['last_name'] ?? ''));
                                    }
                                    echo $schoolName !== '' ? h($schoolName) : '-';
                                    ?>
                                </td>
                                <td data-title="Convention"><?php echo $datarecord->Conventions['name'];?></td>
                                <td data-title="Event"><?php echo $datarecord->event_id_number.' '.$datarecord->Events['event_name'];?></td>
                                <td data-title="Group"><?php echo !empty($datarecord->group_name) ? "Group ".$datarecord->group_name : '-'; ?></td>
                                <td data-title="Student"><?php echo ($datarecord->student_id > 0) ? $datarecord->Students['first_name'].' '.$datarecord->Students['middle_name'].' '.$datarecord->Students['last_name'] : '-'; ?></td>
                                <td data-title="Judge"><?php echo $datarecord->Judge['first_name'].' '.$datarecord->Judge['last_name']; ?></td>
                                <?php
                                $submittedTs = 0;
                                $submittedTimeDisplay = '-';
                                if (!empty($datarecord->modified)) {
                                    if (is_object($datarecord->modified) && method_exists($datarecord->modified, 'getTimestamp')) {
                                        $submittedTs = (int)$datarecord->modified->getTimestamp();
                                        $submittedTimeDisplay = $datarecord->modified->format('h:i:s A');
                                    } else {
                                        $submittedTs = strtotime((string)$datarecord->modified);
                                        if ($submittedTs !== false && $submittedTs > 0) {
                                            $submittedTimeDisplay = date('h:i:s A', $submittedTs);
                                        } else {
                                            $submittedTs = 0;
                                        }
                                    }
                                }
                                ?>
                                <td data-title="Submitted Time" data-order="<?php echo $submittedTs; ?>"><?php echo $submittedTimeDisplay; ?></td>
                                <td data-title="Status">
                                    <?php if($datarecord->guideline_breach == 1): ?>
                                        <span style="background:#e67e22;color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;">Pending</span>
                                    <?php elseif($datarecord->guideline_breach == 2): ?>
                                        <span style="background:#27ae60;color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;">Accepted</span>
                                    <?php elseif($datarecord->guideline_breach == 3): ?>
                                        <span style="background:#6c757d;color:#fff;padding:3px 10px;border-radius:4px;font-size:12px;">Rejected</span>
                                    <?php endif; ?>
                                </td>
                                <td data-title="Reason">
                                    <?php
                                    $reason = trim((string)($datarecord->guideline_breach_reason ?? ''));
                                    $displayReason = $reason !== '' ? $reason : 'No reason provided.';
                                    ?>
                                    <button type="button"
                                        onclick="showBreachReason('<?php echo addslashes(h($displayReason)); ?>')"
                                        style="background:#27ae60;color:#fff;border:none;border-radius:4px;padding:4px 8px;cursor:pointer;">
                                        <i class="fa fa-eye"></i>
                                    </button>
                                </td>
                                <td data-title="Events Team Response" style="white-space:pre-wrap;"><?php echo h(trim((string)($datarecord->guideline_breach_team_response ?? '')) ?: '—'); ?></td>
                                <td data-title="Display">
                                    <?php if (\App\Service\GuidelineBreachPolicy::shouldRemoveFromDisplay($datarecord)): ?>
                                        <span style="color:#c0392b;font-weight:600;">Removed</span>
                                    <?php elseif ($breachLevel === 'extreme' && (int)$datarecord->guideline_breach === 1): ?>
                                        <span style="color:#777;">Team decision pending</span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td data-title="Action">
                                    <?php if($datarecord->guideline_breach == 1): ?>
                                        <?php
                                        $breachLevelRanks = ['minor' => 1, 'major' => 2, 'extreme' => 3];
                                        $currentLevelRank = $breachLevelRanks[$breachLevel] ?? 4;
                                        $reviewLevelOptions = array_filter(
                                            ['minor' => 'Minor', 'major' => 'Major', 'extreme' => 'Extreme'],
                                            static fn($key) => !isset($breachLevelRanks[$breachLevel]) || $breachLevelRanks[$key] <= $currentLevelRank,
                                            ARRAY_FILTER_USE_KEY
                                        );
                                        echo $this->Form->create(null, [
                                            'url' => ['controller' => 'eventsubmissions', 'action' => 'reviewguidelinebreach', $datarecord->slug],
                                            'type' => 'post',
                                            'style' => 'min-width:190px;',
                                        ]);
                                        echo $this->Form->select('guideline_breach_level', $reviewLevelOptions, [
                                            'value' => $breachLevel,
                                            'empty' => 'Select level',
                                            'class' => 'form-control input-sm',
                                            'required' => true,
                                        ]);
                                        echo $this->Form->textarea('guideline_breach_team_response', [
                                            'value' => (string)($datarecord->guideline_breach_team_response ?? ''),
                                            'rows' => 2,
                                            'class' => 'form-control input-sm',
                                            'placeholder' => 'Optional response to judge',
                                            'style' => 'margin-top:5px;',
                                        ]);
                                        if ($breachLevel === 'extreme') {
                                            echo '<label style="display:block;margin:5px 0;font-weight:normal;">';
                                            echo $this->Form->checkbox('guideline_breach_display_removed', [
                                                'value' => 1,
                                                'checked' => (int)($datarecord->guideline_breach_display_removed ?? 0) === 1,
                                            ]);
                                            echo ' Remove from public display</label>';
                                        }
                                        echo $this->Form->button('Accept', [
                                            'name' => 'decision',
                                            'value' => 'accept',
                                            'class' => 'btn btn-success btn-xs',
                                            'onclick' => "return confirm('Accept this breach and apply its tier restrictions?');",
                                        ]);
                                        echo ' '.$this->Form->button('Reject', [
                                            'name' => 'decision',
                                            'value' => 'reject',
                                            'class' => 'btn btn-danger btn-xs',
                                            'onclick' => "return confirm('Reject this breach and restore eligibility?');",
                                        ]);
                                        echo $this->Form->end();
                                        ?>
                                    <?php elseif(in_array((int)$datarecord->guideline_breach, [2, 3], true)): ?>
                                        <?php echo $this->Form->postLink('<i class="fa fa-trash"></i>', ['controller' => 'eventsubmissions', 'action' => 'deleteguidelinebreach', $datarecord->slug], ['escape' => false, 'title' => 'Clear breach', 'class' => 'btn btn-danger btn-xs', 'confirm' => 'Are you sure you want to clear this guideline breach?']); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top:10px;display:flex;justify-content:space-between;align-items:center;">
                <span id="gb-filter-status" style="font-size:12px;color:#666;"></span>
                <span style="font-weight:600;">Total entries: <?php echo number_format($totalEntries); ?></span>
            </div>
        </section>
    </div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<style>
#guideline_breach_filter label { display:flex; align-items:center; gap:8px; font-weight:600; color:#1c2452; justify-content:flex-end; }
#guideline_breach_filter { text-align:right !important; }
#guideline_breach_filter input { border:1px solid #ccc; border-radius:4px; padding:5px 10px; font-size:13px; width:260px; }
#guideline_breach_filter input:focus { outline:none; border-color:#1c2452; box-shadow:0 0 0 2px rgba(28,36,82,.15); }
.dataTables_info { font-size:12px; color:#666; }
.page-link { color:#1c2452 !important; }
.active>.page-link { background:#1c2452 !important; border-color:#1c2452 !important; color:#fff !important; }
</style>
<script>
$(document).ready(function() {
    var dt = $('#guideline_breach').DataTable({
        pageLength: 50,
        order: [[0, 'desc']],
        language: { search: '<i class="fa fa-search"></i>', searchPlaceholder: 'Search by school, event, student, judge...' },
        columnDefs: [{ orderable: false, targets: -1 }]
    });
    dt.on('search.dt draw.dt', function() {
        var info = dt.page.info();
        var txt = info.recordsDisplay < info.recordsTotal
            ? 'Showing ' + info.recordsDisplay + ' of ' + info.recordsTotal + ' entries'
            : '';
        $('#gb-filter-status').text(txt);
    });
});
</script>

<?php } else { ?>
    <div class="admin_no_record">No record found.</div>
<?php } ?>