<?php
/**
 * @var bool $isLocked
 * @var int $step
 * @var string $error
 * @var array<int, array{label: string, ok: bool}> $checks
 * @var bool $allChecksOk
 * @var string $defaultAppUrl
 */
ob_start();
?>
<div class="auth-card" style="max-width:580px;">
    <?php if ($isLocked): ?>
        <h1>Installatie al voltooid</h1>
        <p class="muted">Deze applicatie is al geïnstalleerd en vergrendeld. Verwijder <code>.env</code> of log in via het dashboard.</p>
        <a href="index.php" class="btn btn-primary" style="display:inline-flex;margin-top:1rem;">Naar Dashboard</a>
    <?php else: ?>
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
            <ul class="check-list" id="prereqList">
                <?php foreach ($checks as $c): ?>
                    <li class="check-item <?= $c['passed'] ? 'ok' : 'fail' ?>" data-check-id="<?= htmlspecialchars($c['id'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="check-row">
                            <span class="check-icon"><?= $c['passed'] ? '✓' : '✗' ?></span>
                            <span class="check-label"><?= htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="check-val muted"><?= htmlspecialchars($c['current_value'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php if (!$c['passed']): ?>
                            <div class="remediation-box" role="alert">
                                <div class="remediation-title"><?= htmlspecialchars($c['fix_title'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="remediation-cmd-wrap">
                                    <pre><code><?= htmlspecialchars($c['fix_command'], ENT_QUOTES, 'UTF-8') ?></code></pre>
                                    <button type="button" class="btn btn-sm copy-fix-btn" data-copy-text="<?= htmlspecialchars($c['fix_command'], ENT_QUOTES, 'UTF-8') ?>">Kopieer</button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="btn-group" style="margin-top:1rem;">
                <button type="button" id="recheckPrereqsBtn" class="btn">🔄 Opnieuw controleren</button>
                <a href="index.php?route=install&step=2" id="nextStepBtn" class="btn btn-primary <?= $allChecksOk ? '' : 'hidden' ?>" style="flex:1;justify-content:center;">Volgende stap →</a>
            </div>
            <p id="prereqErrorHint" class="muted <?= $allChecksOk ? 'hidden' : '' ?>" style="color:var(--danger);margin-top:0.75rem;">Los bovenstaande fouten op en klik op "Opnieuw controleren".</p>
            <script type="module" src="assets/js/pages/InstallerPage.js"></script>

        <?php elseif ($step === 2): ?>
            <form method="post" action="index.php?route=install&step=2" class="stack-form">
                <input type="hidden" name="step" value="2">
                <label>Database Host <input type="text" name="db_host" value="127.0.0.1" required></label>
                <label>Database Poort <input type="text" name="db_port" value="3306" required></label>
                <label>Database Naam <input type="text" name="db_name" value="iptv_manager" required></label>
                <label>Database Gebruiker <input type="text" name="db_user" required></label>
                <label>Database Wachtwoord <input type="password" name="db_pass"></label>
                <label>Publieke / LAN App URL <input type="url" name="app_url" value="<?= htmlspecialchars($defaultAppUrl, ENT_QUOTES, 'UTF-8') ?>" required></label>
                <label>Toegestane LAN Subnets (CIDR) <input type="text" name="lan_subnets" value="192.168.0.0/16,10.0.0.0/8" required></label>
                <button type="submit" class="btn btn-primary">Database & .env Opslaan →</button>
            </form>

        <?php elseif ($step === 3): ?>
            <form method="post" action="index.php?route=install&step=3" class="stack-form">
                <input type="hidden" name="step" value="3">
                <label>Admin Gebruikersnaam <input type="text" name="username" value="admin" required></label>
                <label>Admin Wachtwoord (min. 6 tekens) <input type="password" name="password" minlength="6" required></label>
                <button type="submit" class="btn btn-primary">Beheerder Aanmaken →</button>
            </form>

        <?php elseif ($step === 4): ?>
            <form method="post" action="index.php?route=install&step=4" class="stack-form">
                <input type="hidden" name="step" value="4">
                <label>M3U Playlist URL (wordt versleuteld opgeslagen) <input type="url" name="m3u_url" placeholder="http://provider.example/get.php?username=..." required></label>
                <label>EPG XMLTV URL <input type="url" name="epg_url" value="http://epgguide.ddns.net/epg/tvguide.xml" required></label>
                <div class="grid-2">
                    <label>Sync Interval (dagen) <input type="number" name="interval_days" value="3" min="1" max="30"></label>
                    <label>Voorkeursuur (0-23) <input type="number" name="preferred_hour" value="3" min="0" max="23"></label>
                </div>
                <label class="checkbox-row">
                    <input type="checkbox" name="run_sync" value="1" checked>
                    <span>Direct initiële M3U & EPG synchronisatie uitvoeren</span>
                </label>
                <button type="submit" class="btn btn-primary">Installatie Voltooien ✓</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Installatie Wizard — IPTV & EPG Manager';
$bodyClass = 'auth-body';
require __DIR__ . '/layout.php';
