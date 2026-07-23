<?php

namespace App\Services\Automation\Drivers;

use App\Models\AutomationWorkflowLink;
use App\Models\AutomationWorkflowState;
use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\CalendarEventPresentationService;
use App\Services\Automation\CommerceOrderSourceService;
use App\Services\Automation\Contracts\AutomationWorkflowDriver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class CommerceOrderGoogleCalendarWorkflowDriver implements AutomationWorkflowDriver
{
    public function __construct(
        protected CommerceOrderSourceService $sources,
        protected CalendarEventPresentationService $calendarPresentation,
    ) {}

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    public function run(string $workflowKey, array $definition, bool $dryRun = false): array
    {
        $provider = strtolower(trim((string) data_get($definition, 'trigger.provider')));
        $tenantId = (int) ($definition['tenant_id'] ?? 0);
        $workflowId = (int) ($definition['automation_workflow_id'] ?? 0);
        $connectionId = (int) data_get($definition, 'trigger.connection_id', 0);
        $calendarId = trim((string) data_get($definition, 'action.calendar_id'));
        $credentials = (array) ($definition['credentials'] ?? []);

        if (! in_array($provider, CommerceOrderSourceService::LIVE_PROVIDERS, true)) {
            throw new AutomationWorkflowException('This commerce workflow source is not live yet.');
        }
        if ($tenantId <= 0 || $workflowId <= 0 || $connectionId <= 0) {
            throw new AutomationWorkflowException('The workflow is missing its tenant-owned commerce connection.');
        }
        if ($calendarId === '') {
            throw new AutomationWorkflowException('Choose a Google Calendar before running this workflow.');
        }

        $overlapMinutes = max(0, (int) data_get($definition, 'trigger.modified_overlap_minutes', 5));
        $modifiedSince = $this->modifiedSince(
            $workflowKey,
            $workflowId,
            max(1, (int) data_get($definition, 'trigger.bootstrap_lookback_days', 14)),
            $overlapMinutes,
        );
        $orders = $this->sources->fetch(
            provider: $provider,
            tenantId: $tenantId,
            connectionId: $connectionId,
            modifiedSince: $modifiedSince,
            pollLimit: min(100, max(1, (int) data_get($definition, 'trigger.poll_limit', 100))),
            maxOrders: max(1, (int) data_get($definition, 'trigger.max_tasks_per_run', 500)),
            locationIds: (array) data_get($definition, 'trigger.location_ids', []),
        );

        $counts = [
            'fetched' => count($orders), 'processed' => 0, 'created' => 0, 'updated' => 0,
            'unchanged' => 0, 'skipped' => 0, 'held_for_review' => 0, 'failed' => 0,
        ];
        $dryRunCounts = ['would_create' => 0, 'would_update' => 0];
        $nextCursor = $modifiedSince;
        $errors = [];

        foreach ($orders as $order) {
            if (! is_array($order)) {
                continue;
            }
            $counts['processed']++;
            $sourceId = trim((string) ($order['source_id'] ?? ''));
            $updatedAt = $this->date((string) ($order['updated_at'] ?? ''));
            if ($updatedAt?->gt($nextCursor)) {
                $nextCursor = $updatedAt;
            }
            if ($sourceId === '') {
                $counts['skipped']++;

                continue;
            }

            $link = $this->link($workflowKey, $provider, $sourceId, ! $dryRun, $workflowId);
            $cancelBehavior = (string) data_get($definition, 'action.presentation.cancelled_order_behavior', 'mark_cancelled');
            if ((bool) ($order['cancelled'] ?? false) && $cancelBehavior === 'leave_unchanged') {
                $counts[$link && filled($link->destination_id) ? 'unchanged' : 'skipped']++;

                continue;
            }

            $eventPayload = $this->buildEventPayload(
                $order,
                $provider,
                $workflowKey,
                (string) data_get($definition, 'trigger.schedule_source', 'fulfillment'),
                (array) ($definition['action'] ?? []),
            );
            if ($eventPayload === null) {
                $counts['held_for_review']++;

                continue;
            }

            $fingerprint = hash('sha256', json_encode($eventPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($link && filled($link->destination_id) && hash_equals((string) $link->source_fingerprint, $fingerprint)) {
                $counts['unchanged']++;

                continue;
            }
            if ($dryRun) {
                $dryRunCounts[$link && filled($link->destination_id) ? 'would_update' : 'would_create']++;

                continue;
            }

            try {
                $existingId = trim((string) ($link?->destination_id ?? ''));
                $eventId = $existingId !== '' ? $this->updateGoogleEvent($calendarId, $existingId, $eventPayload, $credentials) : null;
                if ($eventId === null) {
                    $eventId = $this->createGoogleEvent($calendarId, $eventPayload, $credentials);
                    $counts['created']++;
                } else {
                    $counts['updated']++;
                }
                $this->upsertLink($workflowKey, $provider, $sourceId, $eventId, $fingerprint, $order, $tenantId, $workflowId);
            } catch (\Throwable $exception) {
                $counts['failed']++;
                $errors[] = 'order='.$sourceId.' error='.$exception->getMessage();
            }
        }

        return [
            'ok' => $counts['failed'] === 0,
            'status' => $counts['failed'] === 0 ? 'success' : 'partial_failure',
            'workflow_key' => $workflowKey,
            'cursor' => $nextCursor->toIso8601String(),
            'counts' => $counts,
            'dry_run' => $dryRun,
            'dry_run_counts' => $dryRunCounts,
            'errors' => $errors,
        ];
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $action @return array<string,mixed>|null */
    protected function buildEventPayload(array $order, string $provider, string $workflowKey, string $scheduleSource, array $action): ?array
    {
        $source = in_array($scheduleSource, ['order_created', 'fulfillment', 'delivery', 'pickup'], true) ? $scheduleSource : 'fulfillment';
        $scheduledValue = trim((string) data_get($order, 'schedule.'.$source, ''));
        if ($scheduledValue === '') {
            return null;
        }

        $timezone = trim((string) ($action['timezone'] ?? config('app.timezone', 'UTC')));
        $scheduled = $this->scheduledDate($scheduledValue, $timezone);
        if ($scheduled === null) {
            return null;
        }
        $scheduled = $scheduled->addDays((int) ($action['schedule_offset_days'] ?? 0));
        $mode = (string) ($action['event_time_mode'] ?? 'source_time');
        $duration = min(1440, max(1, (int) ($action['default_duration_minutes'] ?? 60)));
        if ($mode === 'fixed_time') {
            [$hour, $minute] = array_pad(array_map('intval', explode(':', (string) ($action['default_start_time'] ?? '09:00'))), 2, 0);
            $scheduled = $scheduled->setTime(min(23, max(0, $hour)), min(59, max(0, $minute)));
        }

        $appearance = $this->calendarPresentation->render($order, (array) ($action['presentation'] ?? []), $provider);
        $allDay = $mode === 'all_day';

        return [
            ...$appearance,
            'start' => $allDay
                ? ['date' => $scheduled->toDateString()]
                : ['dateTime' => $scheduled->toIso8601String(), 'timeZone' => $timezone],
            'end' => $allDay
                ? ['date' => $scheduled->addDay()->toDateString()]
                : ['dateTime' => $scheduled->addMinutes($duration)->toIso8601String(), 'timeZone' => $timezone],
            'extendedProperties' => ['private' => [
                'commerceProvider' => $provider,
                'commerceOrderId' => (string) ($order['source_id'] ?? ''),
                'automationWorkflow' => $workflowKey,
            ]],
        ];
    }

    protected function modifiedSince(string $workflowKey, int $workflowId, int $lookbackDays, int $overlapMinutes): CarbonImmutable
    {
        $fallback = CarbonImmutable::now()->subDays($lookbackDays);
        $state = $this->state($workflowKey, $workflowId);
        $cursor = trim((string) ($state?->cursor ?? ''));
        $parsed = $this->date($cursor);
        if ($parsed === null) {
            return $fallback;
        }

        return $overlapMinutes > 0 ? $parsed->subMinutes($overlapMinutes) : $parsed;
    }

    protected function createGoogleEvent(string $calendarId, array $payload, array $credentials): string
    {
        $response = $this->googleRequest($credentials)->post($this->googleBase().'/calendars/'.rawurlencode($calendarId).'/events', $payload);
        $json = $this->decode($response, 'Google Calendar event create failed.');
        $eventId = trim((string) ($json['id'] ?? ''));
        if ($eventId === '') {
            throw new AutomationWorkflowException('Google Calendar did not return an event ID.');
        }

        return $eventId;
    }

    protected function updateGoogleEvent(string $calendarId, string $eventId, array $payload, array $credentials): ?string
    {
        foreach (['start', 'end'] as $boundary) {
            $value = (array) ($payload[$boundary] ?? []);
            if (array_key_exists('date', $value)) {
                $value['dateTime'] = null;
                $value['timeZone'] = null;
            } else {
                $value['date'] = null;
            }
            $payload[$boundary] = $value;
        }
        $response = $this->googleRequest($credentials)->patch($this->googleBase().'/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId), $payload);
        if ($response->status() === 404) {
            return null;
        }
        $json = $this->decode($response, 'Google Calendar event update failed.');

        return trim((string) ($json['id'] ?? '')) ?: $eventId;
    }

    protected function googleRequest(array $credentials): PendingRequest
    {
        return Http::acceptJson()->asJson()->withToken($this->googleAccessToken($credentials))
            ->timeout(20)->retry(2, 250, throw: false);
    }

    protected function googleAccessToken(array $credentials): string
    {
        $accessToken = trim((string) ($credentials['google_calendar_access_token'] ?? ''));
        if ($accessToken !== '') {
            return $accessToken;
        }
        $clientId = trim((string) ($credentials['google_calendar_client_id'] ?? config('services.google_calendar.oauth_client_id')));
        $clientSecret = trim((string) ($credentials['google_calendar_client_secret'] ?? config('services.google_calendar.oauth_client_secret')));
        $refreshToken = trim((string) ($credentials['google_calendar_refresh_token'] ?? config('services.google_calendar.oauth_refresh_token')));
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new AutomationWorkflowException('Google Calendar needs to be reconnected.');
        }
        $response = Http::asForm()->acceptJson()->timeout(20)->retry(2, 250, throw: false)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => $clientId, 'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken, 'grant_type' => 'refresh_token',
            ]);
        $payload = $this->decode($response, 'Google OAuth token refresh failed.');
        $accessToken = trim((string) ($payload['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new AutomationWorkflowException('Google OAuth did not return an access token.');
        }

        return $accessToken;
    }

    protected function state(string $workflowKey, int $workflowId): ?AutomationWorkflowState
    {
        if (! Schema::hasTable('automation_workflow_states')) {
            return null;
        }

        return AutomationWorkflowState::query()->where('automation_workflow_id', $workflowId)->first()
            ?? AutomationWorkflowState::query()->where('workflow_key', $workflowKey)->first();
    }

    protected function link(string $workflowKey, string $provider, string $sourceId, bool $requireTable, int $workflowId): ?AutomationWorkflowLink
    {
        if (! Schema::hasTable('automation_workflow_links')) {
            if (! $requireTable) {
                return null;
            }
            throw new AutomationWorkflowException('automation_workflow_links table is missing. Run migrations first.');
        }

        return AutomationWorkflowLink::query()->where('automation_workflow_id', $workflowId)
            ->where('source_system', $provider.'_order')->where('source_id', $sourceId)->first();
    }

    protected function upsertLink(
        string $workflowKey,
        string $provider,
        string $sourceId,
        string $destinationId,
        string $fingerprint,
        array $order,
        int $tenantId,
        int $workflowId,
    ): void {
        AutomationWorkflowLink::query()->updateOrCreate(
            ['automation_workflow_id' => $workflowId, 'source_system' => $provider.'_order', 'source_id' => $sourceId],
            [
                'workflow_key' => $workflowKey,
                'tenant_id' => $tenantId,
                'destination_system' => 'google_calendar_event',
                'destination_id' => $destinationId,
                'source_fingerprint' => $fingerprint,
                'last_synced_at' => now(),
                'metadata' => [
                    'provider' => $provider,
                    'order_number' => (string) ($order['order_number'] ?? ''),
                    'order_updated_at' => (string) ($order['updated_at'] ?? ''),
                    'status' => (string) ($order['status'] ?? ''),
                ],
            ],
        );
    }

    protected function scheduledDate(string $value, string $timezone): ?CarbonImmutable
    {
        try {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                ? CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone)
                : CarbonImmutable::parse($value)->setTimezone($timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function date(string $value): ?CarbonImmutable
    {
        try {
            return trim($value) !== '' ? CarbonImmutable::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    protected function decode(Response $response, string $message): array
    {
        $json = is_array($response->json()) ? (array) $response->json() : [];
        if ($response->successful()) {
            return $json;
        }
        $apiMessage = trim((string) data_get($json, 'error.message', data_get($json, 'errors.0.message', data_get($json, 'errors.0.detail', ''))));
        throw new AutomationWorkflowException($message.' (HTTP '.$response->status().($apiMessage !== '' ? ': '.$apiMessage : '').')');
    }

    protected function googleBase(): string
    {
        return 'https://www.googleapis.com/calendar/v3';
    }
}
