<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\Retail\RetailShowroomService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    Http::preventStrayRequests();
    $this->originalEnvironment = app()->environment();
    app()->instance('env', 'staging');
    $this->owner = User::factory()->create(['email' => 'visualizer-owner@example.invalid', 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->visitor = User::factory()->create(['email' => 'avery-demo-visitor@everbranch-demo.invalid', 'role' => 'pouring', 'is_active' => true, 'email_verified_at' => now()]);
    config()->set('retail_showroom.enabled', true);
    config()->set('retail_showroom.owner_email', $this->owner->email);
    app(RetailShowroomService::class)->seed($this->owner);
    $this->host = parse_url(route('retail.showroom'), PHP_URL_HOST);
    $this->base = 'http://'.$this->host;
    config()->set('retail_showroom.presenter_host', $this->host);
    config()->set('retail_showroom.avery_pin_enabled', true);
    // Fictional test-only value. Never use or record a deployed access code.
    config()->set('retail_showroom.avery_pin_hash', Hash::make('visualizer-fixture-only'));
});

afterEach(function () {
    app()->instance('env', $this->originalEnvironment);
    Http::assertNothingSent();
});

function unlockIllustrativeAvery($test): void
{
    $test->post($test->base.'/avery', ['code' => 'visualizer-fixture-only'])
        ->assertRedirect('/retail/showroom/concepts/avery-knifeworks');
}

test('the existing access flow is required for both the visualizer page and private model', function () {
    $this->get($this->base.'/avery/visualizer')->assertRedirect('/avery');
    $this->get($this->base.'/avery/visualizer/model.glb')->assertNotFound();
    unlockIllustrativeAvery($this);
    $this->get($this->base.'/avery/visualizer')->assertOk()
        ->assertSee('Illustrative demo, not an Avery product model.')
        ->assertSee('No microphone is used')
        ->assertSee('/avery/visualizer/model.glb', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
    $response = $this->get($this->base.'/avery/visualizer/model.glb')->assertOk()
        ->assertHeader('Content-Type', 'model/gltf-binary')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($response->baseResponse->getFile()->getPathname())->toBe(resource_path('retail-visualizer/illustrative-knife.glb'));
    expect(substr(file_get_contents(resource_path('retail-visualizer/illustrative-knife.glb')), 0, 4))->toBe('glTF');
    $this->assertGuest();
    expect($this->visitor->tenants()->count())->toBe(0);
});

test('the Avery-only concept shows the entry without changing its existing content or other merchants', function () {
    unlockIllustrativeAvery($this);
    $this->get($this->base.'/retail/showroom/concepts/avery-knifeworks')->assertOk()
        ->assertSee('Open the knife visualizer')->assertSee('Fictional model')
        ->assertSee('/retail/app.mjs', false);
    $this->get($this->base.'/retail/showroom/concepts/sawyer-naturals')->assertNotFound();
    $this->get($this->base.'/studio/')->assertNotFound();
    $this->getJson($this->base.'/retail/showroom/api/bootstrap')->assertOk()
        ->assertJsonCount(1, 'vendors')->assertJsonPath('vendors.0.slug', 'avery-knifeworks');
    $this->assertGuest();
});

test('grant expiry and hash rotation remove page and model access', function (string $change) {
    unlockIllustrativeAvery($this);
    if ($change === 'expiry') {
        $this->withSession(['avery_guest.expires' => time() - 1]);
    } else {
        config()->set('retail_showroom.avery_pin_hash', Hash::make('rotated-fictional-fixture'));
    }
    $this->get($this->base.'/avery/visualizer')->assertRedirect('/avery');
    $this->get($this->base.'/avery/visualizer/model.glb')->assertNotFound();
})->with(['expiry', 'rotation']);

test('new reads remain unavailable outside the exact enabled staging presenter host', function (string $change) {
    unlockIllustrativeAvery($this);
    match ($change) {
        'production' => app()->instance('env', 'production'),
        'disabled' => config()->set('retail_showroom.avery_pin_enabled', false),
        'showroom-disabled' => config()->set('retail_showroom.enabled', false),
        'wrong-presenter' => config()->set('retail_showroom.presenter_host', 'other.example.invalid'),
    };
    $this->get($this->base.'/avery/visualizer')->assertNotFound();
    $this->get($this->base.'/avery/visualizer/model.glb')->assertNotFound();
})->with(['production', 'disabled', 'showroom-disabled', 'wrong-presenter']);

test('no model selector foreign scope or mutation method is accepted', function () {
    unlockIllustrativeAvery($this);
    foreach (['tenant_id=999', 'merchant=sawyer-naturals', 'business_slug=foreign', 'model=secret.glb', 'path=../private', 'merchant=avery-knifeworks'] as $query) {
        $this->get($this->base.'/avery/visualizer?'.$query)->assertNotFound();
        $this->get($this->base.'/avery/visualizer/model.glb?'.$query)->assertNotFound();
    }
    foreach (['post', 'put', 'delete'] as $method) {
        $this->$method($this->base.'/avery/visualizer')->assertNotFound();
        $this->$method($this->base.'/avery/visualizer/model.glb')->assertNotFound();
    }
    $this->get($this->base.'/avery/visualizer/other.glb')->assertNotFound();
    $this->get('http://foreign.example.invalid/avery/visualizer')->assertNotFound();
    $this->get('http://foreign.example.invalid/avery/visualizer/model.glb')->assertNotFound();
});

test('reads cannot write workspace data or bypass a deactivated guest identity', function () {
    unlockIllustrativeAvery($this);
    $counts = [User::count(), Tenant::count()];
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|create|alter|drop|replace)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    $this->get($this->base.'/avery/visualizer')->assertOk();
    $this->get($this->base.'/avery/visualizer/model.glb')->assertOk();
    expect($writes)->toBe([])->and([User::count(), Tenant::count()])->toBe($counts);
    $this->visitor->update(['is_active' => false]);
    $this->get($this->base.'/avery/visualizer')->assertStatus(503);
    $this->get($this->base.'/avery/visualizer/model.glb')->assertStatus(503);
    $this->assertGuest();
});

test('the vendored runtime is pinned and its WASM base does not produce double-slash asset paths', function () {
    $manifest = json_decode(file_get_contents(public_path('avery-visualizer/build-manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['upstreamCommit'])->toBe('a5cf35775e2594ffa5a39e0a9972da2e6184accf');
    foreach ($manifest['files'] as $file => $hash) {
        expect(hash_file('sha256', public_path('avery-visualizer/'.$file)))->toBe($hash);
    }
    $bundle = file_get_contents(public_path('avery-visualizer/viewer-Ch5YCX_w.js'));
    expect($bundle)->toContain('wasm: Q0 + "wasm"')->not->toContain('wasm: Q0 + "wasm/"');
    expect(is_file(public_path('avery-visualizer/illustrative-knife.glb')))->toBeFalse();
});
