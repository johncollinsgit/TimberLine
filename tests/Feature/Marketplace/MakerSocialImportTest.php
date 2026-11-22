<?php

use App\Models\IntegrationConnection;
use App\Models\Marketplace\Identity;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\Membership;
use App\Models\Marketplace\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketplace\MakerSocialImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('normalizes social video shares and drops unrelated or credentialed links', function () {
    expect(MakerSocialImport::postUrl('https://www.instagram.com/reel/Ab_9/?igsh=a'))->toBe('https://www.instagram.com/reel/Ab_9/')
        ->and(MakerSocialImport::postUrl('https://www.facebook.com/reel/123/'))->toBe('https://www.facebook.com/reel/123/')
        ->and(MakerSocialImport::postUrl('https://youtube.com/shorts/abcdefghijk?si=thing'))->toBe('https://youtube.com/shorts/abcdefghijk')
        ->and(MakerSocialImport::postUrl('https://bad.example/shorts/abcdefghijk'))->toBeNull()
        ->and(MakerSocialImport::postUrl('https://someone@youtube.com/shorts/abcdefghijk'))->toBeNull();
});

it('imports videos exclusively through the invited owner and their connected workspace account', function () {
    config(['marketplace.enabled' => true, 'marketplace.host' => 'marketsandmakers.test', 'marketplace.checkout_provider' => 'everbranch',
        'marketing_desk.social_read_enabled' => true]);
    \Illuminate\Support\Facades\Route::middleware('web')->group(base_path('routes/marketplace.php'));
    $tenant = Tenant::create(['name' => 'Import fixture', 'slug' => 'social-import']);
    $other = Tenant::create(['name' => 'Other studio', 'slug' => 'other-social-studio']);
    Store::create(['tenant_id' => $tenant->id, 'name' => 'Studio', 'host' => 'marketsandmakers.test', 'verified_at' => now()]);
    $maker = Maker::create(['name' => 'Potter', 'slug' => 'potter', 'city' => 'Easley', 'story' => 'Pottery', 'status' => 'approved', 'tenant_id' => $tenant->id]);
    $identity = Identity::create(['email' => 'potter@example.test', 'active' => true]);
    Membership::create(['maker_id' => $maker->id, 'identity_id' => $identity->id, 'active' => true, 'role' => 'owner']);
    $user = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant, ['role' => 'admin', 'membership_active' => true]);
    $make = fn ($tenant, $identifier) => IntegrationConnection::create(['tenant_id' => $tenant->id, 'provider' => 'instagram', 'status' => 'connected',
        'external_account_id' => $identifier, 'external_account_secret' => $identifier, 'access_token' => 'fixture-token', 'scopes' => ['instagram_business_basic']]);
    $account = $make($tenant, '771');
    $foreign = $make($other, '772');
    Http::preventStrayRequests();
    Http::fake(['https://graph.instagram.com/*/771/media*' => Http::response(['data' => [
        ['id' => '81', 'media_type' => 'VIDEO', 'caption' => 'Throwing a bowl', 'media_url' => 'https://a.cdninstagram.com/bowl.mp4', 'permalink' => 'https://www.instagram.com/reel/abc/'],
        ['id' => '82', 'media_type' => 'VIDEO', 'media_url' => 'https://untrusted.example/file'],
    ]])]);
    $url = 'https://marketsandmakers.test/maker/'.$maker->id.'/studio/social';
    $this->actingAs($user)->withSession(['marketplace_identity' => $identity->id])->getJson($url.'/accounts')->assertForbidden();
    DB::table('marketplace_video_invitations')->insert(['maker_id' => $maker->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->getJson($url.'/accounts')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $account->id);
    $this->getJson($url.'/instagram/'.$account->id)->assertOk()->assertJsonCount(1)->assertJsonPath('0.title', 'Throwing a bowl')->assertJsonMissingPath('0.source');
    $this->getJson($url.'/instagram/'.$foreign->id)->assertNotFound();
    $this->postJson($url.'/instagram/'.$account->id.'/82')->assertNotFound();
});
