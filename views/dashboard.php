<?php
/**
 * @var string $csrfToken
 */
ob_start();
?>
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
        <button id="adminToolsBtn" class="btn btn-ghost" title="Admin & Database Tools">⚙️</button>
        <button id="themeToggle" class="btn btn-ghost" title="Thema wisselen">🌓</button>
        <button id="logoutBtn" class="btn btn-ghost">Uitloggen</button>
    </div>
</header>

<main class="container">
    <section id="tab-dashboard" class="tab-panel active"></section>
    <section id="tab-channels" class="tab-panel"></section>
    <section id="tab-epg" class="tab-panel"></section>
    <section id="tab-cache" class="tab-panel"></section>
    <section id="tab-emoji-rules" class="tab-panel"></section>
    <section id="tab-admin" class="tab-panel"></section>
    <section id="tab-settings" class="tab-panel"></section>
</main>

<div id="modalRoot"></div>
<div id="toastRoot" class="toast hidden"></div>
<?php
$jsFiles = glob(dirname(__DIR__) . '/assets/js/{,*/}*.js', GLOB_BRACE) ?: [];
$jsVer = 0;
foreach ($jsFiles as $f) {
    $mt = (int) @filemtime($f);
    if ($mt > $jsVer) {
        $jsVer = $mt;
    }
}
?>
<script type="module" src="assets/js/App.js?v=<?= $jsVer ?>"></script>
<?php
$content = ob_get_clean();
$pageTitle = 'IPTV & EPG Manager';
$bodyClass = '';
require __DIR__ . '/layout.php';
