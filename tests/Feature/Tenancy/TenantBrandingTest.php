<?php

use App\Models\Tenant;
use App\Models\TenantBrandProfile;
use App\Models\User;
use App\Services\Tenancy\TenantBrandProfileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function brandTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

function brandUser(Tenant $tenant, string $tenantRole = 'owner', string $role = 'manager'): User
{
    $user = User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['role' => $tenantRole]);

    return $user;
}

test('collins gets its branded profile and bundled launch asset manifest', function (): void {
    $tenant = brandTenant('Collins Electric', 'collins-electric');
    $profile = app(TenantBrandProfileService::class)->ensureForTenant($tenant);

    expect($profile->display_name)->toBe('Collins Upstate Electric')
        ->and($profile->theme_key)->toBe('collins-upstate-electric')
        ->and($profile->decor_preset)->toBe('signal')
        ->and($profile->assets()->count())->toBeGreaterThanOrEqual(15);

    $presentation = app(TenantBrandProfileService::class)->presentationFor($tenant);
    expect($presentation['light_logo_url'])->toContain('collins-lockup-navy.svg')
        ->and($presentation['dark_logo_url'])->toContain('collins-lockup-white.svg')
        ->and($presentation['icon_url'])->toContain('collins-icon.svg');
});

test('tenant owner can open custom workspace controls and the workspace shell resolves the Collins theme', function (): void {
    $tenant = brandTenant('Collins Electric', 'collins-electric');
    $user = brandUser($tenant, 'owner');

    $response = $this->actingAs($user)
        ->get('http://collins-electric.theeverbranch.com/workspace/brand');

    $response->assertOk()
        ->assertSeeText('Customize workspace')
        ->assertSeeText('Collins launch kit')
        ->assertSee('data-tenant-theme="collins-upstate-electric"', false)
        ->assertSee('Customize workspace', false);
});

test('uploaded brand marks are served through the application with an opaque hero treatment', function (): void {
    Storage::fake('public');
    $tenant = brandTenant('Uploaded Brand', 'uploaded-brand');
    $user = brandUser($tenant, 'owner');
    $profile = app(TenantBrandProfileService::class)->ensureForTenant($tenant, $user);
    app(TenantBrandProfileService::class)->storeLogo(
        $profile,
        UploadedFile::fake()->image('solid-logo.png', 900, 300),
        'light_logo',
        $user,
    );

    $presentation = app(TenantBrandProfileService::class)->presentationFor($tenant);
    expect($presentation['light_logo_url'])->toContain('/workspace-brand-assets/'.$profile->id.'/light_logo');
    expect($presentation['light_logo_url'])->toContain('?v=');
    $this->get($presentation['light_logo_url'])->assertOk()->assertHeader('x-content-type-options', 'nosniff');
    $this->actingAs($user)->get('http://uploaded-brand.theeverbranch.com/workspace/brand')
        ->assertOk()
        ->assertSee('tenant-brand-editor__hero-mark', false)
        ->assertSee('background:#061d42', false);
});

test('manager membership cannot customize the workspace', function (): void {
    $tenant = brandTenant('Restricted Workspace', 'restricted-workspace');
    $user = brandUser($tenant, 'manager');

    $this->actingAs($user)
        ->get('http://restricted-workspace.theeverbranch.com/workspace/brand')
        ->assertForbidden();
});

test('brand changes stay inside the selected tenant and contrast failures are rejected', function (): void {
    $first = brandTenant('First Workspace', 'first-workspace');
    $second = brandTenant('Second Workspace', 'second-workspace');
    $user = brandUser($first, 'admin', 'admin');
    $user->tenants()->attach($second->id, ['role' => 'admin']);
    app(TenantBrandProfileService::class)->ensureForTenant($first);
    app(TenantBrandProfileService::class)->ensureForTenant($second);

    $payload = [
        'display_name' => 'Second Signal',
        'tagline' => 'Always ready',
        'primary_color' => '#123C43',
        'accent_color' => '#1E5A63',
        'surface_color' => '#FFFFFF',
        'text_color' => '#0F1C1F',
        'display_style' => 'technical',
        'corner_style' => 'standard',
        'decor_preset' => 'grid',
    ];

    $this->actingAs($user)
        ->put('http://second-workspace.theeverbranch.com/workspace/brand?tenant=second-workspace', $payload)
        ->assertRedirect();

    expect(TenantBrandProfile::query()->where('tenant_id', $second->id)->value('display_name'))->toBe('Second Signal')
        ->and(TenantBrandProfile::query()->where('tenant_id', $first->id)->value('display_name'))->toBe('First Workspace');

    try {
        app(TenantBrandProfileService::class)->assertAccessiblePalette('#FFFFFF', '#FDFDFD', '#FEFEFE', '#FFFFFF');
        $this->fail('Expected inaccessible colors to fail validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKeys(['text_color', 'primary_color', 'accent_color']);
    }
});

test('customize workspace appears only in the owner admin settings navigation', function (): void {
    $tenant = brandTenant('Navigation Brand', 'navigation-brand');
    $owner = brandUser($tenant, 'owner');
    $manager = brandUser($tenant, 'manager');

    $this->actingAs($owner)
        ->get('http://navigation-brand.theeverbranch.com/dashboard')
        ->assertOk()
        ->assertSeeText('Customize workspace');

    $this->actingAs($manager)
        ->get('http://navigation-brand.theeverbranch.com/dashboard')
        ->assertOk()
        ->assertDontSeeText('Customize workspace');
});
