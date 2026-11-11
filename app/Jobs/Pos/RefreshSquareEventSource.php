<?php

namespace App\Jobs\Pos;

use App\Services\Marketing\SquareSourceRefresh;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Verified provider notification; original reader/policy is checked again before source-only import. */
class RefreshSquareEventSource implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public int $tenant, public array $binding, public string $kind, public string $providerId,
        public string $eventId, public string $runtime)
    {
        $connection = (string) config('pos_live.external_sales_queue_connection', 'database');
        abort_unless(in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true), 503, 'Square history needs its asynchronous source queue.');
        $this->onConnection($connection);
        $this->onQueue('event-sales-history');
    }

    public function uniqueId(): string
    {
        return hash('sha256', json_encode([$this->runtime, $this->tenant, $this->binding, $this->kind, $this->providerId, $this->eventId], JSON_THROW_ON_ERROR));
    }

    public function backoff(): array
    {
        return [2, 5, 15];
    }

    public function requestId(): string
    {
        $hash = $this->uniqueId();

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-5'.substr($hash, 13, 3).'-8'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }

    public function handle(SquareSourceRefresh $refresh): void
    {
        // A serialized job cannot cross into a replacement runtime after deployment/configuration change.
        abort_unless(hash_equals($this->runtime, (string) config('pos_live.runtime_id')) && config('pos_live.external_sales_ingest_enabled') === true, 409);
        $refresh->refreshObject($this->tenant, $this->binding, $this->kind, $this->providerId, $this->requestId());
    }

    public function failed(\Throwable $error): void
    {
        Log::warning('square event source refresh requires review', ['tenant_id' => $this->tenant, 'source_reference' => $this->uniqueId(), 'error_class' => get_class($error)]);
    }
}
