<?php

namespace App\Console\Commands;

use App\Models\TenantSiteDomain;
use App\Services\ManagedWebsite\CloudflareWebsiteDomains;
use App\Services\ManagedWebsite\ManagedWebsiteDomainService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ReconcileWebsiteDomains extends Command
{
    protected $signature = 'website:reconcile-domains {--limit=20 : Maximum pending domains to check}';

    protected $description = 'Recover paid self-service domain connections after DNS and TLS propagation';

    public function handle(ManagedWebsiteDomainService $domains, CloudflareWebsiteDomains $cloudflare): int
    {
        if (! $domains->enabled() || ! $cloudflare->ready() || ! Schema::hasTable('tenant_site_domains')) {
            $this->info('Automatic domain checks are waiting for provider readiness.');

            return self::SUCCESS;
        }

        $candidates = TenantSiteDomain::withoutGlobalScopes()->whereIn('status', ['pending', 'verified'])
            ->where(fn ($query) => $query->whereNull('verification_checked_at')->orWhere('verification_checked_at', '<=', now()->subMinute()))
            ->orderBy('verification_checked_at')->orderBy('id')
            ->limit(max(1, min(100, (int) $this->option('limit'))))->get();
        $connected = 0;
        foreach ($candidates as $domain) {
            Cache::lock('website-domain:'.$domain->id, 120)->get(function () use ($domain, $domains, &$connected): void {
                try {
                    $result = $domains->reconcile($domain->fresh());
                    if ($result->status === 'active') {
                        $connected++;
                    }
                } catch (\Throwable $exception) {
                    Log::warning('Website domain connection deferred.', ['domain_id' => $domain->id, 'exception_type' => $exception::class]);
                } finally {
                    // Rotate skipped/revoked tenants too; one old unapproved
                    // domain cannot starve the rest of the pending queue.
                    $domain->forceFill(['verification_checked_at' => now()])->save();
                }
            });
        }
        $this->info('Checked '.$candidates->count().' pending domains; '.$connected.' connected.');

        return self::SUCCESS;
    }
}
