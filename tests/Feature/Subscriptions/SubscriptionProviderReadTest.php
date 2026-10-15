<?php

use App\Models\ShopifyStore;
use App\Models\SubscriptionCredentialRevision;
use App\Models\SubscriptionInstallation;
use App\Models\SubscriptionOperation;
use App\Models\SubscriptionProviderResource;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionProviderReadException;
use App\Services\Subscriptions\SubscriptionProviderReadService;
use App\Services\Subscriptions\SubscriptionShopifyReadTransport;
use App\Services\Subscriptions\SubscriptionUnsignedRevision;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/SubscriptionProviderReadFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->tenant = Tenant::query()->create(['name' => 'Read merchant', 'slug' => 'read-merchant']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'subscriptions', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test_explicit', 'price_source' => 'test']);
    $this->actor = User::factory()->create(['is_active' => true]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'manager', 'membership_active' => true]);
    $this->store = ShopifyStore::query()->create(['tenant_id' => $this->tenant->id, 'store_key' => 'merchant-one', 'store_role' => 'retail', 'shop_domain' => 'merchant-one.myshopify.com', 'access_token' => 'private-store-token']);
    config()->set('subscriptions.provider_reads_enabled', true);
    config()->set('subscriptions.provider_read_tenant_ids', [(int) $this->tenant->id]);
    // Deliberately unrelated global store slots cannot be the selected identity/token.
    config()->set('services.shopify.stores.retail.access_token', 'wrong-global-token');
    $this->service = app(SubscriptionProviderReadService::class);
    $this->verify = function (?string $uuid = null) {
        return $this->service->verifyInstallation($this->tenant->id, $this->actor, $this->store->id, subscriptionReadExpected(), $uuid ?? (string) Str::uuid());
    };
    $this->sync = function (int $installation, array $bounds = [], ?string $uuid = null) {
        return $this->service->syncOwnedContracts($this->tenant->id, $this->actor, $installation, $uuid ?? (string) Str::uuid(), $bounds);
    };
});

test('explicit verification binds only the selected tenant store and offline readiness sends no HTTP', function () {
    subscriptionReadFake();
    $result = ($this->verify)();
    expect($result['status'])->toBe('completed')->and($result['readiness']['identity_verified'])->toBeTrue()
        ->and($result['readiness']['owned_contract_count'])->toBeNull()->and($result['readiness']['scopes_ready'])->toBeTrue();
    $installation = SubscriptionInstallation::query()->sole();
    expect($installation->shopify_store_id)->toBe($this->store->id)->and($installation->shop_timezone)->toBe('America/New_York');
    Http::assertSent(fn ($r) => $r->header('X-Shopify-Access-Token') === ['private-store-token']);
    $count = count(Http::recorded());
    $offline = $this->service->readiness($this->tenant->id, $installation->id);
    expect(count(Http::recorded()))->toBe($count)->and($offline['provider_execution_enabled'])->toBeFalse()
        ->and($offline['purchases_enabled'])->toBeFalse()->and($offline['background_reads_enabled'])->toBeFalse()
        ->and($offline['webhook_setup_complete'])->toBeFalse()->and($offline['current_app_selling_plan_count'])->toBeNull();
    expect(json_encode($result))->not->toContain('private-store-token')->not->toContain('wrong-global-token');
    expect(DB::table('subscription_credential_revisions')->value('credentials'))->not->toContain('private-store-token');
    expect(SubscriptionCredentialRevision::query()->sole()->toArray())->not->toHaveKey('credentials');
});

test('complete empty inventory is a timestamped zero and retrying the same operation is idempotent', function () {
    subscriptionReadFake();
    $install = ($this->verify)()['readiness']['installation_id'];
    $uuid = (string) Str::uuid();
    $result = ($this->sync)($install, [], $uuid);
    expect($result['status'])->toBe('completed')->and($result['readiness']['owned_contract_count'])->toBe(0)
        ->and($result['readiness']['last_complete_sync_at'])->not->toBeNull()->and($result['readiness']['freshness'])->toBe('fresh');
    $count = count(Http::recorded());
    expect(($this->sync)($install, [], $uuid)['operation_id'])->toBe($result['operation_id'])->and(count(Http::recorded()))->toBe($count);
    expect(fn () => ($this->sync)($install, ['cycle_end' => 10], $uuid))->toThrow(HttpException::class);
});

test('masked resources retain exact revisions money instants and provider order links without changing legacy commerce', function () {
    $before = DB::table('subscription_contracts')->count();
    subscriptionReadFake([subscriptionReadContract()], null, ['lines' => [subscriptionReadLine()], 'attempts' => [subscriptionReadAttempt()], 'cycles' => [subscriptionReadCycle()]]);
    $install = ($this->verify)()['readiness']['installation_id'];
    $result = ($this->sync)($install);
    expect($result['status'])->toBe('completed')->and($result['readiness']['owned_contract_count'])->toBe(1);
    $contract = SubscriptionProviderResource::query()->where('resource_type', 'contract')->sole();
    expect($contract->provider_revision)->toBe('18446744073709551615')
        ->and($contract->snapshot['deliveryPrice']['amount'])->toBe('5.1234')->and($contract->snapshot['nextBillingDate'])->toBe('2026-11-01T14:15:16-05:00');
    expect(SubscriptionProviderResource::query()->where('resource_type', 'order_link')->sole()->provider_gid)->toBe('gid://shopify/SubscriptionContract/101:order:1201')
        ->and(SubscriptionProviderResource::query()->where('resource_type', 'cycle')->sole()->provider_gid)->toBe('gid://shopify/SubscriptionContract/101:cycle:1')
        ->and(SubscriptionProviderResource::query()->where('resource_type', 'payment_method')->sole()->parent_contract_gid)->toBeNull();
    expect(DB::table('subscription_contracts')->count())->toBe($before);
    expect(DB::table('subscription_provider_resources')->where('resource_type', 'payment_method')->value('snapshot'))->not->toContain('4242');
    expect($contract->toArray())->not->toHaveKey('snapshot');
    Http::assertSent(fn ($r) => str_contains($r['query'], 'generalCode: code') && ! str_contains($r['query'], 'mutation'));
});

test('all selected connections paginate and duplicate or looping cursors fail closed', function () {
    $counts = [];
    subscriptionReadFake([subscriptionReadContract()], function ($op, $vars, $data) use (&$counts) {
        $counts[$op] = ($counts[$op] ?? 0) + 1;
        if ($op === 'SubscriptionContractLines') {
            $data['subscriptionContract']['lines'] = ($vars['after'] === null) ? subscriptionReadConnection([subscriptionReadLine('701')], true, 'second-line') : subscriptionReadConnection([subscriptionReadLine('702')]);
        }

        return $data;
    });
    $install = ($this->verify)()['readiness']['installation_id'];
    expect(($this->sync)($install)['status'])->toBe('completed')->and($counts['SubscriptionContractLines'])->toBe(2)
        ->and(SubscriptionProviderResource::query()->where('resource_type', 'contract_line')->count())->toBe(2);
    subscriptionReadFake([subscriptionReadContract()], function ($op, $vars, $data) {
        if ($op === 'SubscriptionContractLines') {
            $data['subscriptionContract']['lines'] = subscriptionReadConnection([subscriptionReadLine()], true, 'same-cursor');
        }

        return $data;
    });
    expect(($this->sync)($install)['status'])->toBe('failed')->and(SubscriptionProviderResource::query()->where('resource_type', 'contract_line')->count())->toBe(2);
});

test('mixed revisions other app ownership and changing attempt observations do not advance evidence', function (string $defect) {
    subscriptionReadFake([subscriptionReadContract()]);
    $install = ($this->verify)()['readiness']['installation_id'];
    ($this->sync)($install);
    $before = SubscriptionInstallation::query()->find($install)->last_synced_at->toISOString();
    $calls = 0;
    subscriptionReadFake([subscriptionReadContract()], function ($op, $vars, $data) use ($defect, &$calls) {
        if ($defect === 'revision' && $op === 'SubscriptionContractLines') {
            $data['subscriptionContract']['revisionId'] = '12';
        }
        if ($defect === 'owner' && $op === 'SubscriptionOwnedContracts') {
            $data['subscriptionContracts']['nodes'][0]['app'] = null;
        }
        if ($defect === 'attempt' && $op === 'SubscriptionContractAttempts') {
            $calls++;
            $data['subscriptionContract']['billingAttempts'] = subscriptionReadConnection($calls === 1 ? [subscriptionReadAttempt()] : []);
        }

        return $data;
    });
    $result = ($this->sync)($install);
    expect($result['status'])->toBe('failed')->and($result['readiness']['owned_contract_count'])->toBe(1)
        ->and($result['readiness']['freshness'])->toBe('stale')->and(SubscriptionInstallation::query()->find($install)->last_synced_at->toISOString())->toBe($before);
})->with(['revision', 'owner', 'attempt']);

test('invalid selected currency and incomplete typed attempt unions cannot become complete', function (string $defect) {
    $core = subscriptionReadContract();
    $attempt = subscriptionReadAttempt();
    if ($defect === 'currency') {
        $core['deliveryPrice']['currencyCode'] = 'CAD';
    }
    if ($defect === 'action') {
        $attempt['state'] = ['__typename' => 'SubscriptionBillingAttemptActionRequiredState'];
    }
    if ($defect === 'error') {
        $attempt['state'] = ['__typename' => 'SubscriptionBillingAttemptFailedState', 'error' => ['__typename' => 'FutureError']];
    }
    subscriptionReadFake([$core], null, ['attempts' => [$attempt]]);
    $install = ($this->verify)()['readiness']['installation_id'];
    $result = ($this->sync)($install);
    expect($result['status'])->toBe('failed')->and($result['readiness']['owned_contract_count'])->toBeNull()->and(SubscriptionProviderResource::query()->count())->toBe(0);
})->with(['currency', 'action', 'error']);

test('provider partial errors denied access wrong actual API version and oversized bodies never mean zero', function (string $defect) {
    subscriptionReadFake();
    $install = ($this->verify)()['readiness']['installation_id'];
    subscriptionReadFake([], function ($op) use ($defect) {
        if ($op !== 'SubscriptionOwnedContracts') {
            return null;
        }

        return match ($defect) {
            'partial' => Http::response(['data' => ['subscriptionContracts' => subscriptionReadConnection([])], 'errors' => [['message' => 'private provider detail', 'extensions' => ['code' => 'ACCESS_DENIED']]]], 200, ['X-Shopify-API-Version' => '2026-10']),
            'denied' => Http::response('private token private-store-token', 403),
            'version' => Http::response(['data' => []], 200, ['X-Shopify-API-Version' => '2027-01']),
            'oversize' => Http::response(str_repeat('x', SubscriptionShopifyReadTransport::MAX_RESPONSE_BYTES + 1), 200, ['X-Shopify-API-Version' => '2026-10']),
        };
    });
    $result = ($this->sync)($install);
    expect(in_array($result['status'], ['failed', 'blocked']))->toBeTrue()->and($result['readiness']['owned_contract_count'])->toBeNull()
        ->and($result['readiness']['last_complete_sync_at'])->toBeNull()->and(json_encode($result))->not->toContain('private provider detail')->not->toContain('private-store-token');
})->with(['partial', 'denied', 'version', 'oversize']);

test('scope evidence uses actual grants and does not perform an empty sync on missing capabilities', function () {
    subscriptionReadFake([], fn ($op) => $op === 'SubscriptionInstallationIdentity' ? subscriptionReadIdentity(['read_own_subscription_contracts']) : null);
    $result = ($this->verify)();
    expect($result['status'])->toBe('completed')->and($result['readiness']['identity_verified'])->toBeTrue()->and($result['readiness']['scopes_ready'])->toBeFalse()
        ->and($result['readiness']['missing_scopes'])->toContain('read_customers', 'read_customer_payment_methods', 'read_products');
    $count = count(Http::recorded());
    expect(fn () => ($this->sync)($result['readiness']['installation_id']))->toThrow(HttpException::class)->and(count(Http::recorded()))->toBe($count);
});

test('same identity token rotation appends immutable revisions and mismatch retains original active credential', function () {
    subscriptionReadFake();
    $install = ($this->verify)()['readiness']['installation_id'];
    $original = SubscriptionInstallation::query()->find($install)->active_credential_revision_id;
    $this->store->update(['access_token' => 'new-private-token']);
    subscriptionReadFake([], function ($op, $vars, $data) {
        if ($op === 'SubscriptionInstallationIdentity') {
            $data['currentAppInstallation']['app']['id'] = 'gid://shopify/App/999';
        }

        return $data;
    });
    expect(($this->verify)()['status'])->toBe('blocked')->and(SubscriptionInstallation::query()->find($install)->active_credential_revision_id)->toBe($original);
    subscriptionReadFake();
    expect(($this->verify)()['status'])->toBe('completed');
    expect(SubscriptionCredentialRevision::query()->count())->toBe(3)->and(SubscriptionCredentialRevision::query()->find($original)->credentials['access_token'])->toBe('private-store-token')
        ->and(SubscriptionInstallation::query()->find($install)->active_credential_revision_id)->not->toBe($original);
    $wrong = subscriptionReadExpected();
    $wrong['app_gid'] = 'gid://shopify/App/999';
    expect(fn () => $this->service->verifyInstallation($this->tenant->id, $this->actor, $this->store->id, $wrong, (string) Str::uuid()))->toThrow(HttpException::class);
});

test('tenant manager entitlement ownership and rollout gates fail before HTTP', function (string $gate) {
    subscriptionReadFake();
    if ($gate === 'global') {
        config()->set('subscriptions.provider_reads_enabled', false);
    }
    if ($gate === 'rollout') {
        config()->set('subscriptions.provider_read_tenant_ids', []);
    }
    if ($gate === 'role') {
        $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'staff']);
    }
    if ($gate === 'membership') {
        $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    }
    if ($gate === 'store') {
        $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other']);
        $this->store->update(['tenant_id' => $other->id]);
    }
    if ($gate === 'entitlement') {
        TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->update(['enabled_status' => 'disabled']);
        app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    }
    expect(fn () => ($this->verify)())->toThrow($gate === 'store' ? ModelNotFoundException::class : HttpException::class);
    Http::assertNothingSent();
})->with(['global', 'rollout', 'role', 'membership', 'store', 'entitlement']);

test('an expired read lease rolls back all projections and watermark rather than publishing unfenced evidence', function () {
    subscriptionReadFake([subscriptionReadContract()]);
    $install = ($this->verify)()['readiness']['installation_id'];
    subscriptionReadFake([subscriptionReadContract()], function ($op, $vars, $data) {
        if ($op === 'SubscriptionContractCore') {
            DB::table('subscription_operations')->where('kind', 'owned_contract_sync')->where('status', 'reading')->update(['lease_expires_at' => now()->subMinute()]);
        }

        return $data;
    });
    $result = ($this->sync)($install);
    expect($result['status'])->toBe('reading')->and($result['readiness']['last_complete_sync_at'])->toBeNull()
        ->and($result['readiness']['owned_contract_count'])->toBeNull()->and(SubscriptionProviderResource::query()->count())->toBe(0);
});

test('bounded resumable slices stage privately and publish a total only after the verified complete generation', function () {
    subscriptionReadFake([subscriptionReadContract(), subscriptionReadContract('102')]);
    $install = ($this->verify)()['readiness']['installation_id'];
    $first = ($this->sync)($install, ['max_contracts' => 1]);
    expect($first['status'])->toBe('completed')->and($first['sync_complete'])->toBeFalse()
        ->and($first['readiness']['owned_contract_count'])->toBeNull()->and(SubscriptionProviderResource::query()->count())->toBe(0);
    $second = ($this->sync)($install, ['max_contracts' => 1]);
    expect($second['sync_complete'])->toBeTrue()->and($second['readiness']['owned_contract_count'])->toBe(2)
        ->and(SubscriptionProviderResource::query()->where('resource_type', 'contract')->count())->toBe(2);
    subscriptionReadFake();
    expect(($this->sync)($install)['readiness']['owned_contract_count'])->toBe(0)
        ->and(SubscriptionProviderResource::query()->where('resource_type', 'contract')->where('stale_reason', 'not_in_latest_observation')->count())->toBe(2);
});

test('unsigned64 decoding stays lossless and arbitrary queries or variables cannot reach HTTP', function () {
    subscriptionReadFake();
    $install = ($this->verify)()['readiness']['installation_id'];
    $installation = SubscriptionInstallation::query()->find($install);
    $revision = SubscriptionCredentialRevision::query()->find($installation->active_credential_revision_id);
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(['*' => Http::response('{"data":{"revisionId":18446744073709551615}}', 200, ['X-Shopify-API-Version' => '2026-10'])]);
    expect(app(SubscriptionShopifyReadTransport::class)->query($installation, $revision, 'SubscriptionInstallationIdentity')['revisionId'])->toBe('18446744073709551615');
    $count = count(Http::recorded());
    expect(fn () => app(SubscriptionShopifyReadTransport::class)->query($installation, $revision, 'mutation ChargeCard'))->toThrow(SubscriptionProviderReadException::class)
        ->and(fn () => app(SubscriptionShopifyReadTransport::class)->query($installation, $revision, 'SubscriptionOwnedContracts', ['after' => null, 'query' => 'arbitrary']))->toThrow(SubscriptionProviderReadException::class)
        ->and(count(Http::recorded()))->toBe($count)->and(SubscriptionUnsignedRevision::compare('9', '10'))->toBeLessThan(0)
        ->and(fn () => SubscriptionUnsignedRevision::parse(1.8446744e19))->toThrow(SubscriptionProviderReadException::class)
        ->and(fn () => SubscriptionUnsignedRevision::parse('18446744073709551616'))->toThrow(SubscriptionProviderReadException::class);
});

test('CLI readiness is offline and missing explicit identity or unauthorized actor cannot query providers', function () {
    subscriptionReadFake();
    $install = ($this->verify)()['readiness']['installation_id'];
    $count = count(Http::recorded());
    $this->artisan('subscriptions:provider-read', ['action' => 'readiness', '--tenant' => (string) $this->tenant->id, '--actor' => (string) $this->actor->id, '--installation' => (string) $install])->assertSuccessful();
    expect(count(Http::recorded()))->toBe($count);
    $this->artisan('subscriptions:provider-read', ['action' => 'verify', '--tenant' => (string) $this->tenant->id, '--actor' => (string) $this->actor->id, '--store' => (string) $this->store->id])->assertFailed();
    expect(count(Http::recorded()))->toBe($count);
});

test('a normal store above one hundred owned contracts resumes without publishing a capped total', function () {
    $contracts = array_map(fn ($id) => subscriptionReadContract((string) $id), range(101, 205));
    subscriptionReadFake($contracts);
    $install = ($this->verify)()['readiness']['installation_id'];
    $slices = 0;
    do {
        $result = ($this->sync)($install, ['max_contracts' => 10]);
        $slices++;
        expect($result['status'])->toBe('completed');
        if (! $result['sync_complete']) {
            expect($result['readiness']['owned_contract_count'])->toBeNull()->and(SubscriptionProviderResource::query()->count())->toBe(0);
        }
        expect($slices)->toBeLessThan(30);
    } while (! $result['sync_complete']);
    expect($result['readiness']['owned_contract_count'])->toBe(105)
        ->and(SubscriptionProviderResource::query()->where('resource_type', 'contract')->count())->toBe(105)
        ->and($result['readiness']['sync_progress'])->toBeNull();
});

test('long resumable generations preserve old observation times and cannot rejuvenate payment evidence', function () {
    subscriptionReadFake([subscriptionReadContract(), subscriptionReadContract('102')], null, ['attempts' => [subscriptionReadAttempt('1101', ['__typename' => 'SubscriptionBillingAttemptPendingState'])]]);
    $install = ($this->verify)()['readiness']['installation_id'];
    $first = ($this->sync)($install, ['max_contracts' => 1]);
    expect($first['sync_complete'])->toBeFalse();
    $started = now();
    $this->travel(2)->hours();
    // The second contract has no attempts. The first observation keeps its time.
    subscriptionReadFake([subscriptionReadContract(), subscriptionReadContract('102')]);
    $last = ($this->sync)($install, ['max_contracts' => 1]);
    expect($last['sync_complete'])->toBeTrue()->and($last['readiness']['freshness'])->toBe('stale');
    $attempt = SubscriptionProviderResource::query()->where('resource_type', 'billing_attempt')->sole();
    expect($attempt->status)->toBe('pending')->and($attempt->observed_at->lte($started))->toBeTrue();
});

test('concurrent read slices cannot regress independent attempt state or stale a newer accepted result', function () {
    subscriptionReadFake([subscriptionReadContract()]);
    $install = ($this->verify)()['readiness']['installation_id'];
    $interleaved = false;
    subscriptionReadFake([subscriptionReadContract()], function ($op, $vars, $data) use ($install, &$interleaved) {
        if ($op === 'SubscriptionContractCore' && ! $interleaved) {
            $interleaved = true;
            // A second authorized worker is fenced before any provider request.
            expect(fn () => ($this->sync)($install))->toThrow(HttpException::class);
        }

        return $data;
    }, ['attempts' => [subscriptionReadAttempt('1101', ['__typename' => 'SubscriptionBillingAttemptPendingState'])]]);
    expect(($this->sync)($install)['sync_complete'])->toBeTrue()->and($interleaved)->toBeTrue();
    subscriptionReadFake([subscriptionReadContract()], null, ['attempts' => [subscriptionReadAttempt()]]);
    expect(($this->sync)($install)['sync_complete'])->toBeTrue()
        ->and(SubscriptionProviderResource::query()->where('resource_type', 'billing_attempt')->sole()->status)->toBe('succeeded');
});

test('unverified same-store setup typo correction retains audit and invalidates old UUIDs before fresh verification', function () {
    subscriptionReadFake();
    $wrong = subscriptionReadExpected();
    $wrong['app_installation_gid'] = 'gid://shopify/AppInstallation/999';
    $oldUuid = (string) Str::uuid();
    $failed = $this->service->verifyInstallation($this->tenant->id, $this->actor, $this->store->id, $wrong, $oldUuid);
    $install = $failed['readiness']['installation_id'];
    expect($failed['status'])->toBe('blocked')->and($failed['readiness']['identity_verified'])->toBeFalse();
    $count = count(Http::recorded());
    $correctionUuid = (string) Str::uuid();
    $corrected = $this->service->correctPendingSetup($this->tenant->id, $this->actor, $install, subscriptionReadExpected(), $correctionUuid);
    expect($corrected['failure_code'])->toBe('setup_identity_corrected')->and(count(Http::recorded()))->toBe($count)
        ->and(SubscriptionOperation::query()->find($corrected['operation_id'])->request['before']['app_installation_gid'])->toBe('gid://shopify/AppInstallation/999');
    expect(fn () => ($this->verify)($oldUuid))->toThrow(HttpException::class);
    expect(($this->verify)()['status'])->toBe('completed');
    expect(fn () => $this->service->correctPendingSetup($this->tenant->id, $this->actor, $install, $wrong, (string) Str::uuid()))->toThrow(HttpException::class);
});

test('token-only same-app rotation preserves pinned signing secret and failed candidates do not stale active evidence', function () {
    subscriptionReadFake();
    $install = ($this->verify)()['readiness']['installation_id'];
    ($this->sync)($install);
    $active = SubscriptionCredentialRevision::query()->find(SubscriptionInstallation::query()->find($install)->active_credential_revision_id);
    // Fixture provisioning simulates a separately approved signing-secret setup.
    DB::table('subscription_credential_revisions')->where('id', $active->id)->update([
        'app_secret' => \Illuminate\Support\Facades\Crypt::encryptString('pinned-private-signing-secret'),
        'app_secret_reference' => 'approved-secret-ref', 'app_secret_revision' => 'approved-v1',
    ]);
    $this->store->update(['access_token' => 'replacement-token']);
    subscriptionReadFake([], function ($op, $vars, $data) {
        if ($op === 'SubscriptionInstallationIdentity') {
            $data['currentAppInstallation']['id'] = 'gid://shopify/AppInstallation/999';
        }

        return $data;
    });
    expect(($this->verify)()['status'])->toBe('blocked')->and($this->service->readiness($this->tenant->id, $install)['freshness'])->toBe('fresh');
    subscriptionReadFake();
    expect(($this->verify)()['status'])->toBe('completed');
    $new = SubscriptionCredentialRevision::query()->find(SubscriptionInstallation::query()->find($install)->active_credential_revision_id);
    expect($new->app_secret)->toBe('pinned-private-signing-secret')->and($new->app_secret_reference)->toBe('approved-secret-ref')
        ->and($new->app_secret_revision)->toBe('approved-v1')->and($new->app_secret_expires_at)->toBeNull();
    $readiness = $this->service->readiness($this->tenant->id, $install);
    expect($readiness['webhook_signing_secret_configured'])->toBeTrue()->and($readiness['webhook_setup_complete'])->toBeFalse()
        ->and(json_encode($readiness))->not->toContain('pinned-private-signing-secret');
});

test('shared customer payment method and shared order produce separate contract order links', function () {
    $first = subscriptionReadContract();
    $second = subscriptionReadContract('102');
    subscriptionReadFake([$first, $second], function ($op, $vars, $data) {
        if ($op === 'SubscriptionContractAttempts') {
            $attempt = subscriptionReadAttempt($vars['id'] === 'gid://shopify/SubscriptionContract/101' ? '1101' : '1102');
            $attempt['subscriptionContract']['id'] = $vars['id'];
            $data['subscriptionContract']['billingAttempts'] = subscriptionReadConnection([$attempt]);
        }

        return $data;
    });
    $install = ($this->verify)()['readiness']['installation_id'];
    expect(($this->sync)($install)['sync_complete'])->toBeTrue()->and(SubscriptionProviderResource::query()->where('resource_type', 'payment_method')->count())->toBe(1)
        ->and(SubscriptionProviderResource::query()->where('resource_type', 'order_link')->count())->toBe(2);
});

test('expired worker A cannot regress a successful newer worker B or stale its accepted generation', function () {
    subscriptionReadFake([subscriptionReadContract()]);
    $install = ($this->verify)()['readiness']['installation_id'];
    $indexes = 0;
    $insideB = false;
    $ranB = false;
    subscriptionReadFake([subscriptionReadContract()], function ($op, $vars, $data) use ($install, &$indexes, &$insideB, &$ranB) {
        if ($op === 'SubscriptionContractAttempts') {
            $attempt = subscriptionReadAttempt('1101', $insideB ? null : ['__typename' => 'SubscriptionBillingAttemptPendingState']);
            $data['subscriptionContract']['billingAttempts'] = subscriptionReadConnection([$attempt]);
        }
        if ($op === 'SubscriptionOwnedContracts' && ++$indexes === 2 && ! $ranB) {
            DB::table('subscription_operations')->where('kind', 'owned_contract_sync')->where('status', 'reading')->update(['lease_expires_at' => now()->subMinute()]);
            $insideB = true;
            $ranB = true;
            expect(($this->sync)($install)['sync_complete'])->toBeTrue();
            $insideB = false;
        }

        return $data;
    });
    $a = ($this->sync)($install);
    expect($ranB)->toBeTrue()->and($a['status'])->toBe('reading')->and($a['readiness']['freshness'])->toBe('fresh')
        ->and(SubscriptionProviderResource::query()->where('resource_type', 'billing_attempt')->sole()->status)->toBe('succeeded')
        ->and(SubscriptionProviderResource::query()->where('resource_type', 'order_link')->sole()->stale_reason)->toBeNull();
});

test('transient same UUID retry resumes its original generation without a spurious generation failure', function () {
    subscriptionReadFake();
    $install = ($this->verify)()['readiness']['installation_id'];
    $uuid = (string) Str::uuid();
    subscriptionReadFake([], fn ($op) => $op === 'SubscriptionOwnedContracts' ? Http::response('unavailable', 503) : null);
    $failed = ($this->sync)($install, [], $uuid);
    expect($failed['status'])->toBe('failed')->and($failed['failure_code'])->toBe('provider_http_error');
    subscriptionReadFake();
    $retry = ($this->sync)($install, [], $uuid);
    expect($retry['operation_id'])->toBe($failed['operation_id'])->and($retry['sync_complete'])->toBeTrue()
        ->and($retry['readiness']['owned_contract_count'])->toBe(0);
});

test('changed index requires an explicit restart and retains the last accepted total until verified', function () {
    subscriptionReadFake([subscriptionReadContract()]);
    $install = ($this->verify)()['readiness']['installation_id'];
    ($this->sync)($install);
    $indexes = 0;
    subscriptionReadFake([subscriptionReadContract()], function ($op, $vars, $data) use (&$indexes) {
        if ($op === 'SubscriptionOwnedContracts' && ++$indexes === 2) {
            $data['subscriptionContracts']['nodes'] = [];
        }

        return $data;
    });
    $failed = ($this->sync)($install);
    expect($failed['failure_code'])->toBe('changing_contract_index')->and($failed['readiness']['owned_contract_count'])->toBe(1)
        ->and($failed['readiness']['sync_progress']['phase'])->toBe('restart_required');
    expect(fn () => ($this->sync)($install))->toThrow(HttpException::class);
    subscriptionReadFake();
    expect(($this->sync)($install, ['restart' => true])['readiness']['owned_contract_count'])->toBe(0);
});

test('a failed UUID cannot be repurposed to a later slice after another UUID advances the generation', function () {
    $contracts = [subscriptionReadContract(), subscriptionReadContract('102')];
    subscriptionReadFake($contracts);
    $install = ($this->verify)()['readiness']['installation_id'];
    $uuid = (string) Str::uuid();
    subscriptionReadFake($contracts, fn ($op) => $op === 'SubscriptionContractCore' ? Http::response('temporary', 503) : null);
    expect(($this->sync)($install, ['max_contracts' => 1], $uuid)['status'])->toBe('failed');
    subscriptionReadFake($contracts);
    expect(($this->sync)($install, ['max_contracts' => 1])['sync_complete'])->toBeFalse();
    $count = count(Http::recorded());
    expect(fn () => ($this->sync)($install, ['max_contracts' => 1], $uuid))->toThrow(HttpException::class)->and(count(Http::recorded()))->toBe($count);
    expect(($this->sync)($install, ['max_contracts' => 1])['readiness']['owned_contract_count'])->toBe(2);
});

test('cross-tenant installation lookup and unavailable selected-store tokens never use global fallback', function () {
    subscriptionReadFake();
    $install = ($this->verify)()['readiness']['installation_id'];
    $other = Tenant::query()->create(['name' => 'Different', 'slug' => 'different-read-store']);
    $count = count(Http::recorded());
    expect(fn () => $this->service->readiness($other->id, $install))->toThrow(ModelNotFoundException::class)->and(count(Http::recorded()))->toBe($count);
    $this->store->update(['access_token' => '']);
    expect(fn () => ($this->verify)())->toThrow(HttpException::class)->and(count(Http::recorded()))->toBe($count);
});

test('opaque owned child identities and unknown masked instruments retain explicit availability without inventing a card', function () {
    $core = subscriptionReadContract();
    $core['customerPaymentMethod']['id'] = 'gid://shopify/CustomerPaymentMethod/opaque-method_abc';
    $core['customerPaymentMethod']['instrument'] = ['__typename' => 'CustomerPaypalBillingAgreement'];
    subscriptionReadFake([$core], null, ['lines' => [subscriptionReadLine('opaque-line_abc')], 'attempts' => [subscriptionReadAttempt('1101', ['__typename' => 'SubscriptionBillingAttemptSuccessState', 'order' => null])]]);
    $install = ($this->verify)()['readiness']['installation_id'];
    expect(($this->sync)($install)['sync_complete'])->toBeTrue()
        ->and(SubscriptionProviderResource::query()->where('resource_type', 'payment_method')->sole()->status)->toBe('unsupported_instrument')
        ->and(SubscriptionProviderResource::query()->where('resource_type', 'billing_attempt')->sole()->status)->toBe('succeeded')
        ->and(SubscriptionProviderResource::query()->where('resource_type', 'order_link')->count())->toBe(0);
});

test('a slower older probe cannot replace a newer verified scope observation on the same credential', function () {
    subscriptionReadFake();
    $install = ($this->verify)()['readiness']['installation_id'];
    $interleaved = false;
    $insideNewer = false;
    subscriptionReadFake([], function ($op) use ($install, &$interleaved, &$insideNewer) {
        if ($op !== 'SubscriptionInstallationIdentity') {
            return null;
        }
        if (! $interleaved) {
            $interleaved = true;
            $insideNewer = true;
            expect($this->service->probe($this->tenant->id, $this->actor, $install, (string) Str::uuid())['status'])->toBe('completed');
            $insideNewer = false;
        }

        return $insideNewer ? subscriptionReadIdentity(['read_own_subscription_contracts']) : subscriptionReadIdentity();
    });
    $older = $this->service->probe($this->tenant->id, $this->actor, $install, (string) Str::uuid());
    expect($older['failure_code'])->toBe('identity_evidence_superseded')->and($older['readiness']['scopes_ready'])->toBeFalse()
        ->and($older['readiness']['missing_scopes'])->toContain('read_customer_payment_methods');
});
