<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/autoload.php';

use App\Config\EnvLoader;
use App\Security\AuthService;

$envFile = dirname(__DIR__) . '/.env';
if (!is_file($envFile)) {
    header('Location: install.php');
    exit;
}

EnvLoader::load($envFile);
$auth = new AuthService();
$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    if (!$auth->verifyCsrfToken($csrf)) {
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

$csrfToken = $auth->getCsrfToken();

if (!$auth->isAuthenticated()):
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inloggen — IPTV & EPG Manager</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h1>IPTV & EPG Manager</h1>
        <p class="muted">Log in om je kanalen, groepen en EPG te beheren.</p>
        <?php if ($loginError !== ''): ?>
            <div class="alert alert-error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post" class="stack-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="login_submit" value="1">
            <label>Gebruikersnaam
                <input type="text" name="username" autocomplete="username" required autofocus>
            </label>
            <label>Wachtwoord
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button type="submit" class="btn btn-primary">Inloggen</button>
        </form>
    </div>
</body>
</html>
<?php
exit;
endif;
?>
<!DOCTYPE html>
<html lang="nl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>IPTV & EPG Manager</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
    <header class="topbar">
        <div class="topbar-left">
            <span class="brand">📺 IPTV & EPG Manager</span>
        </div>
        <nav class="tabs" role="tablist">
            <button class="tab-btn active" data-tab="dashboard">Dashboard</button>
            <button class="tab-btn" data-tab="channels">Groepen & Kanalen</button>
            <button class="tab-btn" data-tab="epg">EPG Koppeling</button>
            <button class="tab-btn" data-tab="settings">Bronnen & Cron</button>
        </nav>
        <div class="topbar-right">
            <button id="themeToggle" class="btn btn-ghost" title="Thema wisselen">🌓</button>
            <button id="logoutBtn" class="btn btn-ghost">Uitloggen</button>
        </div>
    </header>

    <main class="container">
        <!-- TAB 1: DASHBOARD -->
        <section id="tab-dashboard" class="tab-panel active">
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-label">Actieve Kanalen</span>
                    <strong class="stat-value" id="statActiveChannels">-</strong>
                    <span class="stat-sub" id="statTotalChannels">van - totaal</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Actieve Groepen</span>
                    <strong class="stat-value" id="statActiveGroups">-</strong>
                    <span class="stat-sub" id="statTotalGroups">van - totaal</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">EPG Gekoppeld</span>
                    <strong class="stat-value" id="statLinkedEpg">-</strong>
                    <span class="stat-sub" id="statEpgChannels">- EPG zenders in cache</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Laatste Sync (UTC)</span>
                    <strong class="stat-value small" id="statLastSync">-</strong>
                    <span class="stat-sub" id="statScheduleInfo">Elke - uur</span>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2>Synchronisatie</h2>
                    <div class="btn-group">
                        <button class="btn btn-primary" data-sync="all">🔄 Nu Alles Synchroniseren</button>
                        <button class="btn btn-secondary" data-sync="m3u">Alleen M3U</button>
                        <button class="btn btn-secondary" data-sync="epg">Alleen EPG</button>
                    </div>
                </div>
                <div id="syncStatusBanner" class="sync-banner hidden"></div>
            </div>

            <div class="card">
                <h2>LAN Client Endpoints (TiviMate / Kodi / VLC)</h2>
                <div class="endpoint-list">
                    <div class="endpoint-row">
                        <label>M3U Playlist URL</label>
                        <div class="copy-group">
                            <input type="text" id="playlistUrlInput" readonly>
                            <button class="btn btn-secondary copy-btn" data-copy="playlistUrlInput">Kopieer</button>
                        </div>
                    </div>
                    <div class="endpoint-row">
                        <label>XMLTV EPG URL</label>
                        <div class="copy-group">
                            <input type="text" id="epgUrlInput" readonly>
                            <button class="btn btn-secondary copy-btn" data-copy="epgUrlInput">Kopieer</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2>Recente Sync Logs</h2>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr><th>Tijd</th><th>Type</th><th>Status</th><th>Bericht</th></tr>
                        </thead>
                        <tbody id="logsTableBody"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- TAB 2: GROEPEN & KANALEN -->
        <section id="tab-channels" class="tab-panel">
            <div class="split-view">
                <aside class="card groups-pane">
                    <div class="card-header">
                        <h2>Groepen</h2>
                        <div class="btn-group">
                            <button class="btn btn-sm btn-secondary" id="enableAllGroupsBtn">Alles Aan</button>
                            <button class="btn btn-sm btn-secondary" id="disableAllGroupsBtn">Alles Uit</button>
                        </div>
                    </div>
                    <input type="search" id="groupSearchInput" placeholder="Filter groepen..." class="search-input">
                    <div id="groupsList" class="groups-list"></div>
                </aside>

                <div class="card channels-pane">
                    <div class="card-header">
                        <h2 id="selectedGroupTitle">Alle Kanalen</h2>
                        <div class="btn-group">
                            <button class="btn btn-sm btn-secondary" id="bulkEnableChannelsBtn">Selectie Aan</button>
                            <button class="btn btn-sm btn-secondary" id="bulkDisableChannelsBtn">Selectie Uit</button>
                        </div>
                    </div>
                    <div class="toolbar">
                        <input type="search" id="channelSearchInput" placeholder="Zoek kanaalnaam..." class="search-input">
                    </div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="selectAllChannels"></th>
                                    <th>Aan</th>
                                    <th>#</th>
                                    <th>Naam (Origineel / Custom)</th>
                                    <th>Groep</th>
                                </tr>
                            </thead>
                            <tbody id="channelsTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- TAB 3: EPG KOPPELING -->
        <section id="tab-epg" class="tab-panel">
            <div class="card">
                <div class="card-header">
                    <h2>EPG Zender Koppeling</h2>
                    <button class="btn btn-primary" id="autoMatchEpgBtn">✨ Auto-koppel op naam</button>
                </div>
                <div class="toolbar">
                    <input type="search" id="epgChannelSearch" placeholder="Zoek actief kanaal..." class="search-input">
                    <select id="epgStatusFilter" class="select-input">
                        <option value="">Alle statussen</option>
                        <option value="unmapped">Geen EPG</option>
                        <option value="mapped">Gekoppeld</option>
                    </select>
                </div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Kanaal</th>
                                <th>Status</th>
                                <th>Gekoppelde EPG Zender</th>
                                <th>Actie</th>
                            </tr>
                        </thead>
                        <tbody id="epgMappingBody"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- TAB 4: BRONNEN & CRON -->
        <section id="tab-settings" class="tab-panel">
            <div class="card">
                <h2>Bronnen & Automatisering</h2>
                <form id="settingsForm" class="stack-form">
                    <label>M3U Bron URL (laat leeg om huidige te behouden)
                        <input type="url" id="settingM3uUrl" placeholder="Huidige: ••••••">
                    </label>
                    <label>EPG Bron URL (laat leeg om huidige te behouden)
                        <input type="url" id="settingEpgUrl" placeholder="http://epgguide.ddns.net/epg/tvguide.xml">
                    </label>
                    <div class="grid-2">
                        <label>Sync Interval (uren)
                            <input type="number" id="settingIntervalHours" min="1" max="720" required>
                        </label>
                        <label>Voorkeursuur (0-23)
                            <input type="number" id="settingPreferredHour" min="0" max="23" required>
                        </label>
                    </div>
                    <label class="checkbox-row">
                        <input type="checkbox" id="settingEnforceLan">
                        <span>Beperk uitgaande feeds tot lokale LAN-subnets</span>
                    </label>
                    <button type="submit" class="btn btn-primary">Instellingen Opslaan</button>
                </form>
            </div>

            <div class="card">
                <h2>Beveiliging & QNAP Crontab</h2>
                <div class="endpoint-row">
                    <label>Uitgaand Feed Token</label>
                    <div class="copy-group">
                        <input type="text" id="settingOutputToken" readonly>
                        <button class="btn btn-secondary" id="regenTokenBtn">Regenereer Token</button>
                    </div>
                </div>
                <div class="endpoint-row" style="margin-top:1rem;">
                    <label>QNAP Crontab (elke uur controleren)</label>
                    <div class="copy-group">
                        <input type="text" id="qnapCronInput" readonly>
                        <button class="btn btn-secondary copy-btn" data-copy="qnapCronInput">Kopieer</button>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- EPG Picker Modal -->
    <div id="epgModal" class="modal hidden">
        <div class="modal-content">
            <div class="card-header">
                <h3>Kies EPG Zender voor <span id="epgModalChannelName"></span></h3>
                <button class="btn btn-ghost" id="closeEpgModal">✕</button>
            </div>
            <input type="search" id="epgModalSearch" placeholder="Typ zendernaam (bv. NPO 1, VRT 1)..." class="search-input">
            <div id="epgModalResults" class="epg-results"></div>
        </div>
    </div>

    <div id="toast" class="toast hidden"></div>
    <script src="assets/js/app.js"></script>
</body>
</html>
