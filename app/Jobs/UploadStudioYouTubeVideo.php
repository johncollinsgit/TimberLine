<?php

namespace App\Jobs;

use App\Models\IntegrationConnection;
use App\Models\StudioEpisodeDelivery;
use App\Services\ManagedWebsite\StudioEpisodeFeed;
use App\Services\ManagedWebsite\StudioYouTubeUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class UploadStudioYouTubeVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 145;

    public array $backoff = [10, 30, 60, 120];

    public function __construct(public int $tenant, public string $receipt)
    {
        $this->onConnection('studio_distribution')->onQueue('studio-distribution');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->receipt))->releaseAfter(5)->expireAfter(200)];
    }

    public function handle(StudioEpisodeFeed $feed, StudioYouTubeUpload $youtube): void
    {
        $delivery = StudioEpisodeDelivery::forTenantId($this->tenant)->where('destination', 'youtube')->findOrFail($this->receipt);
        if (! in_array($delivery->status, ['queued', 'uploading'], true)) {
            return;
        }
        $details = $delivery->details;
        $connection = IntegrationConnection::forTenantId($this->tenant)->where('provider', 'youtube_studio')->where('status', 'connected')->findOrFail($details['channel']);
        [, $asset] = $feed->asset($this->tenant, $details['episode'], $details['revision'], $details['asset']);
        try {
            $finished = $youtube->transfer($delivery, $connection, $asset);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            // Provider session URLs must stay out of failed-job diagnostics.
            throw new \RuntimeException('YouTube transfer interrupted. The worker can resume the existing session.');
        }
        if (! $finished) {
            self::dispatch($this->tenant, $this->receipt)->delay(now()->addSeconds(2));
        }
    }

    public function failed(?Throwable $error): void
    {
        StudioEpisodeDelivery::forTenantId($this->tenant)->whereKey($this->receipt)->whereIn('status', ['queued', 'uploading'])->update(['status' => 'interrupted']);
    }
}
