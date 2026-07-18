<?php

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowAuditEvent;
use App\Models\AutomationWorkflowLink;
use App\Models\AutomationWorkflowState;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\TenantModuleEntitlement;
use App\Services\Automation\WorkflowProductService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

test('legacy cutover preserves cursor and destination links and activates only after live verification', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry-cutover']);
    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'workflow_automations',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped',
        'entitlement_source' => 'entitlement',
        'price_source' => 'test',
    ]);
    config()->set('services.google_calendar.oauth_client_id', 'shared-google-client');
    config()->set('services.google_calendar.oauth_client_secret', 'shared-google-secret');
    config()->set('services.asana.api_base', 'https://app.asana.com/api/1.0');

    TenantMarketingSetting::query()->create([
        'tenant_id' => $tenant->id,
        'key' => 'workflow_automation_asana_google_calendar',
        'description' => 'Existing Modern Forestry automation',
        'value' => [
            'enabled' => true,
            'trigger' => ['project_gid' => 'modern-project'],
            'action' => ['calendar_id' => 'modern-calendar', 'timezone' => 'America/New_York'],
            'credentials' => [
                'asana_personal_access_token_encrypted' => Crypt::encryptString('asana-token'),
                'google_calendar_client_id_encrypted' => Crypt::encryptString('legacy-google-client'),
                'google_calendar_client_secret_encrypted' => Crypt::encryptString('legacy-google-secret'),
                'google_calendar_refresh_token_encrypted' => Crypt::encryptString('google-refresh'),
            ],
        ],
    ]);
    $legacyKey = 'asana_to_google_calendar::tenant:'.$tenant->id;
    AutomationWorkflowState::query()->create(['workflow_key' => $legacyKey, 'status' => 'idle', 'cursor' => '2026-07-17T10:00:00+00:00']);
    AutomationWorkflowLink::query()->create([
        'workflow_key' => $legacyKey,
        'source_system' => 'asana_task',
        'source_id' => 'task-1',
        'destination_system' => 'google_calendar_event',
        'destination_id' => 'existing-event',
        'source_fingerprint' => 'legacy-fingerprint',
    ]);

    $this->artisan('automation:migrate-legacy-settings --tenant=modern-forestry-cutover')->assertSuccessful();
    $workflow = AutomationWorkflow::query()->forAllTenants()->where('tenant_id', $tenant->id)->firstOrFail();
    expect($workflow->status)->toBe(AutomationWorkflow::STATUS_PAUSED)
        ->and(AutomationWorkflowLink::query()->where('source_id', 'task-1')->value('automation_workflow_id'))->toBe($workflow->id);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'app.asana.com/api/1.0/tasks')) {
            return Http::response(['data' => [[
                'gid' => 'task-1', 'name' => 'Preserved task', 'notes' => 'Same automation',
                'due_on' => '2026-07-21', 'due_at' => null, 'completed' => false,
                'modified_at' => '2026-07-18T12:00:00.000Z', 'permalink_url' => 'https://app.asana.com/task-1',
            ]], 'next_page' => null]);
        }
        if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
            return Http::response(['access_token' => 'google-access', 'expires_in' => 3600]);
        }
        if ($request->method() === 'PATCH' && str_contains($request->url(), '/events/existing-event')) {
            return Http::response(['id' => 'existing-event']);
        }

        return Http::response(['error' => 'unexpected'], 500);
    });

    $this->artisan('automation:cutover-legacy modern-forestry-cutover --dry-run')
        ->expectsOutputToContain('Preview passed')
        ->assertSuccessful();
    expect((bool) data_get(TenantMarketingSetting::query()->where('tenant_id', $tenant->id)->value('value'), 'enabled'))->toBeTrue()
        ->and($workflow->fresh()->status)->toBe(AutomationWorkflow::STATUS_PAUSED);

    $this->artisan('automation:cutover-legacy modern-forestry-cutover --confirm')
        ->expectsOutputToContain('Cutover verified')
        ->assertSuccessful();

    expect((bool) data_get(TenantMarketingSetting::query()->where('tenant_id', $tenant->id)->value('value'), 'enabled'))->toBeFalse()
        ->and($workflow->fresh()->status)->toBe(AutomationWorkflow::STATUS_ACTIVE)
        ->and(AutomationWorkflowLink::query()->where('source_id', 'task-1')->value('destination_id'))->toBe('existing-event')
        ->and(AutomationWorkflowLink::query()->where('source_id', 'task-1')->count())->toBe(1)
        ->and(AutomationWorkflowAuditEvent::query()->forAllTenants()->where('automation_workflow_id', $workflow->id)->where('event_type', 'legacy_cutover_completed')->exists())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && str_contains($request->url(), '/events/existing-event'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'oauth2.googleapis.com/token')
        && $request['client_id'] === 'legacy-google-client'
        && $request['client_secret'] === 'legacy-google-secret'
        && $request['refresh_token'] === 'google-refresh');

    $googleConnection = IntegrationConnection::query()->forAllTenants()
        ->where('tenant_id', $tenant->id)->where('provider', 'google_calendar')->firstOrFail();
    $googleConnection->forceFill(['metadata' => ['credential_source' => 'shared_oauth']])->save();
    $runtimeMethod = new ReflectionMethod(WorkflowProductService::class, 'runtimeDefinition');
    $runtime = $runtimeMethod->invoke(
        app(WorkflowProductService::class),
        $workflow->fresh(),
        (array) $workflow->publishedVersion->definition,
    );
    expect(data_get($runtime, 'credentials.google_calendar_client_id'))->toBe('shared-google-client')
        ->and(data_get($runtime, 'credentials.google_calendar_client_secret'))->toBe('shared-google-secret');
});
