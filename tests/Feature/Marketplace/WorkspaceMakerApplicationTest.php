<?php

use App\Models\Marketplace\Audit;
use App\Models\Marketplace\Identity;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\MakerVideo;
use App\Models\Marketplace\RateAcceptance;
use App\Models\Marketplace\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketplace\MarketplaceCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    config(['marketplace.enabled' => true, 'marketplace.host' => 'marketsandmakers.test',
        'marketplace.checkout_provider' => 'everbranch', 'marketplace.checkout_enabled' => false]);
    $this->merchant = Tenant::create(['name' => 'Marketplace', 'slug' => 'marketplace']);
    Store::create(['tenant_id' => $this->merchant->id, 'name' => 'Marketplace',
        'host' => 'marketsandmakers.test', 'verified_at' => now()]);
    $this->studio = Tenant::create(['name' => 'Fixture Workshop', 'slug' => 'fixture-workshop']);
    $this->operator = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->merchant->users()->attach($this->operator->id, ['role' => 'owner', 'membership_active' => true]);
    $this->studio->users()->attach($this->operator->id, ['role' => 'admin', 'membership_active' => true]);
    $this->args = ['--operator' => $this->operator->id, '--tenant' => $this->studio->id,
        '--city' => 'Pendleton', '--story' => 'A fixture workshop.'];
});

it('keeps two maker brands separate for an authorized workspace operator and replays without accepting terms', function (): void {
    $identity = Identity::create(['email' => $this->operator->email, 'active' => true]);
    $otherBrand = app(MarketplaceCatalog::class)->apply($identity, ['name' => 'Existing brand', 'city' => 'Easley', 'story' => 'Established profile.']);
    $this->artisan('marketplace:apply-workspace-maker', [...$this->args, '--city' => null])->assertSuccessful();
    $maker = Maker::where('tenant_id', $this->studio->id)->sole();
    expect($maker->city)->toBe('');
    expect($maker->status)->toBe('pending')->and($maker->name)->toBe('Fixture Workshop')
        ->and((int) $maker->connected_by)->toBe($this->operator->id)->and(MakerVideo::invited($maker->id))->toBeFalse();
    $maker->update(['status' => 'approved', 'story' => 'Edited by the maker.']);
    $this->artisan('marketplace:apply-workspace-maker', $this->args)->assertSuccessful();
    expect($identity->memberships()->where('active', true)->where('role', 'owner')->pluck('maker_id')->sort()->values()->all())->toBe([$otherBrand->id, $maker->id])
        ->and($otherBrand->fresh()->tenant_id)->toBeNull()->and($maker->fresh()->story)->toBe('Edited by the maker.')
        ->and($maker->fresh()->status)->toBe('approved')->and(RateAcceptance::count())->toBe(0)
        ->and(Audit::where('subject_id', $maker->id)->where('action', 'maker.workspace_connected')->count())->toBe(1);

    Route::middleware('web')->group(base_path('routes/marketplace.php'));
    $sorted = new RouteCollection;
    foreach (collect(Route::getRoutes()->getRoutes())->sortBy(fn ($route) => str_starts_with((string) $route->getName(), 'marketplace.public.') ? 0 : 1) as $route) {
        $sorted->add($route);
    }
    Route::setRoutes($sorted);
    $this->withoutVite();
    $this->withSession(['marketplace_identity' => $identity->id])->get('https://marketsandmakers.test/sell')
        ->assertOk()->assertSee($maker->name)->assertSee($otherBrand->name)
        ->assertSee('href="/maker/'.$maker->id.'"', false)->assertSee('href="/maker/'.$otherBrand->id.'"', false);
    $this->get('https://marketsandmakers.test/makers/'.$maker->slug)->assertOk()->assertDontSee('MADE IN');
});

it('requires both current marketplace authority and seller workspace management', function (): void {
    $stranger = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->studio->users()->attach($stranger->id, ['role' => 'admin', 'membership_active' => true]);
    expect(fn () => $this->artisan('marketplace:apply-workspace-maker', [...$this->args, '--operator' => $stranger->id])->run())->toThrow(HttpException::class);
    $this->studio->users()->updateExistingPivot($this->operator->id, ['membership_active' => false]);
    expect(fn () => $this->artisan('marketplace:apply-workspace-maker', $this->args)->run())->toThrow(ModelNotFoundException::class);
    expect(Maker::count())->toBe(0)->and(Identity::count())->toBe(0);
});

it('does not take over an existing independent profile or reactivate a disabled marketplace identity', function (): void {
    $other = Identity::create(['email' => 'owner@fixture.test', 'active' => true]);
    $independent = app(MarketplaceCatalog::class)->apply($other, ['name' => $this->studio->name, 'city' => 'Pendleton', 'story' => 'Owner application.']);
    expect(fn () => $this->artisan('marketplace:apply-workspace-maker', $this->args)->run())->toThrow(HttpException::class);
    expect($independent->fresh()->tenant_id)->toBeNull()->and($independent->memberships()->sole()->identity_id)->toBe($other->id);
    $inactive = Identity::create(['email' => $this->operator->email, 'active' => false]);
    expect(fn () => $this->artisan('marketplace:apply-workspace-maker', $this->args)->run())->toThrow(HttpException::class);
    expect($inactive->fresh()->active)->toBeFalse()->and(Maker::count())->toBe(1);
});
