<?php

declare(strict_types=1);

return [
    'app_name' => 'Customer & Digital Product Sales',
    'base_url' => 'https://digital-store.top',
    'session_name' => 'cmsdps_session',
    'timezone' => 'UTC',
    'db' => [
        'path' => dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'database.sqlite',
    ],
];

