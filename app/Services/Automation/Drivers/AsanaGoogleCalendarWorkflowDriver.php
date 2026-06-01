<?php

namespace App\Services\Automation\Drivers;

use App\Models\AutomationWorkflowLink;
use App\Models\AutomationWorkflowState;
use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\Contracts\AutomationWorkflowDriver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class AsanaGoogleCalendarWorkflowDriver implements AutomationWorkflowDriver
{
    /**
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    public function run(string $workflowKey, array $definition, bool $dryRun = false): array
    {
        $projectGid = trim((string) data_get($definition, 'trigger.project_gid', ''));
        $calendarId = trim((string) data_get($definition, 'action.calendar_id', ''));

        if ($projectGid === '') {
            throw new AutomationWorkflowException('AUTOMATION_ASANA_PROJECT_GID is required.');
        }

        if ($calendarId === '') {
            throw new AutomationWorkflowException('AUTOMATION_GCAL_CALENDAR_ID (or ASANA_SKYLIGHT_CALENDAR_ID) is required.');
        }

        $timezone = trim((string) data_get($definition, 'action.timezone', 'America/New_York'));
        $defaultStartTime = trim((string) data_get($definition, 'action.default_start_time', '12:00:00'));
        $defaultDurationMinutes = max(1, (int) data_get($definition, 'action.default_duration_minutes', 60));
        $skipCompleted = (bool) data_get($definition, 'action.skip_completed_tasks', true);

        $pollLimit = min(100, max(1, (int) data_get($definition, 'trigger.poll_limit', 100)));
        $maxTasksPerRun = max(1, (int) data_get($definition, 'trigger.max_tasks_per_run', 500));
        $bootstrapLookbackDays = max(1, (int) data_get($definition, 'trigger.bootstrap_lookback_days', 14));
        $overlapMinutes = max(0, (int) data_get($definition, 'trigger.modified_overlap_minutes', 5));

        $modifiedSince = $this->modifiedSince(
            workflowKey: $workflowKey,
            bootstrapLookbackDays: $bootstrapLookbackDays,
            overlapMinutes: $overlapMinutes
        );

        $tasks = $this->fetchAsanaTasks(
            projectGid: $projectGid,
            modifiedSince: $modifiedSince,
            pollLimit: $pollLimit,
            maxTasksPerRun: $maxTasksPerRun
        );

        $counts = [
            'fetched' => count($tasks),
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $dryRunCounts = [
            'would_create' => 0,
            'would_update' => 0,
        ];

        $nextCursor = $modifiedSince;
        $errors = [];

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }

            $counts['processed']++;
            $taskGid = trim((string) ($task['gid'] ?? ''));

            $modifiedAt = $this->nullableDateTime((string) ($task['modified_at'] ?? ''));
            if ($modifiedAt !== null && $modifiedAt->gt($nextCursor)) {
                $nextCursor = $modifiedAt;
            }

            if ($taskGid === '') {
                $counts['skipped']++;
                continue;
            }

            if ($skipCompleted && (bool) ($task['completed'] ?? false)) {
                $counts['skipped']++;
                continue;
            }

            $eventPayload = $this->buildEventPayload(
                task: $task,
                workflowKey: $workflowKey,
                timezone: $timezone,
                defaultStartTime: $defaultStartTime,
                defaultDurationMinutes: $defaultDurationMinutes
            );

            if ($eventPayload === null) {
                $counts['skipped']++;
                continue;
            }

            $fingerprint = hash('sha256', json_encode($eventPayload, JSON_UNESCAPED_SLASHES));
            $link = $this->link($workflowKey, $taskGid, ! $dryRun);

            if (
                $link !== null
                && trim((string) $link->destination_id) !== ''
                && trim((string) $link->source_fingerprint) === $fingerprint
            ) {
                $counts['unchanged']++;
                continue;
            }

            if ($dryRun) {
                if ($link !== null && trim((string) $link->destination_id) !== '') {
                    $dryRunCounts['would_update']++;
                } else {
                    $dryRunCounts['would_create']++;
                }

                continue;
            }

            try {
                $destinationId = $link !== null ? trim((string) $link->destination_id) : '';
                $eventId = $destinationId !== ''
                    ? $this->updateGoogleEvent($calendarId, $destinationId, $eventPayload)
                    : null;

                if ($eventId === null) {
                    $eventId = $this->createGoogleEvent($calendarId, $eventPayload);
                    $counts['created']++;
                } else {
                    $counts['updated']++;
                }

                $this->upsertLink(
                    workflowKey: $workflowKey,
                    sourceId: $taskGid,
                    destinationId: $eventId,
                    sourceFingerprint: $fingerprint,
                    task: $task
                );
            } catch (\Throwable $exception) {
                $counts['failed']++;
                $errors[] = sprintf('task=%s error=%s', $taskGid, $exception->getMessage());
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

    protected function modifiedSince(string $workflowKey, int $bootstrapLookbackDays, int $overlapMinutes): CarbonImmutable
    {
        $fallback = CarbonImmutable::now()->subDays($bootstrapLookbackDays);
        $state = $this->state($workflowKey);

        $cursor = trim((string) ($state?->cursor ?? ''));
        if ($cursor === '') {
            return $fallback;
        }

        $parsed = $this->nullableDateTime($cursor);
        if ($parsed === null) {
            return $fallback;
        }

        return $overlapMinutes > 0 ? $parsed->subMinutes($overlapMinutes) : $parsed;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function fetchAsanaTasks(
        string $projectGid,
        CarbonImmutable $modifiedSince,
        int $pollLimit,
        int $maxTasksPerRun
    ): array {
        $tasks = [];
        $offset = null;

        do {
            $query = [
                'project' => $projectGid,
                'modified_since' => $modifiedSince->toIso8601String(),
                'limit' => $pollLimit,
                'opt_fields' => implode(',', [
                    'gid',
                    'name',
                    'notes',
                    'due_on',
                    'due_at',
                    'completed',
                    'modified_at',
                    'permalink_url',
                ]),
            ];

            if ($offset !== null) {
                $query['offset'] = $offset;
            }

            $response = $this->asanaRequest()->get($this->asanaApiBase().'/tasks', $query);
            $payload = $this->decodeResponse($response, 'Asana tasks fetch failed');

            $data = array_values(array_filter((array) ($payload['data'] ?? []), 'is_array'));
            foreach ($data as $row) {
                $tasks[] = $row;
                if (count($tasks) >= $maxTasksPerRun) {
                    break 2;
                }
            }

            $offset = data_get($payload, 'next_page.offset');
            $offset = is_string($offset) && trim($offset) !== '' ? trim($offset) : null;
        } while ($offset !== null);

        return $tasks;
    }

    /**
     * @param  array<string,mixed>  $task
     * @return array<string,mixed>|null
     */
    protected function buildEventPayload(
        array $task,
        string $workflowKey,
        string $timezone,
        string $defaultStartTime,
        int $defaultDurationMinutes
    ): ?array {
        $taskGid = trim((string) ($task['gid'] ?? ''));
        $summary = trim((string) ($task['name'] ?? ''));
        $description = trim((string) ($task['notes'] ?? ''));
        $permalink = trim((string) ($task['permalink_url'] ?? ''));

        if ($summary === '' || $taskGid === '') {
            return null;
        }

        $dueAt = trim((string) ($task['due_at'] ?? ''));
        $dueOn = trim((string) ($task['due_on'] ?? ''));

        if ($dueAt === '' && $dueOn === '') {
            return null;
        }

        if ($dueAt !== '') {
            $start = CarbonImmutable::parse($dueAt)->setTimezone($timezone);
        } else {
            $start = CarbonImmutable::parse($dueOn.' '.$defaultStartTime, $timezone);
        }

        $end = $start->addMinutes($defaultDurationMinutes);

        $eventDescription = $description;
        if ($permalink !== '') {
            $eventDescription .= ($eventDescription !== '' ? "\n\n" : '').'Asana task: '.$permalink;
        }

        return [
            'summary' => $summary,
            'description' => $eventDescription,
            'start' => [
                'dateTime' => $start->toIso8601String(),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $end->toIso8601String(),
                'timeZone' => $timezone,
            ],
            'extendedProperties' => [
                'private' => [
                    'asanaTaskGid' => $taskGid,
                    'automationWorkflow' => $workflowKey,
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function createGoogleEvent(string $calendarId, array $payload): string
    {
        $response = $this->googleCalendarRequest()->post(
            $this->googleCalendarApiBase().'/calendars/'.rawurlencode($calendarId).'/events',
            $payload
        );
        $json = $this->decodeResponse($response, 'Google Calendar event create failed');
        $eventId = trim((string) ($json['id'] ?? ''));

        if ($eventId === '') {
            throw new AutomationWorkflowException('Google Calendar response did not return an event id.');
        }

        return $eventId;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function updateGoogleEvent(string $calendarId, string $eventId, array $payload): ?string
    {
        $response = $this->googleCalendarRequest()->patch(
            $this->googleCalendarApiBase().'/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId),
            $payload
        );

        if ($response->status() === 404) {
            return null;
        }

        $json = $this->decodeResponse($response, 'Google Calendar event update failed');
        $resolvedId = trim((string) ($json['id'] ?? ''));

        return $resolvedId !== '' ? $resolvedId : $eventId;
    }

    protected function asanaRequest(): PendingRequest
    {
        $token = trim((string) config('services.asana.personal_access_token', ''));
        if ($token === '') {
            throw new AutomationWorkflowException('ASANA_PERSONAL_ACCESS_TOKEN is required.');
        }

        return Http::acceptJson()
            ->withToken($token)
            ->timeout(20)
            ->retry(2, 250, throw: false);
    }

    protected function googleCalendarRequest(): PendingRequest
    {
        $accessToken = $this->googleAccessToken();

        return Http::acceptJson()
            ->withToken($accessToken)
            ->asJson()
            ->timeout(20)
            ->retry(2, 250, throw: false);
    }

    protected function googleAccessToken(): string
    {
        $configuredAccessToken = trim((string) config('services.google_calendar.oauth_access_token', ''));
        if ($configuredAccessToken !== '') {
            return $configuredAccessToken;
        }

        $clientId = trim((string) config('services.google_calendar.oauth_client_id', ''));
        $clientSecret = trim((string) config('services.google_calendar.oauth_client_secret', ''));
        $refreshToken = trim((string) config('services.google_calendar.oauth_refresh_token', ''));

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new AutomationWorkflowException(
                'Google Calendar OAuth is not configured. Set GOOGLE_CALENDAR_CLIENT_ID, GOOGLE_CALENDAR_CLIENT_SECRET, and GOOGLE_CALENDAR_REFRESH_TOKEN.'
            );
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 250, throw: false)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);

        $payload = $this->decodeResponse($response, 'Google OAuth token refresh failed');
        $accessToken = trim((string) ($payload['access_token'] ?? ''));

        if ($accessToken === '') {
            throw new AutomationWorkflowException('Google OAuth token response did not include access_token.');
        }

        return $accessToken;
    }

    protected function asanaApiBase(): string
    {
        return rtrim((string) config('services.asana.api_base', 'https://app.asana.com/api/1.0'), '/');
    }

    protected function googleCalendarApiBase(): string
    {
        return 'https://www.googleapis.com/calendar/v3';
    }

    protected function decodeResponse(Response $response, string $message): array
    {
        $payload = $response->json();
        $json = is_array($payload) ? $payload : [];

        if ($response->successful()) {
            return $json;
        }

        $apiMessage = trim((string) data_get($json, 'errors.0.message', data_get($json, 'error.message', data_get($json, 'error', ''))));
        if ($apiMessage === '') {
            $apiMessage = trim((string) $response->body());
        }

        throw new AutomationWorkflowException($message.' (HTTP '.$response->status().($apiMessage !== '' ? ': '.$apiMessage : '').')');
    }

    protected function state(string $workflowKey): ?AutomationWorkflowState
    {
        if (! Schema::hasTable('automation_workflow_states')) {
            return null;
        }

        return AutomationWorkflowState::query()
            ->where('workflow_key', $workflowKey)
            ->first();
    }

    protected function link(string $workflowKey, string $sourceId, bool $requireTable = true): ?AutomationWorkflowLink
    {
        if (! Schema::hasTable('automation_workflow_links')) {
            if (! $requireTable) {
                return null;
            }

            throw new AutomationWorkflowException('automation_workflow_links table is missing. Run migrations first.');
        }

        return AutomationWorkflowLink::query()
            ->where('workflow_key', $workflowKey)
            ->where('source_system', 'asana_task')
            ->where('source_id', $sourceId)
            ->first();
    }

    /**
     * @param  array<string,mixed>  $task
     */
    protected function upsertLink(
        string $workflowKey,
        string $sourceId,
        string $destinationId,
        string $sourceFingerprint,
        array $task
    ): void {
        AutomationWorkflowLink::query()->updateOrCreate(
            [
                'workflow_key' => $workflowKey,
                'source_system' => 'asana_task',
                'source_id' => $sourceId,
            ],
            [
                'destination_system' => 'google_calendar_event',
                'destination_id' => $destinationId,
                'source_fingerprint' => $sourceFingerprint,
                'last_synced_at' => now(),
                'metadata' => [
                    'task_name' => (string) ($task['name'] ?? ''),
                    'task_due_on' => (string) ($task['due_on'] ?? ''),
                    'task_due_at' => (string) ($task['due_at'] ?? ''),
                    'task_modified_at' => (string) ($task['modified_at'] ?? ''),
                ],
            ]
        );
    }

    protected function nullableDateTime(string $value): ?CarbonImmutable
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($trimmed);
        } catch (\Throwable) {
            return null;
        }
    }
}
