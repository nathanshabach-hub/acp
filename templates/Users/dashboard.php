<?php
if (!function_exists('buildInstructionalVideoEmbedUrl')) {
    function buildInstructionalVideoEmbedUrl($videoLink)
    {
        $videoLink = trim((string)$videoLink);
        if ($videoLink === '') {
            return '';
        }
        if (preg_match('/youtu\.be\/([A-Za-z0-9_-]{6,})/i', $videoLink, $matches)) {
            return 'https://www.youtube.com/embed/' . $matches[1];
        }
        if (preg_match('/(?:v=|embed\/)([A-Za-z0-9_-]{6,})/i', $videoLink, $matches)) {
            return 'https://www.youtube.com/embed/' . $matches[1];
        }
        if (preg_match('/^[A-Za-z0-9_-]{6,}$/', $videoLink)) {
            return 'https://www.youtube.com/embed/' . $videoLink;
        }
        return $videoLink;
    }
}

$instructionalVideos = [];
if (!empty($settingsD)) {
    $videoLinks = [];
    $videoLinksFile = WWW_ROOT . 'files' . DS . 'dashboard_video_links.json';
    if (is_file($videoLinksFile)) {
        $fileVideoLinks = json_decode((string)@file_get_contents($videoLinksFile), true);
        if (is_array($fileVideoLinks)) {
            $videoLinks = $fileVideoLinks;
        }
    }
    if (!empty($settingsD->video_links_json)) {
        $decodedVideoLinks = json_decode((string)$settingsD->video_links_json, true);
        if (is_array($decodedVideoLinks)) {
            $videoLinks = $decodedVideoLinks;
        }
    }
    if (empty($videoLinks)) {
        for ($i = 1; $i <= 9; $i++) {
            $fieldName = 'video_link_' . $i;
            if (!empty($settingsD->{$fieldName})) {
                $videoLinks[] = $settingsD->{$fieldName};
            }
        }
    }
    foreach ((array)$videoLinks as $videoLink) {
        if (!is_scalar($videoLink) || trim((string)$videoLink) === '') {
            continue;
        }
        $instructionalVideos[] = [
            'title' => 'Portal walkthrough ' . (count($instructionalVideos) + 1),
            'url' => buildInstructionalVideoEmbedUrl($videoLink),
        ];
    }
}

$dashboardUser = isset($userDetails) && is_object($userDetails)
    ? $userDetails : (object)['first_name' => '', 'user_type' => ''];
$session = $this->request->getSession();
$userType = (string)$session->read('user_type');
$profileType = (string)$session->read('current_session_profile_type');
$hasConvention = $session->read('sess_selected_convention_registration_id') > 0;
$profileAction = $userType === 'Judge' ? 'judgeeditprofile' : 'editprofile';
$quickActions = [
    ['My profile', 'Keep your contact details up to date.', 'user-o', ['controller' => 'users', 'action' => $profileAction]],
    ['Account security', 'Manage your password securely.', 'lock', ['controller' => 'users', 'action' => 'changepassword']],
    ['Video guides', 'Get to know your convention portal.', 'play-circle-o', '#portal-guides'],
];

if ($userType === 'School') {
    $quickActions = [
        ['Convention registrations', 'View and manage your registrations.', 'calendar-o', ['controller' => 'conventionregistrations', 'action' => 'myregistrations']],
        ['Global students', 'Manage your school student list.', 'graduation-cap', ['controller' => 'users', 'action' => 'students']],
        ['Global supervisors', 'Keep your supervisor list up to date.', 'users', ['controller' => 'users', 'action' => 'teachers']],
        ['Payments', 'Review your payment transactions.', 'credit-card', ['controller' => 'transactions', 'action' => 'mytransactions']],
    ];
} elseif ($userType === 'Student') {
    $quickActions = [
        ['My schedule', 'Find out where you need to be.', 'calendar-o', ['controller' => 'users', 'action' => 'myschedule']],
        ['My events', 'See the events you are taking part in.', 'flag-o', ['controller' => 'users', 'action' => 'myevents']],
        ['Registration checklist', 'Review your convention registration.', 'check-square-o', ['controller' => 'conventionregistrations', 'action' => 'packageregistration']],
        ['My profile', 'Keep your personal details current.', 'user-o', ['controller' => 'users', 'action' => 'editprofile']],
    ];
} elseif ($userType === 'Judge' || ($userType === 'Teacher_Parent' && $profileType === 'Judge')) {
    array_unshift($quickActions, ['Convention registrations', 'View your convention registrations.', 'calendar-o', ['controller' => 'conventionregistrations', 'action' => 'myregistrations']]);
    if ($userType === 'Judge' && $hasConvention) {
        $quickActions[2] = ['My judging events', 'Access events for your convention.', 'flag-o', ['controller' => 'conventionregistrations', 'action' => 'judgeevents']];
    } elseif ($userType === 'Teacher_Parent') {
        $quickActions[2] = ['Judge experience', 'Review your judging experience.', 'star-o', ['controller' => 'users', 'action' => 'judgeexperience']];
    }
} elseif ($userType === 'Teacher_Parent' && $profileType === 'Supervisor' && $hasConvention) {
    $quickActions = [
        ['Student events', 'Manage your student event registrations.', 'calendar-plus-o', ['controller' => 'conventionregistrations', 'action' => 'studentevents']],
        ['Student grouping', 'Organise your convention groups.', 'users', ['controller' => 'groups', 'action' => 'viewlist']],
        ['Registration checklist', 'Review your convention registration.', 'check-square-o', ['controller' => 'conventionregistrations', 'action' => 'packageregistration']],
        ['My profile', 'Keep your contact details current.', 'user-o', ['controller' => 'users', 'action' => 'editprofile']],
    ];
} elseif ($userType === 'Teacher_Parent') {
    array_unshift($quickActions, ['Judge profile', 'View your judge profile and application.', 'id-card-o', ['controller' => 'users', 'action' => 'applyforjudge']]);
}
$primaryAction = $quickActions[0];
?>
<div class="container-fluid p-0">
    <div class="row">
        <?= $this->element('user_left_menu') ?>
        <main id="portal-main" class="col-md-9 ms-sm-auto col-lg-10 px-md-4 portal-main" tabindex="-1">
            <div class="ersu_message"><?= $this->Flash->render() ?></div>
            <div class="portal-page-heading">
                <div>
                    <p class="portal-eyebrow">Your workspace / Overview</p>
                    <h1>Dashboard</h1>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span><?= h(date('l, j F Y')) ?></span>
                    <?php if ($userType === 'Teacher_Parent'): ?>
                        <?php $hasUnreadNotifications = isset($unreadNotificationCount) && $unreadNotificationCount > 0; ?>
                        <?= $this->Html->link(
                            'Notification' . ($hasUnreadNotifications ? ' (' . (int)$unreadNotificationCount . ')' : ''),
                            ['controller' => 'users', 'action' => 'notifications'],
                            [
                                'class' => 'btn btn-outline-primary btn-sm' . ($hasUnreadNotifications ? ' portal-notification-unread' : ''),
                                'aria-label' => $hasUnreadNotifications
                                    ? 'Notifications: ' . (int)$unreadNotificationCount . ' unread messages' : 'Notifications',
                            ]
                        ) ?>
                    <?php endif; ?>
                </div>
            </div>

            <section class="portal-welcome" aria-labelledby="welcome-title">
                <canvas class="portal-welcome-particles" aria-hidden="true"></canvas>
                <div>
                    <p class="portal-eyebrow">A.C.E. Student Convention</p>
                    <h2 id="welcome-title">Welcome back<?= $dashboardUser->first_name ? ', ' . h($dashboardUser->first_name) : '' ?>.</h2>
                    <p>Your convention journey, all in one place. Manage your details, find what you need, and get ready for what comes next.</p>
                </div>
                <?= $this->Html->link(
                    h($primaryAction[0]) . ' <i class="fa fa-arrow-right" aria-hidden="true"></i>',
                    $primaryAction[3], ['class' => 'btn btn-primary', 'escape' => false]
                ) ?>
            </section>

            <section aria-labelledby="quick-access-title">
                <details class="portal-quick-accordion">
                    <summary class="portal-section-heading">
                        <h2 id="quick-access-title">Quick Access</h2>
                        <i class="fa fa-chevron-down portal-quick-chevron" aria-hidden="true"></i>
                        <span class="portal-quick-summary-description">Your most-used tools, one click away</span>
                    </summary>
                    <div class="portal-quick-grid">
                        <?php foreach ($quickActions as [$label, $description, $icon, $url]) { ?>
                            <a class="portal-quick-card" href="<?= h($this->Url->build($url)) ?>">
                                <span class="portal-quick-icon"><i class="fa fa-<?= h($icon) ?>" aria-hidden="true"></i></span>
                                <strong><?= h($label) ?></strong>
                                <p><?= h($description) ?></p>
                                <i class="fa fa-arrow-right" aria-hidden="true"></i>
                            </a>
                        <?php } ?>
                    </div>
                </details>
            </section>

            <div class="portal-dashboard-columns">
                <section id="portal-guides" class="portal-panel portal-main" aria-labelledby="guides-title">
                    <div class="portal-section-heading">
                        <div>
                            <h2 id="guides-title">A little guidance goes a long way</h2>
                            <p>Step-by-step videos to help you navigate the portal.</p>
                        </div>
                        <span class="portal-video-count"><?= count($instructionalVideos) ?> videos</span>
                    </div>
                    <?php if ($instructionalVideos) { ?>
                        <div class="portal-video-grid">
                            <?php foreach ($instructionalVideos as $index => $video) { ?>
                                <article class="portal-video-card">
                                    <div class="portal-video-frame">
                                        <iframe src="<?= h($video['url']) ?>" title="<?= h($video['title']) ?>" loading="lazy"
                                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                            referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                                    </div>
                                    <p class="portal-video-meta">Video <?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?> / Portal guide</p>
                                    <h3><?= h($video['title']) ?></h3>
                                </article>
                            <?php } ?>
                        </div>
                    <?php } else { ?>
                        <div class="portal-empty-state">Instructional videos will appear here when available. In the meantime, our events team can help you get started.</div>
                    <?php } ?>
                </section>

                <aside class="portal-dashboard-aside" aria-label="Updates and support">
                    <?php if ($userType !== 'Student' && !empty($settingsD->postinfo)) { ?>
                        <section class="portal-panel">
                            <div class="portal-aside-title">
                                <i class="fa fa-bullhorn" aria-hidden="true"></i>
                                <h2>From the events team</h2>
                            </div>
                            <div class="portal-announcement"><?= $settingsD->postinfo ?></div>
                        </section>
                    <?php } ?>
                    <section class="portal-panel">
                        <div class="portal-aside-title">
                            <i class="fa fa-compass" aria-hidden="true"></i>
                            <h2>Make yourself at home</h2>
                        </div>
                        <ol class="portal-guide-steps">
                            <li>
                                <strong><?= $userType === 'Student' ? 'Explore your convention' : 'Choose your convention' ?></strong>
                                <p><?= $userType === 'Student' ? 'Your schedule and events are available from the menu.' : 'Use the selector at the top to open your convention workspace.' ?></p>
                            </li>
                            <li>
                                <strong>Keep your details current</strong>
                                <p>Check your profile so the events team can stay in touch.</p>
                            </li>
                            <li>
                                <strong>Find your next step</strong>
                                <p>Use the navigation or watch a walkthrough whenever you need a hand.</p>
                            </li>
                        </ol>
                    </section>
                    <section class="portal-panel portal-support-panel">
                        <i class="fa fa-comments-o" aria-hidden="true"></i>
                        <h2>Here to help you</h2>
                        <p>Have a question about registration or finding your way around? Reach out to our friendly events team.</p>
                        <a href="mailto:events@scee.edu.au">Contact the events team <i class="fa fa-arrow-right" aria-hidden="true"></i></a>
                    </section>
                </aside>
            </div>
        </main>
    </div>
</div>
