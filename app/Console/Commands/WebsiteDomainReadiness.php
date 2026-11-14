<?php

namespace App\Console\Commands;

use App\Services\ManagedWebsite\CloudflareWebsiteDomains;
use App\Services\ManagedWebsite\ManagedWebsiteDomainService;
use Illuminate\Console\Command;

class WebsiteDomainReadiness extends Command
{
    protected $signature = 'website:domain-readiness {--provider : Check Cloudflare fallback origin with a read-only API call}';

    protected $description = 'Report website domain readiness without exposing credentials or changing provider resources';

    public function handle(CloudflareWebsiteDomains $cloudflare, ManagedWebsiteDomainService $domains): int
    {
        $checks = [
            'editor_enabled' => (bool) config('managed_website.editor_enabled'),
            'publishing_enabled' => (bool) config('managed_website.publishing_enabled'),
            'public_render_enabled' => (bool) config('managed_website.public_render_enabled'),
            'custom_domains_enabled' => $domains->enabled(),
            'domain_activation_enabled' => (bool) config('managed_website.custom_domain_activation_enabled'),
            'domain_target_configured' => $domains->connectionTarget() !== '',
            'cloudflare_configured' => $cloudflare->ready(),
            'relay_enabled' => (bool) config('managed_website.relay.enabled', false),
            'relay_secret_configured' => strlen((string) config('managed_website.relay.secret', '')) >= 32,
        ];
        if ($this->option('provider')) {
            $checks['cloudflare_fallback_active'] = (bool) $cloudflare->infrastructureStatus()['fallback_active'];
        }
        $this->line(json_encode($checks, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
