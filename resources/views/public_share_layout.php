<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <title><?= e($title ?? 'Freigegebene Seite') ?></title>
    <?= $vite->tags('public-share.js', $cspNonce ?? null) ?>
</head>
<body class="min-h-screen antialiased">
<?= $content ?? '' ?>
</body>
</html>
