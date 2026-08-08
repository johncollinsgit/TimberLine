<?php

declare(strict_types=1);

use Everbranch\Ci\LegacyMigrationCompatibility;

require_once dirname(__DIR__, 3).'/scripts/ci/lib/LegacyMigrationCompatibility.php';

it('accepts only an exact checksum-pinned legacy compatibility change', function (): void {
    $path = 'database/migrations/legacy.php';
    $before = '<?php // before';
    $after = '<?php // after';
    $manifest = [
        $path => [
            'before_sha256' => hash('sha256', $before),
            'after_sha256' => hash('sha256', $after),
            'reason' => 'A generated MySQL identifier blocks clean installation.',
            'test' => 'tests/Unit/Infrastructure/LegacyMigrationCompatibilityTest.php',
        ],
    ];

    $compatibility = new LegacyMigrationCompatibility(dirname(__DIR__, 3), $manifest);

    expect($compatibility->validate($path, $before, $after))->toBe([])
        ->and($compatibility->validate($path, $before, $after.' changed'))
        ->toContain("{$path}: compatibility approval does not match the proposed migration checksum.");
});

it('rejects an unapproved edit to a released migration', function (): void {
    $path = 'database/migrations/released.php';
    $compatibility = new LegacyMigrationCompatibility(dirname(__DIR__, 3), []);

    expect($compatibility->validate($path, 'before', 'after'))
        ->toContain("{$path}: an existing migration was changed. Add a new restart-safe repair migration instead; production may already have recorded the original.");
});
