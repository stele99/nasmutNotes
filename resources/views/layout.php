<!DOCTYPE html>
<html lang="de" x-data="theme" x-init="init" :data-theme="mode === 'system' ? null : mode">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <title><?= e($title ?? 'Notizen & Tasks') ?></title>
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <?= $vite->tags('app.js', $cspNonce ?? null) ?>
</head>
<body class="min-h-screen antialiased">
<?= $content ?? '' ?>
</body>
</html>
