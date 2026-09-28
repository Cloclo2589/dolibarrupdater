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
 * \file    htdocs/custom/dolibarrupdater/class/dolibarrupdater.class.php
 * \ingroup dolibarrupdater
 * \brief   Download a stable Dolibarr release and replace htdocs files.
 */

/**
 * Engine that downloads a GitHub stable release and applies it on htdocs.
 */
class DolibarrUpdater
{
	/**
	 * @var string Last error message, already translated when $langs is available
	 */
	public $error = '';

	/**
	 * @var string Non-blocking warning, already translated
	 */
	public $warning = '';

	/**
	 * @var array<string,mixed>|null In-memory job state
	 */
	private $state = null;

	/**
	 * @var array<int,array<string,mixed>>|null Cached environment checks
	 */
	private $envChecks = null;

	const MAX_ARCHIVE_BYTES = 419430400; // 400 MiB
	const MIN_ARCHIVE_BYTES = 1048576; // 1 MiB
	const MIN_PACKAGE_FILES = 1000;

	/**
	 * Path of documents/install.lock.
	 *
	 * @return string
	 */
	public function installLockPath()
	{
		return DOL_DATA_ROOT.'/install.lock';
	}

	/**
	 * Path of htdocs/install.lock, displayed only.
	 *
	 * @return string
	 */
	public function htdocsLockPath()
	{
		return DOL_DOCUMENT_ROOT.'/install.lock';
	}

	/**
	 * @return bool
	 */
	public function isInstallLockPresent()
	{
		return is_file($this->installLockPath());
	}

	/**
	 * @return bool
	 */
	public function isHtdocsLockPresent()
	{
		return is_file($this->htdocsLockPath());
	}

	/**
	 * Create documents/install.lock. Does not replace an existing file.
	 *
	 * @return int 1 if created, -1 on error
	 */
	public function createInstallLock()
	{
		global $langs;
		$langs->load('dolibarrupdater@dolibarrupdater');

		$path = $this->installLockPath();
		if (!is_dir(DOL_DATA_ROOT) || !is_writable(DOL_DATA_ROOT)) {
			$this->error = $langs->trans('DolibarrUpdaterLockWriteError');
			return -1;
		}
		if (is_file($path)) {
			$this->error = $langs->trans('DolibarrUpdaterLockAlready');
			return -1;
		}

		$content = "This is a lock file to prevent use of install or upgrade pages (set with permission 644)\n";
		$content .= 'Created: '.gmdate('c')."\n";
		if (file_put_contents($path, $content, LOCK_EX) === false) {
			$this->error = $langs->trans('DolibarrUpdaterLockWriteError');
			return -1;
		}
		@chmod($path, 0644);
		return 1;
	}

	/**
	 * Delete documents/install.lock and nothing else.
	 *
	 * @return int 1 if deleted, -1 on error
	 */
	public function deleteInstallLock()
	{
		global $langs;
		$langs->load('dolibarrupdater@dolibarrupdater');

		$path = $this->installLockPath();
		if (!is_file($path)) {
			$this->error = $langs->trans('DolibarrUpdaterLockMissing');
			return -1;
		}

		$real = realpath($path);
		$data = realpath(DOL_DATA_ROOT);
		if ($real === false || $data === false || basename($real) !== 'install.lock' || dirname($real) !== $data) {
			$this->error = $langs->trans('DolibarrUpdaterLockDeleteError');
			return -1;
		}
		if (!@unlink($real)) {
			$this->error = $langs->trans('DolibarrUpdaterLockDeleteError');
			return -1;
		}
		return 1;
	}

	/**
	 * Pre-update checks.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function checkEnvironment()
	{
		if (is_array($this->envChecks)) {
			return $this->envChecks;
		}

		@set_time_limit(120);

		$checks = array();
		$checks[] = array('code' => 'curl', 'ok' => function_exists('curl_init'));
		$checks[] = array('code' => 'zip', 'ok' => class_exists('ZipArchive'));

		$htdocsOk = is_dir(DOL_DOCUMENT_ROOT) && is_writable(DOL_DOCUMENT_ROOT) && is_dir(DOL_DOCUMENT_ROOT.'/core') && is_writable(DOL_DOCUMENT_ROOT.'/core');
		$checks[] = array('code' => 'htdocs', 'ok' => $htdocsOk, 'detail' => DOL_DOCUMENT_ROOT);

		$dataOk = is_dir(DOL_DATA_ROOT) && is_writable(DOL_DATA_ROOT);
		$checks[] = array('code' => 'data', 'ok' => $dataOk, 'detail' => DOL_DATA_ROOT);

		$size = $this->directorySize(DOL_DOCUMENT_ROOT);
		$free = @disk_free_space(DOL_DATA_ROOT);
		$needed = ($size > 0) ? (int) (($size * 2) + (150 * 1024 * 1024)) : 0;
		$diskOk = ($size > 0 && $free !== false && $free >= $needed);
		$checks[] = array(
			'code' => 'disk',
			'ok' => $diskOk,
			'free' => ($free === false ? 0 : (int) $free),
			'needed' => $needed,
			'size' => $size,
		);

		$this->envChecks = $checks;
		return $this->envChecks;
	}

	/**
	 * @return bool
	 */
	public function environmentAllowsUpdate()
	{
		foreach ($this->checkEnvironment() as $check) {
			if (empty($check['ok'])) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Stable x.y.z releases from GitHub, newest first.
	 *
	 * @return array<int,array{version:string,published:string}>
	 */
	public function fetchStableReleases()
	{
		global $langs;
		$langs->load('dolibarrupdater@dolibarrupdater');
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		$cache = $this->workDir().'/releases-v2.cache.json';
		if (is_file($cache) && (time() - filemtime($cache) < 600)) {
			$cached = $this->readJsonFile($cache);
			if (is_array($cached)) {
				return $cached;
			}
		}

		$raw = $this->httpGet('https://api.github.com/repos/Dolibarr/dolibarr/releases?per_page=100');
		if ($raw === false) {
			$cached = $this->readJsonFile($cache);
			if (is_array($cached)) {
				$this->error = '';
				$this->warning = $langs->trans('DolibarrUpdaterGithubCached');
				return $cached;
			}
			if ($this->error === '') {
				$this->error = $langs->trans('DolibarrUpdaterGithubError', 'HTTP');
			} else {
				$this->error = $langs->trans('DolibarrUpdaterGithubError', $this->error);
			}
			return array();
		}

		$data = json_decode($raw, true);
		if (!is_array($data)) {
			$this->error = $langs->trans('DolibarrUpdaterGithubError', 'JSON');
			return array();
		}
		if (isset($data['message']) && !isset($data[0])) {
			$this->error = $langs->trans('DolibarrUpdaterGithubError', (string) $data['message']);
			return array();
		}

		$out = array();
		foreach ($data as $rel) {
			if (!is_array($rel)) {
				continue;
			}
			$tag = isset($rel['tag_name']) ? (string) $rel['tag_name'] : '';
			if (!empty($rel['draft']) || !$this->isVersionToken($tag)) {
				continue;
			}
			$channel = $this->channelOfTag($tag, !empty($rel['prerelease']));
			if ($channel === '') {
				continue;
			}
			$out[] = array(
				'version' => $tag,
				'published' => isset($rel['published_at']) ? (string) $rel['published_at'] : '',
				'channel' => $channel,
			);
		}

		usort($out, function ($a, $b) {
			$va = preg_split('/[\-\.]/', $a['version']);
			$vb = preg_split('/[\-\.]/', $b['version']);
			return versioncompare($vb, $va);
		});

		if ($this->ensureDir($this->workDir())) {
			$this->writeFile($cache, json_encode($out));
			$oldCache = $this->workDir().'/releases.cache.json';
			if (is_file($oldCache)) {
				@unlink($oldCache);
			}
		}
		return $out;
	}

	/**
	 * Stable releases, plus betas when requested, optionally limited to one major series.
	 *
	 * @param array<int,array{version:string,published:string,channel?:string}> $releases Releases from GitHub
	 * @param bool $includeBeta True to keep beta tags
	 * @param string $major Empty for all, or "19", "21", "24"...
	 * @return array<int,array{version:string,published:string,channel?:string}>
	 */
	public function selectReleases($releases, $includeBeta, $major = '')
	{
		$out = array();
		$major = $this->normalizeMajor($major);
		foreach ($releases as $rel) {
			$channel = !empty($rel['channel']) ? (string) $rel['channel'] : 'stable';
			if ($channel !== 'stable' && !($includeBeta && $channel === 'beta')) {
				continue;
			}
			if ($major !== '' && $this->majorOfVersion($rel['version']) !== $major) {
				continue;
			}
			$out[] = $rel;
		}
		return $out;
	}

	/**
	 * Major numbers present in a release list, newest first.
	 *
	 * @param array<int,array{version:string}> $releases Releases
	 * @return array<int,string>
	 */
	public function listMajors($releases)
	{
		$majors = array();
		foreach ($releases as $rel) {
			$major = $this->majorOfVersion(isset($rel['version']) ? $rel['version'] : '');
			if ($major !== '' && !isset($majors[$major])) {
				$majors[$major] = $major;
			}
		}
		$keys = array_map('strval', array_keys($majors));
		rsort($keys, SORT_NUMERIC);
		return $keys;
	}

	/**
	 * @param string $version Version token
	 * @return string Major number or empty
	 */
	public function majorOfVersion($version)
	{
		if (!preg_match('/^(\d+)\./', (string) $version, $m)) {
			return '';
		}
		return $m[1];
	}

	/**
	 * @param string $major Candidate major
	 * @return string Digits only, or empty
	 */
	public function normalizeMajor($major)
	{
		$major = trim((string) $major);
		if ($major === '' || $major === 'all' || $major === '0') {
			return '';
		}
		return preg_match('/^\d{1,3}$/', $major) ? $major : '';
	}

	/**
	 * x.y.z or x.y.z-beta / x.y.z-beta2. Nothing else is accepted in URLs or filenames.
	 *
	 * @param string $version Version tag
	 * @return bool
	 */
	public function isVersionToken($version)
	{
		return (bool) preg_match('/^\d+\.\d+\.\d+(-beta\d*)?$/', (string) $version);
	}

	/**
	 * @param string $tag        Release tag
	 * @param bool   $prerelease GitHub prerelease flag
	 * @return string stable, beta, or empty when refused
	 */
	private function channelOfTag($tag, $prerelease)
	{
		if (preg_match('/^\d+\.\d+\.\d+-beta\d*$/', $tag)) {
			return 'beta';
		}
		if (preg_match('/^\d+\.\d+\.\d+$/', $tag)) {
			return $prerelease ? 'beta' : 'stable';
		}
		return '';
	}

	/**
	 * Releases strictly newer than DOL_VERSION.
	 *
	 * @param array<int,array{version:string,published:string}> $releases Stable releases
	 * @return array<int,array{version:string,published:string}>
	 */
	public function newerReleases($releases)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$current = preg_split('/[\-\.]/', DOL_VERSION);
		$newer = array();
		foreach ($releases as $rel) {
			$candidate = preg_split('/[\-\.]/', $rel['version']);
			if (versioncompare($candidate, $current) > 0) {
				$newer[] = $rel;
			}
		}
		return $newer;
	}

	/**
	 * @param string $version Candidate x.y.z
	 * @param array<int,array{version:string,published:string}> $releases Stable releases
	 * @return bool
	 */
	public function isAllowedVersion($version, $releases)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		if (!$this->isVersionToken($version)) {
			return false;
		}
		$current = preg_split('/[\-\.]/', DOL_VERSION);
		$candidate = preg_split('/[\-\.]/', $version);
		if (versioncompare($candidate, $current) <= 0) {
			return false;
		}
		foreach ($releases as $rel) {
			if (!empty($rel['version']) && $rel['version'] === $version) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Official zip URL. Built locally, never taken from the API payload.
	 *
	 * @param string $version Stable x.y.z
	 * @return string Empty when the version is not a stable tag
	 */
	public function releaseZipUrl($version)
	{
		if (!$this->isVersionToken($version)) {
			return '';
		}
		return 'https://github.com/Dolibarr/dolibarr/releases/download/'.$version.'/dolibarr-'.$version.'.zip';
	}

	/**
	 * @return array<string,mixed>
	 */
	public function loadState()
	{
		if (is_array($this->state)) {
			return $this->state;
		}
		$data = $this->readJsonFile($this->stateFile());
		if (!is_array($data)) {
			$this->state = array();
			return $this->state;
		}
		$this->state = $data;
		return $this->state;
	}

	/**
	 * Prepare a job. Does not download yet.
	 *
	 * @param string $version Target stable version
	 * @param array<int,array{version:string,published:string}> $releases Stable releases
	 * @return int 1 if OK, -1 if KO
	 */
	public function startJob($version, $releases)
	{
		global $langs;
		$langs->load('dolibarrupdater@dolibarrupdater');

		$this->loadState();
		if (!empty($this->state['next']) && $this->state['next'] !== 'done') {
			$this->error = $langs->trans('DolibarrUpdaterJobExists');
			return -1;
		}
		if (!$this->environmentAllowsUpdate()) {
			$this->error = $langs->trans('DolibarrUpdaterEnvBlocked');
			return -1;
		}
		if (!$this->isAllowedVersion($version, $releases)) {
			$this->error = $langs->trans('DolibarrUpdaterBadVersion');
			return -1;
		}
		if (!$this->ensureDir($this->workDir()) || !$this->ensureDir($this->workDir().'/backups')) {
			$this->error = $langs->trans('DolibarrUpdaterNotWritable', DOL_DATA_ROOT);
			return -1;
		}

		$safeVersion = $version;
		$this->state = array(
			'target_version' => $safeVersion,
			'current_version' => DOL_VERSION,
			'next' => 'backup',
			'done' => array(),
			'log' => array(),
			'backup_dir' => $this->workDir().'/backups/'.DOL_VERSION.'-'.gmdate('YmdHis'),
			'zip_file' => $this->workDir().'/dolibarr-'.$safeVersion.'.zip',
			'extract_dir' => $this->workDir().'/extract',
			'source_htdocs' => '',
			'stats' => array('copied' => 0, 'skipped' => 0, 'deleted' => 0),
		);
		$this->appendLog($langs->trans('DolibarrUpdaterJobStarted', $safeVersion));
		return 1;
	}

	/**
	 * Run the step stored in the job, then advance.
	 *
	 * @return int 1 if a step finished, 2 if the wizard must be opened, -1 on error
	 */
	public function advance()
	{
		global $langs;
		$langs->load('dolibarrupdater@dolibarrupdater');

		$this->loadState();
		if (empty($this->state['next']) || $this->state['next'] === 'done') {
			$this->error = $langs->trans('DolibarrUpdaterNoJob');
			return -1;
		}
		if (!$this->validStatePaths()) {
			return -1;
		}

		@set_time_limit(0);
		@ignore_user_abort(true);

		$step = (string) $this->state['next'];
		$this->appendLog($langs->trans('DolibarrUpdaterStepStart', $langs->trans($this->stepLangKey($step))));

		$result = $this->executeStep($step);
		if ($result < 0) {
			if ($this->error !== '') {
				$this->appendLog($this->error);
			}
			return -1;
		}

		$this->state['done'][$step] = gmdate('c');
		if ($step === 'unlock') {
			$this->state['next'] = 'done';
			$this->saveState();
			return 2;
		}

		$map = array(
			'backup' => 'download',
			'download' => 'extract',
			'extract' => 'apply',
			'apply' => 'unlock',
		);
		if (empty($map[$step])) {
			$this->error = $langs->trans('DolibarrUpdaterNoJob');
			return -1;
		}
		$this->state['next'] = $map[$step];
		$this->saveState();
		return 1;
	}

	/**
	 * Drop the job, the archive and the extract directory. Backups stay.
	 *
	 * @return int
	 */
	public function resetJob()
	{
		$this->loadState();
		if (!empty($this->state['zip_file']) && $this->isUnderWork($this->state['zip_file']) && is_file($this->state['zip_file'])) {
			@unlink($this->state['zip_file']);
		}
		if (!empty($this->state['extract_dir']) && $this->isUnderWork($this->state['extract_dir']) && is_dir($this->state['extract_dir'])) {
			$this->deleteTree($this->state['extract_dir']);
		}
		$file = $this->stateFile();
		$this->state = null;
		if (is_file($file)) {
			@unlink($file);
		}
		return 1;
	}

	/**
	 * Human size.
	 *
	 * @param int|float $bytes Bytes
	 * @return string
	 */
	public static function formatBytes($bytes)
	{
		$bytes = (float) $bytes;
		if ($bytes < 1024) {
			return round($bytes).' B';
		}
		if ($bytes < 1048576) {
			return round($bytes / 1024, 1).' KB';
		}
		if ($bytes < 1073741824) {
			return round($bytes / 1048576, 1).' MB';
		}
		return round($bytes / 1073741824, 2).' GB';
	}

	/**
	 * @param string $step Step code
	 * @return int 1 or 2 if OK, -1 if KO
	 */
	private function executeStep($step)
	{
		if ($step === 'backup') {
			return $this->runBackup();
		}
		if ($step === 'download') {
			return $this->runDownload();
		}
		if ($step === 'extract') {
			return $this->runExtract();
		}
		if ($step === 'apply') {
			return $this->runApply();
		}
		if ($step === 'unlock') {
			return $this->runUnlock();
		}
		global $langs;
		$this->error = $langs->trans('DolibarrUpdaterNoJob');
		return -1;
	}

	/**
	 * @return int
	 */
	private function runBackup()
	{
		global $langs;

		$backup = (string) $this->state['backup_dir'];
		if (!$this->isUnderWork($backup) || !$this->ensureDir($backup)) {
			$this->error = $langs->trans('DolibarrUpdaterNotWritable', $backup);
			return -1;
		}

		$confSrc = DOL_DOCUMENT_ROOT.'/conf/conf.php';
		if (!is_file($confSrc)) {
			$this->error = $langs->trans('DolibarrUpdaterConfMissing');
			return -1;
		}
		if (!copy($confSrc, $backup.'/conf.php')) {
			$this->error = $langs->trans('DolibarrUpdaterCopyFailed', 'conf/conf.php');
			return -1;
		}
		@chmod($backup.'/conf.php', 0600);

		$stats = $this->copyTree(DOL_DOCUMENT_ROOT, $backup.'/htdocs', 'backup');
		if ($stats === null) {
			return -1;
		}
		$this->appendLog($langs->trans('DolibarrUpdaterDoneBackup', (string) $stats['copied'], $backup));
		return 1;
	}

	/**
	 * @return int
	 */
	private function runDownload()
	{
		global $langs;

		$version = (string) $this->state['target_version'];
		$url = $this->releaseZipUrl($version);
		$dest = (string) $this->state['zip_file'];
		if ($url === '' || !$this->isUnderWork($dest)) {
			$this->error = $langs->trans('DolibarrUpdaterBadVersion');
			return -1;
		}
		if (is_file($dest)) {
			@unlink($dest);
		}

		$fh = fopen($dest, 'wb');
		if ($fh === false) {
			$this->error = $langs->trans('DolibarrUpdaterNotWritable', dirname($dest));
			return -1;
		}

		$ch = curl_init($url);
		if ($ch === false) {
			fclose($fh);
			$this->error = $langs->trans('DolibarrUpdaterDownloadFailed', 'curl');
			return -1;
		}
		$options = array(
			CURLOPT_FILE => $fh,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_USERAGENT => 'DolibarrUpdater/'.DOL_VERSION,
			CURLOPT_FAILONERROR => true,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		);
		curl_setopt_array($ch, $options);
		$this->forceHttps($ch);
		curl_setopt($ch, CURLOPT_NOPROGRESS, false);
		curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($resource, $downloadSize, $downloaded) {
			if ($downloadSize > self::MAX_ARCHIVE_BYTES || $downloaded > self::MAX_ARCHIVE_BYTES) {
				return 1;
			}
			return 0;
		});

		$ok = curl_exec($ch);
		$error = curl_error($ch);
		$final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
		curl_close($ch);
		fclose($fh);

		if ($ok !== true) {
			@unlink($dest);
			$this->error = $langs->trans('DolibarrUpdaterDownloadFailed', $error !== '' ? $error : 'curl');
			return -1;
		}
		$host = parse_url((string) $final, PHP_URL_HOST);
		if (!$this->isAllowedDownloadHost((string) $host)) {
			@unlink($dest);
			$this->error = $langs->trans('DolibarrUpdaterBadHost');
			return -1;
		}
		if (!$this->zipLooksValid($dest)) {
			@unlink($dest);
			return -1;
		}

		$this->appendLog($langs->trans('DolibarrUpdaterDoneDownload', self::formatBytes((int) filesize($dest))));
		return 1;
	}

	/**
	 * @return int
	 */
	private function runExtract()
	{
		global $langs;

		$version = (string) $this->state['target_version'];
		$zipFile = (string) $this->state['zip_file'];
		$extract = (string) $this->state['extract_dir'];
		if (!$this->zipLooksValid($zipFile) || !$this->isUnderWork($extract)) {
			return -1;
		}
		if (is_dir($extract)) {
			if ($this->deleteTree($extract) < 0) {
				$this->error = $langs->trans('DolibarrUpdaterNotWritable', $extract);
				return -1;
			}
		}
		if (!$this->ensureDir($extract)) {
			$this->error = $langs->trans('DolibarrUpdaterNotWritable', $extract);
			return -1;
		}

		$zip = new ZipArchive();
		if ($zip->open($zipFile) !== true) {
			$this->error = $langs->trans('DolibarrUpdaterBadArchive');
			return -1;
		}
		if (!$this->zipEntriesAreSafe($zip)) {
			$zip->close();
			$this->error = $langs->trans('DolibarrUpdaterZipSlip');
			return -1;
		}
		$extracted = $zip->extractTo($extract);
		$zip->close();
		if ($extracted !== true) {
			$this->error = $langs->trans('DolibarrUpdaterBadArchive');
			return -1;
		}

		$source = $extract.'/dolibarr-'.$version.'/htdocs';
		$versionFile = $source.'/version.inc.php';
		if (!is_file($versionFile) || !$this->isUnder($versionFile, $extract)) {
			$this->error = $langs->trans('DolibarrUpdaterMissingHtdocs');
			return -1;
		}
		$found = $this->readPackageVersion($versionFile);
		if ($found !== $version) {
			$this->error = $langs->trans('DolibarrUpdaterVersionMismatch', $version);
			return -1;
		}

		$this->state['source_htdocs'] = $source;
		$this->saveState();
		$this->appendLog($langs->trans('DolibarrUpdaterDoneExtract', $found));
		return 1;
	}

	/**
	 * @return int
	 */
	private function runApply()
	{
		global $langs;

		$version = (string) $this->state['target_version'];
		$source = (string) $this->state['source_htdocs'];
		$extract = (string) $this->state['extract_dir'];
		if ($source === '' || !$this->isUnder($source, $extract) || !is_file($source.'/version.inc.php')) {
			$this->error = $langs->trans('DolibarrUpdaterMissingHtdocs');
			return -1;
		}
		if ($this->readPackageVersion($source.'/version.inc.php') !== $version) {
			$this->error = $langs->trans('DolibarrUpdaterVersionMismatch', $version);
			return -1;
		}

		$stats = $this->copyTree($source, DOL_DOCUMENT_ROOT, 'apply');
		if ($stats === null) {
			return -1;
		}
		if ($stats['copied'] < self::MIN_PACKAGE_FILES) {
			$this->error = $langs->trans('DolibarrUpdaterSourceTooSmall');
			return -1;
		}
		if ($this->readPackageVersion(DOL_DOCUMENT_ROOT.'/version.inc.php') !== $version) {
			$this->error = $langs->trans('DolibarrUpdaterVersionMismatch', $version);
			return -1;
		}

		$deleted = $this->pruneTree($source, DOL_DOCUMENT_ROOT);
		if ($deleted < 0) {
			return -1;
		}

		$this->state['stats'] = array(
			'copied' => $stats['copied'],
			'skipped' => $stats['skipped'],
			'deleted' => $deleted,
		);
		$this->saveState();
		$this->appendLog($langs->trans('DolibarrUpdaterDoneApply', (string) $stats['copied'], (string) $stats['skipped'], (string) $deleted));
		return 1;
	}

	/**
	 * @return int
	 */
	private function runUnlock()
	{
		global $langs;

		if (!is_dir(DOL_DATA_ROOT) || !is_writable(DOL_DATA_ROOT)) {
			$this->error = $langs->trans('DolibarrUpdaterUnlockFailed');
			return -1;
		}
		$file = DOL_DATA_ROOT.'/upgrade.unlock';
		$content = 'Unlocked by dolibarrupdater on '.gmdate('c').' for upgrade to '.$this->state['target_version']."\n";
		if (file_put_contents($file, $content, LOCK_EX) === false) {
			$this->error = $langs->trans('DolibarrUpdaterUnlockFailed');
			return -1;
		}
		@chmod($file, 0644);
		return 1;
	}

	/**
	 * @param string $source Source directory
	 * @param string $dest   Destination directory
	 * @param string $mode   backup or apply
	 * @return array{copied:int,skipped:int}|null
	 */
	private function copyTree($source, $dest, $mode)
	{
		global $langs;

		$sourceReal = realpath($source);
		if ($sourceReal === false || !is_dir($sourceReal)) {
			$this->error = $langs->trans('DolibarrUpdaterPathRefused', $source);
			return null;
		}
		if ($mode === 'apply') {
			$doc = realpath(DOL_DOCUMENT_ROOT);
			$destReal = realpath($dest);
			if ($doc === false || $destReal === false || $destReal !== $doc) {
				$this->error = $langs->trans('DolibarrUpdaterPathRefused', $dest);
				return null;
			}
		} else {
			if (!$this->ensureDir($dest)) {
				$this->error = $langs->trans('DolibarrUpdaterNotWritable', $dest);
				return null;
			}
			$destReal = realpath($dest);
			if ($destReal === false || !$this->isUnderWork($destReal)) {
				$this->error = $langs->trans('DolibarrUpdaterPathRefused', $dest);
				return null;
			}
		}

		$copied = 0;
		$skipped = 0;
		$directory = new RecursiveDirectoryIterator($sourceReal, FilesystemIterator::SKIP_DOTS);
		$filter = new RecursiveCallbackFilterIterator($directory, function ($current) use ($sourceReal, $mode) {
			if ($current->isLink()) {
				return false;
			}
			$rel = $this->relativePath($sourceReal, $current->getPathname());
			if ($rel === null || $this->hasDotDot($rel)) {
				return false;
			}
			if ($mode === 'apply' && $this->isExcludedFromCopy($rel)) {
				return false;
			}
			if ($mode === 'backup' && $this->isExcludedFromBackup($rel)) {
				return false;
			}
			return true;
		});
		$iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

		foreach ($iterator as $item) {
			$rel = $this->relativePath($sourceReal, $item->getPathname());
			if ($rel === null || $rel === '' || $this->hasDotDot($rel)) {
				$skipped++;
				continue;
			}
			$target = $destReal.'/'.$rel;
			if (!$this->isContained($target, $destReal)) {
				$this->error = $langs->trans('DolibarrUpdaterPathRefused', $rel);
				return null;
			}
			if ($item->isDir()) {
				if (!is_dir($target) && !@mkdir($target, 0755, true)) {
					$this->error = $langs->trans('DolibarrUpdaterNotWritable', $rel);
					return null;
				}
				continue;
			}
			$parent = dirname($target);
			if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
				$this->error = $langs->trans('DolibarrUpdaterNotWritable', $rel);
				return null;
			}
			if (!@copy($item->getPathname(), $target)) {
				$this->error = $langs->trans('DolibarrUpdaterCopyFailed', $rel);
				return null;
			}
			$copied++;
			if ($copied % 2000 === 0) {
				$this->appendLog($langs->trans('DolibarrUpdaterProgressCopy', (string) $copied));
			}
		}

		return array('copied' => $copied, 'skipped' => $skipped);
	}

	/**
	 * Delete core files that are not in the new package.
	 *
	 * @param string $source New htdocs
	 * @param string $dest   Installed htdocs
	 * @return int Number of deleted files, or -1
	 */
	private function pruneTree($source, $dest)
	{
		global $langs;

		$sourceReal = realpath($source);
		$destReal = realpath($dest);
		$doc = realpath(DOL_DOCUMENT_ROOT);
		if ($sourceReal === false || $destReal === false || $doc === false || $destReal !== $doc) {
			$this->error = $langs->trans('DolibarrUpdaterPathRefused', $dest);
			return -1;
		}

		$files = array();
		$dirs = array();
		$directory = new RecursiveDirectoryIterator($sourceReal, FilesystemIterator::SKIP_DOTS);
		$iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST);
		foreach ($iterator as $item) {
			if ($item->isLink()) {
				continue;
			}
			$rel = $this->relativePath($sourceReal, $item->getPathname());
			if ($rel === null || $rel === '' || $this->hasDotDot($rel)) {
				continue;
			}
			if ($item->isDir()) {
				$dirs[$rel] = true;
			} else {
				$files[$rel] = true;
			}
		}
		if (count($files) < self::MIN_PACKAGE_FILES) {
			$this->error = $langs->trans('DolibarrUpdaterSourceTooSmall');
			return -1;
		}

		$deleted = 0;
		$destDir = new RecursiveDirectoryIterator($destReal, FilesystemIterator::SKIP_DOTS);
		$destFilter = new RecursiveCallbackFilterIterator($destDir, function ($current) use ($destReal) {
			if ($current->isLink()) {
				return false;
			}
			$rel = $this->relativePath($destReal, $current->getPathname());
			if ($rel === null || $this->hasDotDot($rel)) {
				return false;
			}
			if ($this->isExcludedFromPrune($rel)) {
				return false;
			}
			return true;
		});
		$destIterator = new RecursiveIteratorIterator($destFilter, RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($destIterator as $item) {
			$rel = $this->relativePath($destReal, $item->getPathname());
			if ($rel === null || $rel === '' || $this->isExcludedFromPrune($rel)) {
				continue;
			}
			if ($item->isDir()) {
				if (empty($dirs[$rel])) {
					@rmdir($item->getPathname());
				}
				continue;
			}
			if (!empty($files[$rel])) {
				continue;
			}
			if (!@unlink($item->getPathname())) {
				$this->error = $langs->trans('DolibarrUpdaterDeleteFailed', $rel);
				return -1;
			}
			$deleted++;
		}
		return $deleted;
	}

	/**
	 * @param string $rel Relative path
	 * @return bool
	 */
	private function isExcludedFromCopy($rel)
	{
		$rel = ltrim(str_replace('\\', '/', $rel), '/');
		if ($rel === 'conf/conf.php') {
			return true;
		}
		if ($rel === 'custom' || strpos($rel, 'custom/') === 0) {
			return true;
		}
		if ($rel === 'install.lock') {
			return true;
		}
		if ($rel === '.git' || strpos($rel, '.git/') === 0) {
			return true;
		}
		$dataRel = $this->dataRootRelative();
		if ($dataRel !== '' && ($rel === $dataRel || strpos($rel, $dataRel.'/') === 0)) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $rel Relative path
	 * @return bool
	 */
	private function isExcludedFromPrune($rel)
	{
		$rel = ltrim(str_replace('\\', '/', $rel), '/');
		if ($rel === 'conf' || strpos($rel, 'conf/') === 0) {
			return true;
		}
		if ($rel === 'custom' || strpos($rel, 'custom/') === 0) {
			return true;
		}
		if ($rel === 'install.lock') {
			return true;
		}
		if ($rel === '.git' || strpos($rel, '.git/') === 0) {
			return true;
		}
		$dataRel = $this->dataRootRelative();
		if ($dataRel !== '' && ($rel === $dataRel || strpos($rel, $dataRel.'/') === 0)) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $rel Relative path
	 * @return bool
	 */
	private function isExcludedFromBackup($rel)
	{
		$rel = ltrim(str_replace('\\', '/', $rel), '/');
		if ($rel === '.git' || strpos($rel, '.git/') === 0) {
			return true;
		}
		$dataRel = $this->dataRootRelative();
		if ($dataRel !== '' && ($rel === $dataRel || strpos($rel, $dataRel.'/') === 0)) {
			return true;
		}
		return false;
	}

	/**
	 * Relative path of DOL_DATA_ROOT when it lives inside htdocs.
	 *
	 * @return string
	 */
	private function dataRootRelative()
	{
		$doc = realpath(DOL_DOCUMENT_ROOT);
		$data = realpath(DOL_DATA_ROOT);
		if ($doc === false || $data === false) {
			return '';
		}
		$doc = rtrim(str_replace('\\', '/', $doc), '/');
		$data = str_replace('\\', '/', $data);
		if ($data === $doc || strpos($data, $doc.'/') !== 0) {
			return '';
		}
		return substr($data, strlen($doc) + 1);
	}

	/**
	 * @param string $versionFile version.inc.php path
	 * @return string
	 */
	private function readPackageVersion($versionFile)
	{
		$content = @file_get_contents($versionFile);
		if ($content === false) {
			return '';
		}
		if (!preg_match("/define\\(\\s*['\"]DOL_MAJOR_VERSION['\"]\\s*,\\s*['\"](\\d+)['\"]\\s*\\)/", $content, $major)) {
			return '';
		}
		if (!preg_match("/define\\(\\s*['\"]DOL_MINOR_VERSION['\"]\\s*,\\s*['\"](\\d+\\.\\d+(?:-beta\\d*)?)['\"]\\s*\\)/", $content, $minor)) {
			return '';
		}
		return $major[1].'.'.$minor[1];
	}

	/**
	 * @param string $zipFile Zip path
	 * @return bool
	 */
	private function zipLooksValid($zipFile)
	{
		global $langs;
		if (!is_file($zipFile)) {
			$this->error = $langs->trans('DolibarrUpdaterBadArchive');
			return false;
		}
		$size = filesize($zipFile);
		if ($size === false || $size < self::MIN_ARCHIVE_BYTES || $size > self::MAX_ARCHIVE_BYTES) {
			$this->error = $langs->trans('DolibarrUpdaterBadArchive');
			return false;
		}
		$fh = fopen($zipFile, 'rb');
		if ($fh === false) {
			$this->error = $langs->trans('DolibarrUpdaterBadArchive');
			return false;
		}
		$magic = fread($fh, 2);
		fclose($fh);
		if ($magic !== 'PK') {
			$this->error = $langs->trans('DolibarrUpdaterBadArchive');
			return false;
		}
		$zip = new ZipArchive();
		if ($zip->open($zipFile) !== true) {
			$this->error = $langs->trans('DolibarrUpdaterBadArchive');
			return false;
		}
		$safe = $this->zipEntriesAreSafe($zip);
		$zip->close();
		if (!$safe) {
			$this->error = $langs->trans('DolibarrUpdaterZipSlip');
			return false;
		}
		return true;
	}

	/**
	 * @param ZipArchive $zip Open archive
	 * @return bool
	 */
	private function zipEntriesAreSafe($zip)
	{
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name === false) {
				return false;
			}
			$name = str_replace('\\', '/', $name);
			if (isset($name[0]) && $name[0] === '/') {
				return false;
			}
			if ($this->hasDotDot($name)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $rel Relative path
	 * @return bool
	 */
	private function hasDotDot($rel)
	{
		$parts = explode('/', str_replace('\\', '/', $rel));
		foreach ($parts as $part) {
			if ($part === '..') {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $root Absolute root
	 * @param string $path Absolute path
	 * @return string|null
	 */
	private function relativePath($root, $path)
	{
		$root = rtrim(str_replace('\\', '/', $root), '/');
		$path = str_replace('\\', '/', $path);
		if ($path === $root) {
			return '';
		}
		if (strpos($path, $root.'/') !== 0) {
			return null;
		}
		return substr($path, strlen($root) + 1);
	}

	/**
	 * True when $path stays inside $root, including a path that does not exist yet.
	 *
	 * @param string $path Candidate path
	 * @param string $root Existing directory
	 * @return bool
	 */
	private function isContained($path, $root)
	{
		$rootReal = realpath($root);
		if ($rootReal === false) {
			return false;
		}
		$rootReal = rtrim(str_replace('\\', '/', $rootReal), '/');
		$path = str_replace('\\', '/', $path);
		if ($path !== $rootReal && strpos($path, $rootReal.'/') !== 0) {
			return false;
		}

		$cursor = $path;
		while ($cursor !== '/' && $cursor !== '' && !file_exists($cursor)) {
			$next = dirname($cursor);
			if ($next === $cursor) {
				break;
			}
			$cursor = $next;
		}
		$realCursor = realpath($cursor);
		if ($realCursor === false) {
			return false;
		}
		$realCursor = rtrim(str_replace('\\', '/', $realCursor), '/');
		if ($realCursor !== $rootReal && strpos($realCursor, $rootReal.'/') !== 0) {
			return false;
		}
		$suffix = substr($path, strlen($cursor));
		$resolved = $realCursor.$suffix;
		return ($resolved === $rootReal || strpos($resolved, $rootReal.'/') === 0);
	}

	/**
	 * @param string $path File or directory
	 * @param string $root Existing directory
	 * @return bool
	 */
	private function isUnder($path, $root)
	{
		$rootReal = realpath($root);
		if ($rootReal === false) {
			return false;
		}
		if (file_exists($path)) {
			$pathReal = realpath($path);
			if ($pathReal === false) {
				return false;
			}
			$rootReal = rtrim($rootReal, '/');
			return ($pathReal === $rootReal || strpos($pathReal, $rootReal.'/') === 0);
		}
		return $this->isContained($path, $rootReal);
	}

	/**
	 * @param string $path Path
	 * @return bool
	 */
	private function isUnderWork($path)
	{
		return $this->isUnder($path, $this->workDir());
	}

	/**
	 * @return bool
	 */
	private function validStatePaths()
	{
		global $langs;
		$langs->load('dolibarrupdater@dolibarrupdater');

		if (empty($this->state['target_version']) || !$this->isVersionToken((string) $this->state['target_version'])) {
			$this->error = $langs->trans('DolibarrUpdaterBadVersion');
			return false;
		}
		foreach (array('backup_dir', 'zip_file', 'extract_dir') as $key) {
			if (empty($this->state[$key]) || !$this->isUnderWork((string) $this->state[$key])) {
				$this->error = $langs->trans('DolibarrUpdaterPathRefused', isset($this->state[$key]) ? (string) $this->state[$key] : $key);
				return false;
			}
		}
		if (!empty($this->state['source_htdocs']) && !$this->isUnder((string) $this->state['source_htdocs'], (string) $this->state['extract_dir'])) {
			$this->error = $langs->trans('DolibarrUpdaterPathRefused', (string) $this->state['source_htdocs']);
			return false;
		}
		return true;
	}

	/**
	 * @param string $dir Directory
	 * @return int 1 if removed, -1 if refused or failed
	 */
	private function deleteTree($dir)
	{
		$real = realpath($dir);
		$work = realpath($this->workDir());
		if ($real === false || $work === false || $real === $work || strpos($real, rtrim($work, '/').'/') !== 0) {
			return -1;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $item) {
			if ($item->isLink() || !$item->isDir()) {
				if (!@unlink($item->getPathname())) {
					return -1;
				}
			} elseif (!@rmdir($item->getPathname())) {
				return -1;
			}
		}
		if (!@rmdir($real)) {
			return -1;
		}
		return 1;
	}

	/**
	 * @param string $dir Directory
	 * @return int
	 */
	private function directorySize($dir)
	{
		$real = realpath($dir);
		if ($real === false || !is_dir($real)) {
			return 0;
		}
		$cache = $this->workDir().'/htdocs-size.cache';
		if (is_file($cache) && (time() - filemtime($cache) < 1800)) {
			$cached = (int) trim((string) file_get_contents($cache));
			if ($cached > 0) {
				return $cached;
			}
		}

		$size = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS)
		);
		foreach ($iterator as $item) {
			if ($item->isLink() || !$item->isFile()) {
				continue;
			}
			$bytes = $item->getSize();
			if ($bytes !== false) {
				$size += (int) $bytes;
			}
		}
		if ($size > 0 && $this->ensureDir($this->workDir())) {
			$this->writeFile($cache, (string) $size);
		}
		return $size;
	}

	/**
	 * @param string $url HTTPS URL
	 * @return string|false
	 */
	private function httpGet($url)
	{
		$parts = parse_url($url);
		if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https' || empty($parts['host'])) {
			$this->error = 'HTTPS';
			return false;
		}
		if (!$this->isAllowedDownloadHost($parts['host']) && strtolower($parts['host']) !== 'api.github.com') {
			$this->error = 'host';
			return false;
		}

		$ch = curl_init($url);
		if ($ch === false) {
			$this->error = 'curl';
			return false;
		}
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 3,
			CURLOPT_USERAGENT => 'DolibarrUpdater/'.DOL_VERSION,
			CURLOPT_HTTPHEADER => array('Accept: application/vnd.github+json'),
			CURLOPT_CONNECTTIMEOUT => 20,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		));
		$this->forceHttps($ch);
		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		$finalHost = parse_url((string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), PHP_URL_HOST);
		curl_close($ch);

		if ($body === false || $code < 200 || $code >= 300) {
			$this->error = $error !== '' ? $error : ('HTTP '.$code);
			return false;
		}
		if (!$this->isAllowedDownloadHost((string) $finalHost) && strtolower((string) $finalHost) !== 'api.github.com') {
			$this->error = 'host';
			return false;
		}
		return (string) $body;
	}

	/**
	 * @param CurlHandle|resource $ch Curl handle
	 * @return void
	 */
	private function forceHttps($ch)
	{
		if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
			curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
		}
		if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
			curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
		}
	}

	/**
	 * @param string $host Hostname
	 * @return bool
	 */
	private function isAllowedDownloadHost($host)
	{
		$host = strtolower((string) $host);
		if ($host === 'github.com' || $host === 'api.github.com') {
			return true;
		}
		return (strlen($host) > 22 && substr($host, -22) === '.githubusercontent.com');
	}

	/**
	 * @param string $message Log line
	 * @return void
	 */
	private function appendLog($message)
	{
		if (!is_array($this->state)) {
			return;
		}
		if (empty($this->state['log']) || !is_array($this->state['log'])) {
			$this->state['log'] = array();
		}
		$this->state['log'][] = gmdate('Y-m-d H:i:s').' '.$message;
		if (count($this->state['log']) > 80) {
			$this->state['log'] = array_slice($this->state['log'], -80);
		}
		$this->saveState();
	}

	/**
	 * @return bool
	 */
	private function saveState()
	{
		if (!is_array($this->state)) {
			return false;
		}
		if (!$this->ensureDir($this->workDir())) {
			return false;
		}
		$json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			return false;
		}
		return $this->writeFile($this->stateFile(), $json);
	}

	/**
	 * @param string $file JSON file
	 * @return array<string,mixed>|null
	 */
	private function readJsonFile($file)
	{
		if (!is_file($file)) {
			return null;
		}
		$data = json_decode((string) file_get_contents($file), true);
		return is_array($data) ? $data : null;
	}

	/**
	 * @param string $dir Directory
	 * @return bool
	 */
	private function ensureDir($dir)
	{
		if (is_dir($dir)) {
			return true;
		}
		return @mkdir($dir, 0755, true) || is_dir($dir);
	}

	/**
	 * @param string $path    File path
	 * @param string $content Content
	 * @return bool
	 */
	private function writeFile($path, $content)
	{
		if (file_put_contents($path, $content, LOCK_EX) === false) {
			return false;
		}
		@chmod($path, 0644);
		return true;
	}

	/**
	 * @return string
	 */
	private function workDir()
	{
		return DOL_DATA_ROOT.'/dolibarrupdater';
	}

	/**
	 * @return string
	 */
	private function stateFile()
	{
		return $this->workDir().'/state.json';
	}

	/**
	 * @param string $step Step code
	 * @return string
	 */
	private function stepLangKey($step)
	{
		$map = array(
			'backup' => 'DolibarrUpdaterStepBackup',
			'download' => 'DolibarrUpdaterStepDownload',
			'extract' => 'DolibarrUpdaterStepExtract',
			'apply' => 'DolibarrUpdaterStepApply',
			'unlock' => 'DolibarrUpdaterStepUnlock',
		);
		return isset($map[$step]) ? $map[$step] : 'DolibarrUpdaterJob';
	}

	/**
	 * Absolute path of this module directory.
	 *
	 * @return string
	 */
	public function moduleDir()
	{
		return DOL_DOCUMENT_ROOT.'/custom/dolibarrupdater';
	}

	/**
	 * @return string Absolute path of git binary, or empty
	 */
	public function gitBinary()
	{
		static $cached = null;
		if ($cached !== null) {
			return $cached;
		}
		$cached = '';
		foreach (array('/usr/bin/git', '/bin/git', '/usr/local/bin/git') as $path) {
			if (is_executable($path)) {
				$cached = $path;
				return $cached;
			}
		}
		return $cached;
	}

	/**
	 * @return bool
	 */
	public function isModuleGitRepo()
	{
		return is_dir($this->moduleDir().'/.git');
	}

	/**
	 * Validate HTTPS remote for the module repository.
	 *
	 * @param string $url Remote URL
	 * @return bool
	 */
	public function isAllowedGitRemote($url)
	{
		$url = trim((string) $url);
		$parts = parse_url($url);
		if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https') {
			return false;
		}
		if (empty($parts['host']) || empty($parts['path'])) {
			return false;
		}
		$host = strtolower($parts['host']);
		if (!preg_match('/^[a-z0-9.-]+$/', $host) || strpos($host, '..') !== false) {
			return false;
		}
		$path = $parts['path'];
		if (strpos($path, '..') !== false) {
			return false;
		}
		// owner/repo[.git]
		if (!preg_match('#^/[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+(\.git)?/?$#', $path)) {
			return false;
		}
		return true;
	}

	/**
	 * @param string $branch Branch name
	 * @return bool
	 */
	public function isAllowedGitBranch($branch)
	{
		$branch = (string) $branch;
		if ($branch === '' || strlen($branch) > 100) {
			return false;
		}
		if ($branch[0] === '-' || strpos($branch, '..') !== false) {
			return false;
		}
		return (bool) preg_match('#^[A-Za-z0-9._/\-]+$#', $branch);
	}

	/**
	 * Installed module version from the descriptor file.
	 *
	 * @return string Empty when unreadable
	 */
	public function moduleVersion()
	{
		$file = $this->moduleDir().'/core/modules/modDolibarrupdater.class.php';
		$content = @file_get_contents($file);
		if ($content === false) {
			return '';
		}
		return $this->parseModuleDescriptorVersion($content);
	}

	/**
	 * Remote heads for an HTTPS git URL, preferred branches first.
	 *
	 * @param string $remote HTTPS remote URL
	 * @return array<int,string>
	 */
	public function listRemoteBranches($remote)
	{
		global $langs;
		$langs->load('dolibarrupdater@dolibarrupdater');

		$remote = trim((string) $remote);
		if ($this->gitBinary() === '') {
			$this->error = $langs->trans('DolibarrUpdaterGitMissing');
			return array();
		}
		if (!$this->isAllowedGitRemote($remote)) {
			$this->error = $langs->trans('DolibarrUpdaterGitBadRemote');
			return array();
		}

		$cache = $this->workDir().'/branches-'.md5($remote).'.cache.json';
		if (is_file($cache) && (time() - filemtime($cache) < 600)) {
			$cached = $this->readJsonFile($cache);
			if (is_array($cached)) {
				$out = array();
				foreach ($cached as $name) {
					if (is_string($name) && $this->isAllowedGitBranch($name)) {
						$out[] = $name;
					}
				}
				return $out;
			}
		}

		$cwd = $this->gitListCwd();
		$result = $this->runGit(array('ls-remote', '--heads', $remote), $cwd);
		if ($result['code'] !== 0) {
			$this->error = $langs->trans('DolibarrUpdaterGitBranchesFailed', $result['err'] !== '' ? $result['err'] : $result['out']);
			return array();
		}

		$branches = array();
		foreach (preg_split('/\R/', $result['out']) as $line) {
			$line = trim($line);
			if ($line === '' || !preg_match('#^[0-9a-fA-F]+\s+refs/heads/(.+)$#', $line, $m)) {
				continue;
			}
			$name = $m[1];
			if ($this->isAllowedGitBranch($name) && !in_array($name, $branches, true)) {
				$branches[] = $name;
			}
		}
		$branches = $this->sortBranchNames($branches);

		if ($this->ensureDir($this->workDir())) {
			$this->writeFile($cache, json_encode(array_values($branches)));
		}
		return $branches;
	}

	/**
	 * Module version published on the configured remote branch.
	 *
	 * @param string $remote HTTPS remote URL
	 * @param string $branch Branch name
	 * @return string Empty when unavailable
	 */
	public function fetchRemoteModuleVersion($remote = '', $branch = '')
	{
		$remote = trim((string) ($remote !== '' ? $remote : getDolGlobalString('DOLIBARRUPDATER_GIT_REMOTE')));
		$branch = trim((string) ($branch !== '' ? $branch : getDolGlobalString('DOLIBARRUPDATER_GIT_BRANCH', 'main')));
		if (!$this->isAllowedGitRemote($remote) || !$this->isAllowedGitBranch($branch)) {
			return '';
		}

		$cache = $this->workDir().'/module-version-'.md5($remote.'|'.$branch).'.cache.json';
		if (is_file($cache) && (time() - filemtime($cache) < 600)) {
			$cached = $this->readJsonFile($cache);
			if (is_array($cached) && !empty($cached['version']) && is_string($cached['version'])) {
				return $cached['version'];
			}
		}

		$content = $this->fetchRemoteDescriptorContent($remote, $branch);
		$version = $this->parseModuleDescriptorVersion($content);
		if ($version !== '' && $this->ensureDir($this->workDir())) {
			$this->writeFile($cache, json_encode(array('version' => $version)));
		}
		return $version;
	}

	/**
	 * Status of the module git checkout, including version and remote update check.
	 *
	 * @return array{git:bool,repo:bool,remote:string,branch:string,commit:string,dirty:bool,can_update:bool,message:string,version:string,remote_version:string,update_available:bool,branches:array<int,string>}
	 */
	public function moduleGitStatus()
	{
		global $langs;
		$langs->load('dolibarrupdater@dolibarrupdater');

		$status = array(
			'git' => ($this->gitBinary() !== ''),
			'repo' => $this->isModuleGitRepo(),
			'remote' => getDolGlobalString('DOLIBARRUPDATER_GIT_REMOTE'),
			'branch' => getDolGlobalString('DOLIBARRUPDATER_GIT_BRANCH', 'main'),
			'commit' => '',
			'dirty' => false,
			'can_update' => false,
			'message' => '',
			'version' => $this->moduleVersion(),
			'remote_version' => '',
			'update_available' => false,
			'branches' => array(),
		);

		if (!$status['git']) {
			$status['message'] = $langs->trans('DolibarrUpdaterGitMissing');
			return $status;
		}
		if ($status['remote'] !== '' && !$this->isAllowedGitRemote($status['remote'])) {
			$status['message'] = $langs->trans('DolibarrUpdaterGitBadRemote');
			return $status;
		}
		if ($status['branch'] !== '' && !$this->isAllowedGitBranch($status['branch'])) {
			$status['message'] = $langs->trans('DolibarrUpdaterGitBadBranch');
			return $status;
		}
		if ($status['remote'] === '') {
			$status['message'] = $langs->trans('DolibarrUpdaterGitNotConfigured');
			return $status;
		}

		$previousError = $this->error;
		$this->error = '';
		$status['branches'] = $this->listRemoteBranches($status['remote']);
		$branchError = $this->error;
		$this->error = $previousError;

		$status['can_update'] = true;
		if (!$status['repo']) {
			$status['message'] = $langs->trans('DolibarrUpdaterGitNotInitialized');
		} else {
			$head = $this->runGit(array('rev-parse', '--short', 'HEAD'), $this->moduleDir());
			if ($head['code'] === 0) {
				$status['commit'] = trim($head['out']);
			}
			$porcelain = $this->runGit(array('status', '--porcelain'), $this->moduleDir());
			if ($porcelain['code'] === 0 && trim($porcelain['out']) !== '') {
				$status['dirty'] = true;
			}
			$status['message'] = $langs->trans('DolibarrUpdaterGitReady');
		}

		if ($this->isAllowedGitBranch($status['branch'])) {
			$status['remote_version'] = $this->fetchRemoteModuleVersion($status['remote'], $status['branch']);
			if ($status['remote_version'] !== '' && $status['version'] !== '') {
				require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
				$localParts = preg_split('/[\-\.]/', $status['version']);
				$remoteParts = preg_split('/[\-\.]/', $status['remote_version']);
				if (versioncompare($remoteParts, $localParts) > 0) {
					$status['update_available'] = true;
					$status['message'] = $langs->trans('DolibarrUpdaterModuleUpdateAvailable', $status['remote_version']);
				} elseif (!$status['update_available'] && $status['repo']) {
					$status['message'] = $langs->trans('DolibarrUpdaterModuleUpToDate');
				}
			} elseif ($branchError !== '' && empty($status['branches'])) {
				$status['message'] = $branchError;
			} elseif ($status['remote_version'] === '' && $status['repo']) {
				$status['message'] = $langs->trans('DolibarrUpdaterModuleVersionCheckFailed');
			}
		}

		return $status;
	}

	/**
	 * Save git remote and branch constants.
	 *
	 * @param DoliDB $db     Database handler
	 * @param string $remote HTTPS remote URL
	 * @param string $branch Branch name
	 * @return int 1 if OK, -1 if KO
	 */
	public function saveModuleGitConfig($db, $remote, $branch)
	{
		global $conf, $langs;
		$langs->load('dolibarrupdater@dolibarrupdater');

		$remote = trim((string) $remote);
		$branch = trim((string) $branch);
		if ($remote !== '' && !$this->isAllowedGitRemote($remote)) {
			$this->error = $langs->trans('DolibarrUpdaterGitBadRemote');
			return -1;
		}

		if ($remote === '') {
			$branch = '';
		} else {
			$branches = $this->listRemoteBranches($remote);
			if (!empty($branches)) {
				if ($branch === '' || !$this->isAllowedGitBranch($branch)) {
					if (in_array('main', $branches, true)) {
						$branch = 'main';
					} elseif (in_array('master', $branches, true)) {
						$branch = 'master';
					} else {
						$branch = $branches[0];
					}
				}
				if (!in_array($branch, $branches, true)) {
					$this->error = $langs->trans('DolibarrUpdaterGitBadBranch');
					return -1;
				}
			} else {
				$currentRemote = getDolGlobalString('DOLIBARRUPDATER_GIT_REMOTE');
				$currentBranch = getDolGlobalString('DOLIBARRUPDATER_GIT_BRANCH');
				if (!($remote === $currentRemote && $branch === $currentBranch && $this->isAllowedGitBranch($branch))) {
					$this->error = $this->error !== '' ? $this->error : $langs->trans('DolibarrUpdaterGitBranchesEmpty');
					return -1;
				}
			}
		}

		$result1 = dolibarr_set_const($db, 'DOLIBARRUPDATER_GIT_REMOTE', $remote, 'chaine', 0, '', $conf->entity);
		$result2 = dolibarr_set_const($db, 'DOLIBARRUPDATER_GIT_BRANCH', $branch, 'chaine', 0, '', $conf->entity);
		if ($result1 < 0 || $result2 < 0) {
			$this->error = $langs->trans('DolibarrUpdaterGitSaveFailed');
			return -1;
		}
		return 1;
	}

	/**
	 * @param string $content Descriptor PHP source
	 * @return string
	 */
	private function parseModuleDescriptorVersion($content)
	{
		if (!is_string($content) || $content === '') {
			return '';
		}
		if (!preg_match('/\$this->version\s*=\s*[\'"](\d+\.\d+\.\d+(?:-beta\d*)?)[\'"]/', $content, $m)) {
			return '';
		}
		return $m[1];
	}

	/**
	 * @param string $remote HTTPS remote
	 * @param string $branch Branch
	 * @return string Descriptor file contents or empty
	 */
	private function fetchRemoteDescriptorContent($remote, $branch)
	{
		$parts = parse_url($remote);
		if (empty($parts['host']) || empty($parts['path'])) {
			return '';
		}
		$host = strtolower((string) $parts['host']);
		if ($host === 'github.com' && preg_match('#^/([^/]+)/([^/]+?)(?:\.git)?/?$#', (string) $parts['path'], $m)) {
			$branchPath = implode('/', array_map('rawurlencode', explode('/', $branch)));
			$url = 'https://raw.githubusercontent.com/'.$m[1].'/'.$m[2].'/'.$branchPath.'/core/modules/modDolibarrupdater.class.php';
			$raw = $this->httpGet($url);
			return ($raw === false) ? '' : $raw;
		}

		if (!$this->isModuleGitRepo()) {
			return '';
		}
		$moduleReal = realpath($this->moduleDir());
		if ($moduleReal === false) {
			return '';
		}

		$remoteGet = $this->runGit(array('remote', 'get-url', 'origin'), $moduleReal);
		if ($remoteGet['code'] !== 0) {
			$add = $this->runGit(array('remote', 'add', 'origin', $remote), $moduleReal);
			if ($add['code'] !== 0) {
				return '';
			}
		} elseif (trim($remoteGet['out']) !== $remote) {
			$set = $this->runGit(array('remote', 'set-url', 'origin', $remote), $moduleReal);
			if ($set['code'] !== 0) {
				return '';
			}
		}

		$fetch = $this->runGit(array('fetch', '--depth', '1', 'origin', $branch), $moduleReal);
		if ($fetch['code'] !== 0) {
			return '';
		}
		$show = $this->runGit(array('show', 'FETCH_HEAD:core/modules/modDolibarrupdater.class.php'), $moduleReal);
		if ($show['code'] !== 0) {
			return '';
		}
		return $show['out'];
	}

	/**
	 * @param array<int,string> $branches Branch names
	 * @return array<int,string>
	 */
	private function sortBranchNames(array $branches)
	{
		usort($branches, function ($a, $b) {
			$priority = array('main' => 0, 'master' => 1);
			$pa = isset($priority[$a]) ? $priority[$a] : 100;
			$pb = isset($priority[$b]) ? $priority[$b] : 100;
			if ($pa !== $pb) {
				return $pa - $pb;
			}
			return strcasecmp($a, $b);
		});
		return array_values($branches);
	}

	/**
	 * Working directory for git commands that do not need a repository.
	 *
	 * @return string
	 */
	private function gitListCwd()
	{
		$module = $this->moduleDir();
		if (is_dir($module)) {
			return $module;
		}
		if ($this->ensureDir($this->workDir())) {
			return $this->workDir();
		}
		return DOL_DATA_ROOT;
	}

	/**
	 * Clone or hard-reset the module directory from the configured remote.
	 *
	 * @return int 1 if OK, -1 if KO
	 */
	public function updateModuleFromGit()
	{
		global $langs;
		$langs->load('dolibarrupdater@dolibarrupdater');

		@set_time_limit(0);

		if ($this->gitBinary() === '') {
			$this->error = $langs->trans('DolibarrUpdaterGitMissing');
			return -1;
		}

		$remote = getDolGlobalString('DOLIBARRUPDATER_GIT_REMOTE');
		$branch = getDolGlobalString('DOLIBARRUPDATER_GIT_BRANCH', 'main');
		if (!$this->isAllowedGitRemote($remote) || !$this->isAllowedGitBranch($branch)) {
			$this->error = $langs->trans('DolibarrUpdaterGitNotConfigured');
			return -1;
		}

		$moduleDir = $this->moduleDir();
		$moduleReal = realpath($moduleDir);
		$customReal = realpath(DOL_DOCUMENT_ROOT.'/custom');
		if ($moduleReal === false || $customReal === false || strpos($moduleReal, rtrim($customReal, '/').'/') !== 0) {
			$this->error = $langs->trans('DolibarrUpdaterPathRefused', $moduleDir);
			return -1;
		}
		if (!is_writable($moduleReal) || !is_writable($customReal)) {
			$this->error = $langs->trans('DolibarrUpdaterNotWritable', $moduleReal);
			return -1;
		}

		if ($this->isModuleGitRepo()) {
			return $this->pullModuleGit($moduleReal, $remote, $branch);
		}
		return $this->cloneModuleGit($moduleReal, $remote, $branch);
	}

	/**
	 * @param string $moduleReal Absolute module path
	 * @param string $remote     HTTPS remote
	 * @param string $branch     Branch
	 * @return int
	 */
	private function pullModuleGit($moduleReal, $remote, $branch)
	{
		global $langs;

		$remoteGet = $this->runGit(array('remote', 'get-url', 'origin'), $moduleReal);
		if ($remoteGet['code'] !== 0) {
			$add = $this->runGit(array('remote', 'add', 'origin', $remote), $moduleReal);
			if ($add['code'] !== 0) {
				$this->error = $langs->trans('DolibarrUpdaterGitUpdateFailed', $add['err'] !== '' ? $add['err'] : $add['out']);
				return -1;
			}
		} elseif (trim($remoteGet['out']) !== $remote) {
			$set = $this->runGit(array('remote', 'set-url', 'origin', $remote), $moduleReal);
			if ($set['code'] !== 0) {
				$this->error = $langs->trans('DolibarrUpdaterGitUpdateFailed', $set['err'] !== '' ? $set['err'] : $set['out']);
				return -1;
			}
		}

		$fetch = $this->runGit(array('fetch', '--depth', '1', 'origin', $branch), $moduleReal);
		if ($fetch['code'] !== 0) {
			$this->error = $langs->trans('DolibarrUpdaterGitFetchFailed', $fetch['err'] !== '' ? $fetch['err'] : $fetch['out']);
			return -1;
		}
		$checkout = $this->runGit(array('checkout', '-B', $branch, 'FETCH_HEAD'), $moduleReal);
		if ($checkout['code'] !== 0) {
			$this->error = $langs->trans('DolibarrUpdaterGitUpdateFailed', $checkout['err'] !== '' ? $checkout['err'] : $checkout['out']);
			return -1;
		}
		$reset = $this->runGit(array('reset', '--hard', 'FETCH_HEAD'), $moduleReal);
		if ($reset['code'] !== 0) {
			$this->error = $langs->trans('DolibarrUpdaterGitUpdateFailed', $reset['err'] !== '' ? $reset['err'] : $reset['out']);
			return -1;
		}
		if (!$this->moduleLooksValid($moduleReal)) {
			$this->error = $langs->trans('DolibarrUpdaterGitInvalidTree');
			return -1;
		}
		return 1;
	}

	/**
	 * First-time install of git tracking by cloning into a temp dir then syncing.
	 *
	 * @param string $moduleReal Absolute module path
	 * @param string $remote     HTTPS remote
	 * @param string $branch     Branch
	 * @return int
	 */
	private function cloneModuleGit($moduleReal, $remote, $branch)
	{
		global $langs;

		if (!$this->ensureDir($this->workDir())) {
			$this->error = $langs->trans('DolibarrUpdaterNotWritable', $this->workDir());
			return -1;
		}
		$tmp = $this->workDir().'/module-git-'.gmdate('YmdHis');
		if (is_dir($tmp) && $this->deleteTree($tmp) < 0) {
			$this->error = $langs->trans('DolibarrUpdaterNotWritable', $tmp);
			return -1;
		}

		$clone = $this->runGit(array('clone', '--depth', '1', '--branch', $branch, $remote, $tmp), $this->workDir());
		if ($clone['code'] !== 0 || !is_dir($tmp.'/.git')) {
			if (is_dir($tmp)) {
				$this->deleteTree($tmp);
			}
			$this->error = $langs->trans('DolibarrUpdaterGitUpdateFailed', $clone['err'] !== '' ? $clone['err'] : $clone['out']);
			return -1;
		}
		if (!$this->moduleLooksValid($tmp)) {
			$this->deleteTree($tmp);
			$this->error = $langs->trans('DolibarrUpdaterGitInvalidTree');
			return -1;
		}

		$synced = $this->syncDirectory($tmp, $moduleReal);
		if ($synced > 0) {
			$synced = $this->pruneSyncedTree($tmp, $moduleReal);
		}
		$this->deleteTree($tmp);
		if ($synced < 0) {
			return -1;
		}
		return 1;
	}

	/**
	 * Remove files in dest that are absent from source (module sync only).
	 *
	 * @param string $source Temporary clone
	 * @param string $dest   Module directory
	 * @return int 1 if OK, -1 if KO
	 */
	private function pruneSyncedTree($source, $dest)
	{
		global $langs;

		$sourceReal = realpath($source);
		$destReal = realpath($dest);
		$moduleReal = realpath($this->moduleDir());
		if ($sourceReal === false || $destReal === false || $moduleReal === false || $destReal !== $moduleReal) {
			$this->error = $langs->trans('DolibarrUpdaterPathRefused', $dest);
			return -1;
		}

		$keep = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($sourceReal, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($iterator as $item) {
			$rel = $this->relativePath($sourceReal, $item->getPathname());
			if ($rel !== null && $rel !== '') {
				$keep[$rel] = true;
			}
		}

		$destIterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($destReal, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($destIterator as $item) {
			$rel = $this->relativePath($destReal, $item->getPathname());
			if ($rel === null || $rel === '' || !empty($keep[$rel])) {
				continue;
			}
			if ($item->isDir()) {
				@rmdir($item->getPathname());
				continue;
			}
			if (!@unlink($item->getPathname())) {
				$this->error = $langs->trans('DolibarrUpdaterDeleteFailed', $rel);
				return -1;
			}
		}
		return 1;
	}

	/**
	 * @param string $dir Module directory
	 * @return bool
	 */
	private function moduleLooksValid($dir)
	{
		return is_file($dir.'/core/modules/modDolibarrupdater.class.php')
			&& is_file($dir.'/admin/update.php')
			&& is_file($dir.'/class/dolibarrupdater.class.php');
	}

	/**
	 * Copy source tree over destination (files and .git).
	 *
	 * @param string $source Source directory
	 * @param string $dest   Destination module directory
	 * @return int 1 if OK, -1 if KO
	 */
	private function syncDirectory($source, $dest)
	{
		global $langs;

		$sourceReal = realpath($source);
		$destReal = realpath($dest);
		if ($sourceReal === false || $destReal === false) {
			$this->error = $langs->trans('DolibarrUpdaterPathRefused', $dest);
			return -1;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($sourceReal, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($iterator as $item) {
			$rel = $this->relativePath($sourceReal, $item->getPathname());
			if ($rel === null || $this->hasDotDot($rel)) {
				$this->error = $langs->trans('DolibarrUpdaterPathRefused', (string) $rel);
				return -1;
			}
			$target = $destReal.'/'.$rel;
			if (!$this->isContained($target, $destReal)) {
				$this->error = $langs->trans('DolibarrUpdaterPathRefused', $rel);
				return -1;
			}
			if ($item->isDir()) {
				if (!is_dir($target) && !@mkdir($target, 0755, true)) {
					$this->error = $langs->trans('DolibarrUpdaterNotWritable', $rel);
					return -1;
				}
				continue;
			}
			$parent = dirname($target);
			if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
				$this->error = $langs->trans('DolibarrUpdaterNotWritable', $rel);
				return -1;
			}
			if (!@copy($item->getPathname(), $target)) {
				$this->error = $langs->trans('DolibarrUpdaterCopyFailed', $rel);
				return -1;
			}
		}
		return 1;
	}

	/**
	 * Run git without a shell.
	 *
	 * @param array<int,string> $args Arguments after the git binary
	 * @param string            $cwd  Working directory
	 * @return array{code:int,out:string,err:string}
	 */
	private function runGit(array $args, $cwd)
	{
		$git = $this->gitBinary();
		if ($git === '' || !is_dir($cwd)) {
			return array('code' => -1, 'out' => '', 'err' => 'git');
		}
		$cmd = array_merge(array($git, '-c', 'safe.directory='.$cwd), $args);
		$descriptors = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$process = @proc_open($cmd, $descriptors, $pipes, $cwd, array(
			'GIT_TERMINAL_PROMPT' => '0',
			'LC_ALL' => 'C',
		));
		if (!is_resource($process)) {
			return array('code' => -1, 'out' => '', 'err' => 'proc_open');
		}
		fclose($pipes[0]);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($process);
		return array(
			'code' => (int) $code,
			'out' => is_string($out) ? $out : '',
			'err' => is_string($err) ? $err : '',
		);
	}
}
