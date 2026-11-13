<style>
.notification-inbox { border: 1px solid #dce5eb; border-radius: 12px; background: #fff; overflow: hidden; }
.notification-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 22px; background: #f7fafb; border-bottom: 1px solid #e3eaef; }
.user-portal .notification-folders { display: flex; flex-wrap: wrap; gap: 8px; background: transparent; }
.user-portal .notification-folder { padding: 8px 14px; border-radius: 7px; color: #536574 !important; }
.user-portal .notification-folder[aria-current="page"] { color: #075e59 !important; background: #dff3ef; font-weight: 700; }
.notification-count { margin-left: 5px; font-size: 12px; }
.notification-note { color: #657887; font-size: 13px; margin-bottom: 18px; }
.notification-row { border-bottom: 1px solid #edf1f4; position: relative; }
.notification-row:last-child { border-bottom: 0; }
.notification-row.unread { background: #f0f8fc; box-shadow: inset 3px 0 #087f78; }
.notification-row form, .notification-actions form { margin: 0; }
.user-portal .notification-open { display: grid; grid-template-columns: minmax(120px, 180px) minmax(0, 1fr) 145px; align-items: center; gap: 20px; padding: 20px 24px; color: #304858 !important; width: 100%; background: transparent; border: 0; border-radius: 0; text-align: left; font: inherit; cursor: pointer; }
.user-portal .notification-open:hover { background: #e7f2f5; }
.notification-open:focus-visible { outline: 3px solid #087f78; outline-offset: -3px; }
.notification-sender, .notification-subject, .notification-preview { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.notification-row.unread .notification-sender, .notification-row.unread .notification-subject { font-weight: 700; }
.notification-preview { color: #6a7e8d; font-size: 13px; margin-top: 4px; }
.notification-time { font-size: 12px; color: #617583; text-align: right; }
.notification-unread-label { font-size: 11px; color: #07695e; margin-right: 7px; }
.notification-detail { padding: 28px; }
.notification-detail h2 { font-size: 24px; overflow-wrap: anywhere; }
.notification-detail-meta { color: #617583; font-size: 13px; padding: 12px 0 22px; border-bottom: 1px solid #e3eaef; }
.notification-body { white-space: pre-wrap; overflow-wrap: anywhere; margin: 24px 0; line-height: 1.8; }
.notification-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.user-portal .notification-inbox .btn-outline-secondary:hover,
.user-portal .notification-inbox .btn-outline-secondary:focus-visible,
.user-portal .notification-inbox .btn-outline-secondary:active { color: #fff !important; background-color: #536574; border-color: #536574; }
.notification-empty { padding: 60px 24px; text-align: center; color: #637b89; }
.notification-empty i { display: block; font-size: 36px; margin-bottom: 16px; color: #80aaa4; }
.notification-pagination { padding: 16px 22px; border-top: 1px solid #e3eaef; display: flex; justify-content: space-between; align-items: center; gap: 12px; background: #fff; }
@media (max-width: 767px) {
    .user-portal .notification-open { grid-template-columns: minmax(0, 1fr) auto; gap: 8px 12px; padding: 16px; }
    .notification-summary { grid-column: 1 / -1; grid-row: 2; }
    .notification-time { font-size: 11px; }
    .notification-detail { padding: 20px 16px; }
    .notification-detail h2 { font-size: 20px; }
    .notification-toolbar { padding: 14px; }
}
</style>
<div class="container-fluid p-0">
    <div class="row">
        <?= $this->element('user_left_menu') ?>
        <main id="portal-main" class="col-md-9 ms-sm-auto col-lg-10 px-md-4 portal-main" tabindex="-1">
            <div class="ersu_message"><?= $this->Flash->render() ?></div>
            <div class="portal-page-heading">
                <div><p class="portal-eyebrow">Your workspace</p><h1>Notifications</h1></div>
                <?php if ($isSchool): ?>
                    <?= $this->Html->link('Manage sponsorship requests', ['action' => 'crossschoolrequests'], ['class' => 'btn btn-outline-secondary']) ?>
                <?php endif; ?>
            </div>
            <p class="notification-note">Your portal updates, in one place.<?= $isSchool ? ' Sponsorship updates and portal email notices appear here.' : ' Portal email notices appear here.' ?> Full email contents and attachments are not copied here; schools and judges can open their sent invoices securely from their invoice notices.</p>
            <?php if (isset($agreementsAvailable) && !$agreementsAvailable): ?>
                <div class="alert alert-warning">Supervisor agreements are unavailable until migrations 012 and 014 are applied. Please contact the events team.</div>
            <?php endif; ?>
            <?php if (!$available): ?>
                <div class="alert alert-warning">The notification inbox is unavailable until database migration 012 is applied. Please contact the events team.</div>
            <?php else: ?>
                <section class="notification-inbox" aria-label="Notifications">
                    <div class="notification-toolbar">
                        <nav class="notification-folders" aria-label="Notification folders">
                            <?php foreach (['inbox' => 'Inbox', 'unread' => 'Unread', 'archive' => 'Archived'] as $key => $label): ?>
                                <?= $this->Html->link(
                                    h($label) . ($key === 'unread' ? '<span class="notification-count">(' . (int)$inbox['counts']['unread'] . ')</span>' : ''),
                                    ['action' => 'notifications', '?' => ['folder' => $key]],
                                    ['class' => 'notification-folder', 'escapeTitle' => false, 'aria-current' => $folder === $key ? 'page' : null]
                                ) ?>
                            <?php endforeach; ?>
                        </nav>
                        <span class="notification-note mb-0"><?= (int)$inbox['total'] ?> message<?= $inbox['total'] === 1 ? '' : 's' ?></span>
                    </div>
                    <?php if ($message): ?>
                        <article class="notification-detail">
                            <div class="notification-actions mb-4">
                                <?= $this->Html->link('Back to list', ['action' => 'notifications', '?' => ['folder' => $folder]], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
                                <?= $this->Form->postButton($message['read_at'] ? 'Mark unread' : 'Mark read',
                                    ['action' => 'notificationaction', $message['id'], $message['read_at'] ? 'unread' : 'read'],
                                    ['class' => 'btn btn-outline-secondary btn-sm']) ?>
                                <?= $this->Form->postButton($message['archived_at'] ? 'Move to inbox' : 'Archive',
                                    ['action' => 'notificationaction', $message['id'], $message['archived_at'] ? 'restore' : 'archive'],
                                    ['class' => 'btn btn-outline-secondary btn-sm']) ?>
                            </div>
                            <h2><?= h($message['subject']) ?></h2>
                            <div class="notification-detail-meta">
                                <strong><?= h($message['sender']) ?></strong>
                                <span class="ms-2"><?= h($message['created']) ?> (Brisbane time)</span>
                                <span class="d-block mt-1"><?= match ($message['kind']) {
                                    'email' => 'Portal email notice',
                                    'supervisor_agreement' => 'Supervisor agreement',
                                    default => 'Sponsorship update',
                                } ?></span>
                            </div>
                            <?php if ($message['kind'] !== 'supervisor_agreement'): ?>
                                <div class="notification-body"><?= h($message['body']) ?></div>
                            <?php endif; ?>
                            <?php if ($message['kind'] === 'supervisor_agreement' && isset($agreementsAvailable) && $agreementsAvailable): ?>
                                <?php if ($notificationAgreement): ?>
                                    <?= $this->element('supervisor_agreements', ['supervisorAgreements' => [$notificationAgreement]]) ?>
                                <?php else: ?>
                                    <p class="alert alert-info">No students are currently assigned to you for this agreement's convention. There is no agreement to accept.</p>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($notificationInvoice)): ?>
                                <div class="notification-actions">
                                    <?= $this->Html->link('View Invoice', ['action' => 'notificationinvoice', $message['id']], ['class' => 'btn btn-primary', 'target' => '_blank', 'rel' => 'noopener']) ?>
                                    <?= $this->Html->link('Download PDF', ['action' => 'notificationinvoice', $message['id'], '?' => ['download' => '1']], ['class' => 'btn btn-outline-secondary']) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($isSchool && $message['kind'] === 'sponsorship'): ?>
                                <?= $this->Html->link('View sponsorship requests', ['action' => 'crossschoolrequests'], ['class' => 'btn btn-primary']) ?>
                            <?php endif; ?>
                        </article>
                    <?php elseif (!$inbox['messages']): ?>
                        <div class="notification-empty">
                            <i class="fa fa-envelope-o" aria-hidden="true"></i>
                            <h2 class="h5"><?= $folder === 'unread' ? 'You are all caught up' : ($folder === 'archive' ? 'No archived messages' : 'Your inbox is clear') ?></h2>
                            <p class="mb-0"><?= $isSchool ? 'New sponsorship updates and portal email notices' : 'New portal email notices' ?> will appear in your inbox.</p>
                        </div>
                    <?php else: ?>
                        <div aria-label="Messages">
                            <?php foreach ($inbox['messages'] as $entry): ?>
                                <div class="notification-row<?= $entry['read_at'] ? '' : ' unread' ?>">
                                    <?php
                                    $preview = preg_replace('/\s+/', ' ', $entry['body']);
                                    $row = '<span class="notification-sender">' . h($entry['sender']) . '</span>'
                                        . '<span class="notification-summary"><span class="notification-subject">'
                                        . ($entry['read_at'] ? '' : '<span class="notification-unread-label">Unread</span>')
                                        . h($entry['subject']) . '</span><span class="notification-preview">' . h($preview) . '</span></span>'
                                        . '<time class="notification-time" datetime="' . h(str_replace(' ', 'T', $entry['created']) . '+10:00') . '">'
                                        . h(date('j M Y, g:ia', strtotime($entry['created']))) . '</time>';
                                    ?>
                                    <?= $this->Form->postButton($row, ['action' => 'notificationaction', $entry['id'], 'read', 'open'],
                                        ['class' => 'notification-open', 'escapeTitle' => false, 'aria-label' => 'Open ' . $entry['subject']]) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($inbox['pages'] > 1): ?>
                            <nav class="notification-pagination" aria-label="Message pages">
                                <span>Page <?= (int)$inbox['page'] ?> of <?= (int)$inbox['pages'] ?></span>
                                <div class="notification-actions">
                                    <?php foreach ([-1 => 'Previous', 1 => 'Next'] as $step => $label): ?>
                                        <?php if ($inbox['page'] + $step >= 1 && $inbox['page'] + $step <= $inbox['pages']): ?>
                                            <?= $this->Html->link($label, ['action' => 'notifications', '?' => ['folder' => $folder, 'page' => $inbox['page'] + $step]], ['class' => 'btn btn-outline-secondary btn-sm']) ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>
</div>
