<?php

use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareCustomer;
use App\Services\Marketing\CustomerAccountFence;
use App\Services\Marketing\CustomerSourceOwnershipReconciliation;
use App\Services\Marketing\SquareCustomerIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PosProcessRace;

require_once __DIR__.'/../Support/TeamFixtures.php';

// A failed final audit must not turn independently committed link restores into one page transaction.
uses(Tests\Support\PosLiveTestCase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function ownershipAuditOriginalLink(int $tenant): MarketingProfileLink
{
    $profile = MarketingProfile::create(['tenant_id' => $tenant, 'first_name' => 'Fictional retained buyer', 'accepts_email_marketing' => false]);
    $source = SquareCustomer::create(['tenant_id' => $tenant, 'square_customer_id' => 'fictional-'.Str::uuid()]);

    return MarketingProfileLink::create(['marketing_profile_id' => $profile->id, 'source_type' => 'square_customer',
        'source_id' => $source->square_customer_id, 'source_meta' => ['original' => 'retained']]);
}

it('serializes the final MySQL ownership audit with source authorization before either foreign key can invert the locks', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Independent sessions on the explicitly disposable MySQL database required.');
    }
    [$tenant, $owner] = teamWorkspace();
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');

    $results = PosProcessRace::run(
        fn () => DB::transaction(function () use ($tenant, $owner): string {
            // The enabled refresh policy takes this same mutex before its current authorization.
            app(CustomerAccountFence::class)->lock($tenant->id);
            app(SquareCustomerIdentityService::class)->authorize($tenant->id, $owner);

            return 'source_authorized';
        }),
        function () use ($tenant, $owner): array {
            // No retained links: the old path went straight to the unfenced audit INSERT.
            $result = app(CustomerSourceOwnershipReconciliation::class)->batch($tenant->id, $owner, apply: true);

            return ['scanned' => $result['scanned'], 'restored' => $result['restored']];
        },
        fn ($query) => preg_match('/select.*from [`"]tenants[`"].*for update/is', $query->sql)
            && in_array($tenant->id, $query->bindings, true),
    );

    expect($results)->toBe(['source_authorized', ['scanned' => 0, 'restored' => 0]])
        ->and(MarketingImportRun::forTenantId($tenant->id)->where('type', 'customer_source_ownership')->count())->toBe(1);
    Http::assertNothingSent();
});

it('rechecks current membership for the final audit and retains the preceding committed link', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $link = ownershipAuditOriginalLink($tenant->id);
    $revoked = false;
    MarketingProfileLink::updated(function ($changed) use ($tenant, $owner, $link, &$revoked): void {
        if (! $revoked && $changed->id === $link->id && $changed->tenant_id === $tenant->id) {
            $revoked = true;
            DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $owner->id)->update(['membership_active' => false]);
        }
    });

    try {
        app(CustomerSourceOwnershipReconciliation::class)->batch($tenant->id, $owner, apply: true);
        $this->fail('Revoked membership must not authorize the final audit.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(403);
    }

    expect($revoked)->toBeTrue()->and(DB::transactionLevel())->toBe(0)
        ->and($link->fresh()->tenant_id)->toBe($tenant->id)
        ->and($link->fresh()->source_meta['original'])->toBe('retained')
        ->and(MarketingImportRun::forTenantId($tenant->id)->where('type', 'customer_source_ownership')->count())->toBe(0)
        ->and(DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $owner->id)->value('membership_active'))->toBeFalsy();
    Http::assertNothingSent();
});

it('keeps per-link commits after an interrupted final audit and replays without restoring the same source twice', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $link = ownershipAuditOriginalLink($tenant->id);
    $interrupted = true;
    MarketingImportRun::creating(function ($run) use ($tenant, &$interrupted): void {
        if ($interrupted && $run->tenant_id === $tenant->id && $run->type === 'customer_source_ownership') {
            throw new RuntimeException('fictional_final_audit_interruption');
        }
    });

    try {
        expect(fn () => app(CustomerSourceOwnershipReconciliation::class)->batch($tenant->id, $owner, apply: true))
            ->toThrow(RuntimeException::class, 'fictional_final_audit_interruption');
    } finally {
        $interrupted = false;
    }
    $committed = $link->fresh();
    expect(DB::transactionLevel())->toBe(0)->and($committed->tenant_id)->toBe($tenant->id)
        ->and($committed->source_meta['original'])->toBe('retained')
        ->and(MarketingImportRun::forTenantId($tenant->id)->where('type', 'customer_source_ownership')->count())->toBe(0);
    if (DB::connection()->getDriverName() === 'mysql') {
        config(['database.connections.ownership_audit_observer' => DB::connection()->getConfig()]);
        try {
            expect(DB::connection('ownership_audit_observer')->table('marketing_profile_links')->where('id', $link->id)->value('tenant_id'))->toBe($tenant->id);
        } finally {
            DB::purge('ownership_audit_observer');
        }
    }
    $result = app(CustomerSourceOwnershipReconciliation::class)->batch($tenant->id, $owner, apply: true);
    expect($result['restored'])->toBe(0)->and($result['scanned'])->toBe(0)
        ->and($link->fresh()->source_meta)->toBe($committed->source_meta)
        ->and(MarketingProfile::forTenantId($tenant->id)->findOrFail($committed->marketing_profile_id)->accepts_email_marketing)->toBeFalse()
        ->and(MarketingImportRun::forTenantId($tenant->id)->where('type', 'customer_source_ownership')->count())->toBe(1);
    Http::assertNothingSent();
});
