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
            <?php
            $stepLabels = [1 => '1. Systeem', 2 => '2. Database', 3 => '3. Admin', 4 => '4. Bronnen'];
            foreach ($stepLabels as $num => $lbl):
                if ($num < $step): ?>
                    <a href="index.php?route=install&step=<?= $num ?>" class="step-link completed"><?= $lbl ?></a>
                <?php else: ?>
                    <span class="<?= $step === $num ? 'active' : '' ?>"><?= $lbl ?></span>
                <?php endif;
            endforeach;
            ?>
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
            <script type="module" src="assets/js/pages/InstallerPage.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/js/pages/InstallerPage.js') ?>"></script>

        <?php elseif ($step === 2): ?>
            <form method="post" action="index.php?route=install&step=2" class="stack-form">
                <input type="hidden" name="step" value="2">
                <label class="checkbox-row">
                    <input type="checkbox" name="use_existing_user" id="useExistingUserToggle" value="1">
                    <span>Ik gebruik al een bestaande restricted gebruiker (geen root-provisioning)</span>
                </label>

                <div id="adminProvisionBlock" class="card" style="margin-bottom:0;">
                    <h3 style="margin-top:0;">Blok A — Eenmalige Database Beheerder</h3>
                    <p class="muted" style="font-size:0.85rem;">Wordt uitsluitend gebruikt om de database en het restricted account aan te maken. Wordt <strong>niet</strong> opgeslagen in <code>.env</code>.</p>
                    <div class="grid-2">
                        <label>DB Host <input type="text" name="db_host" value="<?= htmlspecialchars($savedDbHost ?? '127.0.0.1', ENT_QUOTES, 'UTF-8') ?>" required></label>
                        <label>Poort <input type="text" name="db_port" value="<?= htmlspecialchars($savedDbPort ?? '3306', ENT_QUOTES, 'UTF-8') ?>" required></label>
                    </div>
                    <div class="grid-2" style="margin-top:0.75rem;">
                        <label>Beheerder / Root Gebruiker <input type="text" name="admin_user" value="root"></label>
                        <label>Beheerder Wachtwoord <input type="password" name="admin_pass"></label>
                    </div>
                </div>

                <div class="card" style="margin-bottom:0;">
                    <h3 style="margin-top:0;">Blok B — Dedicated Applicatie-account (.env)</h3>
                    <label>Database Naam <input type="text" name="db_name" value="<?= htmlspecialchars($savedDbName ?? 'iptv_manager', ENT_QUOTES, 'UTF-8') ?>" required></label>
                    <label style="margin-top:0.75rem;">Restricted DB Gebruiker <input type="text" name="app_user" value="<?= htmlspecialchars($suggestedAppUser ?? 'iptv_user', ENT_QUOTES, 'UTF-8') ?>" required></label>
                    <label style="margin-top:0.75rem;">Gegenereerd DB Wachtwoord
                        <div class="copy-group">
                            <input type="text" name="app_pass" id="appDbPassInput" value="<?= htmlspecialchars($suggestedAppPass ?? '', ENT_QUOTES, 'UTF-8') ?>" data-has-existing="<?= !empty($hasExistingDbPass) ? '1' : '0' ?>" <?= empty($hasExistingDbPass) ? 'required' : '' ?>>
                            <button type="button" class="btn btn-sm" id="regenAppPassBtn" title="Nieuw wachtwoord genereren">🔄</button>
                            <button type="button" class="btn btn-sm" id="copyAppPassBtn">Kopieer</button>
                        </div>
                        <?php if (!empty($hasExistingDbPass)): ?>
                            <span class="muted" style="font-size:0.8rem;">Reeds opgeslagen — laat leeg om te behouden of vul in om te overschrijven.</span>
                        <?php endif; ?>
                    </label>
                </div>

                <label>Publieke / LAN App URL <input type="url" name="app_url" value="<?= htmlspecialchars($savedAppUrl ?? $defaultAppUrl, ENT_QUOTES, 'UTF-8') ?>" required></label>
                <label>Toegestane LAN Subnets (CIDR) <input type="text" name="lan_subnets" value="<?= htmlspecialchars($savedSubnets ?? '192.168.0.0/16,10.0.0.0/8', ENT_QUOTES, 'UTF-8') ?>" required></label>
                <div class="wizard-footer">
                    <a href="index.php?route=install&step=1" class="btn">← Vorige stap</a>
                    <button type="submit" class="btn btn-primary">Database Provisionen & .env Opslaan →</button>
                </div>
            </form>
            <script type="module" src="assets/js/pages/InstallerPage.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/js/pages/InstallerPage.js') ?>"></script>

        <?php elseif ($step === 3): ?>
            <form method="post" action="index.php?route=install&step=3" class="stack-form">
                <input type="hidden" name="step" value="3">
                <label>Admin Gebruikersnaam <input type="text" name="username" value="<?= htmlspecialchars($savedAdminUser ?? 'admin', ENT_QUOTES, 'UTF-8') ?>" required></label>
                <label>Admin Wachtwoord (min. 6 tekens)
                    <input type="password" name="password" minlength="6" data-has-existing="<?= !empty($hasExistingAdminPass) ? '1' : '0' ?>" <?= empty($hasExistingAdminPass) ? 'required' : '' ?>>
                    <?php if (!empty($hasExistingAdminPass)): ?>
                        <span class="muted" style="font-size:0.8rem;">Reeds opgeslagen — laat leeg om te behouden of vul in om te overschrijven.</span>
                    <?php endif; ?>
                </label>
                <div class="wizard-footer">
                    <a href="index.php?route=install&step=2" class="btn">← Vorige stap</a>
                    <button type="submit" class="btn btn-primary">Beheerder Aanmaken →</button>
                </div>
            </form>

        <?php elseif ($step === 4): ?>
            <form method="post" action="index.php?route=install&step=4" class="stack-form">
                <input type="hidden" name="step" value="4">
                <label>Bron-modus
                    <select name="source_mode" id="sourceModeSelect">
                        <option value="xtream">Xtream Codes Login (Aanbevolen — auto M3U + EPG)</option>
                        <option value="custom_urls">Handmatige M3U & EPG URL's</option>
                    </select>
                </label>

                <div id="xtreamFieldsBlock" class="card" style="margin-bottom:0;">
                    <label>Server URL (bv. http://provider.tld:8080)
                        <input type="url" name="xtream_host" id="xtreamHostInput" placeholder="http://provider.tld:8080">
                    </label>
                    <div class="grid-2" style="margin-top:0.75rem;">
                        <label>Gebruikersnaam <input type="text" name="xtream_username" id="xtreamUserInput"></label>
                        <label>Wachtwoord <input type="password" name="xtream_password" id="xtreamPassInput"></label>
                    </div>
                </div>

                <div id="customUrlFieldsBlock" class="card hidden" style="margin-bottom:0;">
                    <label>M3U Playlist URL (wordt versleuteld opgeslagen)
                        <input type="url" name="m3u_url" id="customM3uInput" placeholder="http://provider.example/get.php?username=..." data-has-existing="<?= !empty($hasExistingM3u) ? '1' : '0' ?>">
                        <?php if (!empty($hasExistingM3u)): ?>
                            <span class="muted" style="font-size:0.8rem;">Reeds opgeslagen — laat leeg om te behouden of vul in om te overschrijven.</span>
                        <?php endif; ?>
                    </label>
                    <div id="xtreamAutoDetectBanner" class="sync-banner hidden" style="margin-bottom:0.75rem;">
                        <span>✨ Xtream M3U gedetecteerd!</span>
                        <button type="button" class="btn btn-sm" id="applyDetectedXmltvBtn">Vul xmltv.php EPG-URL in</button>
                    </div>
                    <label style="margin-top:0.75rem;">EPG XMLTV / .xml.gz URL (optioneel)
                        <input type="url" name="epg_url" id="customEpgInput" placeholder="https://.../guide.xml.gz" data-has-existing="<?= !empty($hasExistingEpg) ? '1' : '0' ?>">
                    </label>
                </div>

                <div class="grid-2">
                    <label>Sync Interval (dagen) <input type="number" name="interval_days" value="<?= (int) ($savedIntervalDays ?? 3) ?>" min="1" max="30"></label>
                    <label>Voorkeursuur (0-23) <input type="number" name="preferred_hour" value="<?= (int) ($savedPreferredHour ?? 3) ?>" min="0" max="23"></label>
                </div>
                <label class="checkbox-row">
                    <input type="checkbox" name="run_sync" value="1" checked>
                    <span>Direct initiële M3U & EPG synchronisatie uitvoeren (non-blocking)</span>
                </label>
                <div class="wizard-footer">
                    <a href="index.php?route=install&step=3" class="btn">← Vorige stap</a>
                    <button type="submit" class="btn btn-primary">Installatie Voltooien ✓</button>
                </div>
            </form>
            <script type="module" src="assets/js/pages/InstallerPage.js"></script>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Installatie Wizard — IPTV & EPG Manager';
$bodyClass = 'auth-body';
require __DIR__ . '/layout.php';
