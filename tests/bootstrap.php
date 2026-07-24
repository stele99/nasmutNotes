<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

putenv('APP_ENV=testing');
putenv('APP_DEBUG=true');
putenv('APP_KEY=base64:dGVzdC1rZXktZm9yLXBodW5pdC10ZXN0cy1vbmx5ITE=');
putenv('SESSION_LIFETIME_DAYS=30');
putenv('DB_PATH=:memory:');
