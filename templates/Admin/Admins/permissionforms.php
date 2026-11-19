<div class="content-wrapper">
    <section class="content-header">
        <h1>Permission Form Submissions</h1>
        <ol class="breadcrumb">
            <li><a href="<?php echo HTTP_PATH; ?>/admin/admins/dashboard"><i class="fa fa-dashboard"></i> Home</a></li>
            <li class="active">Permission Forms</li>
        </ol>
    </section>

    <section class="content">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <?php echo h($convSD->Conventions['name'] ?? 'Selected convention'); ?>
                    — <?php echo h($convSD->season_year ?? ''); ?>
                </h3>
            </div>
            <div class="box-body">
                <?php if (empty($permissionFormSubmissions)): ?>
                    <div class="alert alert-info">
                        No permission forms have been submitted for this convention season.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>School</th>
                                    <th>Form</th>
                                    <th>Submitted</th>
                                    <th>File</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($permissionFormSubmissions as $submission): ?>
                                    <tr>
                                        <td><?php echo h($submission['school_name'] ?? 'Unknown school'); ?></td>
                                        <td><?php echo h($submission['form_label'] ?? 'Permission Form'); ?></td>
                                        <td><?php echo h(date('j M Y, g:i a', strtotime((string)$submission['submitted_at']))); ?></td>
                                        <td>
                                            <?php echo $this->Html->link(
                                                '<i class="fa fa-download"></i> Download PDF',
                                                ['controller' => 'admins', 'action' => 'downloadpermissionform', $submission['id']],
                                                ['escape' => false, 'class' => 'btn btn-primary btn-xs']
                                            ); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>
