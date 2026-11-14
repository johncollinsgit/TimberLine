<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\TenantSiteDomain;
use App\Models\User;
use App\Support\Tenancy\TenantHostBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManagedWebsiteDomainService
{
    public function __construct(private readonly TenantHostBuilder $hosts, private readonly CloudflareWebsiteDomains $cloudflare) {}

    public function enabled(): bool
    {
        return (bool) config('managed_website.custom_domains_enabled', false);
    }

    public function enabledFor(Tenant $tenant): bool
    {
        return $this->enabled()
            && (in_array((int) $tenant->id, (array) config('managed_website.custom_domain_tenant_ids', []), true)
                || app(ManagedWebsiteSelfServicePolicy::class)->eligible($tenant));
    }

    public function activationEnabledFor(Tenant $tenant): bool
    {
        return $this->enabledFor($tenant)
            && (bool) config('managed_website.custom_domain_activation_enabled', false);
    }

    public function connectionTarget(): string
    {
        return (string) config('managed_website.custom_domain_target', '');
    }

    public function publicUrl(TenantSite $site): string
    {
        $active = $site->relationLoaded('domains')
            ? $site->domains->first(fn (TenantSiteDomain $domain): bool => $domain->status === 'active' && $domain->is_primary)
            : $site->domains()->where('status', 'active')->where('is_primary', true)->first();
        if ($active instanceof TenantSiteDomain) {
            return 'https://'.$active->hostname;
        }

        return $this->platformUrl($site);
    }

    /**
     * Every Website receives this first-party address as soon as its site
     * record exists. Wildcard DNS and host-based tenant resolution mean no
     * per-customer DNS work is required; publishing is the only go-live step.
     */
    public function platformUrl(TenantSite $site): string
    {
        return (string) $this->hosts->urlForHostPath($this->hosts->hostForSlug((string) $site->subdomain), '/');
    }

    /** @return array<int,array{type:string,name:string,value:string,label:string}> */
    public function connectionRecords(TenantSiteDomain $domain): array
    {
        $records = [[
            'type' => 'TXT',
            'name' => $this->verificationRecordName($domain->hostname),
            'value' => $this->verificationValue($domain),
            'label' => 'Ownership verification',
        ]];

        $target = $this->connectionTarget();
        if ($target !== '') {
            $records[] = [
                'type' => 'CNAME',
                'name' => $domain->hostname,
                'value' => $target,
                'label' => 'Website routing',
            ];
        }

        return $records;
    }

    public function request(TenantSite $site, string $input, ?User $actor): TenantSiteDomain
    {
        $hostname = $this->normalizeHostname($input);
        if ($this->isReservedHost($hostname)) {
            throw ValidationException::withMessages(['domain' => 'That address is reserved for Everbranch and cannot be connected to a customer site.']);
        }

        $existing = TenantSiteDomain::query()->where('hostname', $hostname)->first();
        if ($existing && (int) $existing->tenant_site_id !== (int) $site->id) {
            throw ValidationException::withMessages(['domain' => 'That domain is already connected to another website.']);
        }

        // Retrying an in-progress request must not invalidate the DNS proof the
        // customer just entered or disconnect a currently published domain.
        if ($existing && $existing->status !== 'disabled') {
            return $existing;
        }

        return DB::transaction(function () use ($site, $hostname, $actor, $existing): TenantSiteDomain {
            $domain = $existing ?: new TenantSiteDomain([
                'tenant_id' => $site->tenant_id,
                'tenant_site_id' => $site->id,
                'hostname' => $hostname,
                'is_primary' => ! $site->domains()->where('is_primary', true)->exists(),
                'created_by_user_id' => $actor?->id,
            ]);

            // Regenerating the proof makes a restarted connection safe and
            // prevents an old TXT value from being replayed after a domain moves.
            $domain->forceFill([
                'status' => 'pending',
                'verification_token' => Str::random(48),
                'verification_checked_at' => null,
                'verified_at' => null,
                'activated_at' => null,
                'last_error' => null,
                'updated_by_user_id' => $actor?->id,
            ])->save();

            app(ManagedWebsiteService::class)->recordEvent($site, null, $actor, 'domain.requested', [
                'hostname' => $hostname,
            ]);

            return $domain->fresh();
        });
    }

    public function verify(TenantSiteDomain $domain, ?User $actor): TenantSiteDomain
    {
        $domain->loadMissing('site');
        abort_if(in_array($domain->status, ['active', 'disabled'], true), 422, 'This domain is not waiting for connection.');
        $verificationToken = (string) $domain->verification_token;
        $recordName = $this->verificationRecordName($domain->hostname);
        $expected = $this->verificationValue($domain);
        $records = $this->txtRecords($recordName);
        $verified = in_array($expected, $records, true);
        $edge = $verified ? $this->cloudflare->check($domain->hostname, true) : null;

        // DNS and provider calls stay outside the transaction. Their result may
        // only update the same still-pending proof, never a canceled/restarted setup.
        $domain = DB::transaction(function () use ($domain, $verificationToken, $verified, $edge, $actor): TenantSiteDomain {
            $current = TenantSiteDomain::query()->where('tenant_id', $domain->tenant_id)->whereKey($domain->id)->lockForUpdate()->firstOrFail();
            if (! in_array($current->status, ['pending', 'verified'], true)
                || ! hash_equals($verificationToken, (string) $current->verification_token)) {
                return $current;
            }
            $before = [$current->status, $current->last_error];
            $current->forceFill([
                'verification_checked_at' => now(),
                'status' => $verified ? 'verified' : 'pending',
                'verified_at' => $verified ? now() : null,
                'last_error' => $verified ? ($edge['ready'] ? null : $edge['message']) : 'We could not find the Everbranch verification TXT record yet. DNS changes can take a few minutes.',
                'updated_by_user_id' => $actor?->id,
            ])->save();
            if ($before !== [$current->status, $current->last_error]) {
                app(ManagedWebsiteService::class)->recordEvent($current->site, null, $actor, $verified ? 'domain.verified' : 'domain.verification_pending', [
                    'hostname' => $current->hostname,
                ]);
            }

            return $current;
        });

        return $domain->fresh();
    }

    public function activate(TenantSiteDomain $domain, ?User $actor): TenantSiteDomain
    {
        $domain->loadMissing('site');
        if (! $this->activationEnabledFor($domain->site->tenant)) {
            abort(423, 'Custom domains are not enabled for this website yet.');
        }
        abort_unless($domain->status === 'verified', 422, 'Verify ownership before activating this domain.');
        abort_unless($domain->site->status === 'published' && $domain->site->public_enabled, 422, 'Publish the website before activating a custom domain.');
        // Recheck proof and provider readiness now. A stale "verified" row cannot
        // activate a hostname whose DNS or certificate is no longer valid.
        if (! in_array($this->verificationValue($domain), $this->txtRecords($this->verificationRecordName($domain->hostname)), true)) {
            throw ValidationException::withMessages(['domain' => 'The ownership record is missing. Restore it and check the connection again.']);
        }
        if ($this->connectionTarget() === '' || ! in_array(strtolower(rtrim($this->connectionTarget(), '.')), $this->cnameRecords($domain->hostname), true)) {
            throw ValidationException::withMessages(['domain' => 'The website record does not point to Everbranch yet. Check the CNAME record, or ask us to handle the connection.']);
        }
        $edge = $this->cloudflare->check($domain->hostname);
        if (! $edge['ready']) {
            $domain->forceFill(['last_error' => $edge['message'], 'verification_checked_at' => now()])->save();
            throw ValidationException::withMessages(['domain' => $edge['message']]);
        }

        DB::transaction(function () use ($domain, $actor): void {
            $current = TenantSiteDomain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->status === 'verified' && hash_equals((string) $domain->verification_token, (string) $current->verification_token), 409, 'Domain setup changed. Check the connection again.');
            $domain = $current;
            TenantSiteDomain::query()->where('tenant_site_id', $domain->tenant_site_id)->update(['is_primary' => false]);
            $domain->refresh();
            $domain->forceFill([
                'status' => 'active',
                'is_primary' => true,
                'activated_at' => now(),
                'last_error' => null,
                'updated_by_user_id' => $actor?->id,
            ])->save();
            app(ManagedWebsiteService::class)->recordEvent($domain->site, null, $actor, 'domain.activated', ['hostname' => $domain->hostname]);
        });

        return $domain->fresh();
    }

    /** Recover customer-requested connections after DNS/certificate propagation. */
    public function reconcile(TenantSiteDomain $domain): TenantSiteDomain
    {
        $domain->loadMissing('site.tenant');
        $tenant = $domain->site?->tenant;
        if (! $tenant || ! in_array($domain->status, ['pending', 'verified'], true)
            || ! $this->enabledFor($tenant)
            || ! app(ManagedWebsiteSelfServicePolicy::class)->eligible($tenant)) {
            return $domain;
        }
        $websites = app(ManagedWebsiteService::class);
        if (! $websites->editorEnabledFor($tenant) || ! $websites->publishingEnabled() || ! $websites->publicRenderingEnabled()) {
            // Autonomous connection is a new public-host publication. The
            // scheduler must honor the same operational freezes as the UI.
            return $domain;
        }

        $domain = $this->verify($domain, null);
        if ($domain->status === 'verified' && ! $domain->last_error
            && $domain->site->status === 'published' && $domain->site->public_enabled
            && $this->activationEnabledFor($tenant)) {
            try {
                return $this->activate($domain, null);
            } catch (ValidationException $exception) {
                $domain->forceFill(['last_error' => (string) collect($exception->errors())->flatten()->first()])->save();
            }
        }

        return $domain->fresh();
    }

    public function deactivate(TenantSiteDomain $domain, ?User $actor): void
    {
        $domain->loadMissing('site');
        $domain->forceFill([
            'status' => 'disabled',
            'is_primary' => false,
            'activated_at' => null,
            'updated_by_user_id' => $actor?->id,
        ])->save();
        app(ManagedWebsiteService::class)->recordEvent($domain->site, null, $actor, 'domain.deactivated', ['hostname' => $domain->hostname]);
    }

    /**
     * End an unactivated domain setup without deleting its audit trail. A user can
     * immediately enter a different address; a later request for this hostname
     * receives a fresh, single-use verification token.
     */
    public function cancel(TenantSiteDomain $domain, ?User $actor): void
    {
        abort_if($domain->status === 'active', 422, 'Use the live-domain control to disable an active website address.');

        $domain->loadMissing('site');
        $domain->forceFill([
            'status' => 'disabled',
            'is_primary' => false,
            'activated_at' => null,
            'last_error' => null,
            'updated_by_user_id' => $actor?->id,
        ])->save();

        app(ManagedWebsiteService::class)->recordEvent($domain->site, null, $actor, 'domain.setup_cancelled', ['hostname' => $domain->hostname]);
    }

    public function tenantForActiveHost(string $host): ?Tenant
    {
        if (! $this->enabled()) {
            return null;
        }

        $hostname = $this->normalizeHostname($host, false);
        if ($hostname === null || $this->isReservedHost($hostname)) {
            return null;
        }

        $domain = TenantSiteDomain::query()
            ->where('hostname', $hostname)
            ->where('status', 'active')
            ->with(['site.tenant'])
            ->first();

        if (! $domain || ! $domain->site || ! $domain->site->public_enabled || $domain->site->status !== 'published'
            || ! (in_array((int) $domain->site->tenant_id, (array) config('managed_website.custom_domain_tenant_ids', []), true)
                || app(ManagedWebsiteSelfServicePolicy::class)->registered($domain->site->tenant))
            || ! app(ManagedWebsiteSelfServicePolicy::class)->publicAccessAllowed($domain->site->tenant)) {
            return null;
        }

        return $domain->site->tenant;
    }

    public function isPlatformHost(string $host): bool
    {
        $hostname = $this->normalizeHostname($host, false);

        return $hostname !== null && $this->isReservedHost($hostname);
    }

    public function verificationRecordName(string $hostname): string
    {
        return '_everbranch-verify.'.$hostname;
    }

    public function verificationValue(TenantSiteDomain $domain): string
    {
        return 'everbranch-site='.$domain->verification_token;
    }

    public function normalizeHostname(string $value, bool $throw = true): ?string
    {
        $value = strtolower(trim($value));
        if (str_contains($value, '://')) {
            $parts = parse_url($value);
            $path = trim((string) ($parts['path'] ?? ''), '/');
            if (! is_array($parts) || $path !== '' || array_intersect(['query', 'fragment', 'port', 'user', 'pass'], array_keys($parts)) !== []) {
                return $this->invalid($throw);
            }
            $value = (string) ($parts['host'] ?? '');
        }
        $value = rtrim($value, '.');
        if ($value === '' || strlen($value) > 253 || filter_var($value, FILTER_VALIDATE_IP) || ! str_contains($value, '.') || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value) !== 1) {
            return $this->invalid($throw);
        }

        return $value;
    }

    /** @return array<int,string> */
    protected function txtRecords(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);
        if (! is_array($records)) {
            return [];
        }

        return collect($records)
            ->map(static fn (array $record): string => trim((string) ($record['txt'] ?? '')))
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<int,string> */
    protected function cnameRecords(string $name): array
    {
        $records = @dns_get_record($name, DNS_CNAME);

        return collect(is_array($records) ? $records : [])
            ->map(static fn (array $record): string => strtolower(rtrim((string) ($record['target'] ?? ''), '.')))
            ->filter()->values()->all();
    }

    protected function isReservedHost(string $hostname): bool
    {
        $reserved = [
            config('tenancy.domains.canonical.public_host'),
            config('tenancy.domains.canonical.landlord_host'),
            config('tenancy.landlord.primary_host'),
            ...((array) config('tenancy.landlord.hosts', [])),
            ...((array) config('tenancy.auth.flagship_hosts', [])),
            ...array_keys((array) config('tenancy.auth.host_map', [])),
            ...((array) config('evergrove.hosts', [])),
        ];
        foreach ($reserved as $candidate) {
            $normal = $this->normalizeHostname((string) $candidate, false);
            if ($normal !== null && $normal === $hostname) {
                return true;
            }
        }

        $base = $this->normalizeHostname((string) config('tenancy.domains.canonical.base_domain'), false);

        return $base !== null && ($hostname === $base || str_ends_with($hostname, '.'.$base));
    }

    protected function invalid(bool $throw): ?string
    {
        if ($throw) {
            throw ValidationException::withMessages(['domain' => 'Enter a domain only, such as example.com. Do not include a page, port, or login link.']);
        }

        return null;
    }
}
