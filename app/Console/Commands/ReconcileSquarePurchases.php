<?php

namespace App\Console\Commands;

use App\Models\MarketingCampaign;
use App\Models\MarketingImportRun;
use App\Models\SquareOrder;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Services\Marketing\CustomerAccountFence;
use App\Services\Marketing\SquareConnectionContext;
use App\Services\Marketing\SquareCustomerIdentityService;
use App\Services\Marketing\SquarePurchaseEffects;
use App\Services\Team\Operations\OperationsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ReconcileSquarePurchases extends Command
{
    protected $signature = 'customer-sources:reconcile-square-purchases {tenant}
        {--actor= : Active tenant owner/admin} {--order= : One original order ID}
        {--customer= : Original Square customer ID, including payment-backed orders}
        {--from= : Earliest rewardable purchase, with timezone} {--event= : Reviewed Teams occurrence UUID}
        {--campaign= : Existing campaign to associate with this occurrence} {--location= : Original Square location}
        {--enroll : Review source scope and enable ongoing purchase effects} {--after=0} {--limit=100} {--apply}';

    protected $description = 'Preview or apply original Square purchase linking, campaign attribution and tenant rewards.';

    public function handle(SquarePurchaseEffects $effects): int
    {
        $tenant = Tenant::findOrFail((int) $this->argument('tenant'));
        $actor = User::findOrFail((int) $this->option('actor'));
        app(SquareCustomerIdentityService::class)->authorize($tenant->id, $actor);
        $apply = (bool) $this->option('apply');
        if ($this->option('enroll')) {
            $result = $this->enroll($tenant, $actor, $apply);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $query = SquareOrder::forTenantId($tenant->id)->orderBy('id')->where('id', '>', max(0, (int) $this->option('after')));
        if ($this->option('order')) {
            $query->where('square_order_id', $this->option('order'));
        }
        if ($this->option('customer')) {
            $customerId = (string) $this->option('customer');
            $query->where(fn ($q) => $q->where('square_customer_id', $customerId)->orWhereIn('square_order_id',
                \App\Models\SquarePayment::forTenantId($tenant->id)->where('square_customer_id', $customerId)->whereNotNull('square_order_id')->select('square_order_id')));
        }
        if ($this->option('from')) {
            $query->where('closed_at', '>=', CarbonImmutable::parse($this->option('from'))->utc());
        }
        $rows = $query->limit(max(1, min(500, (int) $this->option('limit'))))->get();
        $results = [];
        foreach ($rows as $row) {
            try {
                $result = $effects->reconcile($tenant->id, $row->square_order_id, ! $apply);
                $results[] = ['cache_id' => $row->id, 'order_id' => $row->square_order_id, ...$result];
            } catch (\Throwable $error) {
                report($error);
                $results[] = ['cache_id' => $row->id, 'order_id' => $row->square_order_id, 'status' => 'review', 'reason' => $error::class];
            }
        }
        $this->line(json_encode(['mode' => $apply ? 'apply' : 'preview', 'results' => $results, 'after' => $rows->last()?->id,
            'has_more' => $rows->count() === max(1, min(500, (int) $this->option('limit')))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function enroll(Tenant $tenant, User $actor, bool $apply): array
    {
        abort_unless($this->option('from') && $this->option('event') && $this->option('location'), 422, 'Specify the original location, event and effective timestamp.');
        $event = TeamOperationRecord::where('tenant_id', $tenant->id)->where('kind', 'event')->findOrFail($this->option('event'));
        app(OperationsAccess::class)->record($tenant, $actor, $event);
        $map = TeamOperationRecord::where('tenant_id', $tenant->id)->where('area_id', $event->area_id)->where('kind', 'event_link')->where('status', 'accepted')->get()
            ->first(fn ($r) => ($r->data['occurrence_id'] ?? null) === $event->id);
        abort_unless($map, 422, 'Review this occurrence and recurring series first.');
        $location = (string) $this->option('location');
        $runs = [];
        foreach (['orders', 'payments'] as $kind) {
            $runs[$kind] = MarketingImportRun::forTenantId($tenant->id)->where('type', 'square_'.$kind.'_sync')->where('status', 'completed')->latest('id')->limit(100)->get()
                ->first(fn ($r) => in_array($location, $r->summary['source_binding']['location_ids'] ?? [], true));
            abort_unless($runs[$kind], 422, 'A completed original source import is required.');
            app(SquareConnectionContext::class)->assertCurrent($tenant->id, $runs[$kind]->summary['source_binding']);
        }
        $binding = $runs['orders']->summary['source_binding'];
        foreach (['merchant_id', 'environment'] as $field) {
            abort_unless(($runs['payments']->summary['source_binding'][$field] ?? null) === ($binding[$field] ?? null), 409);
        }
        abort_unless(($binding['environment'] ?? null) === 'production', 422, 'Real rewards require the original production merchant.');
        $campaign = $this->option('campaign') ? MarketingCampaign::forTenantId($tenant->id)->findOrFail((int) $this->option('campaign')) : null;
        $existing = app(SquarePurchaseEffects::class)->policy($tenant->id);
        $backfillTerms = app(SquarePurchaseEffects::class)->termsAt($tenant->id, CarbonImmutable::now());
        abort_unless($backfillTerms && ($backfillTerms['access']['launch_state'] ?? null) === 'published', 422, 'Publish the reviewed purchase rewards policy first.');
        $policy = ['enabled' => true, 'merchant_id' => $binding['merchant_id'], 'environment' => $binding['environment'],
            'location_ids' => array_values($binding['location_ids']), 'effective_from' => CarbonImmutable::parse($this->option('from'))->utc()->toIso8601String(),
            'activated_at' => $existing['activated_at'] ?? now()->toIso8601String(), 'backfill_event_ids' => array_values(array_unique([...($existing['backfill_event_ids'] ?? []), $event->id])),
            'reviewed_by' => (int) $actor->id, 'attribution_window_days' => 7];
        $policy['backfill_terms'] = array_replace($existing['backfill_terms'] ?? [], [$event->id => $backfillTerms + ['owner_reviewed_backfill' => true]]);
        $scope = ['location_id' => $location, 'starts_on' => $event->starts_on->toDateString(), 'ends_on' => $event->ends_on->toDateString(),
            'orders_run_id' => $runs['orders']->id, 'payments_run_id' => $runs['payments']->id];
        $preview = ['mode' => $apply ? 'apply' : 'preview', 'policy' => $policy, 'event' => $event->id, 'scope' => $scope, 'campaign_id' => $campaign?->id];
        if (! $apply) {
            return $preview;
        }

        return app(CustomerAccountFence::class)->forTenant($tenant->id, function () use ($tenant, $actor, $event, $map, $scope, $policy, $campaign, $preview) {
            app(SquareCustomerIdentityService::class)->authorize($tenant->id, $actor);
            $fresh = $map->fresh();
            abort_unless($fresh->version === $map->version, 409, 'Event source mapping changed.');
            $data = $fresh->data;
            $data['square_scopes'] = [...array_values(array_filter($data['square_scopes'] ?? [], fn ($s) => ($s['location_id'] ?? '') !== $scope['location_id'])), $scope];
            $data['reviewed_by'] = (string) $actor->id;
            $data['reviewed_at'] = now()->toIso8601String();
            $data['reviewed_source_versions'][$event->id] = $event->version;
            $fresh->update(['data' => $data, 'version' => $fresh->version + 1]);
            if ($campaign) {
                $record = TeamOperationRecord::firstOrNew(['tenant_id' => $tenant->id, 'area_id' => $event->area_id, 'kind' => 'campaign_link', 'source_key' => 'campaign:'.$campaign->id]);
                abort_if($record->exists && ($record->data['event_id'] ?? null) !== $event->id, 409, 'Review the campaign’s existing event association first.');
                $record->fill(['id' => $record->id ?? (string) Str::uuid(), 'status' => 'accepted', 'version' => ($record->version ?? 0) + 1,
                    'title' => $campaign->name, 'visibility' => 'admin', 'data' => ['event_id' => $event->id, 'campaign_id' => $campaign->id,
                        'reviewed_by' => $actor->id, 'reviewed_at' => now()->toIso8601String(), 'original_sending_evidence_retained' => true]])->save();
            }
            TenantMarketingSetting::updateOrCreate(['tenant_id' => $tenant->id, 'key' => SquarePurchaseEffects::SETTING], ['value' => $policy]);
            MarketingImportRun::create(['tenant_id' => $tenant->id, 'type' => 'square_purchase_effects_enrollment', 'status' => 'completed',
                'source_label' => 'owner_reviewed_square_purchase_effects', 'created_by' => $actor->id, 'started_at' => now(), 'finished_at' => now(), 'summary' => $preview]);

            return $preview;
        });
    }
}
