<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/autoload.php';

use App\Config\EnvLoader;
use App\Database\Connection;
use App\Repositories\SettingsRepository;
use App\Services\SchedulerService;

$rootDir = dirname(__DIR__);
$envFile = $rootDir . '/.env';
$schemaFile = $rootDir . '/database/schema.sql';

// Check if already installed
$isInstalled = false;
if (is_file($envFile)) {
    try {
        EnvLoader::load($envFile);
        $pdo = Connection::getInstance();
        $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($userCount > 0) {
            $isInstalled = true;
        }
    } catch (\Throwable) {
        $isInstalled = false;
    }
}

if ($isInstalled) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="nl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Installatie Vergrendeld — IPTV & EPG Manager</title>
        <link rel="stylesheet" href="assets/css/app.css">
    </head>
    <body class="auth-body">
        <div class="auth-card">
            <h1>Installatie al voltooid</h1>
            <p class="muted">Deze applicatie is al geïnstalleerd en vergrendeld. Verwijder <code>.env</code> of log in via het dashboard.</p>
            <a href="index.php" class="btn btn-primary" style="display:inline-flex;margin-top:1rem;">Naar Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$step = max(1, min(4, (int) ($_GET['step'] ?? $_POST['step'] ?? 1)));
$error = '';
$successMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($step === 2) {
            $dbHost = trim((string) ($_POST['db_host'] ?? '127.0.0.1'));
            $dbPort = trim((string) ($_POST['db_port'] ?? '3306'));
            $dbName = trim((string) ($_POST['db_name'] ?? 'iptv_manager'));
            $dbUser = trim((string) ($_POST['db_user'] ?? ''));
            $dbPass = (string) ($_POST['db_pass'] ?? '');
            $appUrl = rtrim(trim((string) ($_POST['app_url'] ?? '')), '/');
            $subnets = trim((string) ($_POST['lan_subnets'] ?? '192.168.0.0/16,10.0.0.0/8'));

            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $appKey = bin2hex(random_bytes(32));
            $envContent = implode("\n", [
                'APP_ENV=production',
                "APP_KEY={$appKey}",
                "APP_URL={$appUrl}",
                "DB_HOST={$dbHost}",
                "DB_PORT={$dbPort}",
                "DB_NAME={$dbName}",
                "DB_USER={$dbUser}",
                'DB_PASS="' . str_replace('"', '\"', $dbPass) . '"',
                "LAN_SUBNET_ALLOW={$subnets}",
                '',
            ]);

            if (file_put_contents($envFile, $envContent) === false) {
                throw new RuntimeException('Kan .env niet wegschrijven. Controleer schrijfrechten.');
            }

            $sql = file_get_contents($schemaFile);
            if ($sql === false) {
                throw new RuntimeException('Kan database/schema.sql niet lezen.');
            }
            $pdo->exec($sql);

            header('Location: install.php?step=3');
            exit;
        }

        if ($step === 3) {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if ($username === '' || strlen($password) < 6) {
                throw new RuntimeException('Gebruikersnaam is verplicht en wachtwoord moet minstens 6 tekens bevatten.');
            }

            EnvLoader::load($envFile);
            $pdo = Connection::getInstance();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:u, :p)');
            $stmt->execute([':u' => $username, ':p' => $hash]);

            $settings = new SettingsRepository($pdo);
            if ((string) $settings->get('output_token', '') === '') {
                $settings->set('output_token', bin2hex(random_bytes(16)), false);
            }

            header('Location: install.php?step=4');
            exit;
        }

        if ($step === 4) {
            EnvLoader::load($envFile);
            $pdo = Connection::getInstance();
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
                $scheduler = new SchedulerService($pdo, $settings);
                $scheduler->run(true, 'all');
            }

            header('Location: index.php');
            exit;
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

// System checks for Step 1
$checks = [
    ['label' => 'PHP >= 8.2 (' . PHP_VERSION . ')', 'ok' => version_compare(PHP_VERSION, '8.2.0', '>=')],
    ['label' => 'Extensie: pdo_mysql', 'ok' => extension_loaded('pdo_mysql')],
    ['label' => 'Extensie: xmlreader', 'ok' => extension_loaded('xmlreader')],
    ['label' => 'Extensie: curl', 'ok' => extension_loaded('curl')],
    ['label' => 'Extensie: openssl', 'ok' => extension_loaded('openssl')],
    ['label' => 'Schrijfrechten root (.env)', 'ok' => is_writable($rootDir)],
    ['label' => 'Schrijfrechten storage/', 'ok' => is_writable($rootDir . '/storage')],
];
$allChecksOk = !in_array(false, array_column($checks, 'ok'), true);
$defaultAppUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'nas.local')
    . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installatie Wizard — IPTV & EPG Manager</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-body">
    <div class="auth-card" style="max-width:580px;">
        <div class="wizard-steps">
            <span class="<?= $step === 1 ? 'active' : '' ?>">1. Systeem</span>
            <span class="<?= $step === 2 ? 'active' : '' ?>">2. Database</span>
            <span class="<?= $step === 3 ? 'active' : '' ?>">3. Admin</span>
            <span class="<?= $step === 4 ? 'active' : '' ?>">4. Bronnen</span>
        </div>

        <h1>IPTV & EPG Manager Setup</h1>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <p class="muted">Controle van vereiste PHP-extensies en schrijfrechten op je QNAP NAS / server.</p>
            <ul class="check-list">
                <?php foreach ($checks as $c): ?>
                    <li class="<?= $c['ok'] ? 'ok' : 'fail' ?>">
                        <span><?= $c['ok'] ? '✓' : '✗' ?></span>
                        <?= htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8') ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($allChecksOk): ?>
                <a href="install.php?step=2" class="btn btn-primary" style="width:100%;justify-content:center;">Volgende stap →</a>
            <?php else: ?>
                <p class="muted" style="color:var(--danger);">Los bovenstaande fouten op en vernieuw de pagina.</p>
            <?php endif; ?>

        <?php elseif ($step === 2): ?>
            <form method="post" class="stack-form">
                <input type="hidden" name="step" value="2">
                <label>Database Host
                    <input type="text" name="db_host" value="127.0.0.1" required>
                </label>
                <label>Database Poort
                    <input type="text" name="db_port" value="3306" required>
                </label>
                <label>Database Naam
                    <input type="text" name="db_name" value="iptv_manager" required>
                </label>
                <label>Database Gebruiker
                    <input type="text" name="db_user" required>
                </label>
                <label>Database Wachtwoord
                    <input type="password" name="db_pass">
                </label>
                <label>Publieke / LAN App URL
                    <input type="url" name="app_url" value="<?= htmlspecialchars($defaultAppUrl, ENT_QUOTES, 'UTF-8') ?>" required>
                </label>
                <label>Toegestane LAN Subnets (CIDR)
                    <input type="text" name="lan_subnets" value="192.168.0.0/16,10.0.0.0/8" required>
                </label>
                <button type="submit" class="btn btn-primary">Database & .env Opslaan →</button>
            </form>

        <?php elseif ($step === 3): ?>
            <form method="post" class="stack-form">
                <input type="hidden" name="step" value="3">
                <label>Admin Gebruikersnaam
                    <input type="text" name="username" value="admin" required>
                </label>
                <label>Admin Wachtwoord (min. 6 tekens)
                    <input type="password" name="password" minlength="6" required>
                </label>
                <button type="submit" class="btn btn-primary">Beheerder Aanmaken →</button>
            </form>

        <?php elseif ($step === 4): ?>
            <form method="post" class="stack-form">
                <input type="hidden" name="step" value="4">
                <label>M3U Playlist URL (wordt versleuteld opgeslagen)
                    <input type="url" name="m3u_url" placeholder="http://provider.example/get.php?username=..." required>
                </label>
                <label>EPG XMLTV URL
                    <input type="url" name="epg_url" value="http://epgguide.ddns.net/epg/tvguide.xml" required>
                </label>
                <div class="grid-2">
                    <label>Sync Interval (dagen)
                        <input type="number" name="interval_days" value="3" min="1" max="30">
                    </label>
                    <label>Voorkeursuur (0-23)
                        <input type="number" name="preferred_hour" value="3" min="0" max="23">
                    </label>
                </div>
                <label class="checkbox-row">
                    <input type="checkbox" name="run_sync" value="1" checked>
                    <span>Direct initiële M3U & EPG synchronisatie uitvoeren</span>
                </label>
                <button type="submit" class="btn btn-primary">Installatie Voltooien ✓</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
