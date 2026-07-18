<?php

namespace App\Console\Commands;

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowAuditEvent;
use App\Models\AutomationWorkflowLink;
use App\Models\AutomationWorkflowState;
use App\Models\AutomationWorkflowVersion;
use App\Models\IntegrationConnection;
use App\Models\TenantMarketingSetting;
use App\Services\Automation\TenantWorkflowAutomationSettingsService;
use App\Services\Automation\WorkflowProductService;
use App\Services\Automation\WorkflowTemplateCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AutomationMigrateLegacySettings extends Command
{
    protected $signature = 'automation:migrate-legacy-settings
        {--tenant= : Limit migration to one tenant id or slug}
        {--dry-run : Report changes without writing them}';

    protected $description = 'Idempotently promote legacy tenant workflow settings into productized workflows and connections.';

    public function handle(WorkflowTemplateCatalog $catalog, WorkflowProductService $product, TenantWorkflowAutomationSettingsService $settings): int
    {
        if (! Schema::hasTable('automation_workflows')) {
            $this->error('Run migrations before promoting legacy workflow settings.');

            return self::FAILURE;
        }

        $tenant = trim((string) $this->option('tenant'));
        $rows = TenantMarketingSetting::query()->where('key', 'workflow_automation_asana_google_calendar')
            ->when($tenant !== '', function ($query) use ($tenant): void {
                $query->whereHas('tenant', fn ($tenantQuery) => $tenantQuery
                    ->where('slug', $tenant)
                    ->orWhere('id', ctype_digit($tenant) ? (int) $tenant : 0));
            })->get();
        $created = 0;
        foreach ($rows as $row) {
            $tenantId = (int) $row->tenant_id;
            if (AutomationWorkflow::query()->forAllTenants()->where('tenant_id', $tenantId)->where('template_key', 'asana_to_google_calendar')->exists()) {
                $this->line("tenant={$tenantId} status=already_migrated");

                continue;
            }
            $stored = is_array($row->value) ? $row->value : [];
            $definition = $catalog->defaultDefinition('asana_to_google_calendar');
            $definition['trigger'] = array_merge((array) $definition['trigger'], (array) ($stored['trigger'] ?? []));
            $definition['action'] = array_merge((array) $definition['action'], (array) ($stored['action'] ?? []));
            $this->line("tenant={$tenantId} status=".($this->option('dry-run') ? 'would_migrate' : 'migrating'));
            if ($this->option('dry-run')) {
                $created++;

                continue;
            }
            $legacySettingId = (int) $row->id;

            DB::transaction(function () use ($tenantId, $definition, $product, $settings, $legacySettingId): void {
                $hash = $product->definitionHash($definition);
                $workflow = AutomationWorkflow::query()->forAllTenants()->create([
                    'tenant_id' => $tenantId,
                    'template_key' => 'asana_to_google_calendar',
                    'name' => 'Asana tasks to Google Calendar',
                    // Cutover is a separate verified operation. Migration never
                    // starts a second scheduler beside an existing Zap/legacy run.
                    'status' => AutomationWorkflow::STATUS_PAUSED,
                    'draft_definition' => $definition,
                    'test_state' => ['migrated' => ['ok' => true, 'definition_hash' => $hash, 'tested_at' => now()->toIso8601String()]],
                    'published_at' => now(),
                ]);
                $version = AutomationWorkflowVersion::query()->forAllTenants()->create([
                    'tenant_id' => $tenantId,
                    'automation_workflow_id' => $workflow->id,
                    'version' => 1,
                    'definition_hash' => $hash,
                    'definition' => $definition,
                    'published_at' => now(),
                ]);
                $workflow->forceFill(['published_version_id' => $version->id])->save();

                $legacyInstanceKey = $settings->instanceKey('asana_to_google_calendar', $tenantId);
                AutomationWorkflowState::query()->where('workflow_key', $legacyInstanceKey)->update([
                    'tenant_id' => $tenantId,
                    'automation_workflow_id' => $workflow->id,
                ]);
                AutomationWorkflowLink::query()->where('workflow_key', $legacyInstanceKey)->update([
                    'tenant_id' => $tenantId,
                    'automation_workflow_id' => $workflow->id,
                ]);

                $credentials = $settings->effectiveCredentials($tenantId);
                foreach ([
                    'asana' => ['access_token' => $credentials['asana_personal_access_token'] ?? null, 'refresh_token' => $credentials['asana_oauth_refresh_token'] ?? null],
                    'google_calendar' => ['access_token' => null, 'refresh_token' => $credentials['google_calendar_refresh_token'] ?? null],
                ] as $provider => $tokens) {
                    if (blank($tokens['access_token']) && blank($tokens['refresh_token'])) {
                        continue;
                    }
                    IntegrationConnection::query()->forAllTenants()->updateOrCreate(
                        ['tenant_id' => $tenantId, 'provider' => $provider, 'external_account_id' => ''],
                        ['external_account_label' => ucfirst(str_replace('_', ' ', $provider)).' workflow account', 'status' => IntegrationConnection::STATUS_CONNECTED, ...$tokens, 'connected_at' => now()]
                    );
                }

                AutomationWorkflowAuditEvent::query()->forAllTenants()->create([
                    'tenant_id' => $tenantId,
                    'automation_workflow_id' => $workflow->id,
                    'event_type' => 'legacy_migrated',
                    'after_state' => [
                        'status' => $workflow->status,
                        'template_key' => $workflow->template_key,
                        'definition_hash' => $hash,
                    ],
                    'context' => ['legacy_setting_id' => $legacySettingId, 'legacy_workflow_key' => $legacyInstanceKey, 'legacy_execution_preserved' => true],
                    'occurred_at' => now(),
                ]);
            });
            $created++;
        }

        $this->info(($this->option('dry-run') ? 'Would migrate ' : 'Migrated ').$created.' workflow(s).');

        return self::SUCCESS;
    }
}
