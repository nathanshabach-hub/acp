<?php
use App\Service\ResultPackageService;
use Cake\ORM\TableRegistry;

$events = (new ResultPackageService(TableRegistry::getTableLocator()))->events(
    $arrConvSeasonEvent,
    (int)$userDetails->id,
    (int)$conventionRegD->conventionseason_id,
    (int)$conventionRegD->id
);
?>

<style>
.event-result-accordion {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.event-result-item {
    border: 1px solid #dce4ef;
    border-radius: 8px;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
}
.event-result-summary {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    cursor: pointer;
    background: #f8fafc;
    font-weight: 600;
    color: #1f2937;
    list-style: none;
}
.event-result-summary::-webkit-details-marker {
    display: none;
}
.event-result-summary:focus {
    outline: 2px solid #7c93b2;
    outline-offset: 2px;
}
.event-result-meta {
    display: block;
    font-size: 12px;
    color: #5b6673;
    font-weight: 500;
    margin-top: 2px;
}
.event-result-badge {
    background: #e3eefb;
    color: #21528f;
    border-radius: 999px;
    padding: 4px 8px;
    font-size: 12px;
    white-space: nowrap;
}
.event-result-body {
    padding: 12px 14px 14px;
    border-top: 1px solid #eef2f7;
}
.event-result-table {
    margin-bottom: 0;
}
.event-result-table thead > tr > th {
    background: #f4f7fb;
}
.event-result-table td {
    vertical-align: top;
}
.event-result-table .student_cp {
    display: inline-block;
    margin-top: 4px;
    color: #b45309;
    font-size: 12px;
}
</style>

<?php if (count($arrConvSeasonEvent) > 0) { ?>

    <div class="panel-body">

        <?php echo $this->Form->create(null, ['id' => 'actionFrom', 'method' => 'Post']); ?>
        <section id="no-more-tables" class="lstng-section">

            <div class="tbl-resp-listing">
                <?php if (count($arrConvSeasonEvent) > 0) { ?>
                    <div class="event-result-accordion">
                        <?php foreach ($events as $event) { ?>
                            <?php
                            $overallpositions = $event['positions'];
                            $countpositions = count($overallpositions);
                            ?>

                                <details class="event-result-item" open>
                                    <summary class="event-result-summary">
                                        <span>
                                            <strong><?php echo h($event['event_name']); ?></strong>
                                            <span class="event-result-meta"><?php echo h($event['event_id_number']); ?></span>
                                        </span>
                                        <span class="event-result-badge"><?php echo $countpositions; ?> placement<?php echo $countpositions == 1 ? '' : 's'; ?></span>
                                    </summary>
                                    <div class="event-result-body">
                                        <?php if ($countpositions > 0) { ?>
                                            <table class="table table-bordered table-condensed event-result-table">
                                                <thead>
                                                    <tr>
                                                        <th width="20%">Position</th>
                                                        <th width="40%">Student / Group</th>
                                                        <th width="30%">School</th>
                                                        <th width="10%">Print</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($overallpositions as $ovpos) { ?>
                                                        <tr>
                                                            <td data-title="Position"><?php echo h($ovpos['position']); ?></td>
                                                            <td data-title="Student / Group">
                                                                <?php echo h($ovpos['showName']); ?>
                                                                <?php if ($ovpos['commandPerformance']) { echo '<br><span class="student_cp">This is event was nominated for a Command Performance.</span>'; } ?>
                                                            </td>
                                                            <td data-title="School"><?php echo h($ovpos['showSchoolName']); ?></td>
                                                            <td data-title="Print Certificate">
                                                                <?php echo $this->Html->link('<i class="fa fa-print"></i>', ['controller' => 'conventionregistrations', 'action' => 'placecertificatepdf', $ovpos['slug'], $ovpos['position']], ['escape' => false, 'class' => '', 'target' => '_blank', 'title' => 'Print Place Certificate']); ?>
                                                            </td>
                                                        </tr>
                                                    <?php } ?>
                                                </tbody>
                                            </table>
                                        <?php } else { ?>
                                            <div class="text-muted">No placements found for this event.</div>
                                        <?php } ?>
                                    </div>
                                </details>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        </section>

        <?php echo $this->Form->end(); ?>
    </div>

<?php } else { ?>
    <div id="listingJS" style="display: none;" class="alert alert-success alert-block fade in"></div>
    <div class="admin_no_record">No student event found.</div>
<?php } ?>
