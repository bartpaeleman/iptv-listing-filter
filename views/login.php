<?php
/**
 * @var string $csrfToken
 * @var string $loginError
 */
ob_start();
?>
<div class="auth-card">
    <h1>IPTV & EPG Manager</h1>
    <p class="muted">Log in om je kanalen, groepen en EPG te beheren.</p>
    <?php if ($loginError !== ''): ?>
        <div class="alert alert-error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <form method="post" action="index.php?route=login" id="loginForm" name="loginForm" class="stack-form" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="login_submit" value="1">
        <label for="username">Gebruikersnaam
            <input type="text" id="username" name="username" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required autofocus>
        </label>
        <label for="password">Wachtwoord
            <input type="password" id="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit" class="btn btn-primary">Inloggen</button>
    </form>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Inloggen — IPTV & EPG Manager';
$bodyClass = 'auth-body';
require __DIR__ . '/layout.php';
