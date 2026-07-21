<?php

use App\Models\FieldServiceJob;
use App\Models\FieldServiceTask;
use App\Models\FieldServiceWorkCandidate;
use App\Models\Tenant;
use App\Models\User;

test('app review workspace command creates an idempotent fictional field operations tenant', function (): void {
    $arguments = ['--password' => 'Review-only-passphrase-220'];

    $this->artisan('everbranch:prepare-app-review-workspace', $arguments)->assertSuccessful();
    $this->artisan('everbranch:prepare-app-review-workspace', $arguments)->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'everbranch-review')->sole();
    $owner = User::query()->where('email', 'appreview@theeverbranch.com')->sole();

    expect($owner->tenants()->whereKey($tenant->id)->exists())->toBeTrue()
        ->and(FieldServiceJob::query()->forTenantId($tenant->id)->count())->toBe(2)
        ->and(FieldServiceTask::query()->forTenantId($tenant->id)->sole()->assignees()->count())->toBe(2)
        ->and(FieldServiceWorkCandidate::query()->forTenantId($tenant->id)->count())->toBe(1)
        ->and($tenant->moduleEntitlements()->where('enabled_status', 'enabled')->count())->toBe(7);
});

test('app review workspace command requires a nontrivial password', function (): void {
    $this->artisan('everbranch:prepare-app-review-workspace', ['--password' => 'too-short'])
        ->assertFailed();
});
