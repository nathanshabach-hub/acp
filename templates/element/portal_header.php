<?php
use Cake\ORM\TableRegistry;

$session = $this->request->getSession();
$portalUser = TableRegistry::getTableLocator()->get('Users')->find()
    ->where(['Users.id' => $session->read('user_id')])->first();
$portalName = (string)($portalUser->first_name ?? 'My account');
$portalType = (string)$session->read('user_type');
$profileType = (string)$session->read('current_session_profile_type');
$portalRole = $portalType === 'Teacher_Parent' ? ($profileType ?: 'Supervisor') : $portalType;
$selectedConvention = $session->read('sess_selected_convention_registration_id')
    ? $session->read('sess_selected_convention_id') : '';
$profileAction = $portalType === 'Judge' ? 'judgeeditprofile' : 'editprofile';
?>
<header class="portal-header">
    <a class="portal-skip-link" href="#portal-content">Skip to content</a>
    <a class="portal-brand" href="<?= $this->Url->build(['controller' => 'Users', 'action' => 'dashboard']) ?>" aria-label="Accelerate convention portal home">
        <?= $this->Html->image('front/main-logo-120px.png', ['alt' => 'Accelerate']) ?>
        <span>CONVENTION PORTAL</span>
    </a>
    <button class="user-header-sidebar-toggle portal-menu-button" type="button"
        aria-controls="sidebarMenu" aria-expanded="false" aria-label="Toggle navigation" title="Show or hide navigation">
        <i class="fa fa-bars" aria-hidden="true"></i>
    </button>
    <div class="portal-header-workspace">
        <?php if ($portalType !== 'Student') { ?>
            <form method="post" action="<?= h(HTTP_PATH) ?>/homes/headerconvddfrmsubmit"
                id="header_conventions_dd_frm" class="portal-convention-form">
                <label for="convention_id">Your convention</label>
                <select name="Conventions[convention_id]" id="convention_id" class="form-select" autocomplete="off">
                    <option value="">Choose a convention</option>
                    <?php foreach (($userConvHeaderDD ?? []) as $conventionId => $conventionName) { ?>
                        <option value="<?= h($conventionId) ?>" <?= (string)$selectedConvention === (string)$conventionId ? 'selected' : '' ?>><?= h($conventionName) ?></option>
                    <?php } ?>
                </select>
                <noscript><button class="btn btn-primary" type="submit">Select convention</button></noscript>
            </form>
        <?php } else { ?>
            <span class="portal-workspace-label">Your student workspace</span>
        <?php } ?>
    </div>
    <nav class="portal-header-actions" aria-label="Resources and account">
        <details class="portal-dropdown portal-resources">
            <summary aria-label="Convention resources"><i class="fa fa-question-circle-o" aria-hidden="true"></i><span>Resources</span><i class="fa fa-angle-down" aria-hidden="true"></i></summary>
            <div class="portal-dropdown-menu">
                <p>Convention resources</p>
                <a href="https://convention.accelerateministries.com.au/what-is-student-convention/" target="_blank" rel="noopener">About student convention</a>
                <a href="https://convention.accelerateministries.com.au/guidelines/" target="_blank" rel="noopener">Guidelines</a>
                <a href="https://convention.accelerateministries.com.au/international/" target="_blank" rel="noopener">International Student Convention (ISC)</a>
            </div>
        </details>
        <details class="portal-dropdown portal-account">
            <summary aria-label="Account menu for <?= h($portalName) ?>">
                <span class="portal-avatar"><?= h(mb_strtoupper(mb_substr($portalName, 0, 1))) ?></span>
                <span class="portal-account-label"><strong><?= h($portalName) ?></strong><small><?= h($portalRole) ?> account</small></span>
                <i class="fa fa-angle-down" aria-hidden="true"></i>
            </summary>
            <div class="portal-dropdown-menu">
                <p>My account</p>
                <?= $this->Html->link('Edit profile', ['controller' => 'Users', 'action' => $profileAction]) ?>
                <?php if ($session->read('school_student_access') === null): ?>
                    <?= $this->Html->link('Change password', ['controller' => 'Users', 'action' => 'changepassword']) ?>
                <?php endif; ?>
                <?php if ($portalType === 'Teacher_Parent' && $portalUser && $portalUser->is_judge == '1') { ?>
                    <?= $this->Html->link(
                        $profileType === 'Judge' ? 'Switch to Supervisor' : 'Switch to Judge',
                        ['controller' => 'Users', 'action' => 'switchprofile', $profileType === 'Judge' ? 'switchtosupervisor' : 'switchtojudge']
                    ) ?>
                <?php } ?>
                <hr>
                <?= $this->Html->link('Sign out', ['controller' => 'Users', 'action' => 'logout']) ?>
            </div>
        </details>
    </nav>
</header>
