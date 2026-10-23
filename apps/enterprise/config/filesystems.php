<?php

return [
    'default' => 'local',
    'disks' => [
        'local' => ['driver' => 'local', 'root' => storage_path('app/private'), 'serve' => false, 'visibility' => 'private', 'throw' => true],
        'team_private' => ['driver' => 'local', 'root' => storage_path('app/team-private'), 'serve' => false, 'visibility' => 'private', 'throw' => true],
    ],
    'links' => [],
];
