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
                    $config = [
                        'db_host' => (string) ($_POST['db_host'] ?? '127.0.0.1'),
                        'db_port' => (string) ($_POST['db_port'] ?? '3306'),
                        'db_name' => (string) ($_POST['db_name'] ?? 'iptv_manager'),
                        'admin_user' => (string) ($_POST['admin_user'] ?? 'root'),
                        'admin_pass' => (string) ($_POST['admin_pass'] ?? ''),
                        'app_user' => (string) ($_POST['app_user'] ?? 'iptv_user'),
                        'app_pass' => (string) ($_POST['app_pass'] ?? ''),
                        'app_url' => (string) ($_POST['app_url'] ?? ''),
                        'lan_subnets' => (string) ($_POST['lan_subnets'] ?? '192.168.0.0/16,10.0.0.0/8'),
                        'use_existing_user' => isset($_POST['use_existing_user']) && $_POST['use_existing_user'] === '1',
                    ];
                    $installer->setupDatabaseAndEnv($config);
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
        $creds = $installer->generateAppUserCredentials();
        $envFile = dirname(__DIR__, 2) . '/.env';
        $hasEnv = is_file($envFile);
        if ($hasEnv) {
            EnvLoader::load($envFile);
        }

        $savedDbHost = $hasEnv ? (EnvLoader::get('DB_HOST') ?? '127.0.0.1') : '127.0.0.1';
        $savedDbPort = $hasEnv ? (EnvLoader::get('DB_PORT') ?? '3306') : '3306';
        $savedDbName = $hasEnv ? (EnvLoader::get('DB_NAME') ?? 'iptv_manager') : 'iptv_manager';
        $savedAppUrl = $hasEnv ? (EnvLoader::get('APP_URL') ?? $defaultAppUrl) : $defaultAppUrl;
        $savedSubnets = $hasEnv ? (EnvLoader::get('LAN_SUBNET_ALLOW') ?? '192.168.0.0/16,10.0.0.0/8') : '192.168.0.0/16,10.0.0.0/8';
        $suggestedAppUser = $hasEnv ? (EnvLoader::get('DB_USER') ?? $creds['app_user']) : $creds['app_user'];
        $hasExistingDbPass = $hasEnv && (string) (EnvLoader::get('DB_PASS') ?? '') !== '';
        $suggestedAppPass = $hasExistingDbPass ? '' : $creds['app_pass'];

        $savedAdminUser = 'admin';
        $hasExistingAdminPass = false;
        $hasExistingM3u = false;
        $hasExistingEpg = false;
        $savedIntervalDays = 3;
        $savedPreferredHour = 3;

        if ($hasEnv) {
            try {
                $pdo = Database::getInstance();
                $uRow = $pdo->query('SELECT username FROM users ORDER BY id ASC LIMIT 1')->fetch();
                if ($uRow) {
                    $savedAdminUser = (string) $uRow['username'];
                    $hasExistingAdminPass = true;
                }
                $settings = new SettingsRepository($pdo);
                $hasExistingM3u = (string) $settings->get('m3u_source_url', '') !== '';
                $hasExistingEpg = (string) $settings->get('epg_source_url', '') !== '';
                $savedIntervalDays = max(1, (int) floor((int) ($settings->get('sync_interval_hours', '72') ?? '72') / 24));
                $savedPreferredHour = (int) ($settings->get('sync_preferred_hour', '3') ?? '3');
            } catch (\Throwable) {
                // DB not yet provisioned
            }
        }

        $isLocked = false;

        require dirname(__DIR__, 2) . '/views/install.php';
    }
}
