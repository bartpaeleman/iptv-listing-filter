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

use App\Controllers\CacheController;
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
        $router->addApiRoute('sync_status', DashboardController::class, 'syncStatus');
        $router->addApiRoute('clear_cache', DashboardController::class, 'clearCache');
        $router->addApiRoute('clear_sync_logs', DashboardController::class, 'clearSyncLogs');
        $router->addApiRoute('run_update_sql', DashboardController::class, 'runUpdateSql');
        $router->addApiRoute('logout', DashboardController::class, 'logout');
        $router->addApiRoute('groups', ChannelController::class, 'groups');
        $router->addApiRoute('groups_search', ChannelController::class, 'searchGroups');
        $router->addApiRoute('set_group_sort', ChannelController::class, 'setGroupSort');
        $router->addApiRoute('update_group', ChannelController::class, 'updateGroup');
        $router->addApiRoute('update_group_category', ChannelController::class, 'updateGroupCategory');
        $router->addApiRoute('group_category_rules', ChannelController::class, 'groupCategoryRules');
        $router->addApiRoute('save_group_category_rule', ChannelController::class, 'saveGroupCategoryRule');
        $router->addApiRoute('delete_group_category_rule', ChannelController::class, 'deleteGroupCategoryRule');
        $router->addApiRoute('bulk_groups', ChannelController::class, 'bulkGroups');
        $router->addApiRoute('channels', ChannelController::class, 'channels');
        $router->addApiRoute('update_channel', ChannelController::class, 'updateChannel');
        $router->addApiRoute('bulk_channels', ChannelController::class, 'bulkChannels');
        $router->addApiRoute('reorder_group_channels', ChannelController::class, 'reorderGroupChannels');
        $router->addApiRoute('country_tags', ChannelController::class, 'countryTags');
        $router->addApiRoute('save_country_tag', ChannelController::class, 'saveCountryTag');
        $router->addApiRoute('delete_country_tag', ChannelController::class, 'deleteCountryTag');
        $router->addApiRoute('scan_tags', ChannelController::class, 'scanTags');
        $router->addApiRoute('apply_country_tags', ChannelController::class, 'applyCountryTags');
        $router->addApiRoute('emoji_rules', EmojiRuleController::class, 'list');
        $router->addApiRoute('save_emoji_rule', EmojiRuleController::class, 'save');
        $router->addApiRoute('toggle_emoji_rule', EmojiRuleController::class, 'toggle');
        $router->addApiRoute('delete_emoji_rule', EmojiRuleController::class, 'delete');
        $router->addApiRoute('epg_search', EpgController::class, 'search');
        $router->addApiRoute('auto_match_epg', EpgController::class, 'autoMatch');
        $router->addApiRoute('get_settings', SettingsController::class, 'get');
        $router->addApiRoute('save_settings', SettingsController::class, 'save');
        $router->addApiRoute('regenerate_token', SettingsController::class, 'regenerateToken');
        $router->addApiRoute('save_epg_source', SettingsController::class, 'saveEpgSource');
        $router->addApiRoute('toggle_epg_source', SettingsController::class, 'toggleEpgSource');
        $router->addApiRoute('delete_epg_source', SettingsController::class, 'deleteEpgSource');
        $router->addApiRoute('test_epg_source', SettingsController::class, 'testEpgSource');
        $router->addApiRoute('clear_db_tables', SettingsController::class, 'clearDbTables');
        $router->addApiRoute('cache_list', CacheController::class, 'listFiles');
        $router->addApiRoute('cache_import', CacheController::class, 'importFile');
        $router->addApiRoute('cache_rename', CacheController::class, 'renameLabel');
        $router->addApiRoute('cache_delete', CacheController::class, 'deleteFiles');
        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
                $logLine = sprintf("[%s] [FATAL] %s in %s:%d\n", date('Y-m-d H:i:s'), $err['message'], $err['file'], $err['line']);
                @file_put_contents(__DIR__ . '/storage/logs/app.log', $logLine, FILE_APPEND);
                if (ob_get_length()) {
                    ob_clean();
                }
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode([
                    'ok' => false,
                    'success' => false,
                    'error' => $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line'],
                ], JSON_UNESCAPED_UNICODE);
            }
        });

        try {
            if ($endpoint !== 'run_update_sql') {
                \App\Core\SchemaMigrator::ensureSchemaUpToDate(\App\Core\Database::getInstance());
            }
            $router->dispatchApi($endpoint);
        } catch (\Throwable $e) {
            $logLine = sprintf("[%s] [API ERROR] %s in %s:%d\n%s\n", date('Y-m-d H:i:s'), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            @file_put_contents(__DIR__ . '/storage/logs/app.log', $logLine, FILE_APPEND);
            if (ob_get_length()) {
                ob_clean();
            }
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'success' => false,
                'error' => $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine(),
            ], JSON_UNESCAPED_UNICODE);
        }
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
