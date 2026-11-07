<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Tenant;
use App\Services\Marketing\CustomerAccountFence;
use App\Services\Pos\PosException;
use App\Services\Pos\PosVerifiedCustomerAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\PosProcessRace;

uses(Tests\TestCase::class);

beforeEach(function () {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Explicit disposable MySQL fence acceptance only.');
    }
    if (! app()->environment('testing') || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1'
        || ! str_starts_with($db->getDatabaseName(), 'codex_teams_') || ! in_array($db->getConfig('host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Requires explicit local codex_teams_ fence acceptance.');
    }
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    Http::preventStrayRequests();
    Mail::fake();
});

test('MySQL fence wait cannot retain a consistent read view from before the committed writer', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Independent PHP processes required.');
    }
    $tenant = Tenant::create(['name' => 'Fictional fence merchant', 'slug' => 'fence-read-view-'.Str::lower(Str::random(12))]);
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional fence customer', 'notes' => 'before']);
    $write = fn () => app(CustomerAccountFence::class)->forTenant($tenant->id, function () use ($tenant, $profile) {
        DB::table('marketing_profiles')->where('tenant_id', $tenant->id)->where('id', $profile->id)->update(['notes' => 'committed']);

        return 'committed';
    });
    $read = fn () => app(CustomerAccountFence::class)->forTenant($tenant->id, fn () => DB::table('marketing_profiles')
        ->where('tenant_id', $tenant->id)->where('id', $profile->id)->value('notes'));
    $results = PosProcessRace::run($write, $read, fn ($query) => str_starts_with($query->sql, 'INSERT INTO customer_account_fences'));
    expect($results)->toBe(['committed', 'committed'])
        ->and(DB::table('customer_account_fences')->where('tenant_id', $tenant->id)->count())->toBe(1);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('MySQL missing fence schema fails closed without running work or losing retained rows', function () {
    $retained = 'fictional_fences_'.bin2hex(random_bytes(6));
    $before = DB::table('customer_account_fences')->count();
    $called = false;
    Schema::rename('customer_account_fences', $retained);
    try {
        expect(fn () => app(CustomerAccountFence::class)->forTenant(987654321, function () use (&$called) {
            $called = true;
        }))->toThrow(PosException::class, 'customer_account_fence_unavailable');
        expect($called)->toBeFalse()->and(Schema::hasTable('customer_account_fences'))->toBeFalse()
            ->and(DB::table($retained)->count())->toBe($before)->and(DB::transactionLevel())->toBe(0);
        expect(app(CustomerAccountFence::class)->mutate([], fn () => 'legacy_upgrade_write'))->toBe('legacy_upgrade_write');
    } finally {
        Schema::rename($retained, 'customer_account_fences');
    }
    expect(Schema::hasTable('customer_account_fences'))->toBeTrue()->and(DB::table('customer_account_fences')->count())->toBe($before);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('nested mapping mutation cannot ignore an account restored and verified while it waits for the fence', function (bool $restoreFirst, bool $nested) {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Independent PHP processes required.');
    }
    $tenant = Tenant::create(['name' => 'Fictional nested mapping merchant', 'slug' => 'fence-nested-'.Str::lower(Str::random(12))]);
    $verified = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional account owner', 'archived_at' => now()]);
    $other = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional conflicting importer']);
    $store = 'fictional_nested';
    $digits = '123456789';
    $gid = 'gid://shopify/Customer/'.$digits;
    $accounts = app(PosVerifiedCustomerAccount::class);
    $meta = ['verification_method' => 'reviewed_existing_customer_account', 'verified_profile_id' => (int) $verified->id,
        'verified_at' => now()->toIso8601String(), 'shopify_store_key' => $store, 'shopify_customer_gid' => $gid, 'shopify_customer_id' => $digits,
        'signature' => $accounts->signature($tenant->id, $verified->id, $store, $gid)];
    $restore = function () use ($tenant, $verified, $store, $digits, $meta) {
        try {
            return app(CustomerAccountFence::class)->forTenant($tenant->id, function () use ($tenant, $verified, $store, $digits, $meta) {
                $profile = MarketingProfile::forTenantId($tenant->id)->whereKey($verified->id)->firstOrFail();
                $profile->update(['archived_at' => null]);
                MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'shopify_customer',
                    'source_id' => $store.':'.$digits, 'source_meta' => $meta]);
                $claim = MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => PosVerifiedCustomerAccount::SOURCE,
                    'source_id' => $store.':'.$digits, 'source_meta' => $meta]);
                if (! app(PosVerifiedCustomerAccount::class)->verified($profile->fresh(), $claim->id)) {
                    throw new RuntimeException('The original reviewed account was not verified.');
                }

                return 'verified';
            });
        } catch (PosException $error) {
            if ($error->blocker !== 'customer_account_mapping_conflict') {
                throw $error;
            }

            return 'blocked';
        }
    };
    $import = function () use ($tenant, $other, $gid, $meta, $nested) {
        try {
            $write = function () use ($tenant, $other, $gid, $meta) {
                MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $other->id, 'source_type' => 'shopify_customer',
                    'source_id' => 'shopify:'.$gid, 'source_meta' => $meta]);

                return 'imported';
            };

            return $nested ? DB::transaction($write, 3) : $write();
        } catch (PosException $error) {
            if ($error->blocker !== 'customer_account_mapping_conflict') {
                throw $error;
            }

            return 'blocked';
        }
    };
    $results = PosProcessRace::run($restoreFirst ? $restore : $import, $restoreFirst ? $import : $restore,
        fn ($query) => str_starts_with($query->sql, 'INSERT INTO customer_account_fences'));
    expect($results)->toBe($restoreFirst ? ['verified', 'blocked'] : ['imported', 'blocked'])
        ->and(MarketingProfileLink::forTenantId($tenant->id)->where('source_type', 'shopify_customer')->where('marketing_profile_id', $other->id)->exists())->toBe(! $restoreFirst)
        ->and(MarketingProfileLink::forTenantId($tenant->id)->where('source_type', PosVerifiedCustomerAccount::SOURCE)->count())->toBe($restoreFirst ? 1 : 0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with([true, false])->with([true, false]);

test('nested mapping validation resolves every retained alias from current locked rows after a committed merge', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Independent PHP processes required.');
    }
    $tenant = Tenant::create(['name' => 'Fictional alias merchant', 'slug' => 'fence-alias-'.Str::lower(Str::random(12))]);
    $owner = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Reviewed survivor']);
    $before = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Old survivor']);
    $alias = MarketingProfile::create(['tenant_id' => $tenant->id, 'merged_into_profile_id' => $before->id, 'merged_at' => now()]);
    $store = 'fictional_alias';
    $digits = '987654321';
    $gid = 'gid://shopify/Customer/'.$digits;
    $meta = ['verification_method' => 'reviewed_existing_customer_account', 'verified_profile_id' => (int) $owner->id, 'verified_at' => now()->toIso8601String(),
        'shopify_store_key' => $store, 'shopify_customer_gid' => $gid, 'shopify_customer_id' => $digits,
        'signature' => app(PosVerifiedCustomerAccount::class)->signature($tenant->id, $owner->id, $store, $gid)];
    foreach (['shopify_customer', PosVerifiedCustomerAccount::SOURCE] as $source) {
        MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $owner->id, 'source_type' => $source, 'source_id' => $store.':'.$digits, 'source_meta' => $meta]);
    }
    $merge = fn () => app(CustomerAccountFence::class)->forTenant($tenant->id, function () use ($tenant, $before, $owner) {
        MarketingProfile::forTenantId($tenant->id)->whereKey($before->id)->firstOrFail()->update(['merged_into_profile_id' => $owner->id, 'merged_at' => now()]);

        return 'merged';
    });
    $import = fn () => DB::transaction(function () use ($tenant, $alias, $gid, $meta) {
        MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $alias->id, 'source_type' => 'shopify_customer', 'source_id' => 'shopify:'.$gid, 'source_meta' => $meta]);

        return 'linked_to_current_survivor';
    }, 3);
    $results = PosProcessRace::run($merge, $import, fn ($query) => str_starts_with($query->sql, 'INSERT INTO customer_account_fences'));
    expect($results)->toBe(['merged', 'linked_to_current_survivor']);
    $claim = MarketingProfileLink::forTenantId($tenant->id)->where('source_type', PosVerifiedCustomerAccount::SOURCE)->firstOrFail();
    expect(app(PosVerifiedCustomerAccount::class)->verified($owner->fresh(), $claim->id))->toBeTrue();
    Http::assertNothingSent();
    Mail::assertNothingSent();
});
