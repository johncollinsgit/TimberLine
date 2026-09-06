<?php

use App\Models\Agreement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

test('maintenance command prepares an idempotent managed website draft and reports safe billing readiness', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Carolina Barrel Co', 'slug' => 'carolina-barrel-co']);
    $actor = User::factory()->create(['email' => 'johncollinsemail@gmail.com']);
    config()->set('commercial.billing_readiness.agreement_checkout', [
        'enabled' => true,
        'tenant_slugs' => ['carolina-barrel-co'],
        'live_webhook_verified' => true,
        'tax_decision_confirmed' => true,
        'relay_payout_verified' => true,
    ]);
    config()->set('services.stripe.secret', 'sk_live_example');
    config()->set('services.stripe.webhook_secret', 'whsec_example');

    expect(Artisan::call('everbranch:prepare-managed-website-agreement', [
        'tenant' => $tenant->slug,
        '--actor-email' => $actor->email,
        '--json' => true,
    ]))->toBe(0);

    $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($result['agreement_status'])->toBe('draft')
        ->and($result['agreement_version'])->toBe(1)
        ->and($result['pricing']['setup_cents'])->toBe(29900)
        ->and($result['pricing']['founder_monthly_cents'])->toBe(8900)
        ->and($result['pricing']['standard_monthly_cents'])->toBe(14900)
        ->and($result['billing_readiness']['ready'])->toBeTrue()
        ->and(Agreement::query()->where('tenant_id', $tenant->id)->count())->toBe(1);

    Artisan::call('everbranch:prepare-managed-website-agreement', [
        'tenant' => $tenant->slug,
        '--actor-email' => $actor->email,
        '--json' => true,
    ]);

    expect(Agreement::query()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(Agreement::query()->firstOrFail()->versions()->count())->toBe(1);
});

test('maintenance command fails closed for unknown tenants and actors', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Carolina Barrel Co', 'slug' => 'carolina-barrel-co']);

    expect(Artisan::call('everbranch:prepare-managed-website-agreement', ['tenant' => 'missing']))->toBe(1)
        ->and(Artisan::call('everbranch:prepare-managed-website-agreement', [
            'tenant' => $tenant->slug,
            '--actor-email' => 'missing@example.com',
        ]))->toBe(1)
        ->and(Agreement::query()->count())->toBe(0);
});
