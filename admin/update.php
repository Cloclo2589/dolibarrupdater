<?php
/* Copyright (C) 2026 IODE
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/dolibarrupdater/admin/update.php
 * \ingroup dolibarrupdater
 * \brief   Download a stable Dolibarr release, replace htdocs, then open the migration wizard.
 */

if (!defined('CSRFCHECK_WITH_TOKEN')) {
	define('CSRFCHECK_WITH_TOKEN', '1');
}

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include str_replace('..', '', $_SERVER['CONTEXT_DOCUMENT_ROOT']).'/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)).'/main.inc.php')) {
	$res = @include substr($tmp, 0, ($i + 1)).'/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))).'/main.inc.php')) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))).'/main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/dolibarrupdater/lib/dolibarrupdater.lib.php');
dol_include_once('/dolibarrupdater/class/dolibarrupdater.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'dolibarrupdater@dolibarrupdater'));

if (empty($user->admin) || !empty($user->socid)) {
	accessforbidden();
}

$hookmanager->initHooks(array('dolibarrupdatersetup', 'globalsetup'));

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$self = $_SERVER['PHP_SELF'].'?mainmenu=home&leftmenu=setup';

$updater = new DolibarrUpdater();
$form = new Form($db);
$formconfirm = '';
$autoContinue = false;

if ($action === 'createlock') {
	if ($updater->createInstallLock() > 0) {
		setEventMessages($langs->trans('DolibarrUpdaterLockCreated'), null, 'mesgs');
	} else {
		setEventMessages($updater->error, null, 'errors');
	}
	header('Location: '.$self);
	exit;
}

if ($action === 'confirm_deletelock' && $confirm === 'yes') {
	if ($updater->deleteInstallLock() > 0) {
		setEventMessages($langs->trans('DolibarrUpdaterLockDeleted'), null, 'mesgs');
	} else {
		setEventMessages($updater->error, null, 'errors');
	}
	header('Location: '.$self);
	exit;
}

if ($action === 'deletelock') {
	$formconfirm = $form->formconfirm(
		$self,
		$langs->trans('DolibarrUpdaterDeleteLock'),
		$langs->trans('DolibarrUpdaterDeleteLockWarning'),
		'confirm_deletelock',
		'',
		0,
		1,
		220,
		500,
		0,
		$langs->trans('DolibarrUpdaterDeleteLockConfirm'),
		$langs->trans('Cancel')
	);
}

if ($action === 'reset') {
	$updater->resetJob();
	setEventMessages($langs->trans('DolibarrUpdaterResetDone'), null, 'mesgs');
	header('Location: '.$self);
	exit;
}

if ($action === 'start') {
	if (!GETPOST('confirmbackup', 'int')) {
		setEventMessages($langs->trans('DolibarrUpdaterNeedConfirm'), null, 'errors');
	} else {
		$includeBeta = GETPOSTINT('includebeta') ? true : false;
		$major = $updater->normalizeMajor(GETPOST('major', 'aZ09'));
		$releases = $updater->selectReleases($updater->fetchStableReleases(), $includeBeta, $major);
		$version = GETPOST('targetversion', 'aZ09');
		if ($updater->startJob($version, $releases) > 0) {
			$autoContinue = true;
		} else {
			setEventMessages($updater->error, null, 'errors');
		}
		if ($updater->warning !== '') {
			setEventMessages($updater->warning, null, 'warnings');
		}
	}
}

if ($action === 'savegit') {
	$remote = GETPOST('git_remote', 'url');
	$branch = GETPOST('git_branch', 'alphanohtml');
	if ($updater->saveModuleGitConfig($db, $remote, $branch) > 0) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	} else {
		setEventMessages($updater->error, null, 'errors');
	}
	header('Location: '.$self);
	exit;
}

if ($action === 'updategit') {
	if ($updater->updateModuleFromGit() > 0) {
		setEventMessages($langs->trans('DolibarrUpdaterGitUpdated'), null, 'mesgs');
	} else {
		setEventMessages($updater->error, null, 'errors');
	}
	header('Location: '.$self);
	exit;
}

if ($action === 'run') {
	$result = $updater->advance();
	if ($result === 2) {
		header('Location: '.DOL_URL_ROOT.'/install/check.php');
		exit;
	}
	if ($result > 0) {
		$autoContinue = true;
	} else {
		setEventMessages($updater->error !== '' ? $updater->error : $langs->trans('DolibarrUpdaterNoJob'), null, 'errors');
	}
}

$state = $updater->loadState();
$checks = $updater->checkEnvironment();
$envOk = true;
foreach ($checks as $check) {
	if (empty($check['ok'])) {
		$envOk = false;
		break;
	}
}

$includeBeta = GETPOSTINT('includebeta') ? true : false;
$major = $updater->normalizeMajor(GETPOST('major', 'aZ09'));
$releases = array();
$newer = array();
$majors = array();
$latestStable = '';
if ($action !== 'run') {
	$allReleases = $updater->fetchStableReleases();
	$majors = $updater->listMajors($allReleases);
	foreach ($allReleases as $rel) {
		$channel = !empty($rel['channel']) ? $rel['channel'] : 'stable';
		if ($latestStable === '' && $channel === 'stable') {
			$latestStable = $rel['version'];
		}
	}
	$releases = $updater->selectReleases($allReleases, $includeBeta, $major);
	if ($updater->error !== '' && $action !== 'start') {
		setEventMessages($updater->error, null, 'errors');
		$updater->error = '';
	}
	if ($updater->warning !== '') {
		setEventMessages($updater->warning, null, 'warnings');
		$updater->warning = '';
	}
	$newer = $updater->newerReleases($releases);
}

$jobActive = (!empty($state['next']) && $state['next'] !== 'done');
$gitStatus = $updater->moduleGitStatus();
$checkLabels = array(
	'curl' => 'DolibarrUpdaterCheckCurl',
	'zip' => 'DolibarrUpdaterCheckZip',
	'htdocs' => 'DolibarrUpdaterCheckHtdocs',
	'data' => 'DolibarrUpdaterCheckData',
	'disk' => 'DolibarrUpdaterCheckDisk',
);

llxHeader('', $langs->trans('DolibarrUpdaterTitle'));

print $formconfirm;

$head = dolibarrupdaterAdminPrepareHead();
print dol_get_fiche_head($head, 'update', $langs->trans('DolibarrUpdaterTitle'), -1, 'fa-download');

print '<div class="info">';
print $langs->trans('DolibarrUpdaterWarnLocal').'<br>';
print $langs->trans('DolibarrUpdaterWarnWizard').'<br>';
print $langs->trans('DolibarrUpdaterWarnBusy');
print '</div>';

print '<br>';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('DolibarrUpdaterLockTitle').'</th></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DolibarrUpdaterLockPath').'</td><td>'.dol_escape_htmltag($updater->installLockPath()).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Status').'</td><td>';
if ($updater->isInstallLockPresent()) {
	print '<span class="badge badge-status4">'.$langs->trans('DolibarrUpdaterLockPresent').'</span>';
} else {
	print '<span class="badge badge-status8">'.$langs->trans('DolibarrUpdaterLockAbsent').'</span>';
}
print '</td></tr>';
print '</table></div>';

if ($updater->isHtdocsLockPresent()) {
	print '<div class="warning">'.$langs->trans('DolibarrUpdaterHtdocsLockNote').' '.dol_escape_htmltag($updater->htdocsLockPath()).'</div>';
}

print '<div class="tabsAction">';
if (!$updater->isInstallLockPresent()) {
	print '<form class="inline-block" method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="createlock">';
	print '<input type="hidden" name="mainmenu" value="home">';
	print '<input type="hidden" name="leftmenu" value="setup">';
	print '<input type="submit" class="butAction" value="'.dol_escape_htmltag($langs->trans('DolibarrUpdaterCreateLock')).'">';
	print '</form>';
} else {
	print '<form class="inline-block" method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="deletelock">';
	print '<input type="hidden" name="mainmenu" value="home">';
	print '<input type="hidden" name="leftmenu" value="setup">';
	print '<input type="submit" class="butActionDelete" value="'.dol_escape_htmltag($langs->trans('DolibarrUpdaterDeleteLock')).'">';
	print '</form>';
}
print '</div>';

print '<br>';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('DolibarrUpdaterChecks').'</th></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DolibarrUpdaterCurrentVersion').'</td><td>'.dol_escape_htmltag(DOL_VERSION).'</td></tr>';
foreach ($checks as $check) {
	$label = isset($checkLabels[$check['code']]) ? $langs->trans($checkLabels[$check['code']]) : $check['code'];
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($label).'</td><td>';
	if (!empty($check['ok'])) {
		print '<span class="badge badge-status4">'.$langs->trans('DolibarrUpdaterCheckOk').'</span>';
	} else {
		print '<span class="badge badge-status8">'.$langs->trans('DolibarrUpdaterCheckKo').'</span>';
	}
	if ($check['code'] === 'disk') {
		if (!empty($check['size'])) {
			print ' '.dol_escape_htmltag($langs->trans('DolibarrUpdaterDiskDetail', DolibarrUpdater::formatBytes($check['free']), DolibarrUpdater::formatBytes($check['needed'])));
		} else {
			print ' '.$langs->trans('DolibarrUpdaterDiskUnknown');
		}
	} elseif (!empty($check['detail'])) {
		print ' '.dol_escape_htmltag($check['detail']);
	}
	print '</td></tr>';
}
print '</table></div>';

if (!$envOk) {
	print '<div class="warning">'.$langs->trans('DolibarrUpdaterWarnWritable').'</div>';
}

// Module self-update via git
print '<br>';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('DolibarrUpdaterGitTitle').'</th></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DolibarrUpdaterModuleVersion').'</td><td>';
print dol_escape_htmltag(!empty($gitStatus['version']) ? $gitStatus['version'] : '-');
print '</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DolibarrUpdaterModuleUpdateStatus').'</td><td>';
if (!empty($gitStatus['update_available']) && !empty($gitStatus['remote_version'])) {
	print '<span class="badge badge-status1">'.$langs->trans('DolibarrUpdaterModuleUpdateAvailable', dol_escape_htmltag($gitStatus['remote_version'])).'</span>';
} elseif (!empty($gitStatus['remote_version']) && !empty($gitStatus['version'])) {
	print '<span class="badge badge-status4">'.$langs->trans('DolibarrUpdaterModuleUpToDate').'</span>';
} elseif ($gitStatus['remote'] === '') {
	print '<span class="opacitymedium">'.$langs->trans('DolibarrUpdaterGitNotConfigured').'</span>';
} else {
	print '<span class="opacitymedium">'.$langs->trans('DolibarrUpdaterModuleVersionCheckFailed').'</span>';
}
print '</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DolibarrUpdaterGitBinary').'</td><td>';
print !empty($gitStatus['git'])
	? '<span class="badge badge-status4">'.$langs->trans('DolibarrUpdaterCheckOk').'</span> '.dol_escape_htmltag($updater->gitBinary())
	: '<span class="badge badge-status8">'.$langs->trans('DolibarrUpdaterCheckKo').'</span>';
print '</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DolibarrUpdaterGitRepo').'</td><td>';
if (!empty($gitStatus['repo'])) {
	print '<span class="badge badge-status4">'.$langs->trans('DolibarrUpdaterGitRepoYes').'</span>';
	if (!empty($gitStatus['commit'])) {
		print ' '.dol_escape_htmltag($gitStatus['commit']);
	}
	if (!empty($gitStatus['dirty'])) {
		print ' <span class="badge badge-status1">'.$langs->trans('DolibarrUpdaterGitDirty').'</span>';
	}
} else {
	print '<span class="badge badge-status8">'.$langs->trans('DolibarrUpdaterGitRepoNo').'</span>';
}
print '</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Status').'</td><td>'.dol_escape_htmltag($gitStatus['message']).'</td></tr>';
print '</table></div>';

$branchOptions = !empty($gitStatus['branches']) && is_array($gitStatus['branches']) ? $gitStatus['branches'] : array();
$branchSelectable = ($gitStatus['remote'] !== '' && !empty($branchOptions));

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savegit">';
print '<input type="hidden" name="mainmenu" value="home">';
print '<input type="hidden" name="leftmenu" value="setup">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('DolibarrUpdaterGitRemote').'</td>';
print '<td><input class="flat minwidth400" type="url" name="git_remote" value="'.dol_escape_htmltag($gitStatus['remote']).'" placeholder="https://github.com/org/dolibarrupdater.git"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DolibarrUpdaterGitBranch').'</td><td>';
if ($branchSelectable) {
	print '<select class="flat minwidth200" name="git_branch">';
	foreach ($branchOptions as $branchOpt) {
		$selected = ($gitStatus['branch'] === $branchOpt) ? ' selected' : '';
		print '<option value="'.dol_escape_htmltag($branchOpt).'"'.$selected.'>'.dol_escape_htmltag($branchOpt).'</option>';
	}
	print '</select>';
} else {
	print '<select class="flat minwidth200" name="git_branch" disabled>';
	print '<option value="">'.$langs->trans('DolibarrUpdaterGitBranchUnavailable').'</option>';
	print '</select>';
	if ($gitStatus['remote'] === '') {
		print ' <span class="opacitymedium">'.$langs->trans('DolibarrUpdaterGitBranchSaveRemoteFirst').'</span>';
	} else {
		print ' <span class="opacitymedium">'.$langs->trans('DolibarrUpdaterGitBranchesEmpty').'</span>';
	}
}
print '</td></tr>';
print '</table></div>';
print '<div class="tabsAction">';
print '<input type="submit" class="butAction" value="'.dol_escape_htmltag($langs->trans('Save')).'">';
print '</form>';
if (!empty($gitStatus['can_update'])) {
	print '<form class="inline-block" method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" onsubmit="return confirm(\''.dol_escape_js($langs->trans('DolibarrUpdaterGitUpdateConfirm')).'\');">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="updategit">';
	print '<input type="hidden" name="mainmenu" value="home">';
	print '<input type="hidden" name="leftmenu" value="setup">';
	print '<input type="submit" class="butAction" value="'.dol_escape_htmltag($langs->trans('DolibarrUpdaterGitUpdate')).'">';
	print '</form>';
}
print '</div>';
print '<div class="info">'.$langs->trans('DolibarrUpdaterGitHelp').'</div>';

if ($jobActive || (!empty($state['next']) && $state['next'] === 'done')) {
	print '<br>';
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th colspan="2">'.$langs->trans('DolibarrUpdaterJob').'</th></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('DolibarrUpdaterTarget').'</td><td>'.dol_escape_htmltag(isset($state['target_version']) ? $state['target_version'] : '').'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans('Status').'</td><td>'.dol_escape_htmltag(isset($state['next']) ? $state['next'] : '').'</td></tr>';
	if (!empty($state['stats']['copied'])) {
		print '<tr class="oddeven"><td>'.$langs->trans('DolibarrUpdaterStepApply').'</td><td>';
		print dol_escape_htmltag($langs->trans(
			'DolibarrUpdaterDoneApply',
			(string) $state['stats']['copied'],
			(string) $state['stats']['skipped'],
			(string) $state['stats']['deleted']
		));
		print '</td></tr>';
	}
	print '</table></div>';

	if (!empty($state['log']) && is_array($state['log'])) {
		print '<br><div class="opacitymedium">'.$langs->trans('DolibarrUpdaterLog').'</div>';
		print '<pre class="small" style="max-height:240px;overflow:auto;white-space:pre-wrap;">';
		foreach ($state['log'] as $line) {
			print dol_escape_htmltag($line)."\n";
		}
		print '</pre>';
	}

	print '<div class="tabsAction">';
	if ($jobActive) {
		print '<form id="dolibarrupdater-next" class="inline-block" method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="run">';
		print '<input type="hidden" name="mainmenu" value="home">';
		print '<input type="hidden" name="leftmenu" value="setup">';
		print '<input type="submit" class="butAction" value="'.dol_escape_htmltag($langs->trans('DolibarrUpdaterContinue')).'">';
		print '</form>';
	}
	if (!empty($state['next']) && $state['next'] === 'done') {
		print '<div class="info">'.$langs->trans('DolibarrUpdaterJobDone').'</div>';
		print '<a class="butAction" href="'.dol_escape_htmltag(DOL_URL_ROOT.'/install/check.php').'">'.dol_escape_htmltag($langs->trans('DolibarrUpdaterOpenWizard')).'</a>';
	}
	print '<form class="inline-block" method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="reset">';
	print '<input type="hidden" name="mainmenu" value="home">';
	print '<input type="hidden" name="leftmenu" value="setup">';
	print '<input type="submit" class="butActionDelete" value="'.dol_escape_htmltag($langs->trans('DolibarrUpdaterReset')).'">';
	print '</form>';
	print '</div>';

	if ($autoContinue && $jobActive) {
		print '<script nonce="'.dol_escape_htmltag(getNonce()).'">document.getElementById("dolibarrupdater-next").submit();</script>';
	}
}

if (!$jobActive) {
	$canStart = (!empty($newer) && $envOk);
	print '<br>';
	print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
	print '<input type="hidden" name="mainmenu" value="home">';
	print '<input type="hidden" name="leftmenu" value="setup">';
	print '<label>'.$langs->trans('DolibarrUpdaterMajor').' ';
	print '<select class="flat minwidth100" name="major">';
	print '<option value="">'.$langs->trans('DolibarrUpdaterMajorAll').'</option>';
	foreach ($majors as $majorOpt) {
		print '<option value="'.dol_escape_htmltag($majorOpt).'"'.($major === $majorOpt ? ' selected' : '').'>'.dol_escape_htmltag($majorOpt).'</option>';
	}
	print '</select></label> ';
	print '<label><input type="checkbox" name="includebeta" value="1"'.($includeBeta ? ' checked' : '').'> '.$langs->trans('DolibarrUpdaterIncludeBeta').'</label> ';
	print '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('DolibarrUpdaterApplyFilter')).'">';
	print '</form>';
	if ($includeBeta) {
		print '<div class="warning">'.$langs->trans('DolibarrUpdaterBetaWarning').'</div>';
	}
	if ($major !== '') {
		print '<div class="info">'.$langs->trans('DolibarrUpdaterMajorFilterInfo', dol_escape_htmltag($major)).'</div>';
	}
	if ($canStart) {
		print '<form id="dolibarrupdater-start" method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="start">';
		print '<input type="hidden" name="mainmenu" value="home">';
		print '<input type="hidden" name="leftmenu" value="setup">';
		if ($includeBeta) {
			print '<input type="hidden" name="includebeta" value="1">';
		}
		if ($major !== '') {
			print '<input type="hidden" name="major" value="'.dol_escape_htmltag($major).'">';
		}
	}
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>'.$langs->trans('DolibarrUpdaterTarget').'</th><th>'.$langs->trans('DolibarrUpdaterPublished').'</th></tr>';
	if (empty($newer)) {
		if ($major !== '') {
			print '<tr class="oddeven"><td colspan="2">'.$langs->trans('DolibarrUpdaterNoNewerMajor', dol_escape_htmltag($major), dol_escape_htmltag(DOL_VERSION));
		} else {
			$emptyKey = $includeBeta ? 'DolibarrUpdaterNoNewerBeta' : 'DolibarrUpdaterNoNewer';
			print '<tr class="oddeven"><td colspan="2">'.$langs->trans($emptyKey, dol_escape_htmltag(DOL_VERSION));
		}
		if ($latestStable !== '') {
			print '<br>'.$langs->trans('DolibarrUpdaterLatestStable', dol_escape_htmltag($latestStable));
		}
		print '</td></tr>';
	} else {
		$first = true;
		foreach ($newer as $rel) {
			$published = '';
			if (!empty($rel['published'])) {
				$ts = strtotime($rel['published']);
				if ($ts) {
					$published = dol_print_date($ts, 'dayhour');
				}
			}
			print '<tr class="oddeven">';
			$channel = !empty($rel['channel']) ? $rel['channel'] : 'stable';
			print '<td><label><input type="radio" name="targetversion" value="'.dol_escape_htmltag($rel['version']).'"'.($first ? ' checked' : '').'> ';
			print dol_escape_htmltag($rel['version']);
			if ($channel === 'beta') {
				print ' <span class="badge badge-status1">'.dol_escape_htmltag($langs->trans('DolibarrUpdaterBeta')).'</span>';
			}
			print '</label></td>';
			print '<td>'.dol_escape_htmltag($published).'</td>';
			print '</tr>';
			$first = false;
		}
	}
	print '</table></div>';

	if ($canStart) {
		print '<div class="marginbottomonly margintoponly">';
		print '<label><input type="checkbox" name="confirmbackup" value="1"> '.$langs->trans('DolibarrUpdaterConfirmBackup').'</label>';
		print '</div>';
		print '<div class="center"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('DolibarrUpdaterStart')).'"></div>';
		print '</form>';
	}
}

print dol_get_fiche_end();
llxFooter();
$db->close();
