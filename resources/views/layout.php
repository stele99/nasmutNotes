<!DOCTYPE html>
<html lang="de" x-data="theme" x-init="init" :data-theme="mode === 'system' ? null : mode">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/icon/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="icon" href="/icon/favicon-16.png" sizes="16x16" type="image/png">
    <link rel="apple-touch-icon" href="/icon/apple-touch-icon.png" sizes="180x180">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#2563eb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Notizen">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="application-name" content="Notizen &amp; Tasks">
    <meta name="format-detection" content="telephone=no">
    <title><?= e($title ?? 'Notizen & Tasks') ?></title>
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <?= $vite->tags('app.js', $cspNonce ?? null) ?>
</head>
<body class="min-h-screen antialiased">
<?= $content ?? '' ?>
</body>
</html>
