<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/autoload.php';

use App\Config\EnvLoader;
use App\Database\Connection;
use App\Repositories\SettingsRepository;
use App\Security\AuthService;
use App\Services\M3uParserService;
use App\Services\SchedulerService;

EnvLoader::load();
header('Content-Type: application/json; charset=utf-8');

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$auth = new AuthService();

// Public cron webhook (protected by token + LAN IP)
if ($action === 'cron') {
    $token = isset($_GET['token']) ? (string) $_GET['token'] : null;
    if (!$auth->verifyLanOrToken($token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $force = isset($_GET['force']) && $_GET['force'] === '1';
    $mode = in_array($_GET['mode'] ?? 'all', ['all', 'm3u', 'epg'], true) ? (string) $_GET['mode'] : 'all';

    try {
        $scheduler = new SchedulerService();
        $res = $scheduler->run($force, $mode);
        echo json_encode(['ok' => true, 'result' => $res]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Niet ingelogd.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if ($method === 'POST') {
    $rawBody = file_get_contents('php://input');
    if (is_string($rawBody) && $rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? $_POST['csrf_token'] ?? '');
    if (!$auth->verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Ongeldig CSRF-token.']);
        exit;
    }
}

$pdo = Connection::getInstance();
$settings = new SettingsRepository($pdo);

try {
    switch ($action) {
        case 'dashboard':
            $activeChannels = (int) $pdo->query(
                'SELECT COUNT(*) FROM channels c INNER JOIN m3u_groups g ON g.id = c.group_id WHERE g.is_enabled = 1 AND c.is_enabled = 1'
            )->fetchColumn();
            $totalChannels = (int) $pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn();
            $activeGroups = (int) $pdo->query('SELECT COUNT(*) FROM m3u_groups WHERE is_enabled = 1')->fetchColumn();
            $totalGroups = (int) $pdo->query('SELECT COUNT(*) FROM m3u_groups')->fetchColumn();
            $linkedEpg = (int) $pdo->query(
                "SELECT COUNT(*) FROM channels c INNER JOIN m3u_groups g ON g.id = c.group_id
                 WHERE g.is_enabled = 1 AND c.is_enabled = 1 AND c.epg_channel_id IS NOT NULL AND c.epg_channel_id <> ''"
            )->fetchColumn();
            $epgChannelsCount = (int) $pdo->query('SELECT COUNT(*) FROM epg_channels')->fetchColumn();
            $logs = $pdo->query('SELECT id, sync_type, status, message, stats_json, created_at FROM sync_logs ORDER BY id DESC LIMIT 15')->fetchAll();

            echo json_encode([
                'ok' => true,
                'data' => [
                    'active_channels' => $activeChannels,
                    'total_channels' => $totalChannels,
                    'active_groups' => $activeGroups,
                    'total_groups' => $totalGroups,
                    'linked_epg' => $linkedEpg,
                    'epg_channels_count' => $epgChannelsCount,
                    'last_m3u_sync' => $settings->get('last_m3u_sync', ''),
                    'last_epg_sync' => $settings->get('last_epg_sync', ''),
                    'sync_interval_hours' => (int) $settings->get('sync_interval_hours', '72'),
                    'sync_preferred_hour' => (int) $settings->get('sync_preferred_hour', '3'),
                    'output_token' => $settings->get('output_token', ''),
                    'app_url' => rtrim((string) EnvLoader::get('APP_URL', ''), '/'),
                    'logs' => $logs,
                ],
            ]);
            break;

        case 'sync':
            if ($method !== 'POST') {
                throw new RuntimeException('POST vereist.');
            }
            $mode = in_array($input['mode'] ?? 'all', ['all', 'm3u', 'epg'], true) ? (string) $input['mode'] : 'all';
            $scheduler = new SchedulerService($pdo, $settings);
            $res = $scheduler->run(true, $mode);
            echo json_encode(['ok' => true, 'data' => $res]);
            break;

        case 'groups':
            $rows = $pdo->query(
                'SELECT g.id, g.original_name, g.custom_name, g.is_enabled, g.sort_order,
                        COUNT(c.id) AS channel_count,
                        SUM(CASE WHEN c.is_enabled = 1 THEN 1 ELSE 0 END) AS active_channel_count
                 FROM m3u_groups g
                 LEFT JOIN channels c ON c.group_id = g.id
                 GROUP BY g.id
                 ORDER BY g.sort_order ASC, g.id ASC'
            )->fetchAll();
            echo json_encode(['ok' => true, 'data' => $rows]);
            break;

        case 'update_group':
            if ($method !== 'POST') {
                throw new RuntimeException('POST vereist.');
            }
            $id = (int) ($input['id'] ?? 0);
            $isEnabled = isset($input['is_enabled']) ? ((int) $input['is_enabled'] ? 1 : 0) : null;
            $customName = array_key_exists('custom_name', $input) ? trim((string) $input['custom_name']) : null;
            $sortOrder = isset($input['sort_order']) ? (int) $input['sort_order'] : null;

            $fields = [];
            $params = [':id' => $id];
            if ($isEnabled !== null) {
                $fields[] = 'is_enabled = :enabled';
                $params[':enabled'] = $isEnabled;
            }
            if ($customName !== null) {
                $fields[] = 'custom_name = :cname';
                $params[':cname'] = $customName !== '' ? $customName : null;
            }
            if ($sortOrder !== null) {
                $fields[] = 'sort_order = :sort';
                $params[':sort'] = $sortOrder;
            }
            if ($fields !== []) {
                $stmt = $pdo->prepare('UPDATE m3u_groups SET ' . implode(', ', $fields) . ' WHERE id = :id');
                $stmt->execute($params);
            }
            echo json_encode(['ok' => true]);
            break;

        case 'bulk_groups':
            if ($method !== 'POST') {
                throw new RuntimeException('POST vereist.');
            }
            $enable = (int) ($input['is_enabled'] ?? 1) ? 1 : 0;
            $ids = $input['ids'] ?? [];
            if (is_array($ids) && $ids !== []) {
                $cleanIds = array_map('intval', $ids);
                $ph = implode(',', array_fill(0, count($cleanIds), '?'));
                $stmt = $pdo->prepare("UPDATE m3u_groups SET is_enabled = ? WHERE id IN ({$ph})");
                $stmt->execute(array_merge([$enable], $cleanIds));
            } else {
                $stmt = $pdo->prepare('UPDATE m3u_groups SET is_enabled = ?');
                $stmt->execute([$enable]);
            }
            echo json_encode(['ok' => true]);
            break;

        case 'channels':
            $groupId = isset($_GET['group_id']) && $_GET['group_id'] !== '' ? (int) $_GET['group_id'] : null;
            $search = trim((string) ($_GET['search'] ?? ''));
            $epgStatus = (string) ($_GET['epg_status'] ?? '');
            $activeOnly = isset($_GET['active_only']) && $_GET['active_only'] === '1';
            $limit = min(200, max(10, (int) ($_GET['limit'] ?? 100)));
            $offset = max(0, (int) ($_GET['offset'] ?? 0));

            $where = ['1=1'];
            $params = [];
            if ($groupId !== null) {
                $where[] = 'c.group_id = :gid';
                $params[':gid'] = $groupId;
            }
            if ($search !== '') {
                $where[] = '(c.tvg_name LIKE :q OR c.custom_name LIKE :q OR c.epg_channel_id LIKE :q)';
                $params[':q'] = '%' . $search . '%';
            }
            if ($activeOnly) {
                $where[] = 'g.is_enabled = 1 AND c.is_enabled = 1';
            }
            if ($epgStatus === 'mapped') {
                $where[] = "c.epg_channel_id IS NOT NULL AND c.epg_channel_id <> ''";
            } elseif ($epgStatus === 'unmapped') {
                $where[] = "(c.epg_channel_id IS NULL OR c.epg_channel_id = '')";
            }

            $whereSql = implode(' AND ', $where);
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM channels c INNER JOIN m3u_groups g ON g.id = c.group_id WHERE {$whereSql}");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $sql = "SELECT c.id, c.tvg_id, c.tvg_name, c.tvg_logo, c.group_id, c.custom_name,
                           c.channel_number, c.epg_channel_id, c.epg_match_type, c.is_enabled, c.sort_order,
                           COALESCE(NULLIF(g.custom_name, ''), g.original_name) AS group_name,
                           ec.display_name AS epg_display_name, ec.icon_url AS epg_icon_url
                    FROM channels c
                    INNER JOIN m3u_groups g ON g.id = c.group_id
                    LEFT JOIN epg_channels ec ON ec.epg_id = c.epg_channel_id
                    WHERE {$whereSql}
                    ORDER BY COALESCE(c.channel_number, 999999) ASC, c.sort_order ASC, c.id ASC
                    LIMIT {$limit} OFFSET {$offset}";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode([
                'ok' => true,
                'data' => [
                    'items' => $stmt->fetchAll(),
                    'total' => $total,
                ],
            ]);
            break;

        case 'update_channel':
            if ($method !== 'POST') {
                throw new RuntimeException('POST vereist.');
            }
            $id = (int) ($input['id'] ?? 0);
            $fields = [];
            $params = [':id' => $id];

            if (isset($input['is_enabled'])) {
                $fields[] = 'is_enabled = :enabled';
                $params[':enabled'] = (int) $input['is_enabled'] ? 1 : 0;
            }
            if (array_key_exists('custom_name', $input)) {
                $cname = trim((string) $input['custom_name']);
                $fields[] = 'custom_name = :cname';
                $params[':cname'] = $cname !== '' ? $cname : null;
            }
            if (array_key_exists('channel_number', $input)) {
                $chno = $input['channel_number'];
                $fields[] = 'channel_number = :chno';
                $params[':chno'] = ($chno !== null && $chno !== '') ? (int) $chno : null;
            }
            if (array_key_exists('epg_channel_id', $input)) {
                $epgId = trim((string) $input['epg_channel_id']);
                $fields[] = 'epg_channel_id = :epg_id';
                $fields[] = 'epg_match_type = :mtype';
                $params[':epg_id'] = $epgId !== '' ? $epgId : null;
                $params[':mtype'] = $epgId !== '' ? 'manual' : 'none';
            }

            if ($fields !== []) {
                $stmt = $pdo->prepare('UPDATE channels SET ' . implode(', ', $fields) . ' WHERE id = :id');
                $stmt->execute($params);
            }
            echo json_encode(['ok' => true]);
            break;

        case 'bulk_channels':
            if ($method !== 'POST') {
                throw new RuntimeException('POST vereist.');
            }
            $enable = (int) ($input['is_enabled'] ?? 1) ? 1 : 0;
            $ids = $input['ids'] ?? [];
            if (is_array($ids) && $ids !== []) {
                $cleanIds = array_map('intval', $ids);
                $ph = implode(',', array_fill(0, count($cleanIds), '?'));
                $stmt = $pdo->prepare("UPDATE channels SET is_enabled = ? WHERE id IN ({$ph})");
                $stmt->execute(array_merge([$enable], $cleanIds));
            }
            echo json_encode(['ok' => true]);
            break;

        case 'epg_search':
            $q = trim((string) ($_GET['q'] ?? ''));
            $stmt = $pdo->prepare(
                'SELECT epg_id, display_name, icon_url
                 FROM epg_channels
                 WHERE display_name LIKE :q OR epg_id LIKE :q
                 ORDER BY display_name ASC
                 LIMIT 30'
            );
            $stmt->execute([':q' => '%' . $q . '%']);
            echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()]);
            break;

        case 'auto_match_epg':
            if ($method !== 'POST') {
                throw new RuntimeException('POST vereist.');
            }
            $epgRows = $pdo->query('SELECT epg_id, display_name FROM epg_channels')->fetchAll();
            $byId = [];
            $byName = [];
            foreach ($epgRows as $r) {
                $byId[strtolower((string) $r['epg_id'])] = (string) $r['epg_id'];
                $norm = M3uParserService::normalizeName((string) $r['display_name']);
                if ($norm !== '') {
                    $byName[$norm] = (string) $r['epg_id'];
                }
            }

            $channels = $pdo->query(
                "SELECT id, tvg_id, tvg_name FROM channels WHERE is_enabled = 1 AND (epg_channel_id IS NULL OR epg_channel_id = '')"
            )->fetchAll();

            $upd = $pdo->prepare("UPDATE channels SET epg_channel_id = :epg, epg_match_type = 'auto' WHERE id = :id");
            $matched = 0;
            foreach ($channels as $ch) {
                $tid = strtolower(trim((string) ($ch['tvg_id'] ?? '')));
                $epgId = ($tid !== '' && isset($byId[$tid])) ? $byId[$tid] : null;
                if ($epgId === null) {
                    $norm = M3uParserService::normalizeName((string) $ch['tvg_name']);
                    if ($norm !== '' && isset($byName[$norm])) {
                        $epgId = $byName[$norm];
                    }
                }
                if ($epgId !== null) {
                    $upd->execute([':epg' => $epgId, ':id' => (int) $ch['id']]);
                    $matched++;
                }
            }
            echo json_encode(['ok' => true, 'data' => ['matched' => $matched]]);
            break;

        case 'get_settings':
            $m3u = (string) $settings->get('m3u_source_url', '');
            $epg = (string) $settings->get('epg_source_url', '');
            echo json_encode([
                'ok' => true,
                'data' => [
                    'm3u_source_url_masked' => $m3u !== '' ? substr($m3u, 0, 28) . '••••••' : '',
                    'epg_source_url_masked' => $epg !== '' ? substr($epg, 0, 36) . (strlen($epg) > 36 ? '••••' : '') : '',
                    'sync_interval_hours' => (int) $settings->get('sync_interval_hours', '72'),
                    'sync_preferred_hour' => (int) $settings->get('sync_preferred_hour', '3'),
                    'enforce_lan_ip' => $settings->get('enforce_lan_ip', '1') === '1',
                    'output_token' => $settings->get('output_token', ''),
                    'app_root' => dirname(__DIR__),
                ],
            ]);
            break;

        case 'save_settings':
            if ($method !== 'POST') {
                throw new RuntimeException('POST vereist.');
            }
            if (!empty($input['m3u_source_url'])) {
                $settings->set('m3u_source_url', trim((string) $input['m3u_source_url']), true);
            }
            if (!empty($input['epg_source_url'])) {
                $settings->set('epg_source_url', trim((string) $input['epg_source_url']), true);
            }
            if (isset($input['sync_interval_hours'])) {
                $settings->set('sync_interval_hours', (string) max(1, (int) $input['sync_interval_hours']), false);
            }
            if (isset($input['sync_preferred_hour'])) {
                $hour = max(0, min(23, (int) $input['sync_preferred_hour']));
                $settings->set('sync_preferred_hour', (string) $hour, false);
            }
            if (isset($input['enforce_lan_ip'])) {
                $settings->set('enforce_lan_ip', $input['enforce_lan_ip'] ? '1' : '0', false);
            }
            echo json_encode(['ok' => true]);
            break;

        case 'regenerate_token':
            if ($method !== 'POST') {
                throw new RuntimeException('POST vereist.');
            }
            $newToken = bin2hex(random_bytes(16));
            $settings->set('output_token', $newToken, false);
            echo json_encode(['ok' => true, 'data' => ['output_token' => $newToken]]);
            break;

        case 'logout':
            if ($method !== 'POST') {
                throw new RuntimeException('POST vereist.');
            }
            $auth->logout();
            echo json_encode(['ok' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Onbekende actie.']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
