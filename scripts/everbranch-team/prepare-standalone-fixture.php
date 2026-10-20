<?php

declare(strict_types=1);

// The inherited preparer refuses existing/non-checkout/non-disposable databases.
require __DIR__.'/prepare-workspace-fixture.php';
App\Models\TenantBrandProfile::query()->create(['tenant_id' => $tenants[0]->id, 'display_name' => 'Modern Forestry']);
$marker = json_decode(file_get_contents($database.'.fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$marker['purpose'] = 'standalone-teams-acceptance';
file_put_contents($database.'.fixture.json', json_encode($marker, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Prepared fictional branded standalone Teams fixture.\n";
