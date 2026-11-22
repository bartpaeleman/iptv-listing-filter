<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/app/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

use App\Controllers\ChannelController;
use App\Controllers\DashboardController;
use App\Controllers\EpgController;
use App\Controllers\FeedController;
use App\Controllers\InstallController;
use App\Controllers\SettingsController;
use App\Core\EnvLoader;
use App\Core\Router;
use App\Security\AuthService;
use App\Security\CsrfGuard;
use App\Services\InstallerService;

$route = (string) ($_GET['route'] ?? 'dashboard');

if ($route === 'install') {
    (new InstallController())->handle();
    exit;
}

if (!InstallerService::isInstalled()) {
    header('Location: index.php?route=install');
    exit;
}

EnvLoader::load(__DIR__ . '/.env');

switch ($route) {
    case 'playlist':
        (new FeedController())->playlist();
        break;

    case 'epg':
        (new FeedController())->epg();
        break;

    case 'cron':
        (new FeedController())->cron();
        break;

    case 'api':
        $endpoint = (string) ($_GET['endpoint'] ?? '');
        $router = new Router();
        $router->addApiRoute('dashboard', DashboardController::class, 'index');
        $router->addApiRoute('sync', DashboardController::class, 'sync');
        $router->addApiRoute('clear_cache', DashboardController::class, 'clearCache');
        $router->addApiRoute('clear_sync_logs', DashboardController::class, 'clearSyncLogs');
        $router->addApiRoute('logout', DashboardController::class, 'logout');
        $router->addApiRoute('groups', ChannelController::class, 'groups');
        $router->addApiRoute('update_group', ChannelController::class, 'updateGroup');
        $router->addApiRoute('bulk_groups', ChannelController::class, 'bulkGroups');
        $router->addApiRoute('channels', ChannelController::class, 'channels');
        $router->addApiRoute('update_channel', ChannelController::class, 'updateChannel');
        $router->addApiRoute('bulk_channels', ChannelController::class, 'bulkChannels');
        $router->addApiRoute('epg_search', EpgController::class, 'search');
        $router->addApiRoute('auto_match_epg', EpgController::class, 'autoMatch');
        $router->addApiRoute('get_settings', SettingsController::class, 'get');
        $router->addApiRoute('save_settings', SettingsController::class, 'save');
        $router->addApiRoute('regenerate_token', SettingsController::class, 'regenerateToken');
        $router->addApiRoute('save_epg_source', SettingsController::class, 'saveEpgSource');
        $router->addApiRoute('toggle_epg_source', SettingsController::class, 'toggleEpgSource');
        $router->addApiRoute('delete_epg_source', SettingsController::class, 'deleteEpgSource');
        $router->addApiRoute('test_epg_source', SettingsController::class, 'testEpgSource');
        $router->dispatchApi($endpoint);
        break;

    case 'dashboard':
    default:
        $auth = new AuthService();
        $csrf = new CsrfGuard();
        $loginError = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['login_submit'])) {
            $token = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
            if (!$csrf->verify($token)) {
                $loginError = 'Ongeldig CSRF-token. Vernieuw de pagina.';
            } else {
                $res = $auth->login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
                if ($res['ok']) {
                    header('Location: index.php');
                    exit;
                }
                $loginError = $res['error'] ?? 'Inloggen mislukt.';
            }
        }

        $csrfToken = $csrf->getToken();
        if (!$auth->isAuthenticated()) {
            require __DIR__ . '/views/login.php';
        } else {
            require __DIR__ . '/views/dashboard.php';
        }
        break;
}
