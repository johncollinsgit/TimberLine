<?php

return [
    'default' => 'single',
    'channels' => [
        'single' => ['driver' => 'single', 'path' => storage_path('logs/enterprise.log'), 'level' => 'warning', 'replace_placeholders' => true],
        'null' => ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class],
    ],
];
