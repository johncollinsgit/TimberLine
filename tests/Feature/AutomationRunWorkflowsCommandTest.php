<?php

use App\Models\AutomationWorkflowLink;
use App\Models\AutomationWorkflowState;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\TenantModuleEntitlement;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('automation_workflows.enabled', true);
    config()->set('automation_workflows.workflows.asana_to_google_calendar', [
        'enabled' => true,
        'tenant_id' => 1,
        'required_module' => 'workflow_automations',
        'driver' => 'asana_google_calendar',
        'trigger' => [
            'project_gid' => '1201541082238924',
            'modified_overlap_minutes' => 5,
            'bootstrap_lookback_days' => 14,
            'poll_limit' => 100,
            'max_tasks_per_run' => 500,
        ],
        'action' => [
            'calendar_id' => 'calendar@example.com',
            'timezone' => 'America/New_York',
            'default_start_time' => '12:00:00',
            'default_duration_minutes' => 60,
            'skip_completed_tasks' => true,
        ],
    ]);
    config()->set('services.asana.personal_access_token', 'asana-token');
    config()->set('services.asana.api_base', 'https://app.asana.com/api/1.0');
    config()->set('services.google_calendar.oauth_access_token', 'google-access-token');

    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    config()->set('automation_workflows.workflows.asana_to_google_calendar.tenant_id', $tenant->id);

    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'workflow_automations',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped',
        'entitlement_source' => 'entitlement',
        'price_source' => 'test',
    ]);
});

/**
 * @return array<string,mixed>
 */
function fakeAsanaTaskPayload(string $modifiedAt = '2026-06-01T12:00:00.000Z'): array
{
    return [
        'gid' => '343049949',
        'name' => 'Asana Task Title',
        'notes' => 'Task notes from Asana',
        'due_on' => '2026-06-02',
        'due_at' => null,
        'completed' => false,
        'modified_at' => $modifiedAt,
        'permalink_url' => 'https://app.asana.com/0/1201541082238924/343049949',
    ];
}

test('automation run command creates google calendar events from asana tasks', function (): void {
    Http::fake([
        'https://app.asana.com/api/1.0/tasks*' => Http::response([
            'data' => [fakeAsanaTaskPayload()],
            'next_page' => null,
        ], 200),
        'https://www.googleapis.com/calendar/v3/calendars/*/events' => Http::response([
            'id' => 'google-event-1',
        ], 200),
    ]);

    $exit = Artisan::call('automation:run', [
        '--workflow' => 'asana_to_google_calendar',
    ]);

    expect($exit)->toBe(0);

    expect(AutomationWorkflowLink::query()
        ->where('workflow_key', 'asana_to_google_calendar')
        ->where('source_system', 'asana_task')
        ->where('source_id', '343049949')
        ->value('destination_id'))->toBe('google-event-1');

    expect(AutomationWorkflowState::query()
        ->where('workflow_key', 'asana_to_google_calendar')
        ->value('last_status'))->toBe('success');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://app.asana.com/api/1.0/tasks')
            && ($request['project'] ?? null) === '1201541082238924';
    });

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/calendar/v3/calendars/calendar%40example.com/events')
        && $request['start'] === ['date' => '2026-06-02']
        && $request['end'] === ['date' => '2026-06-03']);
});

test('automation maps timed tasks to timed events and skips completed tasks', function (): void {
    $timed = fakeAsanaTaskPayload();
    $timed['gid'] = 'timed-task';
    $timed['due_on'] = null;
    $timed['due_at'] = '2026-06-02T16:30:00.000Z';
    $completed = fakeAsanaTaskPayload();
    $completed['gid'] = 'completed-task';
    $completed['completed'] = true;

    Http::fake([
        'https://app.asana.com/api/1.0/tasks*' => Http::response(['data' => [$timed, $completed], 'next_page' => null]),
        'https://www.googleapis.com/calendar/v3/calendars/*/events' => Http::response(['id' => 'timed-event']),
    ]);

    expect(Artisan::call('automation:run', ['--workflow' => 'asana_to_google_calendar']))->toBe(0)
        ->and(Artisan::output())->toContain('created=1')->toContain('skipped=1');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && data_get($request->data(), 'start.dateTime') === '2026-06-02T12:30:00-04:00'
        && data_get($request->data(), 'end.dateTime') === '2026-06-02T13:30:00-04:00');
    expect(AutomationWorkflowLink::query()->where('source_id', 'completed-task')->exists())->toBeFalse();
});

test('automation recreates a remembered destination when google returns 404', function (): void {
    AutomationWorkflowLink::query()->create([
        'workflow_key' => 'asana_to_google_calendar',
        'source_system' => 'asana_task',
        'source_id' => '343049949',
        'destination_system' => 'google_calendar_event',
        'destination_id' => 'missing-event',
        'source_fingerprint' => 'outdated-fingerprint',
    ]);

    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(['data' => [fakeAsanaTaskPayload()], 'next_page' => null]);
        }
        if ($request->method() === 'PATCH') {
            return Http::response(['error' => ['message' => 'Not found']], 404);
        }

        return Http::response(['id' => 'replacement-event'], 200);
    });

    expect(Artisan::call('automation:run', ['--workflow' => 'asana_to_google_calendar']))->toBe(0)
        ->and(AutomationWorkflowLink::query()->where('source_id', '343049949')->value('destination_id'))->toBe('replacement-event');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && str_contains($request->url(), 'missing-event'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/events'));
});

test('automation keeps its cursor behind unresolved partial failures', function (): void {
    $cursor = '2026-05-31T10:00:00+00:00';
    AutomationWorkflowState::query()->create([
        'workflow_key' => 'asana_to_google_calendar',
        'cursor' => $cursor,
        'status' => 'idle',
    ]);

    Http::fake([
        'https://app.asana.com/api/1.0/tasks*' => Http::response([
            'data' => [fakeAsanaTaskPayload('2026-06-02T12:00:00.000Z')],
            'next_page' => null,
        ]),
        'https://www.googleapis.com/calendar/v3/calendars/*/events' => Http::response(['error' => ['message' => 'Temporary failure']], 500),
    ]);

    expect(Artisan::call('automation:run', ['--workflow' => 'asana_to_google_calendar']))->toBe(1)
        ->and(AutomationWorkflowState::query()->where('workflow_key', 'asana_to_google_calendar')->value('cursor'))->toBe($cursor)
        ->and(AutomationWorkflowState::query()->where('workflow_key', 'asana_to_google_calendar')->value('last_status'))->toBe('partial_failure');
});

test('automation run command is idempotent when source fingerprint is unchanged', function (): void {
    Http::fake([
        'https://app.asana.com/api/1.0/tasks*' => Http::response([
            'data' => [fakeAsanaTaskPayload()],
            'next_page' => null,
        ], 200),
        'https://www.googleapis.com/calendar/v3/calendars/*/events' => Http::response([
            'id' => 'google-event-1',
        ], 200),
    ]);

    expect(Artisan::call('automation:run', [
        '--workflow' => 'asana_to_google_calendar',
    ]))->toBe(0);

    Http::fake([
        'https://app.asana.com/api/1.0/tasks*' => Http::response([
            'data' => [fakeAsanaTaskPayload()],
            'next_page' => null,
        ], 200),
        '*' => Http::response(['error' => 'unexpected_google_request'], 500),
    ]);

    $exit = Artisan::call('automation:run', [
        '--workflow' => 'asana_to_google_calendar',
    ]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('unchanged=1');
});

test('automation run command fails when the required tenant module is unavailable', function (): void {
    TenantModuleEntitlement::query()->delete();
    app()->forgetInstance(TenantModuleAccessResolver::class);
    Http::fake();

    $exit = Artisan::call('automation:run', [
        '--workflow' => 'asana_to_google_calendar',
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('status=partial_failure');

    Http::assertNothingSent();
});

test('automation run command executes tenant saved workflow definitions when global config is off', function (): void {
    config()->set('automation_workflows.enabled', false);

    $tenant = Tenant::query()->firstOrFail();

    TenantMarketingSetting::query()->updateOrCreate(
        [
            'tenant_id' => $tenant->id,
            'key' => 'workflow_automation_asana_google_calendar',
        ],
        [
            'value' => [
                'workflow_key' => 'asana_to_google_calendar',
                'enabled' => true,
                'trigger' => [
                    'project_gid' => '1201541082238924',
                    'modified_overlap_minutes' => 5,
                    'bootstrap_lookback_days' => 14,
                    'poll_limit' => 100,
                    'max_tasks_per_run' => 500,
                ],
                'action' => [
                    'calendar_id' => 'calendar@example.com',
                    'timezone' => 'America/New_York',
                    'default_start_time' => '12:00:00',
                    'default_duration_minutes' => 60,
                    'skip_completed_tasks' => true,
                ],
                'credentials' => [
                    'asana_personal_access_token_encrypted' => Crypt::encryptString('tenant-asana-token'),
                    'google_calendar_client_id_encrypted' => Crypt::encryptString('tenant-google-client-id'),
                    'google_calendar_client_secret_encrypted' => Crypt::encryptString('tenant-google-client-secret'),
                    'google_calendar_refresh_token_encrypted' => Crypt::encryptString('tenant-google-refresh-token'),
                ],
            ],
            'description' => 'Tenant workflow automation test definition.',
        ]
    );

    Http::fake([
        'https://app.asana.com/api/1.0/tasks*' => Http::response([
            'data' => [fakeAsanaTaskPayload()],
            'next_page' => null,
        ], 200),
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'google-access-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ], 200),
        'https://www.googleapis.com/calendar/v3/calendars/*/events' => Http::response([
            'id' => 'google-event-tenant-1',
        ], 200),
    ]);

    $exit = Artisan::call('automation:run', [
        '--workflow' => 'asana_to_google_calendar',
    ]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('workflow=asana_to_google_calendar::tenant:'.$tenant->id);

    expect(AutomationWorkflowLink::query()
        ->where('workflow_key', 'asana_to_google_calendar::tenant:'.$tenant->id)
        ->where('source_id', '343049949')
        ->value('destination_id'))->toBe('google-event-tenant-1');
});
