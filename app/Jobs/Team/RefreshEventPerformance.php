<?php

namespace App\Jobs\Team;

use App\Models\TeamArea;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Team\Studio\EventPerformance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshEventPerformance implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public int $tenant, public string $area, public int $actor, public string $runtime)
    {
        $connection = config('team_operations.performance_queue_connection', 'event_performance');
        abort_unless(in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true), 503, 'Event reporting needs an asynchronous queue.');
        $this->onConnection($connection);
        $this->onQueue('event-performance');
    }

    public function uniqueId(): string
    {
        return hash('sha256', implode('|', [$this->runtime, $this->tenant, $this->area, $this->actor]));
    }

    public function handle(EventPerformance $report): void
    {
        abort_unless(hash_equals($this->runtime, (string) config('pos_live.runtime_id', '')), 409);
        $report->rebuild(Tenant::findOrFail($this->tenant), User::findOrFail($this->actor), TeamArea::where('tenant_id', $this->tenant)->findOrFail($this->area));
    }
}
