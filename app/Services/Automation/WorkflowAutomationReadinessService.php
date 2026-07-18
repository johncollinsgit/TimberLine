<?php

namespace App\Services\Automation;

use App\Models\AutomationWorkflowRun;
use App\Services\SchedulerHeartbeatService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class WorkflowAutomationReadinessService
{
    public const QUEUE_HEARTBEAT_KEY = 'automation:queue:last_execution_at';

    public function __construct(protected SchedulerHeartbeatService $scheduler) {}

    /** @return array{ready:bool,checks:array<string,array{ready:bool,message:string}>} */
    public function evaluate(): array
    {
        $schedulerAge = $this->scheduler->minutesSinceHeartbeat();
        $queueAt = $this->queueHeartbeatAt();
        $schemaReady = collect([
            'automation_workflows',
            'automation_workflow_versions',
            'automation_workflow_runs',
            'automation_workflow_run_steps',
            'automation_workflow_audit_events',
            'automation_workflow_states',
            'automation_workflow_links',
            'integration_connections',
        ])->every(fn (string $table): bool => Schema::hasTable($table));

        $checks = [
            'asana_oauth_registration' => $this->check(
                filled(config('services.asana.oauth_client_id')) && filled(config('services.asana.oauth_client_secret')),
                'Shared Asana OAuth app credentials are configured.',
                'Set the shared ASANA_OAUTH_CLIENT_ID and ASANA_OAUTH_CLIENT_SECRET.'
            ),
            'google_oauth_registration' => $this->check(
                filled(config('services.google_calendar.oauth_client_id')) && filled(config('services.google_calendar.oauth_client_secret')),
                'Shared Google Calendar OAuth app credentials are configured.',
                'Set the shared GOOGLE_CALENDAR_CLIENT_ID and GOOGLE_CALENDAR_CLIENT_SECRET.'
            ),
            'redirect_uris' => $this->check(
                $this->validRedirect(config('services.asana.redirect_uri')) && $this->validRedirect(config('services.google_calendar.redirect_uri')),
                'OAuth callback URLs are absolute HTTPS URLs.',
                'Register and configure absolute HTTPS callback URLs for both providers.'
            ),
            'scheduler_heartbeat' => $this->check(
                $schedulerAge !== null && $schedulerAge <= 10,
                'The scheduler heartbeat is current.',
                'Run the scheduler and confirm scheduler:heartbeat has pulsed in the last 10 minutes.'
            ),
            'queue_execution' => $this->check(
                $queueAt !== null && $queueAt->gte(now()->subMinutes(30)),
                'A workflow queue job executed in the last 30 minutes.',
                'Run an entitled staging workflow through the queue and confirm the worker heartbeat.'
            ),
            'encryption_key' => $this->check(
                filled(config('app.key')),
                'Application encryption is configured for tenant OAuth grants.',
                'Set APP_KEY before storing any provider grants.'
            ),
            'database_schema' => $this->check(
                $schemaReady,
                'All productized workflow and connection tables are installed.',
                'Run the productized workflow migrations.'
            ),
        ];

        return ['ready' => collect($checks)->every(fn (array $check): bool => $check['ready']), 'checks' => $checks];
    }

    public function pulseQueue(): void
    {
        Cache::forever(self::QUEUE_HEARTBEAT_KEY, now()->toIso8601String());
    }

    protected function queueHeartbeatAt(): ?CarbonImmutable
    {
        $value = Cache::get(self::QUEUE_HEARTBEAT_KEY);
        if (is_string($value) && filled($value)) {
            return rescue(fn (): CarbonImmutable => CarbonImmutable::parse($value), null, false);
        }

        if (Schema::hasTable('automation_workflow_runs')) {
            $finishedAt = AutomationWorkflowRun::query()->forAllTenants()->whereNotNull('finished_at')->latest('finished_at')->value('finished_at');

            return filled($finishedAt)
                ? rescue(fn (): CarbonImmutable => CarbonImmutable::parse((string) $finishedAt), null, false)
                : null;
        }

        return null;
    }

    protected function validRedirect(mixed $value): bool
    {
        $url = trim((string) $value);

        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false && parse_url($url, PHP_URL_SCHEME) === 'https';
    }

    /** @return array{ready:bool,message:string} */
    protected function check(bool $ready, string $success, string $failure): array
    {
        return ['ready' => $ready, 'message' => $ready ? $success : $failure];
    }
}
