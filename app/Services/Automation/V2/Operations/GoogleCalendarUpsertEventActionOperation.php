<?php

namespace App\Services\Automation\V2\Operations;

use App\Models\AutomationWorkflowLink;
use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\CalendarEventPresentationService;
use App\Services\Automation\V2\CalendarEventParityFingerprint;
use App\Services\Automation\V2\Contracts\ActionOperation;
use App\Services\Automation\V2\Data\ActionOperationContext;
use App\Services\Automation\V2\Data\ActionResult;
use App\Services\Automation\V2\PayloadFingerprint;
use App\Services\Automation\V2\Providers\ProviderAccessTokenService;
use App\Services\Automation\V2\Providers\TenantIntegrationConnectionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleCalendarUpsertEventActionOperation implements ActionOperation
{
    public function __construct(
        protected TenantIntegrationConnectionResolver $connections,
        protected ProviderAccessTokenService $tokens,
        protected PayloadFingerprint $fingerprints,
        protected CalendarEventPresentationService $presentation,
        protected CalendarEventParityFingerprint $parityFingerprints,
    ) {}

    /**
     * Execute the exact v2 mapping and connection-resolution path without an
     * external write. Link selection remains the promotion service's job so it
     * can model the pending atomic step-key remap truthfully.
     *
     * @return array{status:string,semantic_fingerprint?:string,link_fingerprint?:string}
     */
    public function previewMapping(ActionOperationContext $context): array
    {
        if (
            (bool) ($context->config['skip_completed_tasks'] ?? false)
            && filter_var(
                $context->execution->triggerOutput['completed'] ?? false,
                FILTER_VALIDATE_BOOL
            )
        ) {
            return ['status' => 'skipped'];
        }

        $calendarId = trim((string) ($context->config['calendar_id'] ?? ''));
        if ($calendarId === '') {
            throw new AutomationWorkflowException('Choose a Google Calendar before running this action.');
        }
        $connection = $this->connections->resolve(
            $context->execution->tenantId,
            $context->connectionId,
            'google_calendar'
        );
        $payload = $this->eventPayload($context);

        return [
            'status' => 'ready',
            'semantic_fingerprint' => $this->parityFingerprints->hash($payload),
            'link_fingerprint' => $this->fingerprints->hash([
                'connection_id' => (int) $connection->id,
                'calendar_id' => $calendarId,
                'event' => $payload,
            ]),
        ];
    }

    public function execute(ActionOperationContext $context): ActionResult
    {
        if (
            (bool) ($context->config['skip_completed_tasks'] ?? false)
            && filter_var(
                $context->execution->triggerOutput['completed'] ?? false,
                FILTER_VALIDATE_BOOL
            )
        ) {
            return new ActionResult(
                output: ['skipped' => true, 'reason' => 'source_task_completed'],
                summary: ['operation' => 'skipped', 'reason' => 'Source task is completed.'],
                status: 'skipped',
            );
        }

        $calendarId = trim((string) ($context->config['calendar_id'] ?? ''));
        if ($calendarId === '') {
            throw new AutomationWorkflowException('Choose a Google Calendar before running this action.');
        }

        $connection = $this->connections->resolve(
            $context->execution->tenantId,
            $context->connectionId,
            'google_calendar'
        );
        $payload = $this->eventPayload($context);
        $fingerprint = $this->fingerprints->hash([
            'connection_id' => (int) $connection->id,
            'calendar_id' => $calendarId,
            'event' => $payload,
        ]);
        $sourceSystem = trim((string) ($context->execution->metadata['source_system'] ?? 'workflow_event'));
        $sourceId = trim((string) ($context->input['source_id'] ?? ''));
        if ($sourceId === '') {
            $sourceId = trim((string) (
                $context->execution->metadata['source_id']
                ?? $context->execution->runItemId
            ));
        }
        $link = $this->link($context, $sourceSystem, $sourceId);

        if (
            $link !== null
            && filled($link->destination_id)
            && hash_equals((string) $link->source_fingerprint, $fingerprint)
        ) {
            $eventUrl = trim((string) data_get($link->metadata, 'event_url', ''));

            return new ActionResult(
                output: array_filter([
                    'event_id' => (string) $link->destination_id,
                    'event_url' => $eventUrl !== '' ? $eventUrl : null,
                    'operation' => 'unchanged',
                    'unchanged' => true,
                ], static fn (mixed $value): bool => $value !== null),
                summary: ['operation' => 'unchanged', 'calendar_id' => $calendarId],
                externalId: (string) $link->destination_id,
                status: 'unchanged',
            );
        }

        $eventId = trim((string) $link?->destination_id);
        $operation = $eventId === '' ? 'created' : 'updated';
        if ($context->dryRun) {
            return new ActionResult(
                output: ['would_'.$operation => true],
                summary: ['operation' => 'would_'.$operation, 'calendar_id' => $calendarId],
                externalId: $eventId !== '' ? $eventId : null,
                status: 'dry_run',
            );
        }

        $token = $this->tokens->token($connection);
        $eventUrl = null;
        if ($eventId !== '') {
            $updated = $this->updateEvent($calendarId, $eventId, $payload, $token);
            if ($updated === null) {
                $eventId = '';
                $operation = 'created';
            } else {
                $eventId = $updated['id'];
                $eventUrl = $updated['url'];
            }
        }

        if ($eventId === '') {
            $eventId = $this->deterministicEventId($context, $sourceSystem, $sourceId);
            $created = $this->createEvent($calendarId, $eventId, $payload, $token);
            $eventId = $created['id'];
            $eventUrl = $created['url'];
        }

        $this->upsertLink(
            context: $context,
            sourceSystem: $sourceSystem,
            sourceId: $sourceId,
            eventId: $eventId,
            fingerprint: $fingerprint,
            eventUrl: $eventUrl,
            calendarId: $calendarId,
            connectionId: (int) $connection->id,
        );

        return new ActionResult(
            output: array_filter([
                'event_id' => $eventId,
                'event_url' => $eventUrl,
                'operation' => $operation,
            ], static fn (mixed $value): bool => $value !== null),
            summary: ['operation' => $operation, 'calendar_id' => $calendarId],
            externalId: $eventId,
        );
    }

    public function test(ActionOperationContext $context): ActionResult
    {
        $calendarId = trim((string) ($context->config['calendar_id'] ?? ''));
        if ($calendarId === '') {
            throw new AutomationWorkflowException('Choose a Google Calendar before testing this action.');
        }

        $connection = $this->connections->resolve(
            $context->execution->tenantId,
            $context->connectionId,
            'google_calendar'
        );
        $token = $this->tokens->token($connection);
        $start = CarbonImmutable::now()->addMinutes(15)->startOfMinute();
        $eventId = $this->deterministicEventId(
            $context,
            'workflow_test',
            (string) $context->execution->runItemId
        );
        $payload = [
            'summary' => '[Everbranch test] Calendar connection check',
            'description' => 'Temporary workflow test event. Everbranch removes this automatically.',
            'start' => ['dateTime' => $start->toIso8601String()],
            'end' => ['dateTime' => $start->addMinutes(15)->toIso8601String()],
        ];
        $created = $this->createEvent($calendarId, $eventId, $payload, $token);
        $createdId = $created['id'];
        $cleanup = $this->request($token)->delete(
            $this->eventUrl($calendarId, $createdId).'?sendUpdates=none'
        );
        $cleanupOk = $cleanup->successful() || in_array($cleanup->status(), [404, 410], true);
        if (! $cleanupOk) {
            throw new AutomationWorkflowException(
                'The test event was created, but Everbranch could not remove it. Delete the labeled event and try again.'
            );
        }

        return new ActionResult(
            output: array_filter([
                'event_id' => $createdId,
                'event_url' => $created['url'],
                'cleanup_ok' => true,
            ], static fn (mixed $value): bool => $value !== null),
            summary: ['operation' => 'test_create_and_delete', 'cleanup_ok' => true],
            externalId: $createdId,
            status: 'tested',
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function eventPayload(ActionOperationContext $context): array
    {
        if (is_array($context->input['event'] ?? null)) {
            $payload = (array) $context->input['event'];
        } else {
            $payload = $this->buildEventPayload($context);
        }

        $summary = trim((string) ($payload['summary'] ?? ''));
        if ($summary === '') {
            throw new AutomationWorkflowException('Google Calendar needs an event title.');
        }
        if (! is_array($payload['start'] ?? null) || ! is_array($payload['end'] ?? null)) {
            throw new AutomationWorkflowException('Google Calendar needs a valid event start and end.');
        }

        $private = array_filter([
            'automationWorkflowId' => (string) $context->execution->workflowId,
            'automationStepId' => $context->stepId,
            'sourceSystem' => (string) ($context->execution->metadata['source_system'] ?? ''),
            'sourceId' => (string) ($context->execution->metadata['source_id'] ?? ''),
        ], static fn (string $value): bool => $value !== '');
        $payload['extendedProperties']['private'] = [
            ...(array) data_get($payload, 'extendedProperties.private', []),
            ...$private,
        ];

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    protected function buildEventPayload(ActionOperationContext $context): array
    {
        $input = $context->input;
        $trigger = $context->execution->triggerOutput;
        $appearance = $this->presentationPayload($context);
        $summary = trim((string) (
            $input['summary']
            ?? $input['title']
            ?? $appearance['summary']
            ?? $trigger['name']
            ?? (filled($trigger['order_number'] ?? null) ? 'Order #'.$trigger['order_number'] : '')
        ));
        $description = trim((string) (
            $input['description']
            ?? $appearance['description']
            ?? $trigger['notes']
            ?? ''
        ));
        $timezone = trim((string) ($context->config['timezone'] ?? config('app.timezone', 'UTC')));
        $duration = min(1_440, max(1, (int) ($context->config['default_duration_minutes'] ?? 60)));
        $dateOnlyMode = strtolower(trim((string) ($context->config['date_only_mode'] ?? 'all_day')));
        $defaultStartTime = trim((string) ($context->config['default_start_time'] ?? '09:00'));
        $startValue = $input['start']
            ?? $input['start_at']
            ?? $input['starts_at']
            ?? $trigger['due_at']
            ?? $trigger['due_on']
            ?? $trigger['fulfillment_at']
            ?? data_get($trigger, 'schedule.fulfillment')
            ?? data_get($trigger, 'schedule.delivery')
            ?? data_get($trigger, 'schedule.pickup')
            ?? data_get($trigger, 'schedule.order_created');
        $endValue = $input['end'] ?? $input['end_at'] ?? $input['ends_at'] ?? null;

        if (is_array($startValue) && (array_key_exists('date', $startValue) || array_key_exists('dateTime', $startValue))) {
            $start = $startValue;
            $end = is_array($endValue) ? $endValue : $this->defaultEnd($start, $duration, $timezone);
        } else {
            [$start, $end] = $this->boundaries(
                $startValue,
                $endValue,
                $duration,
                $timezone,
                $dateOnlyMode,
                $defaultStartTime,
            );
        }

        return array_filter([
            ...$appearance,
            'summary' => $summary,
            'description' => $description !== '' ? $description : null,
            'location' => filled($input['location'] ?? null)
                ? (string) $input['location']
                : ($appearance['location'] ?? null),
            'start' => $start,
            'end' => $end,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Preserve the proven v1 calendar-presentation contract for converted
     * workflows while explicit v2 mappings remain authoritative.
     *
     * @return array<string,mixed>
     */
    protected function presentationPayload(ActionOperationContext $context): array
    {
        $settings = (array) ($context->config['presentation'] ?? []);
        if ($settings === []) {
            return [];
        }

        $sourceSystem = strtolower((string) ($context->execution->metadata['source_system'] ?? ''));
        $provider = match (true) {
            str_contains($sourceSystem, 'asana') => 'asana',
            str_contains($sourceSystem, 'shopify') => 'shopify',
            str_contains($sourceSystem, 'square') => 'square',
            default => 'everbranch',
        };
        $source = $context->execution->triggerOutput;
        $source['task_name'] ??= $source['name'] ?? null;
        $source['source'] ??= match ($provider) {
            'asana' => 'Asana',
            'shopify' => 'Shopify',
            'square' => 'Square',
            default => 'Everbranch',
        };
        $source['source_url'] ??= $source['permalink_url'] ?? null;
        $source['items'] = $this->displayLineItems($source['items'] ?? $source['line_items'] ?? null);
        $source['total'] = $this->displayTotal($source['total'] ?? null);
        $source['status'] = $this->displayStatus($source['status'] ?? null);
        foreach (['shipping_address', 'billing_address'] as $addressKey) {
            $source[$addressKey] = $this->displayAddress($source[$addressKey] ?? null);
        }

        return $this->presentation->render($source, $settings, $provider);
    }

    protected function displayLineItems(mixed $items): string
    {
        if (! is_array($items)) {
            return is_scalar($items) ? trim((string) $items) : '';
        }

        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(static function (array $item): string {
                $name = trim((string) ($item['name'] ?? 'Item'));
                $quantity = $item['quantity'] ?? null;

                return filled($quantity) ? $quantity.' × '.$name : $name;
            })
            ->filter()
            ->implode(', ');
    }

    protected function displayTotal(mixed $total): string
    {
        if (! is_array($total)) {
            return is_scalar($total) ? trim((string) $total) : '';
        }

        return trim(implode(' ', array_filter([
            is_scalar($total['amount'] ?? null) ? (string) $total['amount'] : null,
            is_scalar($total['currency'] ?? null) ? (string) $total['currency'] : null,
        ])));
    }

    protected function displayStatus(mixed $status): string
    {
        if (! is_array($status)) {
            return is_scalar($status) ? trim((string) $status) : '';
        }

        return collect($status)
            ->reject(static fn (mixed $value): bool => is_bool($value) || ! is_scalar($value))
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->implode(' · ');
    }

    protected function displayAddress(mixed $address): string
    {
        if (! is_array($address)) {
            return is_scalar($address) ? trim((string) $address) : '';
        }

        return collect($address)
            ->filter(static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->implode(', ');
    }

    /**
     * @return array{0:array<string,string>,1:array<string,string>}
     */
    protected function boundaries(
        mixed $startValue,
        mixed $endValue,
        int $duration,
        string $timezone,
        string $dateOnlyMode,
        string $defaultStartTime,
    ): array {
        $startText = trim((string) $startValue);
        if ($startText === '') {
            throw new AutomationWorkflowException('Google Calendar needs an event start.');
        }

        try {
            if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $startText) === 1) {
                if ($dateOnlyMode === 'default_time') {
                    if (preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/', $defaultStartTime) !== 1) {
                        throw new \InvalidArgumentException;
                    }
                    $start = CarbonImmutable::parse($startText.' '.$defaultStartTime, $timezone);
                    $end = filled($endValue)
                        ? CarbonImmutable::parse((string) $endValue, $timezone)
                        : $start->addMinutes($duration);

                    return [
                        ['dateTime' => $start->toIso8601String(), 'timeZone' => $timezone],
                        ['dateTime' => $end->toIso8601String(), 'timeZone' => $timezone],
                    ];
                }
                if ($dateOnlyMode !== 'all_day') {
                    throw new \InvalidArgumentException;
                }
                $startDate = CarbonImmutable::parse($startText, $timezone)->toDateString();
                $endDate = filled($endValue)
                    ? CarbonImmutable::parse((string) $endValue, $timezone)->toDateString()
                    : CarbonImmutable::parse($startText, $timezone)->addDay()->toDateString();

                return [['date' => $startDate], ['date' => $endDate]];
            }

            $start = CarbonImmutable::parse($startText, $timezone);
            $end = filled($endValue)
                ? CarbonImmutable::parse((string) $endValue, $timezone)
                : $start->addMinutes($duration);
        } catch (\Throwable) {
            throw new AutomationWorkflowException('Google Calendar received an invalid event date.');
        }

        if ($end->lte($start)) {
            throw new AutomationWorkflowException('Google Calendar event end must be after its start.');
        }

        return [
            ['dateTime' => $start->toIso8601String(), 'timeZone' => $timezone],
            ['dateTime' => $end->toIso8601String(), 'timeZone' => $timezone],
        ];
    }

    /**
     * @param  array<string,mixed>  $start
     * @return array<string,mixed>
     */
    protected function defaultEnd(array $start, int $duration, string $timezone): array
    {
        try {
            if (filled($start['date'] ?? null)) {
                return ['date' => CarbonImmutable::parse((string) $start['date'], $timezone)->addDay()->toDateString()];
            }

            return [
                'dateTime' => CarbonImmutable::parse((string) ($start['dateTime'] ?? ''), $timezone)
                    ->addMinutes($duration)
                    ->toIso8601String(),
                'timeZone' => (string) ($start['timeZone'] ?? $timezone),
            ];
        } catch (\Throwable) {
            throw new AutomationWorkflowException('Google Calendar received an invalid event start.');
        }
    }

    protected function link(
        ActionOperationContext $context,
        string $sourceSystem,
        string $sourceId
    ): ?AutomationWorkflowLink {
        return AutomationWorkflowLink::query()
            ->where('tenant_id', $context->execution->tenantId)
            ->where('automation_workflow_id', $context->execution->workflowId)
            ->where('step_key', $context->stepId)
            ->where('source_system', $sourceSystem)
            ->where('source_id', $sourceId)
            ->first();
    }

    protected function upsertLink(
        ActionOperationContext $context,
        string $sourceSystem,
        string $sourceId,
        string $eventId,
        string $fingerprint,
        ?string $eventUrl,
        string $calendarId,
        int $connectionId,
    ): void {
        AutomationWorkflowLink::query()->updateOrCreate(
            [
                'tenant_id' => $context->execution->tenantId,
                'automation_workflow_id' => $context->execution->workflowId,
                'step_key' => $context->stepId,
                'source_system' => $sourceSystem,
                'source_id' => $sourceId,
            ],
            [
                'workflow_key' => 'workflow:'.$context->execution->workflowId,
                'destination_system' => 'google_calendar_event',
                'destination_id' => $eventId,
                'source_fingerprint' => $fingerprint,
                'metadata' => array_filter([
                    'workflow_version_id' => $context->execution->workflowVersionId,
                    'event_url' => $eventUrl,
                    'calendar_id' => $calendarId,
                    'connection_id' => $connectionId,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                'last_synced_at' => now(),
            ]
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{id:string,url:?string}
     */
    protected function createEvent(string $calendarId, string $eventId, array $payload, string $token): array
    {
        $payload['id'] = $eventId;
        $response = $this->request($token)->post(
            $this->calendarUrl($calendarId).'/events?sendUpdates=none',
            $payload
        );
        if ($response->status() === 409) {
            return $this->updateEvent($calendarId, $eventId, $payload, $token)
                ?? ['id' => $eventId, 'url' => null];
        }

        $json = $this->decode($response, 'Google Calendar event create failed.');

        return [
            'id' => trim((string) ($json['id'] ?? '')) ?: $eventId,
            'url' => trim((string) ($json['htmlLink'] ?? '')) ?: null,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{id:string,url:?string}|null
     */
    protected function updateEvent(string $calendarId, string $eventId, array $payload, string $token): ?array
    {
        unset($payload['id']);
        foreach (['start', 'end'] as $boundary) {
            $value = (array) ($payload[$boundary] ?? []);
            if (array_key_exists('date', $value)) {
                $value['dateTime'] = null;
                $value['timeZone'] = null;
            } elseif (array_key_exists('dateTime', $value)) {
                $value['date'] = null;
            }
            $payload[$boundary] = $value;
        }

        $response = $this->request($token)->patch(
            $this->eventUrl($calendarId, $eventId).'?sendUpdates=none',
            $payload
        );
        if ($response->status() === 404) {
            return null;
        }

        $json = $this->decode($response, 'Google Calendar event update failed.');

        return [
            'id' => trim((string) ($json['id'] ?? '')) ?: $eventId,
            'url' => trim((string) ($json['htmlLink'] ?? '')) ?: null,
        ];
    }

    protected function deterministicEventId(
        ActionOperationContext $context,
        string $sourceSystem,
        string $sourceId
    ): string {
        return substr(hash(
            'sha256',
            implode('|', [
                $context->execution->workflowId,
                $context->stepId,
                $sourceSystem,
                $sourceId,
            ])
        ), 0, 32);
    }

    protected function request(string $token)
    {
        return Http::acceptJson()->asJson()->withToken($token)
            ->timeout(20)->retry(2, 250, throw: false);
    }

    protected function calendarUrl(string $calendarId): string
    {
        return 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendarId);
    }

    protected function eventUrl(string $calendarId, string $eventId): string
    {
        return $this->calendarUrl($calendarId).'/events/'.rawurlencode($eventId);
    }

    /**
     * @return array<string,mixed>
     */
    protected function decode(Response $response, string $message): array
    {
        $payload = $response->json();
        $json = is_array($payload) ? $payload : [];
        if ($response->successful()) {
            return $json;
        }

        $detail = trim((string) data_get($json, 'error.message', data_get($json, 'error', '')));
        throw new AutomationWorkflowException(
            $message.' (HTTP '.$response->status().($detail !== '' ? ': '.Str::limit($detail, 300) : '').')'
        );
    }
}
