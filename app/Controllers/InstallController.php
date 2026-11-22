<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Database;
use App\Core\EnvLoader;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Services\InstallerService;
use App\Services\SchedulerService;

final class InstallController extends BaseController
{
    public function handle(): void
    {
        if (InstallerService::isInstalled()) {
            http_response_code(403);
            $pageTitle = 'Installatie Vergrendeld';
            $isLocked = true;
            require dirname(__DIR__, 2) . '/views/install.php';
            exit;
        }

        $installer = new InstallerService();
        $step = max(1, min(4, (int) ($_GET['step'] ?? $_POST['step'] ?? 1)));
        $error = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                if ($step === 2) {
                    $installer->setupDatabaseAndEnv(
                        trim((string) ($_POST['db_host'] ?? '127.0.0.1')),
                        trim((string) ($_POST['db_port'] ?? '3306')),
                        trim((string) ($_POST['db_name'] ?? 'iptv_manager')),
                        trim((string) ($_POST['db_user'] ?? '')),
                        (string) ($_POST['db_pass'] ?? ''),
                        trim((string) ($_POST['app_url'] ?? '')),
                        trim((string) ($_POST['lan_subnets'] ?? '192.168.0.0/16,10.0.0.0/8'))
                    );
                    header('Location: index.php?route=install&step=3');
                    exit;
                }

                if ($step === 3) {
                    $username = trim((string) ($_POST['username'] ?? ''));
                    $password = (string) ($_POST['password'] ?? '');
                    if ($username === '' || strlen($password) < 6) {
                        throw new \RuntimeException('Gebruikersnaam is verplicht en wachtwoord moet minstens 6 tekens bevatten.');
                    }
                    EnvLoader::load();
                    $pdo = Database::getInstance();
                    (new UserRepository($pdo))->createUser($username, $password);
                    $settings = new SettingsRepository($pdo);
                    if ((string) $settings->get('output_token', '') === '') {
                        $settings->set('output_token', bin2hex(random_bytes(16)), false);
                    }
                    header('Location: index.php?route=install&step=4');
                    exit;
                }

                if ($step === 4) {
                    EnvLoader::load();
                    $pdo = Database::getInstance();
                    $settings = new SettingsRepository($pdo);
                    $m3uUrl = trim((string) ($_POST['m3u_url'] ?? ''));
                    $epgUrl = trim((string) ($_POST['epg_url'] ?? 'http://epgguide.ddns.net/epg/tvguide.xml'));
                    $intervalDays = max(1, (int) ($_POST['interval_days'] ?? 3));
                    $preferredHour = max(0, min(23, (int) ($_POST['preferred_hour'] ?? 3)));
                    $runSync = isset($_POST['run_sync']) && $_POST['run_sync'] === '1';

                    if ($m3uUrl !== '') {
                        $settings->set('m3u_source_url', $m3uUrl, true);
                    }
                    if ($epgUrl !== '') {
                        $settings->set('epg_source_url', $epgUrl, true);
                    }
                    $settings->set('sync_interval_hours', (string) ($intervalDays * 24), false);
                    $settings->set('sync_preferred_hour', (string) $preferredHour, false);

                    if ($runSync && $m3uUrl !== '') {
                        (new SchedulerService($pdo, $settings))->run(true, 'all');
                    }

                    header('Location: index.php');
                    exit;
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $checks = $installer->checkPrerequisites();
        $allChecksOk = !in_array(false, array_column($checks, 'passed'), true);
        $defaultAppUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'nas.local')
            . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        $isLocked = false;

        require dirname(__DIR__, 2) . '/views/install.php';
    }
}
