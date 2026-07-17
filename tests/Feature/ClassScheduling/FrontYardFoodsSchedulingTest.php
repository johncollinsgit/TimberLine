<?php

use App\Models\Agreement;
use App\Models\ClassEnrollment;
use App\Models\ClassReminder;
use App\Models\ClassSchedulingSetting;
use App\Models\FieldServiceJob;
use App\Models\MarketingProfile;
use App\Models\PlantInventoryAdjustment;
use App\Models\PlantInventoryItem;
use App\Models\ScheduledClass;
use App\Models\SubscriptionAuthorization;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
    Http::fake(['*' => Http::response('demo-image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);
});

test('plant inventory is launch scoped to front yard foods only', function (): void {
    expect((array) config('module_catalog.modules.plant_inventory.tenant_slugs'))->toBe(['front-yard-foods'])
        ->and((array) config('module_catalog.modules.plant_inventory.included_in_plans'))->toBe([])
        ->and((string) config('module_catalog.modules.plant_inventory.market_state'))->toBe('INTERNAL_ONLY')
        ->and((bool) config('module_catalog.modules.plant_inventory.visibility.app_store'))->toBeFalse()
        ->and((bool) config('module_catalog.modules.plant_inventory.visibility.mobile_store'))->toBeFalse()
        ->and((string) config('module_catalog.modules.plant_inventory.mobile.status'))->toBe('hidden')
        ->and((string) config('module_catalog.modules.plant_inventory.mobile.renderer'))->toBe('none')
        ->and(config('module_catalog.modules.plant_inventory.mobile.entry_screen'))->toBeNull()
        ->and((string) config('module_catalog.modules.plant_inventory.mobile.min_app_version'))->toBe('1.0.0')
        ->and((array) config('module_catalog.modules.plant_inventory.mobile.actions'))->toBe([]);
});

test('front yard foods preparation is idempotent and creates tenant four demo access', function (): void {
    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();
    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'front-yard-foods')->firstOrFail();
    $john = User::query()->where('email', 'johncollinsemail@gmail.com')->firstOrFail();

    expect((int) $tenant->id)->toBe(4)
        ->and($john->tenants()->whereKey(4)->first()?->pivot?->role)->toBe('admin')
        ->and(ScheduledClass::query()->forTenantId(4)->count())->toBe(4)
        ->and(ClassEnrollment::query()->forTenantId(4)->count())->toBe(10)
        ->and(MarketingProfile::query()->forTenantId(4)->count())->toBe(6)
        ->and(MarketingProfile::query()->forTenantId(4)->where('normalized_phone', '!=', '8646165468')->exists())->toBeFalse()
        ->and(Agreement::query()->forTenantId(4)->where('template_key', 'front_yard_foods_launch_partner')->count())->toBe(1)
        ->and(Agreement::query()->forTenantId(4)->firstOrFail()->versions()->count())->toBe(1)
        ->and(SubscriptionAuthorization::query()->forTenantId(4)->count())->toBe(0)
        ->and(TenantModuleState::query()->where('tenant_id', 4)->where('module_key', 'plant_inventory')->where('enabled_override', true)->exists())->toBeTrue()
        ->and(TenantModuleEntitlement::query()->where('tenant_id', 4)->where('module_key', 'plant_inventory')->where('enabled_status', 'enabled')->where('billing_status', 'custom_contract')->where('entitlement_source', 'front_yard_foods_launch_partner')->exists())->toBeTrue()
        ->and(TenantModuleState::query()->where('tenant_id', 4)->where('module_key', 'field_service')->where('enabled_override', false)->exists())->toBeTrue()
        ->and(FieldServiceJob::query()->forTenantId(4)->where('external_source', 'front_yard_foods_demo')->whereNull('archived_at')->exists())->toBeFalse()
        ->and($tenant->setupStatus()->firstOrFail()->module_interests)->not->toContain('field_service')
        ->and($tenant->setupStatus()->firstOrFail()->billing_lane_interest)->toBe('undecided');

    $resolved = app(TenantModuleAccessResolver::class)->resolveForTenant((int) $tenant->id, ['plant_inventory', 'field_service']);
    expect(data_get($resolved, 'modules.plant_inventory.enabled'))->toBeTrue()
        ->and(data_get($resolved, 'modules.field_service.enabled'))->toBeFalse()
        ->and(data_get($resolved, 'modules.field_service.reason'))->toBe('disabled_by_override');

    $this->actingAs($john)
        ->get(route('class-scheduling.index', ['tenant' => $tenant->slug]))
        ->assertOk()
        ->assertSeeText('Sourdough Basics')
        ->assertSeeText('Events & Classes')
        ->assertSeeText('Shopify publish pending');

    $this->actingAs($john)
        ->get(route('field-service.index', ['tenant' => $tenant->slug]))
        ->assertForbidden();
});

test('front yard foods workspace shows launch welcome checklist assurance and clean navigation', function (): void {
    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();
    $tenant = Tenant::query()->where('slug', 'front-yard-foods')->firstOrFail();
    $john = User::query()->where('email', 'johncollinsemail@gmail.com')->firstOrFail();

    $this->actingAs($john)
        ->get(route('dashboard', ['tenant' => $tenant->slug]))
        ->assertOk()
        ->assertSeeText('Welcome, Laura')
        ->assertSeeText('What Evergrove is doing')
        ->assertSeeText('What I need from you')
        ->assertSeeText('Shopify login or collaborator invite.')
        ->assertSeeText('Square login or collaborator invite.')
        ->assertSeeText('Your data is not sold.')
        ->assertSeeText('Events & Classes')
        ->assertSeeText('Plant Inventory')
        ->assertSeeText('Messaging · pending')
        ->assertSeeText('User Agreements')
        ->assertDontSeeText('Pouring')
        ->assertDontSeeText('candle wax')
        ->assertDontSeeText('Market box');
});

test('front yard foods plant inventory supports CRUD adjustments and rejects cross tenant changes', function (): void {
    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();
    $tenant = Tenant::query()->where('slug', 'front-yard-foods')->firstOrFail();
    $john = User::query()->where('email', 'johncollinsemail@gmail.com')->firstOrFail();

    $this->actingAs($john)
        ->get(route('plant-inventory.index', ['tenant' => $tenant->slug]))
        ->assertOk()
        ->assertSeeText('No plant inventory yet.')
        ->assertSeeText('Square access');

    $this->post(route('plant-inventory.store', ['tenant' => $tenant->slug]), [
        'name' => 'Strawberry starts',
        'category' => 'Edible plants',
        'sku' => 'FYF-STRAW-START',
        'vendor_source' => 'Purchased resale plants',
        'purchased_cost' => '2.50',
        'sell_price' => '6.00',
        'quantity_on_hand' => 12,
        'reserved_quantity' => 3,
        'square_id' => 'square-strawberry',
        'shopify_product_id' => 'gid://shopify/Product/1',
        'shopify_variant_id' => 'gid://shopify/ProductVariant/1',
        'status' => 'active',
    ])->assertRedirect();

    $item = PlantInventoryItem::query()->forTenantId((int) $tenant->id)->where('sku', 'FYF-STRAW-START')->firstOrFail();
    expect($item->available_quantity)->toBe(9)
        ->and(PlantInventoryAdjustment::query()->forTenantId((int) $tenant->id)->where('plant_inventory_item_id', $item->id)->exists())->toBeTrue();

    $this->post(route('plant-inventory.adjustments.store', ['item' => $item, 'tenant' => $tenant->slug]), [
        'adjustment_type' => PlantInventoryAdjustment::TYPE_HELD,
        'quantity' => 2,
        'notes' => 'Laura asked to hold strawberries.',
    ])->assertRedirect();

    expect($item->fresh()->reserved_quantity)->toBe(5)
        ->and($item->fresh()->available_quantity)->toBe(7);

    $this->post(route('plant-inventory.adjustments.store', ['item' => $item, 'tenant' => $tenant->slug]), [
        'adjustment_type' => PlantInventoryAdjustment::TYPE_SOLD,
        'quantity' => 2,
    ])->assertRedirect();

    expect($item->fresh()->quantity_on_hand)->toBe(10)
        ->and($item->fresh()->reserved_quantity)->toBe(3)
        ->and($item->fresh()->available_quantity)->toBe(7);

    $other = Tenant::query()->create(['name' => 'Other Garden', 'slug' => 'other-garden']);
    TenantAccessProfile::query()->create(['tenant_id' => $other->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleState::query()->create(['tenant_id' => $other->id, 'module_key' => 'plant_inventory', 'enabled_override' => true, 'setup_status' => 'configured']);
    $other->users()->attach($john->id, ['role' => 'admin']);

    $otherResolved = app(TenantModuleAccessResolver::class)->module((int) $other->id, 'plant_inventory');
    expect($otherResolved['enabled'])->toBeFalse()
        ->and($otherResolved['reason'])->toBe('tenant_not_supported');

    $storePayload = app(TenantModuleCatalogService::class)->tenantStorePayload((int) $other->id);
    expect(collect($storePayload['modules'] ?? [])->pluck('module_key')->all())->not->toContain('plant_inventory');

    $this->actingAs($john)
        ->get(route('plant-inventory.index', ['tenant' => $other->slug]))
        ->assertForbidden();

    $this->post(route('plant-inventory.adjustments.store', ['item' => $item, 'tenant' => $other->slug]), [
        'adjustment_type' => PlantInventoryAdjustment::TYPE_SOLD,
        'quantity' => 1,
    ])->assertNotFound();
});

test('front yard foods preparation archives only legacy front yard demo field service jobs', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Front Yard Foods', 'slug' => 'front-yard-foods']);
    $demoJob = FieldServiceJob::query()->create([
        'tenant_id' => (int) $tenant->id,
        'title' => 'Legacy demo consultation',
        'status' => 'scheduled',
        'operational_status' => 'scheduled',
        'external_source' => 'front_yard_foods_demo',
        'external_id' => 'legacy-demo',
    ]);
    $realJob = FieldServiceJob::query()->create([
        'tenant_id' => (int) $tenant->id,
        'title' => 'Real imported customer work',
        'status' => 'scheduled',
        'operational_status' => 'scheduled',
        'external_source' => 'manual',
        'external_id' => 'real-work',
    ]);

    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();

    $archivedDemoJob = $demoJob->fresh();
    $realJob = $realJob->fresh();

    expect($archivedDemoJob->status)->toBe('done')
        ->and($archivedDemoJob->operational_status)->toBe('history')
        ->and($archivedDemoJob->archived_at)->not->toBeNull()
        ->and($archivedDemoJob->metadata['archived_reason'] ?? null)->toBe('front_yard_foods_events_classes_launch_scope')
        ->and($realJob->status)->toBe('scheduled')
        ->and($realJob->operational_status)->toBe('scheduled')
        ->and($realJob->archived_at)->toBeNull();
});

test('front yard foods uses the next open tenant id when tenant four is occupied', function (): void {
    Tenant::query()->forceCreate(['id' => 4, 'name' => 'Existing Tenant', 'slug' => 'existing-tenant']);

    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();

    $tenant = Tenant::query()->where('slug', 'front-yard-foods')->firstOrFail();
    expect((int) $tenant->id)->toBe(5)
        ->and(Tenant::query()->findOrFail(4)->slug)->toBe('existing-tenant');
});

test('public signup creates a tenant customer enrollment and consent gated reminders', function (): void {
    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();
    $tenant = Tenant::query()->findOrFail(4);
    $class = ScheduledClass::query()->forTenantId(4)->where('slug', 'sourdough-basics')->firstOrFail();

    $this->get(route('public.classes.index', ['tenant' => $tenant->slug]))
        ->assertOk()
        ->assertSeeText('Learn with Laura')
        ->assertSeeText('Sourdough Basics');

    $this->post(route('public.classes.store', ['tenant' => $tenant->slug, 'class' => $class->slug]), [
        'name' => 'Taylor Garden',
        'email' => 'taylor.garden@example.test',
        'phone' => '8646165468',
        'seats' => 1,
        'email_reminders_enabled' => 1,
        'sms_reminders_enabled' => 1,
    ])->assertRedirect(route('public.classes.show', ['tenant' => $tenant->slug, 'class' => $class->slug]));

    $this->from(route('public.classes.show', ['tenant' => $tenant->slug, 'class' => $class->slug]))
        ->post(route('public.classes.store', ['tenant' => $tenant->slug, 'class' => $class->slug]), [
            'name' => 'Taylor Garden',
            'email' => 'TAYLOR.GARDEN@example.test',
            'phone' => '8646165468',
            'seats' => 1,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('email');

    $profile = MarketingProfile::query()->forTenantId(4)->where('normalized_email', 'taylor.garden@example.test')->firstOrFail();
    $enrollment = ClassEnrollment::query()->forTenantId(4)->where('marketing_profile_id', $profile->id)->firstOrFail();

    expect($enrollment->scheduled_class_id)->toBe($class->id)
        ->and($enrollment->normalized_phone)->toBe('8646165468')
        ->and(ClassReminder::query()->forTenantId(4)->where('class_enrollment_id', $enrollment->id)->pluck('channel')->sort()->values()->all())->toBe(['email', 'sms'])
        ->and(ClassReminder::query()->forTenantId(4)->where('class_enrollment_id', $enrollment->id)->where('status', 'scheduled')->count())->toBe(2)
        ->and(ClassReminder::query()->forTenantId(4)->where('class_enrollment_id', $enrollment->id)->where('provider_metadata->delivery_gate', 'tenant_provider_and_consent_required')->count())->toBe(2);
});

test('public class surfaces fail closed until a tenant explicitly publishes signup', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Private Classes', 'slug' => 'private-classes']);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleState::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'class_scheduling', 'enabled_override' => true, 'setup_status' => 'configured']);
    ClassSchedulingSetting::query()->create(['tenant_id' => $tenant->id, 'public_signup_enabled' => false]);

    $this->get(route('public.classes.index', ['tenant' => $tenant->slug]))->assertNotFound();
});

test('class detail rejects a class owned by another active scheduling tenant', function (): void {
    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();
    $frontYard = Tenant::query()->findOrFail(4);
    $john = User::query()->where('email', 'johncollinsemail@gmail.com')->firstOrFail();
    $other = Tenant::query()->create(['name' => 'Other Educator', 'slug' => 'other-educator']);
    TenantAccessProfile::query()->create(['tenant_id' => $other->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleState::query()->create(['tenant_id' => $other->id, 'module_key' => 'class_scheduling', 'enabled_override' => true, 'setup_status' => 'configured']);
    $other->users()->attach($john->id, ['role' => 'admin']);
    $frontYardClass = ScheduledClass::query()->forTenantId($frontYard->id)->firstOrFail();

    $this->actingAs($john)
        ->get(route('class-scheduling.show', ['scheduledClass' => $frontYardClass, 'tenant' => $other->slug]))
        ->assertNotFound();
});

test('mobile scheduling exposes clickable classes customers and reminders without field service access', function (): void {
    $this->artisan('everbranch:prepare-front-yard-foods')->assertSuccessful();
    $tenant = Tenant::query()->where('slug', 'front-yard-foods')->firstOrFail();
    $john = User::query()->where('email', 'johncollinsemail@gmail.com')->firstOrFail();
    $class = ScheduledClass::query()->forTenantId($tenant->id)->where('slug', 'sourdough-basics')->firstOrFail();
    $enrollment = ClassEnrollment::query()->forTenantId($tenant->id)->where('scheduled_class_id', $class->id)->firstOrFail();
    Sanctum::actingAs($john, ['mobile:read', 'mobile:write']);

    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/class-scheduling')
        ->assertOk()
        ->assertJsonPath('classes.0.destination.kind', 'scheduled_class')
        ->assertJsonFragment(['title' => 'Sourdough Basics']);

    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/class-scheduling/classes/'.$class->id)
        ->assertOk()
        ->assertJsonPath('class.attendees.0.destination.kind', 'customer')
        ->assertJsonPath('class.attendees.0.message_destination.kind', 'message_customer');

    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/class-scheduling/enrollments/'.$enrollment->id.'/reminders', [
        'channel' => 'email',
        'scheduled_for' => now()->addHours(6)->toIso8601String(),
    ])->assertCreated()->assertJsonPath('reminder.status', 'scheduled');

    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service')
        ->assertNotFound();
});
