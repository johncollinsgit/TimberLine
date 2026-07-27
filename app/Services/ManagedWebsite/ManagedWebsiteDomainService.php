<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\TenantSiteDomain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManagedWebsiteDomainService
{
    public function enabled(): bool
    {
        return (bool) config('managed_website.custom_domains_enabled', false);
    }

    public function enabledFor(Tenant $tenant): bool
    {
        return $this->enabled()
            && in_array((int) $tenant->id, (array) config('managed_website.custom_domain_tenant_ids', []), true);
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

        $base = trim((string) config('tenancy.domains.canonical.base_domain', 'theeverbranch.com'), '.');

        return 'https://'.trim((string) $site->subdomain).'.'.$base;
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
        $recordName = $this->verificationRecordName($domain->hostname);
        $expected = $this->verificationValue($domain);
        $records = $this->txtRecords($recordName);
        $verified = in_array($expected, $records, true);

        $domain->forceFill([
            'verification_checked_at' => now(),
            'status' => $verified ? 'verified' : 'pending',
            'verified_at' => $verified ? now() : null,
            'last_error' => $verified ? null : 'We could not find the Everbranch verification TXT record yet. DNS changes can take a few minutes.',
            'updated_by_user_id' => $actor?->id,
        ])->save();

        app(ManagedWebsiteService::class)->recordEvent($domain->site, null, $actor, $verified ? 'domain.verified' : 'domain.verification_pending', [
            'hostname' => $domain->hostname,
        ]);

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

        DB::transaction(function () use ($domain, $actor): void {
            TenantSiteDomain::query()->where('tenant_site_id', $domain->tenant_site_id)->update(['is_primary' => false]);
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

        if (! $domain || ! $domain->site || ! $domain->site->public_enabled || $domain->site->status !== 'published') {
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
            if (! is_array($parts) || $path !== '' || isset($parts['query'], $parts['fragment'], $parts['port'], $parts['user'], $parts['pass'])) {
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
