<?php

namespace App\Jobs;

use App\Models\HighLevel\WebhookEvent;
use App\Services\HighLevel\BouncieWebhookService;
use App\Services\HighLevel\LifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessHighLevelWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 120;

    public function __construct(public readonly int $eventId) {}

    public function backoff(): array
    {
        return [15, 60, 120, 300, 600, 900, 1800];
    }

    public function handle(): void
    {
        Cache::lock('hl:event:'.$this->eventId, 150)->block(5, function (): void {
            $event = WebhookEvent::findOrFail($this->eventId);
            if ($event->processed_at || ! $event->payload) {
                return;
            }
            $event->increment('attempts');
            try {
                if ($event->provider === 'highlevel') {
                    app(LifecycleService::class)->handle($event->payload, $event->received_at);
                } else {
                    app(BouncieWebhookService::class)->process($event->payload);
                }
                // Keep identifiers and outcome for audit/dedup; clear raw GPS and
                // provider personal data as soon as processing completes.
                $event->update(['processed_at' => now(), 'payload' => null, 'error_code' => null]);
            } catch (Throwable $error) {
                $event->update(['error_code' => 'processing_failed']);
                // Provider exceptions may contain credentials or raw GPS. Do not
                // allow the default failed-job reporter to serialize them.
                throw new \RuntimeException('HighLevel Fleet webhook processing failed; see event '.$event->id.'.');
            }
        });
    }
}
