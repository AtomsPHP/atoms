<?php

declare(strict_types=1);

use Monolog\Handler\StreamHandler;
use Monolog\Level;

return [
    'default' => env('LOG_CHANNEL', 'stderr'),
    'channels' => [
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', Level::Debug->value),
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => 'php://stderr'],
        ],
    ],
];
