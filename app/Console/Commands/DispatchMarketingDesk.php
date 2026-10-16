<?php

namespace App\Console\Commands;

use App\Jobs\MarketingDesk\PublishSocialPost;
use App\Models\MarketingSocialPost;
use App\Models\MarketingSocialPublication;
use App\Services\Marketing\Desk\MarketingDeskAccess;
use App\Services\Marketing\Desk\MarketingSignalService;
use Illuminate\Console\Command;

class DispatchMarketingDesk extends Command
{
    protected $signature = 'marketing:dispatch-desk {--limit=100}';

    protected $description = 'Queue approved social publications and verified inactivity milestones for enabled workspaces';

    public function handle(): int
    {
        foreach ((array) config('marketing_desk.tenant_ids') as $tenantId) {
            if (! app(MarketingDeskAccess::class)->available($tenantId)) {
                continue;
            }
            MarketingSocialPublication::forTenantId($tenantId)->whereIn('status', ['preparing', 'publishing'])->where('updated_at', '<', now()->subMinutes(5))->update(['status' => 'unknown', 'failure_code' => 'interrupted_provider_attempt']);
            if (! config('marketing_desk.delivery_enabled')) {
                continue;
            }
            app(MarketingSignalService::class)->scanInactive($tenantId);
            if (! config('marketing_desk.meta.execution_enabled')) {
                continue;
            }
            $connectionIds = \App\Models\IntegrationConnection::forTenantId($tenantId)->where('status', 'connected')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->select('id');
            $posts = MarketingSocialPost::forTenantId($tenantId)->where('status', 'scheduled')->where('scheduled_at', '<=', now())->select('id');
            MarketingSocialPublication::forTenantId($tenantId)->whereIn('status', ['pending', 'prepared', 'processing'])->whereIn('post_id', $posts)->whereIn('connection_id', $connectionIds)->orderBy('id')->limit(max(1, min(500, (int) $this->option('limit'))))->get()->each(fn ($publication) => PublishSocialPost::dispatch($tenantId, $publication->id));
        }

        return self::SUCCESS;
    }
}
