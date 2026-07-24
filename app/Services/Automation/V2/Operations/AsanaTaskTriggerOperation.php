<?php

namespace App\Services\Automation\V2\Operations;

use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\V2\Contracts\TriggerOperation;
use App\Services\Automation\V2\Data\TriggerEvent;
use App\Services\Automation\V2\Data\TriggerOperationContext;
use App\Services\Automation\V2\Data\TriggerPollResult;
use App\Services\Automation\V2\PayloadFingerprint;
use App\Services\Automation\V2\Providers\ProviderAccessTokenService;
use App\Services\Automation\V2\Providers\TenantIntegrationConnectionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AsanaTaskTriggerOperation implements TriggerOperation
{
    public function __construct(
        protected TenantIntegrationConnectionResolver $connections,
        protected ProviderAccessTokenService $tokens,
        protected PayloadFingerprint $fingerprints,
    ) {}

    public function poll(TriggerOperationContext $context): TriggerPollResult
    {
        $projectGid = trim((string) ($context->config['project_gid'] ?? ''));
        if ($projectGid === '') {
            throw new AutomationWorkflowException('Choose an Asana project before running this trigger.');
        }

        $connection = $this->connections->resolve($context->tenantId, $context->connectionId, 'asana');
        $modifiedSince = $this->modifiedSince($context);
        $pollLimit = min(100, max(1, (int) ($context->config['poll_limit'] ?? 100)));
        $maxTasks = min($context->limit, max(1, (int) (
            $context->config['max_tasks_per_run']
            ?? $context->config['max_items_per_poll']
            ?? $context->limit
        )));
        [$cursorDate, $cursorSourceId] = $this->watermark($context->cursor);
        $scanLimit = min(5_000, max(1_000, $maxTasks * 10));
        $offset = null;
        $tasks = [];
        $pages = 0;

        do {
            if (++$pages > 100) {
                throw new AutomationWorkflowException(
                    'Asana returned too many overlapping task pages to advance the cursor safely.'
                );
            }
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
                    'completed_at',
                    'created_at',
                    'modified_at',
                    'permalink_url',
                    'assignee.gid',
                    'assignee.name',
                    'custom_fields.gid',
                    'custom_fields.name',
                    'custom_fields.display_value',
                    'tags.gid',
                    'tags.name',
                ]),
            ];
            if ($offset !== null) {
                $query['offset'] = $offset;
            }

            $response = Http::acceptJson()
                ->withToken($this->tokens->token($connection))
                ->timeout(20)->retry(2, 250, throw: false)
                ->get(rtrim((string) config('services.asana.api_base', 'https://app.asana.com/api/1.0'), '/').'/tasks', $query);
            $payload = $this->decode($response, 'Asana tasks fetch failed.');
            foreach ((array) ($payload['data'] ?? []) as $task) {
                if (! is_array($task) || blank($task['gid'] ?? null)) {
                    continue;
                }
                $sourceId = trim((string) $task['gid']);
                $modifiedAt = $this->date((string) ($task['modified_at'] ?? ''));
                if (
                    $modifiedAt === null
                    || ! $this->afterWatermark($modifiedAt, $sourceId, $cursorDate, $cursorSourceId)
                ) {
                    continue;
                }
                $tasks[] = [
                    'source_id' => $sourceId,
                    'modified_at' => $modifiedAt,
                    'payload' => $task,
                ];
                if (count($tasks) > $scanLimit) {
                    throw new AutomationWorkflowException(
                        'Asana returned more changed tasks than can be scanned safely in one poll.'
                    );
                }
            }
            $offset = trim((string) data_get($payload, 'next_page.offset', '')) ?: null;
        } while ($offset !== null);

        usort($tasks, static function (array $left, array $right): int {
            $dateOrder = $left['modified_at']->equalTo($right['modified_at'])
                ? 0
                : ($left['modified_at']->lt($right['modified_at']) ? -1 : 1);

            return $dateOrder !== 0
                ? $dateOrder
                : strcmp((string) $left['source_id'], (string) $right['source_id']);
        });
        $hasMore = count($tasks) > $maxTasks;
        $tasks = array_slice($tasks, 0, $maxTasks);
        $events = [];
        $nextCursor = $context->cursor;
        foreach ($tasks as $record) {
            $task = (array) $record['payload'];
            $sourceId = (string) $record['source_id'];
            /** @var CarbonImmutable $modifiedAt */
            $modifiedAt = $record['modified_at'];
            $output = [
                ...$task,
                'id' => $sourceId,
                'task_id' => $sourceId,
            ];
            $fingerprint = $this->fingerprints->hash($output);
            $nextCursor = $this->formatWatermark($modifiedAt, $sourceId);
            $events[] = new TriggerEvent(
                eventKey: 'asana.task:'.hash('sha256', $sourceId.'|'.$fingerprint),
                sourceSystem: 'asana_task',
                sourceId: $sourceId,
                sourceFingerprint: $fingerprint,
                payload: $output,
                occurredAt: $modifiedAt,
            );
        }

        return new TriggerPollResult(
            events: $events,
            nextCursor: $nextCursor,
            hasMore: $hasMore,
            summary: ['fetched' => count($tasks), 'emitted' => count($events)],
        );
    }

    protected function modifiedSince(TriggerOperationContext $context): CarbonImmutable
    {
        $lookbackDays = max(1, min(90, (int) ($context->config['bootstrap_lookback_days'] ?? 14)));
        $overlapMinutes = max(0, min(60, (int) ($context->config['modified_overlap_minutes'] ?? 5)));

        try {
            [$cursor] = $this->watermark($context->cursor);
            $cursor ??= CarbonImmutable::now()->subDays($lookbackDays);
        } catch (\Throwable) {
            $cursor = CarbonImmutable::now()->subDays($lookbackDays);
        }

        return $overlapMinutes > 0 ? $cursor->subMinutes($overlapMinutes) : $cursor;
    }

    protected function date(string $value): ?CarbonImmutable
    {
        try {
            return trim($value) !== '' ? CarbonImmutable::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0:?CarbonImmutable,1:?string} */
    protected function watermark(?string $cursor): array
    {
        $cursor = trim((string) $cursor);
        if ($cursor === '') {
            return [null, null];
        }

        [$dateValue, $sourceId] = array_pad(explode('|', $cursor, 2), 2, null);

        return [$this->date((string) $dateValue), filled($sourceId) ? (string) $sourceId : null];
    }

    protected function afterWatermark(
        CarbonImmutable $modifiedAt,
        string $sourceId,
        ?CarbonImmutable $cursorDate,
        ?string $cursorSourceId,
    ): bool {
        if ($cursorDate === null) {
            return true;
        }
        if ($cursorSourceId === null) {
            // Schema-v1 cursors contain only a timestamp. On the first v2
            // poll, preserve the legacy overlap selection so shadow parity
            // and the real cutover see the same source set. Once v2 writes a
            // compound timestamp|source cursor, normal strict watermarking
            // prevents the overlap from being accepted again.
            return true;
        }
        if ($modifiedAt->gt($cursorDate)) {
            return true;
        }
        if (! $modifiedAt->equalTo($cursorDate)) {
            return false;
        }

        return strcmp($sourceId, $cursorSourceId) > 0;
    }

    protected function formatWatermark(CarbonImmutable $modifiedAt, string $sourceId): string
    {
        return $modifiedAt->toIso8601String().'|'.$sourceId;
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

        $detail = trim((string) data_get($json, 'errors.0.message', ''));
        throw new AutomationWorkflowException(
            $message.' (HTTP '.$response->status().($detail !== '' ? ': '.Str::limit($detail, 300) : '').')'
        );
    }
}
