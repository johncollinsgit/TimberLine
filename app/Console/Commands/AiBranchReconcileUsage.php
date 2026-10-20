<?php

namespace App\Console\Commands;

use App\Models\AiBranchAccount;
use App\Models\AiBranchConversation;
use App\Models\TenantAiUsageEvent;
use App\Models\User;
use App\Services\AiBranch\AiBranchProvider;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Support\Auth\HomeRedirect;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiBranchReconcileUsage extends Command
{
    protected $signature = 'ai-branch:reconcile-usage {event}
        {--actor= : Authorized platform operator user ID}
        {--evidence= : Provider evidence reference, without private prompt or credentials}
        {--input-tokens= : Verified provider input tokens}
        {--output-tokens= : Verified provider output tokens}
        {--cached-input-tokens=0 : Verified cached input tokens}
        {--no-charge : Provider evidence confirms no charge}
        {--apply : Apply the displayed settlement and clear the pending marker}';

    protected $description = 'Preview or reconcile one ambiguous AI request using verified provider evidence';

    public function handle(): int
    {
        $event = TenantAiUsageEvent::query()->where('feature', 'ai_branch_chat')->whereKey($this->argument('event'))->firstOrFail();
        $actor = User::query()->find((int) $this->option('actor'));
        if (! $actor || ! HomeRedirect::isPlatformOperator($actor) || $actor->is_active === false) {
            $this->error('An active platform operator actor is required.');

            return self::FAILURE;
        }
        $evidence = trim((string) $this->option('evidence'));
        $settings = (array) data_get($event->metadata, 'settings');
        $in = filter_var($this->option('input-tokens'), FILTER_VALIDATE_INT);
        $out = filter_var($this->option('output-tokens'), FILTER_VALIDATE_INT);
        $cached = filter_var($this->option('cached-input-tokens'), FILTER_VALIDATE_INT);
        if (strlen($evidence) < 5 || strlen($evidence) > 240 || (! $this->option('no-charge')
            && ($in === false || $out === false || $cached === false || $cached < 0 || $cached > $in || $in < 0 || $out < 0 || $in > ($settings['max_input'] ?? 0) || $out > ($settings['max_output'] ?? 0)))) {
            $this->error('Supply a short evidence reference and valid token counts, or verified no-charge evidence.');

            return self::FAILURE;
        }
        $cost = $this->option('no-charge') ? 0 : app(AiBranchProvider::class)->cost($in, $out, $settings, $cached);
        if ($cost > $event->provider_cost_micros) {
            $this->error('Verified cost exceeds the reservation. Keep the request blocked and investigate model pricing.');

            return self::FAILURE;
        }
        $buyer = $cost * (int) ($settings['buyer_multiplier'] ?? 0);
        $this->line('Tenant '.$event->tenant_id.', request '.$event->id.': reserved '.$event->provider_cost_micros.' micros; verified '.$cost.' micros; buyer charge '.$buyer.' micros.');
        if (! $this->option('apply')) {
            $this->line('Preview only. Add --apply after checking the provider evidence.');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($event, $actor, $evidence, $cost, $buyer, $in, $out, $cached): void {
            AiBranchAccount::query()->where('tenant_id', $event->tenant_id)->lockForUpdate()->firstOrFail();
            $event = TenantAiUsageEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($event->status, ['reserved', 'uncertain'], true) && $event->created_at->lt(now()->subMinutes(2)), 409, 'Only an unresolved request older than two minutes can be reconciled.');
            $before = $event->only(['status', 'provider_cost_micros']);
            if (data_get($event->metadata, 'billing_scope') === 'ai_prepaid') {
                app(\App\Services\Marketing\Messaging\TenantMessagingUsageService::class)->settleAi($event, $cost);
            }
            $event->forceFill(['status' => $this->option('no-charge') ? 'refunded' : 'settled', 'provider_cost_micros' => $cost,
                'buyer_charge_micros' => $buyer,
                'metadata' => [...$event->metadata, 'reconciled_by' => $actor->id, 'evidence' => $evidence, 'input_tokens' => $in ?: 0, 'output_tokens' => $out ?: 0, 'cached_input_tokens' => $cached ?: 0],
            ])->save();
            AiBranchConversation::query()->where('tenant_id', $event->tenant_id)->where('user_id', $event->user_id)
                ->whereKey(data_get($event->metadata, 'conversation_id'))->where('pending_uuid', $event->client_uuid)->update(['pending_uuid' => null]);
            app(LandlordOperatorActionAuditService::class)->record($event->tenant_id, $actor->id, 'ai_branch.usage_reconciliation',
                targetType: 'tenant_ai_usage_event', targetId: $event->id, confirmation: ['apply' => true, 'evidence' => $evidence],
                beforeState: $before, afterState: $event->only(['status', 'provider_cost_micros']));
        });

        return self::SUCCESS;
    }
}
