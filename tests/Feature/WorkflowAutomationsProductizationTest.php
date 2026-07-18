<?php

use App\Jobs\RunAutomationWorkflowJob;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowAuditEvent;
use App\Models\AutomationWorkflowLink;
use App\Models\AutomationWorkflowState;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\TenantWorkflowAutomationSettingsService;
use App\Services\Automation\WorkflowProductService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;

function entitledWorkflowTenant(string $slug = 'workflow-tenant'): array
{
    $tenant = Tenant::query()->create(['name' => str($slug)->headline(), 'slug' => $slug]);
    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'workflow_automations',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped',
        'entitlement_source' => 'entitlement',
        'price_source' => 'test',
    ]);
    $user = User::factory()->create(['role' => 'marketing_manager', 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'marketing_manager']);

    return [$tenant, $user];
}

function workflowConnections(Tenant $tenant, User $user): void
{
    config()->set('services.asana.oauth_client_id', 'shared-asana-client');
    config()->set('services.asana.oauth_client_secret', 'shared-asana-secret');
    config()->set('services.google_calendar.oauth_client_id', 'shared-google-client');
    config()->set('services.google_calendar.oauth_client_secret', 'shared-google-secret');

    foreach (['asana', 'google_calendar'] as $provider) {
        IntegrationConnection::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => $provider,
            'external_account_id' => $provider.'-account',
            'external_account_label' => str($provider)->replace('_', ' ')->headline().' account',
            'status' => IntegrationConnection::STATUS_CONNECTED,
            'refresh_token' => $provider.'-refresh-token',
            'connected_by_user_id' => $user->id,
            'connected_at' => now(),
        ]);
    }
}

test('workflow library and templates are entitled, tenant scoped, and credential safe', function (): void {
    [$tenant, $user] = entitledWorkflowTenant();

    $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->get(route('workflows.create'))
        ->assertOk()
        ->assertSeeText('Asana tasks to Google Calendar')
        ->assertSeeText('Shopify orders to Google Calendar')
        ->assertSeeText('Squarespace orders to Google Calendar')
        ->assertSeeText('Square orders to Google Calendar')
        ->assertSeeText('Wix orders to Google Calendar')
        ->assertDontSee('client_secret')
        ->assertDontSee('refresh_token');

    $workflow = app(WorkflowProductService::class)->create($tenant->id, 'asana_to_google_calendar', $user);
    $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->get(route('workflows.show', $workflow))
        ->assertOk()
        ->assertSeeText('Setup')
        ->assertSeeText('Test')
        ->assertSeeText('Calendar appearance')
        ->assertDontSee('client_secret')
        ->assertDontSee('refresh_token');

    $otherTenant = Tenant::query()->create(['name' => 'Other', 'slug' => 'other-workflow-tenant']);
    $otherWorkflow = AutomationWorkflow::query()->forAllTenants()->create([
        'tenant_id' => $otherTenant->id,
        'template_key' => 'asana_to_google_calendar',
        'name' => 'Other tenant workflow',
        'status' => AutomationWorkflow::STATUS_DRAFT,
        'draft_definition' => app(\App\Services\Automation\WorkflowTemplateCatalog::class)->defaultDefinition('asana_to_google_calendar'),
    ]);

    $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->get(route('workflows.show', $otherWorkflow->id))
        ->assertNotFound();
});

test('disabled workflow entitlement fails closed', function (): void {
    $tenant = Tenant::query()->create(['name' => 'No Automations', 'slug' => 'no-automations']);
    $user = User::factory()->create(['role' => 'marketing_manager', 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'marketing_manager']);

    $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->get(route('workflows.index'))
        ->assertForbidden();
});

test('multiple workflows publish immutable versions and reuse tenant connections', function (): void {
    [$tenant, $user] = entitledWorkflowTenant('multi-workflow-tenant');
    workflowConnections($tenant, $user);
    $service = app(WorkflowProductService::class);

    $first = $service->create($tenant->id, 'asana_to_google_calendar', $user);
    $second = $service->create($tenant->id, 'asana_to_google_calendar', $user);
    expect(AutomationWorkflow::query()->forAllTenants()->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(IntegrationConnection::query()->forAllTenants()->where('tenant_id', $tenant->id)->count())->toBe(2);

    $first = $service->updateDraft($first, [
        'name' => 'Launch calendar',
        'project_gid' => 'asana-project-1',
        'calendar_id' => 'operations@example.com',
        'timezone' => 'America/New_York',
        'default_duration_minutes' => 60,
        'skip_completed_tasks' => true,
        'event_title_template' => 'Launch · {{task_name}}',
        'event_description_fields' => ['notes', 'source_link'],
        'event_location_source' => 'none',
        'event_color_id' => '10',
        'event_availability' => 'busy',
        'event_visibility' => 'private',
        'event_reminders' => 'none',
        'cancelled_order_behavior' => 'mark_cancelled',
    ], $user);

    expect(fn () => $service->publish($first, $user))->toThrow(AutomationWorkflowException::class);

    $hash = $service->definitionHash((array) $first->draft_definition);
    $first->forceFill(['test_state' => [
        'trigger' => ['ok' => true, 'definition_hash' => $hash],
        'action' => ['ok' => true, 'definition_hash' => $hash],
    ]])->save();
    $first = $service->publish($first->fresh(), $user);
    $publishedV1 = $first->publishedVersion;

    $first = $service->updateDraft($first, [
        'name' => 'Launch calendar v2',
        'project_gid' => 'asana-project-1',
        'calendar_id' => 'operations@example.com',
        'timezone' => 'America/New_York',
        'default_duration_minutes' => 90,
        'skip_completed_tasks' => true,
        'event_title_template' => 'Launch · {{task_name}}',
        'event_description_fields' => ['notes', 'source_link'],
        'event_location_source' => 'none',
        'event_color_id' => '10',
        'event_availability' => 'busy',
        'event_visibility' => 'private',
        'event_reminders' => 'none',
        'cancelled_order_behavior' => 'mark_cancelled',
    ], $user);
    $hash = $service->definitionHash((array) $first->draft_definition);
    $first->forceFill(['test_state' => [
        'trigger' => ['ok' => true, 'definition_hash' => $hash],
        'action' => ['ok' => true, 'definition_hash' => $hash],
    ]])->save();
    $first = $service->publish($first->fresh(), $user);

    expect($first->versions()->count())->toBe(2)
        ->and((int) data_get($publishedV1->fresh()->definition, 'action.default_duration_minutes'))->toBe(60)
        ->and((int) data_get($first->publishedVersion->definition, 'action.default_duration_minutes'))->toBe(90)
        ->and(data_get($first->publishedVersion->definition, 'action.presentation.color_id'))->toBe('10')
        ->and(data_get($first->publishedVersion->definition, 'action.presentation.visibility'))->toBe('private');

    $service->pause($first, $user);
    expect($first->fresh()->status)->toBe(AutomationWorkflow::STATUS_PAUSED);
    $service->resume($first->fresh(), $user);
    expect($first->fresh()->status)->toBe(AutomationWorkflow::STATUS_ACTIVE);

    Queue::fake();
    $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
        ->post(route('workflows.run', $first))
        ->assertRedirect();
    Queue::assertPushed(RunAutomationWorkflowJob::class, fn (RunAutomationWorkflowJob $job): bool => $job->workflowId === $first->id
        && $job->mode === 'manual'
        && $job->actorUserId === $user->id);

    $paused = $service->pauseForProvider($tenant->id, 'asana', $user);
    expect($paused)->toBe(1)->and($first->fresh()->status)->toBe(AutomationWorkflow::STATUS_PAUSED);
});

test('legacy migration is dry-run capable and replay safe', function (): void {
    [$tenant] = entitledWorkflowTenant('legacy-workflow-tenant');
    TenantMarketingSetting::query()->create([
        'tenant_id' => $tenant->id,
        'key' => 'workflow_automation_asana_google_calendar',
        'description' => 'Legacy workflow',
        'value' => [
            'enabled' => true,
            'trigger' => ['project_gid' => 'legacy-project'],
            'action' => ['calendar_id' => 'legacy-calendar', 'timezone' => 'America/New_York'],
            'credentials' => [
                'asana_personal_access_token_encrypted' => Crypt::encryptString('legacy-asana-token'),
                'asana_oauth_client_id_encrypted' => Crypt::encryptString('legacy-asana-client'),
                'asana_oauth_client_secret_encrypted' => Crypt::encryptString('legacy-asana-secret'),
                'google_calendar_client_id_encrypted' => Crypt::encryptString('legacy-google-client'),
                'google_calendar_client_secret_encrypted' => Crypt::encryptString('legacy-google-secret'),
                'google_calendar_refresh_token_encrypted' => Crypt::encryptString('legacy-google-token'),
            ],
        ],
    ]);
    $legacyKey = 'asana_to_google_calendar::tenant:'.$tenant->id;
    AutomationWorkflowState::query()->create(['workflow_key' => $legacyKey, 'status' => 'idle', 'cursor' => '2026-07-01T00:00:00+00:00']);
    AutomationWorkflowLink::query()->create([
        'workflow_key' => $legacyKey,
        'source_system' => 'asana_task',
        'source_id' => 'legacy-task',
        'destination_system' => 'google_calendar_event',
        'destination_id' => 'existing-event',
    ]);

    $this->artisan('automation:migrate-legacy-settings --dry-run')->assertSuccessful();
    expect(AutomationWorkflow::query()->forAllTenants()->where('tenant_id', $tenant->id)->count())->toBe(0);

    $this->artisan('automation:migrate-legacy-settings')->assertSuccessful();
    $this->artisan('automation:migrate-legacy-settings')->assertSuccessful();

    $workflow = AutomationWorkflow::query()->forAllTenants()->where('tenant_id', $tenant->id)->firstOrFail();
    $migratedConnections = IntegrationConnection::query()->forAllTenants()->where('tenant_id', $tenant->id)->get();
    expect(AutomationWorkflow::query()->forAllTenants()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and($migratedConnections)->toHaveCount(2)
        ->and($migratedConnections->every(fn (IntegrationConnection $connection): bool => data_get($connection->metadata, 'credential_source') === 'legacy_migration'))->toBeTrue()
        ->and(AutomationWorkflowState::query()->where('workflow_key', $legacyKey)->value('automation_workflow_id'))->toBe($workflow->id)
        ->and(AutomationWorkflowLink::query()->where('workflow_key', $legacyKey)->value('automation_workflow_id'))->toBe($workflow->id)
        ->and(AutomationWorkflowLink::query()->where('destination_id', 'existing-event')->count())->toBe(1)
        ->and(AutomationWorkflowAuditEvent::query()->forAllTenants()->where('automation_workflow_id', $workflow->id)->where('event_type', 'legacy_migrated')->count())->toBe(1)
        ->and(TenantMarketingSetting::query()->where('tenant_id', $tenant->id)->where('key', 'workflow_automation_asana_google_calendar')->exists())->toBeTrue();

    config()->set('services.asana.oauth_client_id', 'shared-asana-client');
    config()->set('services.asana.oauth_client_secret', 'shared-asana-secret');
    config()->set('services.google_calendar.oauth_client_id', 'shared-google-client');
    config()->set('services.google_calendar.oauth_client_secret', 'shared-google-secret');

    $settings = app(TenantWorkflowAutomationSettingsService::class);
    $sharedCredentials = $settings->effectiveCredentials($tenant->id);
    $migrationCredentials = $settings->effectiveCredentials($tenant->id, preferLegacyOAuthClients: true);

    expect($sharedCredentials['google_calendar_client_id'])->toBe('shared-google-client')
        ->and($sharedCredentials['google_calendar_client_secret'])->toBe('shared-google-secret')
        ->and($sharedCredentials['sources']['google_calendar_client_id'])->toBe('global')
        ->and($migrationCredentials['asana_oauth_client_id'])->toBe('legacy-asana-client')
        ->and($migrationCredentials['asana_oauth_client_secret'])->toBe('legacy-asana-secret')
        ->and($migrationCredentials['google_calendar_client_id'])->toBe('legacy-google-client')
        ->and($migrationCredentials['google_calendar_client_secret'])->toBe('legacy-google-secret')
        ->and($migrationCredentials['sources']['google_calendar_client_id'])->toBe('legacy_tenant');
});
