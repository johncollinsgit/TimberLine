<?php

namespace App\Jobs;

use App\Services\Marketing\MarketingDirectMessagingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMarketingDirectMessageBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<int,array{
     *   profile_id:?int,
     *   name:?string,
     *   email:?string,
     *   phone:?string,
     *   normalized_phone:?string,
     *   source_type:string
     * }> $recipients
     * @param array<string,mixed> $options
     */
    public function __construct(
        public string $channel,
        public array $recipients,
        public string $message,
        public array $options = []
    ) {
    }

    public function handle(MarketingDirectMessagingService $service): void
    {
        $service->send(
            channel: $this->channel,
            recipients: $this->recipients,
            message: $this->message,
            options: $this->options,
        );
    }
}
