<?php

use App\Models\AutomationWorkflowLink;
use App\Models\AutomationWorkflowState;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('automation_workflows.enabled', false);
    config()->set('services.asana.personal_access_token', '');
    config()->set('services.google_calendar.oauth_access_token', '');
    config()->set('services.google_calendar.oauth_client_id', '');
    config()->set('services.google_calendar.oauth_client_secret', '');
    config()->set('services.google_calendar.oauth_refresh_token', '');
});

/**
 * @return array<string,mixed>
 */
function workflowAutomationFormData(array $overrides = []): array
{
    return array_merge([
        'workflow_key' => 'asana_to_google_calendar',
        'submit_action' => 'save',
        'enabled' => '1',
        'project_gid' => '1201541082238924',
        'calendar_id' => 'calendar@example.com',
        'timezone' => 'America/New_York',
        'default_start_time' => '12:00',
        'default_duration_minutes' => '60',
        'skip_completed_tasks' => '1',
        'modified_overlap_minutes' => '5',
        'bootstrap_lookback_days' => '14',
        'poll_limit' => '100',
        'max_tasks_per_run' => '500',
        'asana_personal_access_token' => 'asana-token-1234',
        'google_calendar_client_id' => 'google-client-id-1234',
        'google_calendar_client_secret' => 'google-client-secret-1234',
        'google_calendar_refresh_token' => 'google-refresh-token-1234',
    ], $overrides);
}

/**
 * @return array<string,mixed>
 */
function fakeWorkflowAsanaTaskPayload(string $modifiedAt = '2026-06-01T12:00:00.000Z'): array
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

test('workflow automation setup saves encrypted tenant credentials and preserves them across edits', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'workflow_automations',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped',
        'entitlement_source' => 'entitlement',
        'price_source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id]);

    $this->actingAs($user)
        ->post(route('marketing.providers-integrations.workflow-automations.save'), workflowAutomationFormData())
        ->assertRedirect(route('marketing.providers-integrations'))
        ->assertSessionHas('toast', fn (array $toast): bool => ($toast['style'] ?? null) === 'success');

    $setting = TenantMarketingSetting::query()
        ->where('tenant_id', $tenant->id)
        ->where('key', 'workflow_automation_asana_google_calendar')
        ->firstOrFail();

    $encryptedAsanaToken = data_get($setting->value, 'credentials.asana_personal_access_token_encrypted');
    $encryptedGoogleRefreshToken = data_get($setting->value, 'credentials.google_calendar_refresh_token_encrypted');

    expect($encryptedAsanaToken)->not->toBe('asana-token-1234')
        ->and(Crypt::decryptString((string) $encryptedAsanaToken))->toBe('asana-token-1234')
        ->and(Crypt::decryptString((string) $encryptedGoogleRefreshToken))->toBe('google-refresh-token-1234');

    $this->actingAs($user)
        ->post(route('marketing.providers-integrations.workflow-automations.save'), workflowAutomationFormData([
            'calendar_id' => 'updated-calendar@example.com',
            'asana_personal_access_token' => '',
            'google_calendar_client_id' => '',
            'google_calendar_client_secret' => '',
            'google_calendar_refresh_token' => '',
        ]))
        ->assertRedirect(route('marketing.providers-integrations'));

    $setting = $setting->fresh();

    expect(data_get($setting->value, 'action.calendar_id'))->toBe('updated-calendar@example.com')
        ->and(Crypt::decryptString((string) data_get($setting->value, 'credentials.asana_personal_access_token_encrypted')))
        ->toBe('asana-token-1234');

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations'))
        ->assertOk()
        ->assertSeeText('Asana to Google Calendar')
        ->assertSeeText('Native Zap Replacement')
        ->assertSeeText('Saved for this tenant')
        ->assertSeeText('asan********1234');
});

test('workflow automation setup can run live from the connections page using tenant saved credentials', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'workflow_automations',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped',
        'entitlement_source' => 'entitlement',
        'price_source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id]);

    Http::fake([
        'https://app.asana.com/api/1.0/tasks*' => Http::response([
            'data' => [fakeWorkflowAsanaTaskPayload()],
            'next_page' => null,
        ], 200),
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'google-access-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ], 200),
        'https://www.googleapis.com/calendar/v3/calendars/*/events' => Http::response([
            'id' => 'google-event-1',
        ], 200),
    ]);

    $this->actingAs($user)
        ->post(route('marketing.providers-integrations.workflow-automations.save'), workflowAutomationFormData([
            'submit_action' => 'run_now',
        ]))
        ->assertRedirect(route('marketing.providers-integrations'))
        ->assertSessionHas('toast', function (array $toast): bool {
            return ($toast['style'] ?? null) === 'success'
                && str_contains((string) ($toast['message'] ?? ''), 'Live run completed');
        });

    $instanceKey = 'asana_to_google_calendar::tenant:'.$tenant->id;

    expect(AutomationWorkflowLink::query()
        ->where('workflow_key', $instanceKey)
        ->where('source_system', 'asana_task')
        ->where('source_id', '343049949')
        ->value('destination_id'))->toBe('google-event-1');

    expect(AutomationWorkflowState::query()
        ->where('workflow_key', $instanceKey)
        ->value('last_status'))->toBe('success');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://app.asana.com/api/1.0/tasks')
            && ($request['project'] ?? null) === '1201541082238924';
    });

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://oauth2.googleapis.com/token');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/calendar/v3/calendars/calendar%40example.com/events'));
});

test('workflow automation setup can launch google calendar oauth from the connections page', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'workflow_automations',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped',
        'entitlement_source' => 'entitlement',
        'price_source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id]);

    $response = $this->actingAs($user)
        ->post(route('marketing.providers-integrations.workflow-automations.save'), workflowAutomationFormData([
            'submit_action' => 'connect_google',
        ]));

    $response->assertRedirect();

    $redirect = $response->headers->get('Location');

    expect($redirect)->not->toBeNull()
        ->and($redirect)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and(urldecode((string) $redirect))->toContain((string) config('services.google_calendar.redirect_uri'))
        ->and(urldecode((string) $redirect))->toContain('https://www.googleapis.com/auth/calendar');
});

test('google calendar oauth callback stores refresh token and auto-selects the only writable calendar', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'workflow_automations',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped',
        'entitlement_source' => 'entitlement',
        'price_source' => 'test',
    ]);

    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id]);

    $connectResponse = $this->actingAs($user)
        ->post(route('marketing.providers-integrations.workflow-automations.save'), workflowAutomationFormData([
            'submit_action' => 'connect_google',
            'calendar_id' => '',
        ]))
        ->assertRedirect();

    $redirect = $connectResponse->headers->get('Location');
    parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $query);
    $state = (string) ($query['state'] ?? '');

    expect($state)->not->toBe('');

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'connected-google-access-token',
            'refresh_token' => 'connected-google-refresh-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'scope' => 'https://www.googleapis.com/auth/calendar',
        ], 200),
        'https://www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
            'items' => [
                [
                    'id' => 'selected-calendar@example.com',
                    'summary' => 'Asana to Skylight',
                    'timeZone' => 'America/New_York',
                    'primary' => true,
                    'accessRole' => 'owner',
                ],
            ],
        ], 200),
    ]);

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations.workflow-automations.google-calendar.callback', [
            'code' => 'oauth-code-123',
            'state' => $state,
        ]))
        ->assertRedirect(route('marketing.providers-integrations'))
        ->assertSessionHas('toast', function (array $toast): bool {
            return ($toast['style'] ?? null) === 'success'
                && str_contains((string) ($toast['message'] ?? ''), 'selected automatically');
        });

    $setting = TenantMarketingSetting::query()
        ->where('tenant_id', $tenant->id)
        ->where('key', 'workflow_automation_asana_google_calendar')
        ->firstOrFail();

    expect(Crypt::decryptString((string) data_get($setting->value, 'credentials.google_calendar_refresh_token_encrypted')))
        ->toBe('connected-google-refresh-token')
        ->and(data_get($setting->value, 'action.calendar_id'))->toBe('selected-calendar@example.com');

    $this->actingAs($user)
        ->withSession(['tenant_id' => $tenant->id])
        ->get(route('marketing.providers-integrations'))
        ->assertOk()
        ->assertSeeText('Connected via Google OAuth')
        ->assertSeeText('Asana to Skylight');
});
