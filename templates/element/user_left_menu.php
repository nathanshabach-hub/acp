<?php
$session = $this->request->getSession();
$userType = (string)$session->read('user_type');
$profileType = (string)$session->read('current_session_profile_type');
$hasConvention = $session->read('sess_selected_convention_registration_id') > 0;
$menuGroups = [
    'Workspace' => [
        ['Dashboard', 'home', 'users', 'dashboard', 'active_dashboard'],
        ['Notifications', 'bell-o', 'users', 'notifications', 'active_notifications'],
    ],
];

if ($userType === 'School') {
    $menuGroups['Workspace'][] = ['Global Supervisors List', 'users', 'users', 'teachers', 'active_teachers'];
    $menuGroups['Workspace'][] = ['Global Students List', 'graduation-cap', 'users', 'students', 'active_students'];
    $menuGroups['Workspace'][] = ['Convention Registrations', 'calendar-o', 'conventionregistrations', 'myregistrations', 'active_convention_registrations'];
    if ($hasConvention) {
        $menuGroups['Selected convention'] = [
            ['Price Structure', 'tag', 'conventionregistrations', 'pricestructure', 'active_cr_price_structure'],
            ['Supervisor Registration', 'user-plus', 'conventionregistrations', 'teachers', 'active_cr_teachers'],
            ['Student Registration', 'graduation-cap', 'conventionregistrations', 'students', 'active_cr_students'],
            ['Student Event Registration', 'calendar-plus-o', 'conventionregistrations', 'studentevents', 'active_cr_studentevents'],
            ['Student Grouping', 'users', 'groups', 'viewlist', 'active_cr_studentgroups'],
            ['Events of the Heart', 'heart-o', 'heartevents', 'viewlist', 'active_cr_eventsheart'],
            ['View/Edit Event Submissions', 'file-text-o', 'eventsubmissions', 'viewlist', 'active_cr_eventsubmission'],
            ['Registration Checklist', 'check-square-o', 'conventionregistrations', 'packageregistration', 'active_cr_packageregistration'],
            ['Permission Forms', 'clipboard', 'conventionregistrations', 'permissionsforms', 'active_cr_permissionforms'],
            ['Judges Registered', 'gavel', 'conventionregistrations', 'judgesregistered', 'active_cr_judgesregistered'],
            ['Result Package', 'trophy', 'conventionregistrations', 'resultpackage', 'active_cr_resultpackage'],
            ['Reports', 'line-chart', 'Reports', 'index', 'active_reports'],
        ];
    }
    $menuGroups['Payments'] = [
        ['Payment Transactions', 'credit-card', 'transactions', 'mytransactions', 'active_transactions'],
    ];
} elseif ($userType === 'Student') {
    $menuGroups['My convention'] = [
        ['Registration Checklist', 'check-square-o', 'conventionregistrations', 'packageregistration', 'active_cr_packageregistration'],
        ['Student Event Registration', 'calendar-plus-o', 'conventionregistrations', 'studentevents', 'active_cr_studentevents'],
        ['My Schedule', 'calendar-o', 'users', 'myschedule', 'active_myschedule'],
        ['My Events', 'flag-o', 'users', 'myevents', 'active_myevents'],
    ];
} elseif ($userType === 'Teacher_Parent') {
    if ($hasConvention && $profileType === 'Supervisor') {
        $menuGroups['Selected convention'] = [
            ['Student Event Registration', 'calendar-plus-o', 'conventionregistrations', 'studentevents', 'active_cr_studentevents'],
            ['Student Grouping', 'users', 'groups', 'viewlist', 'active_cr_studentgroups'],
            ['Events of the Heart', 'heart-o', 'heartevents', 'viewlist', 'active_cr_eventsheart'],
            ['View/Edit Event Submissions', 'file-text-o', 'eventsubmissions', 'viewlist', 'active_cr_eventsubmission'],
            ['Registration Checklist', 'check-square-o', 'conventionregistrations', 'packageregistration', 'active_cr_packageregistration'],
            ['Scripture', 'book', 'scripture', 'index', 'active_scripture'],
        ];
    }
    if ($profileType === 'Judge') {
        $menuGroups['Judging'] = [
            ['Judge Experience', 'star-o', 'users', 'judgeexperience', 'active_judgeexp'],
            ['Convention Registrations', 'calendar-o', 'conventionregistrations', 'myregistrations', 'active_convention_registrations'],
        ];
        if ($hasConvention) {
            $menuGroups['Judging'][] = ['Scripture', 'book', 'scripture', 'index', 'active_scripture'];
        }
    }
} elseif ($userType === 'Judge') {
    $menuGroups['Workspace'][] = ['Convention Registrations', 'calendar-o', 'conventionregistrations', 'myregistrations', 'active_convention_registrations'];
    if ($hasConvention) {
        $menuGroups['Selected convention'] = [
            ['Events', 'flag-o', 'conventionregistrations', 'judgeevents', 'active_cr_judgeevents'],
            ['Scripture', 'book', 'scripture', 'index', 'active_scripture'],
        ];
    }
}

$menuGroups['Account'] = [
    ['Edit Profile', 'user-o', 'users', $userType === 'Judge' ? 'judgeeditprofile' : 'editprofile', 'active_editprofile'],
    ['Change Password', 'lock', 'users', 'changepassword', 'active_changepassword'],
];
if ($session->read('school_student_access') !== null) {
    $menuGroups['Account'] = array_values(array_filter($menuGroups['Account'], static fn($item) => $item[3] !== 'changepassword'));
}
if ($userType === 'Teacher_Parent') {
    $menuGroups['Account'][] = ['Judge Profile', 'id-card-o', 'users', 'applyforjudge', 'active_applyforjudge'];
}
if (!empty($supervisorAgreementRequired) && $userType === 'Teacher_Parent' && $profileType === 'Supervisor' && isset($menuGroups['Selected convention'])) {
    foreach ($menuGroups['Selected convention'] as &$item) {
        if ($item[2] === 'scripture') {
            continue;
        }
        $item[1] = 'lock';
        $item[2] = 'users';
        $item[3] = 'notifications';
    }
    unset($item);
}
$menuGroups['Account'][] = ['Logout', 'sign-out', 'users', 'logout', ''];

if ($session->read('user_id') > 0 && in_array($userType, ['School', 'Student', 'Teacher_Parent', 'Judge'], true)) {
?>
<button class="portal-sidebar-backdrop" type="button" tabindex="-1" aria-label="Close navigation" hidden></button>
<nav id="sidebarMenu" class="col-md-3 col-lg-2 sidebar portal-sidebar" aria-label="Main navigation">
    <div class="portal-sidebar-heading">
        <span class="portal-sidebar-heading-label">Your workspace</span>
        <div class="portal-sidebar-heading-actions">
            <span class="portal-sidebar-dot" aria-hidden="true"></span>
            <button class="portal-theme-toggle" type="button" aria-label="Night mode" aria-pressed="false" title="Turn on night mode" hidden>
                <i class="fa fa-moon-o" aria-hidden="true"></i>
            </button>
        </div>
    </div>
    <?php if (!empty($supervisorAgreementRequired)): ?>
        <div class="portal-sidebar-help">
            <strong>Supervisor agreement required</strong>
            <p><?= h($supervisorAgreementError ?? 'Accept in Notifications to unlock your student convention activities.') ?></p>
            <?= $this->Html->link('Review agreement', ['controller' => 'users', 'action' => 'notifications']) ?>
        </div>
    <?php endif; ?>
    <?php foreach ($menuGroups as $groupTitle => $items) { ?>
        <div class="portal-nav-group">
            <h2><?= h($groupTitle) ?></h2>
            <ul class="nav flex-column">
                <?php foreach ($items as [$label, $icon, $controller, $action, $activeVariable]) {
                    $isActive = $activeVariable !== '' && $this->get($activeVariable) === 'active';
                    ?>
                    <li class="nav-item">
                        <?= $this->Html->link(
                            '<i class="fa fa-' . h($icon) . '" aria-hidden="true"></i><span>' . h($label) . '</span>' .
                            ($isActive ? '<i class="fa fa-angle-right portal-nav-arrow" aria-hidden="true"></i>' : ''),
                            ['controller' => $controller, 'action' => $action],
                            ['escape' => false, 'class' => 'nav-link' . ($isActive ? ' active' : ''), 'aria-current' => $isActive ? 'page' : null, 'aria-label' => $label, 'title' => $label]
                        ) ?>
                    </li>
                <?php } ?>
            </ul>
        </div>
    <?php } ?>
    <div class="portal-sidebar-help">
        <i class="fa fa-life-ring" aria-hidden="true"></i>
        <strong>A little help along the way</strong>
        <p>Our events team is here for you.</p>
        <a href="mailto:events@scee.edu.au">Contact support <i class="fa fa-arrow-right" aria-hidden="true"></i></a>
    </div>
</nav>
<?php } ?>
