<?php

namespace App\Console\Commands;

use App\Models\AiBranchAccount;
use App\Models\Tenant;
use App\Services\AiBranch\AiAssistantAccess;
use App\Services\AiBranch\AiBranchAccess;
use App\Services\AiBranch\AiBranchProvider;
use App\Services\AiBranch\AiFictionalPilot;
use App\Services\AiBranch\AiWorkspaceContext;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AiVoiceAudit extends Command
{
    protected $signature = 'ai-branch:audit-voice {--tenant= : Optional exact tenant slug} {--json : Emit a machine-readable inventory}';

    protected $description = 'Read-only audit of AI voice access, context, memory and provider wiring for every tenant; no model requests or grants.';

    public function handle(): int
    {
        $rows = [];
        $storage = Schema::hasTable('ai_branch_conversations') && Schema::hasTable('ai_branch_memories') && Schema::hasTable('tenant_ai_usage_events');
        foreach (Tenant::query()->when($this->option('tenant'), fn ($query) => $query->where('slug', $this->option('tenant')))->orderBy('id')->get() as $tenant) {
            $account = AiBranchAccount::where('tenant_id', $tenant->id)->first();
            $checks = ['branch_access' => false, 'assistant_access' => false, 'workspace_context' => false, 'provider' => false];
            $users = $tenant->users()->wherePivot('membership_active', true)->wherePivotIn('role', ['owner', 'tenant_owner', 'admin'])
                ->where('is_active', true)->whereNotNull('email_verified_at')->get();
            $pilot = app(AiFictionalPilot::class);
            $provider = 'openai';
            foreach ($users as $user) {
                $resolver = request()->getUserResolver();
                request()->setUserResolver(fn () => $user);
                try {
                    $pilot->during($tenant, $user, function () use ($tenant, $user, &$checks, &$provider): void {
                        foreach (['branch_access' => fn () => app(AiBranchAccess::class)->authorize($tenant, $user, false),
                            'assistant_access' => fn () => app(AiAssistantAccess::class)->authorize($tenant, $user),
                            'provider' => fn () => app(AiBranchProvider::class)->settings($tenant)] as $name => $check) {
                            try {
                                $result = $check();
                                $checks[$name] = true;
                                if ($name === 'provider' && isset($result['fictional_scope'])) {
                                    $provider = $result['fictional_provider'] ?? 'scripted';
                                }
                            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                                // Expected unconfigured/ineligible state; no credentials or records in output.
                            }
                        }
                        $checks['workspace_context'] = $checks['workspace_context'] || app(AiWorkspaceContext::class)->enabled($tenant);
                    });
                } finally {
                    request()->setUserResolver($resolver);
                }
            }
            $checks['memory_storage'] = $storage;
            $checks['owner_opt_in'] = (bool) $account?->enabled;
            $checks['positive_budget'] = ($account?->budget_cents ?? 0) > 0;
            if (app(\App\Services\AiBranch\AiBranchOffer::class)->metered($tenant)) {
                $credit = \App\Models\TenantMessagingCreditAccount::forAllTenants()->where('tenant_id', $tenant->id)->first();
                $checks['prepaid_credit'] = ($credit?->availableMicros() ?? 0) > 0;
            }
            $rows[] = ['tenant_id' => $tenant->id, 'tenant' => $tenant->slug, 'provider' => $provider,
                'branch_module' => app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'ai_branch'),
                'eligible_members' => $users->count(), 'checks' => $checks,
                'setup_ready' => $checks['branch_access'] && $storage && $users->isNotEmpty(),
                'ready' => ! in_array(false, $checks, true), 'blocking_checks' => array_keys(array_filter($checks, fn ($value) => ! $value))];
        }
        if ($this->option('json')) {
            $this->line(json_encode(['audited_at' => now()->toIso8601String(), 'read_only' => true, 'provider_dispatches' => 0, 'tenants' => $rows], JSON_PRETTY_PRINT));
        } else {
            $this->table(['Tenant', 'Provider', 'Ready', 'Missing'], array_map(fn ($row) => [$row['tenant'], $row['provider'], $row['ready'] ? 'yes' : 'no', implode(', ', $row['blocking_checks'])], $rows));
        }

        return self::SUCCESS;
    }
}
