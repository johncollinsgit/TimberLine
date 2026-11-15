<?php

use App\Models\LandlordOperatorAction;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingReceipt;
use App\Models\TenantCommercialOverride;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Models\TenantSupportTicket;
use App\Models\User;
use App\Models\WorkspaceAsset;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\Billing\TenantAiUsageService;
use App\Services\FieldService\WorkspaceAssetService;
use App\Services\Marketing\Messaging\TenantMessagingGateway;
use App\Services\Mobile\TenantMobileSupportService;
use App\Services\Onboarding\SelfServiceTrialService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
    Notification::fake();
    Http::preventStrayRequests();
    Storage::fake('local');
    config()->set(['self_service.registration_enabled' => true, 'self_service.trial_enabled' => true]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now(), 'role' => 'self_service_pending',
        'requested_via' => 'self_service_registration', 'onboarding_guide_answers' => ['business_template' => 'generic']]);
    $this->actingAs($this->owner)->post(route('workspace.first-login.store'), [
        'workspace_name' => 'Fictional Private Trial', 'template_key' => 'generic', 'start_path' => 'self',
    ])->assertRedirectContains('/workspace/guide');
    $this->tenant = $this->owner->tenants()->firstOrFail();
    $this->owner->refresh();
    $this->actingAs($this->owner);
    $this->trials = app(SelfServiceTrialService::class);
});

test('verified fresh provisioning starts one audited immutable no-card clock without billing objects or grants', function (): void {
    $status = $this->trials->status($this->tenant);
    expect($status['state'])->toBe('active')->and($status['remaining_days'])->toBe(14)
        ->and($status['limits'])->toMatchArray(['customers' => 100, 'jobs' => 25, 'documents' => 20, 'website_photos' => 10]);
    $clock = $this->tenant->accessProfile->metadata['self_service_trial'];
    $this->travel(2)->days();
    $this->trials->startForFreshWorkspace($this->tenant, $this->owner);
    $this->post(route('workspace.first-login.store'), ['workspace_name' => 'Reset attempt', 'template_key' => 'generic', 'start_path' => 'self'])->assertRedirect();
    expect($this->tenant->accessProfile()->first()->metadata['self_service_trial'])->toBe($clock)
        ->and(LandlordOperatorAction::withoutGlobalScopes()->where('action_type', 'self_service.trial_started')->count())->toBe(1)
        ->and(TenantBillingReceipt::withoutGlobalScopes()->count())->toBe(0)
        ->and(TenantCommercialOverride::withoutGlobalScopes()->count())->toBe(0)
        ->and(TenantModuleEntitlement::withoutGlobalScopes()->count())->toBe(0)
        ->and(app(SelfServicePurchaseEligibility::class)->paidForTenant($this->tenant))->toBeFalse();
    Http::assertNothingSent();
});

test('expiry is exact and retains work without renewing or deleting it', function (): void {
    $end = $this->trials->status($this->tenant)['ends_at'];
    MarketingProfile::query()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Retained']);
    $this->travelTo(\Carbon\CarbonImmutable::parse($end)->subSecond());
    expect($this->trials->activeForTenant($this->tenant))->toBeTrue();
    $this->travelTo(\Carbon\CarbonImmutable::parse($end));
    expect($this->trials->status($this->tenant))->toMatchArray(['state' => 'expired', 'can_view_private' => true, 'can_edit_private' => false]);
    $this->get(route('workspace.guide', ['tenant' => $this->tenant->slug]))->assertOk();
    expect(MarketingProfile::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
    Http::assertNothingSent();
});

test('copied or modified tenant metadata cannot extend or fabricate a trial', function (string $mutation): void {
    $profile = $this->tenant->accessProfile;
    $metadata = $profile->metadata;
    if ($mutation === 'end') {
        $metadata['self_service_trial']['ends_at'] = now()->addYear()->toIso8601String();
    } elseif ($mutation === 'limit') {
        $metadata['self_service_trial']['limits']['customers'] = 100000;
    } else {
        $metadata['self_service_trial']['tenant_id']++;
    }
    $profile->forceFill(['metadata' => $metadata])->save();
    expect($this->trials->status($this->tenant))->toMatchArray(['registered_trial' => true, 'state' => 'invalid', 'can_edit_private' => false, 'can_view_private' => false]);
})->with(['end', 'limit', 'tenant']);

test('legacy tenants are not enrolled or backfilled and disabling enrollment does not end existing clocks', function (): void {
    config()->set('self_service.trial_enabled', false);
    expect($this->trials->activeForTenant($this->tenant))->toBeTrue();
    $legacy = Tenant::query()->create(['name' => 'Legacy', 'slug' => 'legacy-trial-exclusion']);
    TenantAccessProfile::query()->create(['tenant_id' => $legacy->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'operator']);
    expect($this->trials->status($legacy)['state'])->toBe('none');
    $this->trials->startForFreshWorkspace($legacy, $this->owner);
    expect($legacy->accessProfile->metadata)->toBeNull();
});

test('removing a clock cannot remove the trial fence or allow a second server start', function (): void {
    $profile = $this->tenant->accessProfile;
    $metadata = $profile->metadata;
    unset($metadata['self_service_trial']);
    $profile->forceFill(['metadata' => $metadata])->save();
    expect($this->trials->status($this->tenant))->toMatchArray(['registered_trial' => true, 'state' => 'invalid', 'can_edit_private' => false]);
    expect(fn () => $this->trials->startForFreshWorkspace($this->tenant, $this->owner))->toThrow(HttpException::class);
    expect(LandlordOperatorAction::withoutGlobalScopes()->where('action_type', 'self_service.trial_started')->count())->toBe(1);
    Route::middleware(['web', 'auth'])->get('/missing-clock-provider', fn () => 'Must not run')->name('missing-clock.provider');
    $this->get('/missing-clock-provider')->assertStatus(423);
});

test('JSON null clock keys remain fenced without registration or a surviving audit marker', function (): void {
    $profile = $this->tenant->accessProfile;
    $profile->forceFill(['metadata' => ['self_service_trial' => null]])->save();
    \Illuminate\Support\Facades\DB::table('landlord_operator_actions')->where('tenant_id', $this->tenant->id)->where('action_type', 'self_service.trial_started')->delete();
    expect($this->trials->status($this->tenant)['state'])->toBe('invalid')
        ->and($this->trials->candidateTenantsForUser($this->owner)->count())->toBe(1);
    expect(fn () => $this->trials->assertMobileAccessAllowed($this->owner))->toThrow(HttpException::class);
});

test('private module access retains every hard catalog or operator restriction', function (string $restriction): void {
    if ($restriction === 'tenant') {
        config()->set('module_catalog.modules.customers.tenant_slugs', ['different-tenant']);
    } elseif ($restriction === 'channel') {
        config()->set('module_catalog.modules.customers.channels', ['shopify']);
    } elseif ($restriction === 'availability') {
        TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'customers', 'availability_status' => 'disabled', 'enabled_status' => 'inherit']);
    } elseif ($restriction === 'override') {
        TenantModuleState::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'customers', 'enabled_override' => false]);
    } else {
        config()->set('module_catalog.modules.uploads.status', 'disabled');
    }
    $resolver = app(TenantModuleAccessResolver::class);
    $key = $restriction === 'dependency' ? 'documents' : 'customers';
    $module = $resolver->resolveForTenant($this->tenant->id, [$key])['modules'][$key];
    expect($module['enabled'])->toBeFalse()->and($module['reason'])->toBe(match ($restriction) {
        'tenant' => 'tenant_not_supported', 'channel' => 'channel_not_supported', 'availability' => 'module_unavailable',
        'override' => 'disabled_by_override', 'dependency' => 'dependency_not_enabled',
    });
})->with(['tenant', 'channel', 'availability', 'override', 'dependency']);

test('unknown protected provider GET and API routes fail before controllers while account logout remains available', function (): void {
    $ran = false;
    Route::middleware(['web', 'auth'])->get('/trial-unknown-provider', function () use (&$ran) {
        $ran = true;

        return 'provider';
    })->name('future.provider.start');
    Route::middleware(['api', 'auth:sanctum'])->get('/api/trial-unknown-provider', function () use (&$ran) {
        $ran = true;

        return 'provider';
    })->name('future.api.provider');
    $this->get('/trial-unknown-provider')->assertStatus(423);
    $this->getJson('/api/trial-unknown-provider')->assertStatus(423);
    expect($ran)->toBeFalse();
    $this->post(route('logout'))->assertRedirect();
});

test('direct paid transport and AI entry points stop before network or reservations', function (): void {
    foreach ([
        fn () => app(TenantMessagingGateway::class)->sendEmail($this->tenant->id, 'fictional@example.test', 'Trial', 'No send'),
        fn () => app(TenantMessagingGateway::class)->sendSms($this->tenant->id, '+15555550100', 'No send'),
        fn () => app(TenantAiUsageService::class)->reserveVoice($this->tenant, $this->owner, (string) \Illuminate\Support\Str::uuid(), 'field', 'model', 10),
    ] as $attempt) {
        try {
            $attempt();
            $this->fail('Trial provider use was admitted.');
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(423);
        }
    }
    Http::assertNothingSent();
    expect(\App\Models\TenantAiUsageEvent::withoutGlobalScopes()->count())->toBe(0);
});

test('trial files stay local and the twenty-first file is refused before storage', function (): void {
    config()->set('filesystems.workspace_asset_disk', 's3');
    $service = app(WorkspaceAssetService::class);
    for ($i = 1; $i <= 20; $i++) {
        $asset = $service->storeUpload($this->tenant, $this->owner, UploadedFile::fake()->createWithContent('private-'.$i.'.txt', 'Private file'), [], 'owner');
        expect($asset->storage_disk)->toBe('local');
    }
    $files = Storage::disk('local')->allFiles();
    try {
        $service->storeUpload($this->tenant, $this->owner, UploadedFile::fake()->createWithContent('overflow.txt', 'No write'), [], 'owner');
        $this->fail('Quota bypass.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(423);
    }
    expect(WorkspaceAsset::withoutGlobalScopes()->count())->toBe(20)->and(Storage::disk('local')->allFiles())->toBe($files);
    $this->travel(15)->days();
    try {
        $service->storeUpload($this->tenant, $this->owner, UploadedFile::fake()->createWithContent('expired.txt', 'No write'), [], 'owner');
        $this->fail('Expired upload.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(423);
    }
    expect(Storage::disk('local')->allFiles())->toBe($files);
});

test('private help creates a durable ticket without queueing an operator SMS', function (): void {
    Queue::fake();
    $ticket = app(TenantMobileSupportService::class)->create($this->tenant, $this->owner, ['subject' => 'Private setup question', 'category' => 'help', 'priority' => 'normal', 'body' => 'Please explain setup.']);
    expect(TenantSupportTicket::withoutGlobalScopes()->count())->toBe(1)
        ->and(TenantSupportTicket::withoutGlobalScopes()->first()->metadata['operator_alert'])->toBe('not_queued_private_trial');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('mobile authentication cannot mint a trial token and existing sessions can still be revoked', function (): void {
    $this->owner->forceFill(['password' => \Illuminate\Support\Facades\Hash::make('Fictional-password-only')])->save();
    auth()->logout();
    $this->postJson('/api/mobile/v1/auth/password', ['email' => $this->owner->email, 'password' => 'Fictional-password-only'])->assertStatus(423);
    expect($this->owner->tokens()->count())->toBe(0);
    $token = $this->owner->createToken('Everbranch mobile:Old session', ['mobile:read', 'mobile:write']);
    $this->withToken($token->plainTextToken)->getJson('/api/mobile/v1/workspaces')->assertStatus(423);
    $this->withToken($token->plainTextToken)->postJson('/api/mobile/v1/auth/logout')->assertOk();
    expect($this->owner->tokens()->count())->toBe(0);
});

test('trial upload failure rolls back its record and removes only the newly written private file', function (): void {
    Storage::disk('local')->put('existing-private-sentinel.txt', 'Keep');
    $this->mock(\App\Services\FieldService\WorkspaceAssetAuditService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Fictional audit failure'));
    expect(fn () => app(WorkspaceAssetService::class)->storeUpload($this->tenant, $this->owner, UploadedFile::fake()->createWithContent('failed.txt', 'Private'), [], 'owner'))->toThrow(RuntimeException::class);
    expect(WorkspaceAsset::withoutGlobalScopes()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe(['existing-private-sentinel.txt']);
});

test('the private job quota rejects the twenty-sixth real job before committing it', function (): void {
    for ($i = 1; $i <= 25; $i++) {
        \App\Models\FieldServiceJob::query()->create(['tenant_id' => $this->tenant->id, 'title' => 'Private job '.$i, 'status' => 'new']);
    }
    $this->postJson(route('field-service.jobs.store', ['tenant' => $this->tenant->slug]), ['title' => 'Overflow private job'])->assertStatus(423);
    expect(\App\Models\FieldServiceJob::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(25);
    Http::assertNothingSent();
    Notification::assertNothingSent();
});

test('real private customer and job creation stops at the frozen customer limit without notifications', function (): void {
    for ($i = 1; $i <= 99; $i++) {
        MarketingProfile::query()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional '.$i]);
    }
    $url = route('field-service.jobs.store', ['tenant' => $this->tenant->slug]);
    $this->post($url, ['title' => 'Private planning job', 'create_customer' => 1, 'customer_name' => 'Fictional final customer'])->assertRedirect();
    expect(MarketingProfile::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(100)
        ->and(\App\Models\FieldServiceJob::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
    $this->postJson($url, ['title' => 'Overflow contact', 'create_customer' => 1, 'customer_name' => 'Fictional overflow'])->assertStatus(423);
    expect(MarketingProfile::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(100)
        ->and(\App\Models\FieldServiceJob::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
    Notification::assertNothingSent();
    Http::assertNothingSent();
});

test('native MySQL JSON preserves the audited trial clock despite object member reordering', function (): void {
    if (\Illuminate\Support\Facades\DB::getDriverName() !== 'mysql') {
        if (getenv('SELF_SERVICE_DISPOSABLE_MYSQL') === '1') {
            $this->fail('The protected native JSON acceptance job requires actual MySQL.');
        }
        $this->markTestSkipped('Native MySQL JSON proof runs in the protected disposable concurrency job.');
    }
    abort_unless(getenv('SELF_SERVICE_DISPOSABLE_MYSQL') === '1' && app()->environment('testing')
        && \Illuminate\Support\Facades\DB::connection()->getConfig('host') === '127.0.0.1'
        && \Illuminate\Support\Facades\DB::connection()->getDatabaseName() === 'everbranch_ci', 403);
    $profile = $this->tenant->accessProfile()->firstOrFail();
    $stored = $profile->metadata['self_service_trial'];
    expect(array_keys($stored))->not->toBe(['policy', 'tenant_id', 'profile_id', 'owner_user_id', 'started_at', 'ends_at', 'limits', 'module_keys', 'clock_sha256', 'converted_at']);
    expect(\Illuminate\Support\Facades\DB::selectOne('SELECT JSON_TYPE(metadata) AS native_type FROM tenant_access_profiles WHERE id = ?', [$profile->id])->native_type)->toBe('OBJECT');
    expect(\Illuminate\Support\Facades\DB::selectOne("SELECT DATA_TYPE AS native_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_access_profiles' AND COLUMN_NAME = 'metadata'")->native_type)->toBe('json');
    expect($this->trials->status($this->tenant)['state'])->toBe('active');
    $this->trials->startForFreshWorkspace($this->tenant, $this->owner);
    expect($profile->fresh()->metadata['self_service_trial']['clock_sha256'])->toBe($stored['clock_sha256'])
        ->and(LandlordOperatorAction::withoutGlobalScopes()->where('action_type', 'self_service.trial_started')->count())->toBe(1);
});

test('a mixed valid and unsupported trial document batch leaves neither rows nor orphan files', function (): void {
    Storage::disk('local')->put('existing-private-sentinel.txt', 'Keep');
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $this->postJson(route('documents.store', ['tenant' => $this->tenant->slug]), ['files' => [
            UploadedFile::fake()->image('valid.png', 8, 8), UploadedFile::fake()->createWithContent('unsupported.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        ]])->assertUnprocessable();
        expect(WorkspaceAsset::withoutGlobalScopes()->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe(['existing-private-sentinel.txt']);
    }
    $this->postJson(route('documents.store', ['tenant' => $this->tenant->slug]), ['files' => UploadedFile::fake()->image('malformed-single.png', 8, 8)])->assertUnprocessable();
    expect(WorkspaceAsset::withoutGlobalScopes()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe(['existing-private-sentinel.txt']);
});

test('partial isolated schemas preserve trial denial without querying absent authority', function (bool $profiles, bool $audits, ?bool $clock, bool $registration, bool $auditMarker, bool $restricted): void {
    $previous = config('database.default');
    config()->set('database.connections.trial_partial', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    config()->set('database.default', 'trial_partial');
    $partialOwner = clone $this->owner;
    $partialOwner->setConnection('trial_partial');
    try {
        $schema = \Illuminate\Support\Facades\Schema::connection('trial_partial');
        $schema->create('tenants', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
        });
        $schema->create('tenant_user', function ($table): void {
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role');
            $table->boolean('membership_active');
            $table->timestamps();
        });
        \Illuminate\Support\Facades\DB::table('tenants')->insert(['id' => $this->tenant->id, 'name' => 'Isolated fixture', 'slug' => $this->tenant->slug]);
        \Illuminate\Support\Facades\DB::table('tenant_user')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'role' => 'owner', 'membership_active' => true]);
        if ($profiles) {
            $schema->create('tenant_access_profiles', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->json('metadata')->nullable();
            });
            $metadata = $clock === null ? ['self_service_trial' => null] : ($clock ? ['self_service_trial' => ['policy' => SelfServiceTrialService::POLICY]] : []);
            if ($registration) {
                $metadata['self_service_registration'] = true;
            }
            \Illuminate\Support\Facades\DB::table('tenant_access_profiles')->insert(['tenant_id' => $this->tenant->id, 'metadata' => json_encode($metadata)]);
        }
        if ($audits) {
            $schema->create('landlord_operator_actions', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('action_type');
            });
            if ($auditMarker) {
                \Illuminate\Support\Facades\DB::table('landlord_operator_actions')->insert(['tenant_id' => $this->tenant->id, 'action_type' => 'self_service.trial_started']);
            }
        }
        $status = $this->trials->status($this->tenant);
        expect($status['state'])->toBe($restricted ? 'invalid' : 'none')->and($status['can_edit_private'])->toBeFalse()
            ->and($this->trials->candidateTenantsForUser($partialOwner)->count())->toBe($restricted ? 1 : 0);
        if ($restricted) {
            expect(fn () => $this->trials->assertMobileAccessAllowed($partialOwner))->toThrow(HttpException::class)
                ->and(fn () => $this->trials->assertPaidUsageAllowed($this->tenant))->toThrow(HttpException::class);
        } else {
            $this->trials->assertMobileAccessAllowed($partialOwner);
        }
    } finally {
        config()->set('database.default', $previous);
        \Illuminate\Support\Facades\DB::purge('trial_partial');
    }
})->with([
    'profile clock without audit authority' => [true, false, true, true, false, true],
    'registration survives clock loss without audit authority' => [true, false, false, true, false, true],
    'null clock key without registration or audit authority' => [true, false, null, false, false, true],
    'retained audit without profile authority' => [false, true, false, false, true, true],
    'independent unmarked Enterprise profile' => [true, false, false, false, false, false],
    'independent runtime without either SaaS authority' => [false, false, false, false, false, false],
]);
