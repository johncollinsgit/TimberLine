<?php

namespace App\Jobs\MarketingDesk;

use App\Services\Marketing\Desk\SocialPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishSocialPost implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $tenantId, public int $publicationId) {}

    public function handle(SocialPublisher $publisher): void
    {
        $publisher->run($this->tenantId, $this->publicationId);
    }
}
