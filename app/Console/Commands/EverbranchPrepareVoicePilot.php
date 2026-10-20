<?php

namespace App\Console\Commands;

use App\Models\AiBranchAccount;
use App\Models\CustomerLoopAction;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceTask;
use App\Models\FieldServiceTaskEvent;
use App\Models\LandlordOperatorAction;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantSetupStatus;
use App\Models\User;
use App\Services\AiBranch\AiFictionalPilot;
use App\Services\Tenancy\LandlordCommercialConfigService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EverbranchPrepareVoicePilot extends Command
{
    protected $signature = 'everbranch:prepare-voice-pilot
        {--owner-email= : Existing verified account receiving this fictional workspace}
        {--authorization= : Human approval reference, without secrets}
        {--provider= : scripted or openai; omitted renewals retain the approved provider}
        {--budget-dollars= : Explicit total lifetime provider cap for an openai pilot, at most 15.00}
        {--verify : Read-only check of the grant, canonical access and approved provider}
        {--apply : Create or renew the seven-day pilot grant}
        {--revoke : Revoke the pilot instead of provisioning}
        {--force-production : Acknowledge provisioning synthetic records in production}';

    protected $description = 'Preview or provision an isolated Ask Everbranch pilot with scripted or explicitly capped AI answers; never provisions a subscription.';

    public function handle(LandlordCommercialConfigService $commercial, LandlordOperatorActionAuditService $audit): int
    {
        $owner = User::where('email', strtolower(trim((string) $this->option('owner-email'))))->first();
        if (! $owner || ! $owner->is_active || ! $owner->hasVerifiedEmail()) {
            $this->error('The owner must be an existing active, email-verified account. No account was created.');

            return self::FAILURE;
        }
        $existing = Tenant::where('slug', AiFictionalPilot::SLUG)->first();
        $previous = $existing ? LandlordOperatorAction::where('tenant_id', $existing->id)->where('action_type', 'ai_voice.pilot.grant')->latest('id')->first() : null;
        if ($existing && (! $previous || (int) data_get($previous->result, 'owner_user_id') !== (int) $owner->id
            || ! TenantAccessProfile::where('tenant_id', $existing->id)->where('source', AiFictionalPilot::SOURCE)->exists())) {
            $this->error('The reserved workspace is not this account\'s previously audited fictional pilot. No takeover allowed.');

            return self::FAILURE;
        }
        $provider = $this->option('provider') ?: data_get($previous?->result, 'provider', 'scripted');
        if (! in_array($provider, ['scripted', 'openai'], true)) {
            $this->error('Provider must be scripted or openai.');

            return self::FAILURE;
        }
        $budget = null;
        $providerSettings = null;
        if ($provider === 'openai' && ! $this->option('revoke') && ! $this->option('verify')) {
            $amount = (string) $this->option('budget-dollars');
            if (! preg_match('/^\d{1,2}(\.\d{1,2})?$/', $amount) || ($budget = (int) round((float) $amount * 100)) < 1 || $budget > 1500) {
                $this->error('Real AI requires an explicit total --budget-dollars between 0.01 and 15.00, including renewals.');

                return self::FAILURE;
            }
            try {
                $providerSettings = app(\App\Services\AiBranch\AiBranchProvider::class)->pilotSettings();
            } catch (\Throwable) {
                $this->error('Dedicated AI Branch credentials, model and verified rates must be configured before granting real AI.');

                return self::FAILURE;
            }
        }
        $this->line('Workspace: '.AiFictionalPilot::SLUG.'; existing owner #'.$owner->id.'; provider: '.$provider
            .($budget !== null ? '; total lifetime provider cap: $'.number_format($budget / 100, 2).'.' : '.'));
        $this->line('Only synthetic jobs, notes, tasks and follow-ups. No subscription, payment, email, SMS, phone call or integration connection.');
        if ($this->option('verify')) {
            $pilot = app(AiFictionalPilot::class);
            if (! $existing || ! $pilot->grant($existing, $owner)) {
                $this->error('No active pilot grant for this owner.');

                return self::FAILURE;
            }
            $resolver = request()->getUserResolver();
            request()->setUserResolver(fn () => $owner);
            try {
                $pilot->during($existing, $owner, function () use ($existing, $owner) {
                    app(\App\Services\AiBranch\AiAssistantAccess::class)->authorize($existing, $owner);
                    $settings = app(\App\Services\AiBranch\AiBranchProvider::class)->settings();
                    abort_unless(isset($settings['fictional_scope']), 403);
                    $snapshot = app(\App\Services\AiBranch\AiWorkspaceContext::class)->capture($existing, $owner);
                    $this->info('Verified active '.($settings['fictional_provider'] ?? 'scripted').' grant; canonical access passed; no provider dispatched. Permitted sample records: '.count($snapshot['sources']));
                });
            } finally {
                request()->setUserResolver($resolver);
            }

            return self::SUCCESS;
        }
        if (! $this->option('apply')) {
            $this->info('Preview only. No records or grants changed.');

            return self::SUCCESS;
        }
        if (trim((string) $this->option('authorization')) === '' || (app()->isProduction() && ! $this->option('force-production'))) {
            $this->error('Apply requires an authorization reference and --force-production on production.');

            return self::FAILURE;
        }
        if ($this->option('revoke')) {
            if (! $existing) {
                $this->error('No pilot exists to revoke.');

                return self::FAILURE;
            }
            $audit->record($existing->id, null, 'ai_voice.pilot.revoke', confirmation: ['explicit' => true, 'reference' => $this->option('authorization')]);
            $this->info('Pilot revoked. Fictional records and receipts retained.');

            return self::SUCCESS;
        }
        $grant = DB::transaction(function () use ($owner, $commercial, $audit, $provider, $budget, $providerSettings) {
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $tenant = Tenant::firstOrCreate(['slug' => AiFictionalPilot::SLUG], ['name' => 'Fictional Cedar Works - Voice Pilot']);
            $tenant = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $prior = LandlordOperatorAction::where('tenant_id', $tenant->id)->where('action_type', 'ai_voice.pilot.grant')->latest('id')->first();
            abort_if($prior && (int) data_get($prior->result, 'owner_user_id') !== (int) $owner->id, 409);
            abort_if($prior && ! TenantAccessProfile::where('tenant_id', $tenant->id)->where('source', AiFictionalPilot::SOURCE)->exists(), 409);
            $tenant->users()->syncWithoutDetaching([$owner->id => ['role' => 'owner', 'membership_active' => true]]);
            if (! $prior) {
                $profile = $commercial->assignTenantPlan($tenant->id, 'base', 'direct', AiFictionalPilot::SOURCE);
                $profile->metadata = ['account_mode' => 'demo', 'tenant_blueprint' => ['business_template' => 'field_service'], 'ai_branch' => ['timezone' => 'America/New_York']];
                $profile->save();
                TenantSetupStatus::create(['tenant_id' => $tenant->id, 'business_profile_status' => 'ready', 'import_path' => 'manual',
                    'landlord_review_status' => 'reviewed', 'billing_lane_interest' => 'free_internal_demo']);
                foreach (['field_service', 'customers', 'marketing', 'customer_loop', 'ai_branch'] as $module) {
                    if (! config('module_catalog.modules.'.$module)) {
                        continue;
                    }
                    $commercial->setTenantModuleEntitlement($tenant->id, $module, ['availability_status' => 'available', 'enabled_status' => 'enabled',
                        'billing_status' => 'trial', 'currency' => 'USD', 'entitlement_source' => AiFictionalPilot::SOURCE,
                        'notes' => 'Synthetic pilot only. No paid subscription or automated customer charge.', 'ends_at' => now()->addDays(7)]);
                }
                AiBranchAccount::create(['tenant_id' => $tenant->id, 'enabled' => true, 'budget_cents' => 1]);
                $customer = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional', 'last_name' => 'Jordan']);
                $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'created_by_user_id' => $owner->id, 'marketing_profile_id' => $customer->id,
                    'title' => 'Fictional workshop entrance lighting', 'status' => 'open', 'scheduled_for' => now()->addDay()]);
                FieldServiceJobNote::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'created_by_user_id' => $owner->id,
                    'body' => 'I will confirm the fictional fixture delivery with the fictional customer.', 'noted_at' => now(), 'metadata' => ['synthetic_only' => true]]);
                $task = FieldServiceTask::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'created_by_user_id' => $owner->id,
                    'title' => 'Fictional fixture availability check', 'status' => 'open', 'priority' => 'normal', 'due_at' => now()->subDay()]);
                FieldServiceTaskEvent::create(['tenant_id' => $tenant->id, 'field_service_task_id' => $task->id, 'actor_user_id' => $owner->id,
                    'event_type' => 'created', 'to_status' => 'open', 'metadata' => ['synthetic_only' => true]]);
                CustomerLoopAction::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id,
                    'title' => 'Fictional delivery follow-up', 'reason' => 'Scripted voice pilot scenario', 'action_type' => 'follow_up', 'status' => 'suggested', 'due_at' => now()]);
            }
            if ($prior) {
                foreach (\App\Models\TenantModuleEntitlement::where('tenant_id', $tenant->id)->where('entitlement_source', AiFictionalPilot::SOURCE)->get() as $entitlement) {
                    $commercial->setTenantModuleEntitlement($tenant->id, $entitlement->module_key, [
                        'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'trial',
                        'currency' => 'USD', 'entitlement_source' => AiFictionalPilot::SOURCE, 'ends_at' => now()->addDays(7),
                        'notes' => 'Renewed synthetic pilot. No paid subscription or automated customer charge.']);
                }
            }
            if ($provider === 'openai') {
                $account = AiBranchAccount::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
                $account->forceFill(['budget_cents' => $budget, 'approved_by' => $owner->id, 'approved_at' => now()])->save();
            }

            return $audit->record($tenant->id, null, 'ai_voice.pilot.grant', targetType: 'fictional_workspace', targetId: $tenant->id,
                context: ['channel' => 'privileged_console'], confirmation: ['explicit' => true, 'reference' => $this->option('authorization')],
                result: ['version' => 2, 'owner_user_id' => (int) $owner->id, 'provider' => $provider, 'synthetic_only' => true,
                    ...($provider === 'openai' ? ['provider_budget_cents' => $budget, 'provider_settings' => $providerSettings, 'cap_window' => 'workspace_lifetime'] : []),
                    'expires_at' => now()->addDays(7)->toIso8601String()]);
        });
        $this->info('Prepared audited pilot grant #'.$grant->id.'. Expires '.$grant->result['expires_at']);
        $this->line(url('/dashboard?tenant='.AiFictionalPilot::SLUG));

        return self::SUCCESS;
    }
}
