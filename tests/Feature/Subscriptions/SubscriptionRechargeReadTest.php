<?php

use App\Models\IntegrationConnection;
use App\Models\SubscriptionRechargeConnection;
use App\Models\SubscriptionRechargeCredentialRevision;
use App\Models\SubscriptionRechargeOperation;
use App\Models\SubscriptionRechargeResource;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionProviderReadException;
use App\Services\Subscriptions\SubscriptionRechargeReadService;
use App\Services\Subscriptions\SubscriptionRechargeTransport;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/SubscriptionRechargeFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->tenant = Tenant::query()->create(['name' => 'Recharge shadow merchant', 'slug' => 'recharge-shadow']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'subscriptions', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test_explicit', 'price_source' => 'test']);
    $this->actor = User::factory()->create(['is_active' => true]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'manager', 'membership_active' => true]);
    $this->source = IntegrationConnection::query()->create(['tenant_id' => $this->tenant->id, 'provider' => 'recharge', 'status' => 'pending', 'access_token' => 'PRIVATE_RECHARGE_TOKEN']);
    config()->set('subscriptions.recharge_reads_enabled', true);
    config()->set('subscriptions.recharge_read_tenant_ids', [(int) $this->tenant->id]);
    $this->service = app(SubscriptionRechargeReadService::class);
    $this->verify = fn (?string $uuid = null) => $this->service->verifyConnection($this->tenant->id, $this->actor, $this->source->id, rechargeExpected(), $uuid ?? (string) Str::uuid());
    $this->sync = fn (int $id, array $bounds = [], ?string $uuid = null) => $this->service->sync($this->tenant->id, $this->actor, $id, $uuid ?? (string) Str::uuid(), $bounds);
    $this->finish = function (int $id, array $bounds = []) {
        $iterations = 0;
        do {
            $result = ($this->sync)($id, $bounds);
            expect($result['status'])->toBe('completed');
            $iterations++;
            expect($iterations)->toBeLessThan(60);
        } while (! $result['sync_complete']);

        return $result;
    };
});

test('Recharge connection verifies exact merchant identity with no domain guess or global fallback', function () {
    rechargeFake();
    $result = ($this->verify)();
    expect($result['status'])->toBe('completed')->and($result['readiness']['identity_verified'])->toBeTrue()->and($result['readiness']['last_complete_counts'])->toBeNull();
    $connection = SubscriptionRechargeConnection::query()->sole();
    expect($connection->provider_store_id)->toBe('9001')->and($connection->identifier)->toBe('reviewed-merchant')->and($connection->shop_domain)->toBeNull();
    Http::assertSent(fn ($r) => $r->header('X-Recharge-Version') === ['2021-11'] && $r->header('X-Recharge-Access-Token') === ['PRIVATE_RECHARGE_TOKEN']);
    $count = count(Http::recorded());
    $ready = $this->service->readiness($this->tenant->id, $connection->id);
    expect(count(Http::recorded()))->toBe($count)->and($ready['servicing_enabled'])->toBeFalse()->and($ready['cutover_enabled'])->toBeFalse()->and($ready['background_reads_enabled'])->toBeFalse();
    expect(json_encode($result))->not->toContain('PRIVATE_RECHARGE_TOKEN')->and(SubscriptionRechargeCredentialRevision::query()->sole()->toArray())->not->toHaveKey('credentials');
});

test('wrong expected identity cannot poison a binding and the same store can be correctly verified afterward', function () {
    rechargeFake();
    $wrong = rechargeExpected();
    $wrong['provider_store_id'] = '999';
    expect(fn () => $this->service->verifyConnection($this->tenant->id, $this->actor, $this->source->id, $wrong, (string) Str::uuid()))->toThrow(SubscriptionProviderReadException::class)
        ->and(SubscriptionRechargeConnection::query()->count())->toBe(0);
    expect(($this->verify)()['status'])->toBe('completed');
    expect(fn () => $this->service->verifyConnection($this->tenant->id, $this->actor, $this->source->id, $wrong, (string) Str::uuid()))->toThrow(HttpException::class);
});

test('full shadow generation preserves source relationships prices dates and strips portal vault and property secrets before storage', function () {
    $legacy = DB::table('subscription_contracts')->count();
    rechargeFake();
    $id = ($this->verify)()['readiness']['connection_id'];
    $first = ($this->sync)($id);
    expect($first['sync_complete'])->toBeFalse()->and($first['readiness']['last_complete_counts'])->toBeNull();
    $done = ($this->finish)($id);
    expect($done['readiness']['last_complete_counts'])->toBe(['customer' => 1, 'address' => 1, 'subscription' => 1, 'payment_method' => 1])
        ->and($done['readiness']['coverage_blockers'])->toContain('manual_settings_review_required', 'source_to_local_mapping_unreviewed');
    $generation = $done['readiness']['last_complete_generation_id'];
    $resources = SubscriptionRechargeResource::query()->where('generation_id', $generation)->get();
    expect($resources)->toHaveCount(5)->and($resources->where('resource_type', 'subscription')->sole()->snapshot['price'])->toBe('19.9950')
        ->and($resources->where('resource_type', 'subscription')->sole()->snapshot['next_charge_scheduled_at'])->toBe('2026-11-01')
        ->and($resources->where('resource_type', 'subscription')->sole()->parent_provider_id)->toBe('201')
        ->and($resources->where('resource_type', 'address')->sole()->snapshot['payment_method_id'])->toBe('401');
    foreach ($resources as $row) {
        $plain = json_encode($row->snapshot);
        expect($plain)->not->toContain('PRIVATE_PORTAL_BEARER_HASH')->not->toContain('PRIVATE_VAULT_CUSTOMER_TOKEN')->not->toContain('PRIVATE_VAULT_PAYMENT_TOKEN')->not->toContain('PRIVATE_NESTED_VAULT_TOKEN')->not->toContain('PRIVATE_PROPERTY_SECRET')->not->toContain('DONT_COPY_ANALYTICS')
            ->and($row->mapping_state)->toBe('unreviewed')->and($row->toArray())->not->toHaveKey('snapshot');
    }
    expect(DB::table('subscription_contracts')->count())->toBe($legacy)->and(DB::table('subscription_recharge_resources')->value('snapshot'))->not->toContain('Fictional');
    Http::assertSent(fn ($r) => $r->method() === 'GET');
});

test('explicit complete empty source is zero while missing pagination or API denial is never an empty success', function (string $defect) {
    rechargeFake();
    $id = ($this->verify)()['readiness']['connection_id'];
    rechargeFake(null, function ($path, $query, $data) use ($defect) {
        if ($path !== 'customers') {
            return null;
        }

        return match ($defect) {
            'pagination' => ['customers' => []], 'denied' => Http::response('PRIVATE_ERROR_TOKEN', 403), 'partial' => ['customers' => null, 'next_cursor' => null, 'previous_cursor' => null]
        };
    });
    $result = ($this->sync)($id);
    expect($result['status'])->toBe('failed')->and($result['readiness']['last_complete_counts'])->toBeNull()->and($result['readiness']['last_complete_sync_at'])->toBeNull()
        ->and(json_encode($result))->not->toContain('PRIVATE_ERROR_TOKEN');
})->with(['pagination', 'denied', 'partial']);

test('cursor resumes above one page and rejects source changes without replacing accepted customer facts', function () {
    rechargeFake();
    $id = ($this->verify)()['readiness']['connection_id'];
    $accepted = ($this->finish)($id)['readiness']['last_complete_generation_id'];
    $fixtures = rechargeShadowFixtures();
    $fixtures['customers'][0]['email'] = 'new@example.test';
    $calls = 0;
    rechargeFake($fixtures, function ($path, $query, $data) use (&$calls) {
        if ($path === 'customers' && ++$calls === 2) {
            $data['customers'][0]['email'] = 'changed-again@example.test';
        }

        return $data;
    });
    $result = null;
    for ($i = 0; $i < 8; $i++) {
        $result = ($this->sync)($id);
    }
    expect($result['failure_code'])->toBe('recharge_source_changed')->and($result['readiness']['last_complete_generation_id'])->toBe($accepted)
        ->and(SubscriptionRechargeResource::query()->where('generation_id', $accepted)->where('resource_type', 'customer')->sole()->snapshot['email'])->toBe('fictional@example.test');
});

test('relationships and provider customer subscription counts must reconcile before generation acceptance', function (string $defect) {
    $fixtures = rechargeShadowFixtures();
    if ($defect === 'customer') {
        $fixtures['addresses'][0]['customer_id'] = 999;
    }
    if ($defect === 'payment') {
        $fixtures['payment_methods'][0]['customer_id'] = 999;
    }
    if ($defect === 'counts') {
        $fixtures['customers'][0]['subscriptions_total_count'] = 5;
    }
    rechargeFake($fixtures);
    $id = ($this->verify)()['readiness']['connection_id'];
    for ($i = 0; $i < 9; $i++) {
        $result = ($this->sync)($id);
    }
    expect($result['status'])->toBe('failed')->and($result['readiness']['last_complete_generation_id'])->toBeNull()
        ->and(SubscriptionRechargeResource::query()->where('completeness', 'complete')->count())->toBe(0);
})->with(['customer', 'payment', 'counts']);

test('missing grants role entitlement and rollout fail closed without provider fallback', function (string $gate) {
    rechargeFake();
    if ($gate === 'global') {
        config()->set('subscriptions.recharge_reads_enabled', false);
    }
    if ($gate === 'tenant') {
        config()->set('subscriptions.recharge_read_tenant_ids', []);
    }
    if ($gate === 'role') {
        $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'staff']);
    }
    if ($gate === 'membership') {
        $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    }
    if ($gate === 'entitlement') {
        TenantModuleEntitlement::query()->where('tenant_id', $this->tenant->id)->update(['enabled_status' => 'disabled']);
        app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    }
    expect(fn () => ($this->verify)())->toThrow(HttpException::class);
    Http::assertNothingSent();
})->with(['global', 'tenant', 'role', 'membership', 'entitlement']);

test('cross tenant connection and customer IDs cannot route to another credential or become empty success', function () {
    rechargeFake();
    $id = ($this->verify)()['readiness']['connection_id'];
    $other = Tenant::query()->create(['name' => 'Other tenant', 'slug' => 'other-recharge']);
    expect(fn () => $this->service->readiness($other->id, $id))->toThrow(ModelNotFoundException::class);
    $result = $this->service->refreshCustomer($this->tenant->id, $this->actor, $id, '999', (string) Str::uuid());
    expect($result['status'])->toBe('failed')->and(SubscriptionRechargeResource::query()->count())->toBe(0);
    $ok = $this->service->refreshCustomer($this->tenant->id, $this->actor, $id, '101', (string) Str::uuid());
    expect($ok['status'])->toBe('completed')->and($ok['readiness']['last_complete_generation_id'])->toBeNull()
        ->and(SubscriptionRechargeResource::query()->sole()->completeness)->toBe('observation_only');
});

test('same UUID replays completed read without HTTP and conflicts cannot change its content', function () {
    rechargeFake();
    $uuid = (string) Str::uuid();
    $first = ($this->verify)($uuid);
    $count = count(Http::recorded());
    expect(($this->verify)($uuid)['operation_id'])->toBe($first['operation_id'])->and(count(Http::recorded()))->toBe($count);
    $id = $first['readiness']['connection_id'];
    $slice = (string) Str::uuid();
    $one = ($this->sync)($id, [], $slice);
    $count = count(Http::recorded());
    expect(($this->sync)($id, [], $slice)['operation_id'])->toBe($one['operation_id'])->and(count(Http::recorded()))->toBe($count)
        ->and(fn () => ($this->sync)($id, ['limit' => 1], $slice))->toThrow(HttpException::class);
});

test('credential rotations preserve original token and source changes cannot silently rebind the verified store', function () {
    rechargeFake();
    $id = ($this->verify)()['readiness']['connection_id'];
    $original = SubscriptionRechargeConnection::query()->find($id)->active_credential_revision_id;
    $this->source->update(['access_token' => 'NEW_PRIVATE_TOKEN']);
    expect(($this->verify)()['status'])->toBe('completed');
    expect(SubscriptionRechargeCredentialRevision::query()->find($original)->credentials['access_token'])->toBe('PRIVATE_RECHARGE_TOKEN')
        ->and(SubscriptionRechargeCredentialRevision::query()->find($original)->status)->toBe('retired');
    rechargeFake(null, function ($path, $query, $data) {
        if ($path === 'store') {
            $data['store']['id'] = 999;
        }

        return $data;
    });
    expect(fn () => ($this->verify)())->toThrow(SubscriptionProviderReadException::class);
});

test('read adapter has no arbitrary URL or action route and rejects unsupported writes before HTTP', function () {
    rechargeFake();
    $id = ($this->verify)()['readiness']['connection_id'];
    $connection = SubscriptionRechargeConnection::query()->find($id);
    $credential = SubscriptionRechargeCredentialRevision::query()->find($connection->active_credential_revision_id);
    $count = count(Http::recorded());
    expect(fn () => app(SubscriptionRechargeTransport::class)->read($connection, $credential, 'cancel', ['id' => '301']))->toThrow(SubscriptionProviderReadException::class)
        ->and(fn () => app(SubscriptionRechargeTransport::class)->read($connection, $credential, 'https://localhost/private'))->toThrow(SubscriptionProviderReadException::class)
        ->and(count(Http::recorded()))->toBe($count);
});

test('new explicit credential source rotates the same store without changing original provenance or crossing tenants', function () {
    rechargeFake();
    $id = ($this->verify)()['readiness']['connection_id'];
    $original = SubscriptionRechargeConnection::query()->find($id)->active_credential_revision_id;
    $new = IntegrationConnection::query()->create(['tenant_id' => $this->tenant->id, 'provider' => 'recharge', 'status' => 'pending', 'external_account_id' => 'draft-rotation', 'access_token' => 'ROTATED_PRIVATE_TOKEN']);
    $uuid = (string) Str::uuid();
    $rotated = $this->service->verifyConnection($this->tenant->id, $this->actor, $new->id, rechargeExpected(), $uuid);
    $connection = SubscriptionRechargeConnection::query()->find($id);
    expect($rotated['status'])->toBe('completed')->and($rotated['readiness']['connection_id'])->toBe($id)
        ->and($connection->source_integration_connection_id)->toBe($this->source->id)->and($connection->active_credential_revision_id)->not->toBe($original)
        ->and(SubscriptionRechargeCredentialRevision::query()->find($connection->active_credential_revision_id)->credentials['source_integration_connection_id'])->toBe($new->id);
    expect(fn () => $this->service->verifyConnection($this->tenant->id, $this->actor, $this->source->id, rechargeExpected(), $uuid))->toThrow(HttpException::class);
    $before = $connection->active_credential_revision_id;
    rechargeFake(null, function ($path, $query, $data) {
        if ($path === 'store') {
            $data['store']['identifier'] = 'another-merchant';
        }

        return $data;
    });
    expect(fn () => $this->service->verifyConnection($this->tenant->id, $this->actor, $new->id, rechargeExpected(), (string) Str::uuid()))->toThrow(SubscriptionProviderReadException::class)
        ->and($connection->fresh()->active_credential_revision_id)->toBe($before);
    $other = Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign-recharge']);
    $foreign = IntegrationConnection::query()->create(['tenant_id' => $other->id, 'provider' => 'recharge', 'status' => 'pending', 'external_account_id' => 'draft-foreign', 'access_token' => 'FOREIGN_TOKEN']);
    expect(fn () => $this->service->verifyConnection($this->tenant->id, $this->actor, $foreign->id, rechargeExpected(), (string) Str::uuid()))->toThrow(ModelNotFoundException::class);
    TenantModuleEntitlement::query()->create(['tenant_id' => $other->id, 'module_key' => 'subscriptions', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test_explicit', 'price_source' => 'test']);
    $this->actor->tenants()->attach($other->id, ['role' => 'manager', 'membership_active' => true]);
    config()->set('subscriptions.recharge_read_tenant_ids', [(int) $this->tenant->id, (int) $other->id]);
    rechargeFake();
    $blocked = false;
    try {
        $this->service->verifyConnection($other->id, $this->actor, $foreign->id, rechargeExpected(), (string) Str::uuid());
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
        $blocked = true;
    }
    expect($blocked)->toBeTrue()->and(SubscriptionRechargeConnection::query()->count())->toBe(1);
});

test('cursor pages preserve distinct source customers with the same email and never deduplicate by Shopify ID', function () {
    $fixtures = rechargeShadowFixtures();
    $second = $fixtures['customers'][0];
    $second['id'] = 102;
    $second['subscriptions_active_count'] = 0;
    $second['subscriptions_total_count'] = 0;
    $fixtures['customers'][] = $second;
    rechargeFake($fixtures);
    $id = ($this->verify)()['readiness']['connection_id'];
    $done = ($this->finish)($id, ['limit' => 1]);
    expect($done['readiness']['last_complete_counts']['customer'])->toBe(2)
        ->and(SubscriptionRechargeResource::query()->where('generation_id', $done['readiness']['last_complete_generation_id'])->where('resource_type', 'customer')->count())->toBe(2);
});

test('expired slice cannot publish stale evidence and transient UUID retry uses its original durable position', function () {
    rechargeFake();
    $id = ($this->verify)()['readiness']['connection_id'];
    $uuid = (string) Str::uuid();
    rechargeFake(null, fn ($path) => $path === 'customers' ? Http::response('temporary', 503) : null);
    expect(($this->sync)($id, [], $uuid)['status'])->toBe('failed');
    rechargeFake();
    expect(($this->sync)($id, [], $uuid)['status'])->toBe('completed');
    $next = (string) Str::uuid();
    rechargeFake(null, function ($path, $query, $data) {
        if ($path === 'addresses') {
            DB::table('subscription_recharge_operations')->where('status', 'reading')->update(['lease_expires_at' => now()->subMinute()]);
        }

        return $data;
    });
    $result = ($this->sync)($id, [], $next);
    expect($result['status'])->toBe('reading')->and(SubscriptionRechargeResource::query()->where('resource_type', 'address')->count())->toBe(0)
        ->and($result['readiness']['last_complete_generation_id'])->toBeNull();
});

test('malformed scalar or unmasked payment data cannot reach encrypted captures or operation results', function (string $defect) {
    $fixtures = rechargeShadowFixtures();
    if ($defect === 'scalar') {
        $fixtures['customers'][0]['first_name'] = ['processor_payment_method_token' => 'PRIVATE_CANARY_DO_NOT_PERSIST'];
    }
    if ($defect === 'last4') {
        $fixtures['payment_methods'][0]['payment_details']['last4'] = 'PRIVATE_CANARY_DO_NOT_PERSIST';
    }
    rechargeFake($fixtures);
    $id = ($this->verify)()['readiness']['connection_id'];
    for ($i = 0; $i < 4; $i++) {
        $result = ($this->sync)($id);
        if ($result['status'] === 'failed') {
            break;
        }
    }
    expect($result['status'])->toBe('failed')->and($result['readiness']['last_complete_generation_id'])->toBeNull();
    foreach (SubscriptionRechargeResource::query()->get() as $row) {
        expect(json_encode($row->snapshot))->not->toContain('PRIVATE_CANARY_DO_NOT_PERSIST');
    }
    foreach (SubscriptionRechargeOperation::query()->get() as $row) {
        expect(json_encode($row->result))->not->toContain('PRIVATE_CANARY_DO_NOT_PERSIST');
    }
})->with(['scalar', 'last4']);

test('slower old verification cannot rotate a newer credential back or replace newer scopes', function (bool $sameToken) {
    rechargeFake();
    $id = ($this->verify)()['readiness']['connection_id'];
    $new = IntegrationConnection::query()->create(['tenant_id' => $this->tenant->id, 'provider' => 'recharge', 'status' => 'pending', 'external_account_id' => 'new-draft', 'access_token' => $sameToken ? 'PRIVATE_RECHARGE_TOKEN' : 'NEW_PRIVATE_TOKEN']);
    $interleaved = false;
    $inside = false;
    rechargeFake(null, function ($path, $query, $data) use ($new, $sameToken, &$interleaved, &$inside) {
        if ($path === 'token_information' && ! $interleaved) {
            $interleaved = true;
            $inside = true;
            expect($this->service->verifyConnection($this->tenant->id, $this->actor, $new->id, rechargeExpected(), (string) Str::uuid())['status'])->toBe('completed');
            $inside = false;
        }
        if ($path === 'token_information' && $inside && $sameToken) {
            $data['token_information']['scopes'] = ['read_store'];
        }

        return $data;
    });
    expect(fn () => ($this->verify)())->toThrow(HttpException::class);
    $connection = SubscriptionRechargeConnection::query()->find($id);
    $active = SubscriptionRechargeCredentialRevision::query()->find($connection->active_credential_revision_id);
    expect($active->credentials['access_token'])->toBe($sameToken ? 'PRIVATE_RECHARGE_TOKEN' : 'NEW_PRIVATE_TOKEN');
    if ($sameToken) {
        expect($this->service->readiness($this->tenant->id, $id)['scopes_ready'])->toBeFalse();
    }
})->with([false, true]);

test('a newer same-token scope verification fences an older in-flight inventory slice', function () {
    rechargeFake();
    $id = ($this->verify)()['readiness']['connection_id'];
    $interleaved = false;
    $inside = false;
    rechargeFake(null, function ($path, $query, $data) use (&$interleaved, &$inside) {
        if ($path === 'customers' && ! $interleaved) {
            $interleaved = true;
            $inside = true;
            expect(($this->verify)()['status'])->toBe('completed');
            $inside = false;
        }
        if ($path === 'token_information' && $inside) {
            $data['token_information']['scopes'] = ['read_store'];
        }

        return $data;
    });
    $result = ($this->sync)($id);
    expect($result['failure_code'])->toBe('recharge_verification_superseded')->and($result['readiness']['scopes_ready'])->toBeFalse()
        ->and(SubscriptionRechargeResource::query()->count())->toBe(0);
});

test('all source statuses and documented nested and nullable fields stay source faithful', function () {
    $fixtures = rechargeShadowFixtures();
    foreach (['cancelled', 'expired'] as $offset => $status) {
        $row = $fixtures['subscriptions'][0];
        $row['id'] = 302 + $offset;
        $row['status'] = $status;
        $fixtures['subscriptions'][] = $row;
    }
    $fixtures['customers'][0]['subscriptions_total_count'] = 3;
    $inactive = $fixtures['customers'][0];
    $inactive['id'] = 102;
    $inactive['subscriptions_total_count'] = 0;
    $inactive['subscriptions_active_count'] = 0;
    $fixtures['customers'][] = $inactive;
    $fixtures['addresses'][0]['shipping_lines_override'] = [['code' => 'Standard', 'price' => '4.900', 'title' => 'Standard']];
    $fixtures['addresses'][0]['shipping_lines_conserved'] = null;
    $fixtures['subscriptions'][0]['charge_interval_frequency'] = '30';
    $fixtures['payment_methods'][0]['payment_details'] = null;
    rechargeFake($fixtures);
    $id = ($this->verify)()['readiness']['connection_id'];
    $done = ($this->finish)($id);
    $generation = $done['readiness']['last_complete_generation_id'];
    expect($done['readiness']['last_complete_counts']['subscription'])->toBe(3)->and($done['readiness']['last_complete_counts']['customer'])->toBe(2)
        ->and(SubscriptionRechargeResource::query()->where('generation_id', $generation)->where('resource_type', 'subscription')->where('provider_id', '301')->sole()->snapshot['charge_interval_frequency'])->toBe('30')
        ->and(SubscriptionRechargeResource::query()->where('generation_id', $generation)->where('resource_type', 'address')->sole()->snapshot['shipping_lines_conserved'])->toBeNull()
        ->and(SubscriptionRechargeResource::query()->where('generation_id', $generation)->where('resource_type', 'payment_method')->sole()->snapshot['payment_details'])->toBeNull();
});

test('large shadow reconciliation resumes in local hundred-row batches with no HTTP and no premature accepted count', function () {
    $fixtures = rechargeShadowFixtures();
    $base = $fixtures['customers'][0];
    $base['subscriptions_total_count'] = 0;
    $base['subscriptions_active_count'] = 0;
    $fixtures['customers'] = array_map(function ($id) use ($base) {
        return array_replace($base, ['id' => $id]);
    }, range(1001, 1205));
    $fixtures['addresses'] = [];
    $fixtures['subscriptions'] = [];
    $fixtures['payment_methods'] = [];
    rechargeFake($fixtures);
    $id = ($this->verify)()['readiness']['connection_id'];
    for ($i = 0; $i < 30; $i++) {
        $result = ($this->sync)($id);
        expect($result['status'])->toBe('completed');
        if (($result['readiness']['sync_progress']['phase'] ?? null) === 'reconcile') {
            break;
        }
    }
    $httpCount = count(Http::recorded());
    $this->travel(2)->hours();
    $one = ($this->sync)($id);
    $two = ($this->sync)($id);
    expect($one['sync_complete'])->toBeFalse()->and($two['sync_complete'])->toBeFalse()->and($two['readiness']['last_complete_counts'])->toBeNull();
    $last = ($this->sync)($id);
    expect($last['sync_complete'])->toBeTrue()->and($last['readiness']['last_complete_counts']['customer'])->toBe(205)
        ->and($last['readiness']['freshness'])->toBe('stale')->and(count(Http::recorded()))->toBe($httpCount);
});

test('operator CLI is offline for readiness and captures one slice without enabling anything', function () {
    rechargeFake();
    $id = ($this->verify)()['readiness']['connection_id'];
    $count = count(Http::recorded());
    $this->artisan('subscriptions:recharge-shadow', ['action' => 'readiness', '--tenant' => (string) $this->tenant->id, '--actor' => (string) $this->actor->id, '--connection' => (string) $id])->assertSuccessful();
    expect(count(Http::recorded()))->toBe($count);
    $this->artisan('subscriptions:recharge-shadow', ['action' => 'capture', '--tenant' => (string) $this->tenant->id, '--actor' => (string) $this->actor->id, '--connection' => (string) $id, '--request' => (string) Str::uuid()])->assertSuccessful();
    expect($this->service->readiness($this->tenant->id, $id)['last_complete_counts'])->toBeNull();
    $this->artisan('subscriptions:recharge-shadow', ['action' => 'capture'])->assertFailed();
});
