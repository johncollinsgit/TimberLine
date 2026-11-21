<?php

namespace App\Console\Commands;

use App\Services\ManagedWebsite\WebsiteDropshipOutbox;
use Illuminate\Console\Command;

class DispatchWebsiteDropshipDrafts extends Command
{
    protected $signature = 'website:dispatch-dropship-drafts {--limit=50 : Maximum due supplier handoffs to enqueue}';

    protected $description = 'Recover and enqueue automatic handoffs for opted-in Shopify suppliers';

    public function handle(WebsiteDropshipOutbox $outbox): int
    {
        $result = $outbox->scan(max(1, min(200, (int) $this->option('limit'))));
        $this->line(sprintf('%d queued, %d deferred, %d moved to review.', $result['queued'], $result['deferred'], $result['review']));

        return self::SUCCESS;
    }
}
