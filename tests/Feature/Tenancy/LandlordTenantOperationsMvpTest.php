<?php

use App\Http\Controllers\Landlord\LandlordTenantOperationsController;
use App\Models\LandlordOperatorAction;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\LandlordTenantOperationsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

function landlordOpsHost(): string
{
    $host = parse_url(route('landlord.dashboard'), PHP_URL_HOST);

    return is_string($host) && $host !== '' ? strtolower($host) : 'forestrybackstage.com';
}

beforeEach(function (): void {
    $host = landlordOpsHost();
    config()->set('tenancy.landlord.primary_host', $host);
    config()->set('tenancy.landlord.hosts', [$host]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    Storage::fake('local');
});

test('landlord tenant selector requires explicit tenant token and redirects into selected tenant operations', function (): void {
    $host = landlordOpsHost();
    $tenant = Tenant::query()->create([
        'name' => 'Selector Tenant',
        'slug' => 'selector-tenant',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/select", [])
        ->assertSessionHasErrors('tenant');

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/select", [
            'tenant' => (string) $tenant->id,
        ])
        ->assertRedirect("http://{$host}/landlord/tenants/{$tenant->id}");
});

test('landlord tenant export is explicitly tenant-scoped and writes audit trace', function (): void {
    $host = landlordOpsHost();
    $tenantA = Tenant::query()->create([
        'name' => 'Ops Tenant A',
        'slug' => 'ops-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Ops Tenant B',
        'slug' => 'ops-tenant-b',
    ]);

    MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Tenant',
        'last_name' => 'A',
        'email' => 'a@example.test',
        'normalized_email' => 'a@example.test',
    ]);
    MarketingProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'first_name' => 'Tenant',
        'last_name' => 'B',
        'email' => 'b@example.test',
        'normalized_email' => 'b@example.test',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenantA->id}/operations/export", [
            'tenant_id' => $tenantA->id,
            'tenant_slug' => $tenantA->slug,
            'confirm_phrase' => 'confirm ' . $tenantA->slug,
            'reason' => 'tenant scoped export validation',
            'confirm_export' => '1',
        ])
        ->assertRedirect();

    $action = LandlordOperatorAction::query()
        ->where('tenant_id', $tenantA->id)
        ->where('action_type', LandlordTenantOperationsController::ACTION_EXPORT)
        ->latest('id')
        ->first();

    expect($action)->not->toBeNull()
        ->and((string) $action?->status)->toBe('success');

    $artifactPath = (string) data_get((array) ($action?->result ?? []), 'artifact_path', '');
    expect($artifactPath)->not->toBe('');
    Storage::disk('local')->assertExists($artifactPath);

    $payload = json_decode((string) Storage::disk('local')->get($artifactPath), true);
    expect((int) data_get($payload, 'tenant.id'))->toBe((int) $tenantA->id)
        ->and((string) data_get($payload, 'artifact_type'))->toBe(LandlordTenantOperationsService::SNAPSHOT_ARTIFACT_TYPE)
        ->and(collect((array) data_get($payload, 'data.marketing_profiles', []))->pluck('email')->all())->toBe(['a@example.test']);
});

test('landlord tenant restore blocks cross-tenant artifact target mismatch and records blocked audit', function (): void {
    $host = landlordOpsHost();
    $tenantA = Tenant::query()->create([
        'name' => 'Restore Tenant A',
        'slug' => 'restore-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Restore Tenant B',
        'slug' => 'restore-tenant-b',
    ]);

    MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Restore',
        'last_name' => 'Target',
        'email' => 'restore-a@example.test',
        'normalized_email' => 'restore-a@example.test',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $snapshot = app(LandlordTenantOperationsService::class)->exportTenantSnapshot($tenantA, $user);
    $snapshotPath = Storage::disk('local')->path((string) $snapshot['artifact_path']);
    $upload = new UploadedFile(
        $snapshotPath,
        (string) $snapshot['artifact_file_name'],
        'application/json',
        null,
        true
    );

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenantB->id}/operations/restore", [
            'tenant_id' => $tenantB->id,
            'tenant_slug' => $tenantB->slug,
            'confirm_phrase' => 'confirm ' . $tenantB->slug,
            'apply_phrase' => 'apply ' . $tenantB->slug,
            'reason' => 'cross tenant restore should block',
            'confirm_restore' => '1',
            'snapshot_file' => $upload,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('tenant_operations_restore');

    $action = LandlordOperatorAction::query()
        ->where('tenant_id', $tenantB->id)
        ->where('action_type', LandlordTenantOperationsController::ACTION_RESTORE)
        ->latest('id')
        ->first();

    expect($action)->not->toBeNull()
        ->and((string) $action?->status)->toBe('blocked');
});

test('landlord tenant restore dry-run reports projected changes without mutating tenant rows', function (): void {
    $host = landlordOpsHost();
    $tenant = Tenant::query()->create([
        'name' => 'Dry Run Tenant',
        'slug' => 'dry-run-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Dry',
        'last_name' => 'Run',
        'email' => 'dry-run@example.test',
        'normalized_email' => 'dry-run@example.test',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $snapshot = app(LandlordTenantOperationsService::class)->exportTenantSnapshot($tenant, $user);
    $snapshotPath = Storage::disk('local')->path((string) $snapshot['artifact_path']);
    $upload = new UploadedFile(
        $snapshotPath,
        (string) $snapshot['artifact_file_name'],
        'application/json',
        null,
        true
    );

    $profile->delete();
    expect(MarketingProfile::query()->where('tenant_id', $tenant->id)->count())->toBe(0);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/operations/restore", [
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'confirm_phrase' => 'confirm ' . $tenant->slug,
            'confirm_restore' => '1',
            'dry_run' => '1',
            'reason' => 'dry run projection before apply',
            'snapshot_file' => $upload,
        ])
        ->assertRedirect();

    expect(MarketingProfile::query()->where('tenant_id', $tenant->id)->count())->toBe(0);

    $action = LandlordOperatorAction::query()
        ->where('tenant_id', $tenant->id)
        ->where('action_type', LandlordTenantOperationsController::ACTION_RESTORE)
        ->latest('id')
        ->firstOrFail();

    expect((string) $action->status)->toBe('success')
        ->and((string) data_get((array) $action->result, 'mode'))->toBe('dry-run')
        ->and((bool) data_get((array) $action->result, 'dry_run'))->toBeTrue()
        ->and((int) data_get((array) $action->result, 'table_results.marketing_profiles.would_insert'))->toBeGreaterThanOrEqual(1);
});

test('landlord tenant restore blocks artifacts whose scope table manifest does not match payload', function (): void {
    $host = landlordOpsHost();
    $tenant = Tenant::query()->create([
        'name' => 'Manifest Tenant',
        'slug' => 'manifest-tenant',
    ]);

    MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Manifest',
        'last_name' => 'Case',
        'email' => 'manifest@example.test',
        'normalized_email' => 'manifest@example.test',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $snapshot = app(LandlordTenantOperationsService::class)->exportTenantSnapshot($tenant, $user);
    $raw = json_decode((string) Storage::disk('local')->get((string) $snapshot['artifact_path']), true);
    $raw['scope']['tables'] = ['marketing_profiles'];
    unset($raw['checksum_sha256']);
    $raw['checksum_sha256'] = hash(
        'sha256',
        (string) json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)
    );

    $tamperedName = 'tampered-manifest.json';
    $tamperedRelativePath = 'landlord/testing/' . $tamperedName;
    Storage::disk('local')->put(
        $tamperedRelativePath,
        (string) json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    $tamperedPath = Storage::disk('local')->path($tamperedRelativePath);

    $upload = new UploadedFile(
        $tamperedPath,
        $tamperedName,
        'application/json',
        null,
        true
    );

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/operations/restore", [
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'confirm_phrase' => 'confirm ' . $tenant->slug,
            'confirm_restore' => '1',
            'apply_phrase' => 'apply ' . $tenant->slug,
            'reason' => 'manifest validation safety',
            'snapshot_file' => $upload,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('tenant_operations_restore');

    $action = LandlordOperatorAction::query()
        ->where('tenant_id', $tenant->id)
        ->where('action_type', LandlordTenantOperationsController::ACTION_RESTORE)
        ->latest('id')
        ->firstOrFail();

    expect((string) $action->status)->toBe('blocked')
        ->and((string) data_get((array) $action->result, 'error'))->toContain('scope table manifest');
});

test('landlord customer modify flow fail-closes foreign-tenant targets and audits blocked action', function (): void {
    $host = landlordOpsHost();
    $tenantA = Tenant::query()->create([
        'name' => 'Modify Tenant A',
        'slug' => 'modify-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Modify Tenant B',
        'slug' => 'modify-tenant-b',
    ]);

    $profileB = MarketingProfile::query()->create([
        'tenant_id' => $tenantB->id,
        'first_name' => 'Foreign',
        'last_name' => 'Profile',
        'email' => 'foreign@example.test',
        'normalized_email' => 'foreign@example.test',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenantA->id}/operations/customers/modify", [
            'tenant_id' => $tenantA->id,
            'tenant_slug' => $tenantA->slug,
            'confirm_phrase' => 'confirm ' . $tenantA->slug,
            'confirm_modify' => '1',
            'profile_id' => $profileB->id,
            'confirm_profile_id' => (string) $profileB->id,
            'reason' => 'verify fail closed',
            'first_name' => 'ShouldNotApply',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('tenant_operations_customer_modify');

    $profileB->refresh();
    expect((string) $profileB->first_name)->toBe('Foreign');

    $action = LandlordOperatorAction::query()
        ->where('tenant_id', $tenantA->id)
        ->where('action_type', LandlordTenantOperationsController::ACTION_CUSTOMER_MODIFY)
        ->latest('id')
        ->first();

    expect($action)->not->toBeNull()
        ->and((string) $action?->status)->toBe('blocked')
        ->and((string) $action?->target_id)->toBe((string) $profileB->id);
});

test('landlord customer delete workflow uses safe archive pattern and records before after trace', function (): void {
    $host = landlordOpsHost();
    $tenant = Tenant::query()->create([
        'name' => 'Archive Tenant',
        'slug' => 'archive-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Archive',
        'last_name' => 'Me',
        'email' => 'archive-me@example.test',
        'normalized_email' => 'archive-me@example.test',
        'phone' => '+15550001111',
        'normalized_phone' => '+15550001111',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => true,
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/operations/customers/archive", [
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'confirm_phrase' => 'confirm ' . $tenant->slug,
            'confirm_delete' => '1',
            'profile_id' => $profile->id,
            'confirm_profile_id' => (string) $profile->id,
            'reason' => 'customer requested deletion',
        ])
        ->assertRedirect();

    $profile->refresh();
    expect($profile->email)->toBeNull()
        ->and($profile->phone)->toBeNull()
        ->and((bool) $profile->accepts_email_marketing)->toBeFalse()
        ->and((bool) $profile->accepts_sms_marketing)->toBeFalse()
        ->and((string) ($profile->notes ?? ''))->toContain('[landlord_operator_archive]');

    $action = LandlordOperatorAction::query()
        ->where('tenant_id', $tenant->id)
        ->where('action_type', LandlordTenantOperationsController::ACTION_CUSTOMER_ARCHIVE)
        ->latest('id')
        ->first();

    expect($action)->not->toBeNull()
        ->and((string) $action?->status)->toBe('success')
        ->and((string) data_get((array) ($action?->before_state ?? []), 'email'))->toBe('archive-me@example.test')
        ->and(data_get((array) ($action?->after_state ?? []), 'email'))->toBeNull();
});

test('snapshot download is tenant-locked and blocks cross-tenant access', function (): void {
    $host = landlordOpsHost();
    $tenantA = Tenant::query()->create([
        'name' => 'Download Tenant A',
        'slug' => 'download-tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Download Tenant B',
        'slug' => 'download-tenant-b',
    ]);

    MarketingProfile::query()->create([
        'tenant_id' => $tenantA->id,
        'first_name' => 'Download',
        'last_name' => 'A',
        'email' => 'download-a@example.test',
        'normalized_email' => 'download-a@example.test',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenantA->id}/operations/export", [
            'tenant_id' => $tenantA->id,
            'tenant_slug' => $tenantA->slug,
            'confirm_phrase' => 'confirm ' . $tenantA->slug,
            'reason' => 'download verification export',
            'confirm_export' => '1',
        ])
        ->assertRedirect();

    $action = LandlordOperatorAction::query()
        ->where('tenant_id', $tenantA->id)
        ->where('action_type', LandlordTenantOperationsController::ACTION_EXPORT)
        ->latest('id')
        ->firstOrFail();

    $this->actingAs($user)
        ->get("http://{$host}/landlord/tenants/{$tenantB->id}/operations/exports/{$action->id}")
        ->assertNotFound();

    $blockedDownloadAction = LandlordOperatorAction::query()
        ->where('tenant_id', $tenantB->id)
        ->where('action_type', LandlordTenantOperationsController::ACTION_EXPORT_DOWNLOAD)
        ->latest('id')
        ->first();

    expect($blockedDownloadAction)->not->toBeNull()
        ->and((string) $blockedDownloadAction?->status)->toBe('blocked');

    $this->actingAs($user)
        ->get("http://{$host}/landlord/tenants/{$tenantA->id}/operations/exports/{$action->id}")
        ->assertOk()
        ->assertHeader('content-type', 'application/json');

    $successfulDownloadAction = LandlordOperatorAction::query()
        ->where('tenant_id', $tenantA->id)
        ->where('action_type', LandlordTenantOperationsController::ACTION_EXPORT_DOWNLOAD)
        ->latest('id')
        ->first();

    expect($successfulDownloadAction)->not->toBeNull()
        ->and((string) $successfulDownloadAction?->status)->toBe('success');
});

test('snapshot download blocks expired export artifacts', function (): void {
    $host = landlordOpsHost();
    $tenant = Tenant::query()->create([
        'name' => 'Expired Artifact Tenant',
        'slug' => 'expired-artifact-tenant',
    ]);

    MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Expired',
        'last_name' => 'Artifact',
        'email' => 'expired-artifact@example.test',
        'normalized_email' => 'expired-artifact@example.test',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/operations/export", [
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'confirm_phrase' => 'confirm ' . $tenant->slug,
            'reason' => 'expired download guard test',
            'confirm_export' => '1',
        ])
        ->assertRedirect();

    $action = LandlordOperatorAction::query()
        ->where('tenant_id', $tenant->id)
        ->where('action_type', LandlordTenantOperationsController::ACTION_EXPORT)
        ->latest('id')
        ->firstOrFail();

    $expiresAt = Carbon::parse((string) data_get((array) $action->result, 'expires_at'));
    $this->travelTo($expiresAt->copy()->addMinute());

    $this->actingAs($user)
        ->get("http://{$host}/landlord/tenants/{$tenant->id}/operations/exports/{$action->id}")
        ->assertNotFound();

    $blockedDownloadAction = LandlordOperatorAction::query()
        ->where('tenant_id', $tenant->id)
        ->where('action_type', LandlordTenantOperationsController::ACTION_EXPORT_DOWNLOAD)
        ->latest('id')
        ->firstOrFail();

    expect((string) $blockedDownloadAction->status)->toBe('blocked')
        ->and((string) data_get((array) $blockedDownloadAction->result, 'error'))->toBe('artifact_expired');

    $this->travelBack();
});

test('customer archive action requires profile id typed confirmation', function (): void {
    $host = landlordOpsHost();
    $tenant = Tenant::query()->create([
        'name' => 'Confirm Profile Tenant',
        'slug' => 'confirm-profile-tenant',
    ]);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Confirm',
        'last_name' => 'Profile',
        'email' => 'confirm-profile@example.test',
        'normalized_email' => 'confirm-profile@example.test',
    ]);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/landlord/tenants/{$tenant->id}/operations/customers/archive", [
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'confirm_phrase' => 'confirm ' . $tenant->slug,
            'confirm_delete' => '1',
            'profile_id' => $profile->id,
            'confirm_profile_id' => (string) ($profile->id + 1),
            'reason' => 'typed profile confirmation mismatch',
        ])
        ->assertSessionHasErrors('confirm_profile_id');

    $profile->refresh();
    expect($profile->email)->toBe('confirm-profile@example.test');
});

test('landlord operator action records are append-only', function (): void {
    $action = LandlordOperatorAction::query()->create([
        'tenant_id' => null,
        'actor_user_id' => null,
        'action_type' => 'test.append_only',
        'status' => 'success',
        'context' => ['check' => 'immutability'],
    ]);

    expect(fn () => $action->update(['status' => 'blocked']))
        ->toThrow(\RuntimeException::class, 'append-only');

    expect(fn () => $action->delete())
        ->toThrow(\RuntimeException::class, 'append-only');
});
