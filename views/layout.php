<?php
/**
 * @var string $pageTitle
 * @var string $content
 * @var string|null $csrfToken
 * @var string|null $bodyClass
 */
?>
<!DOCTYPE html>
<html lang="nl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (!empty($csrfToken)): ?>
        <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <title><?= htmlspecialchars($pageTitle ?? 'IPTV & EPG Manager', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/css/app.css?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/css/app.css') ?>">
    <link rel="stylesheet" href="assets/css/components.css?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/css/components.css') ?>">
</head>
<body class="<?= htmlspecialchars($bodyClass ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <?= $content ?>
</body>
</html>
