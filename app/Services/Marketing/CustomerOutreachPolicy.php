<?php

namespace App\Services\Marketing;

use App\Models\LandlordOperatorAction;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Tenant-reviewed limits for new customer-profile campaigns; never grants consent or sends. */
class CustomerOutreachPolicy
{
    public const KEY = 'customer_profile_outreach_policy';

    public function policySnapshot(int $tenantId): array
    {
        abort_unless($tenantId > 0, 404);
        $state = $this->state($tenantId);
        $published = $state['published'] ?? null;

        return $published ?? ['contract_version' => 1, 'tenant_id' => $tenantId, 'version' => 0,
            'status' => 'unreviewed', 'rules' => $this->defaults(), 'reviewed_at' => null, 'reviewed_by' => null];
    }

    public function editor(Tenant $tenant, User $actor): array
    {
        $this->authorize($tenant, $actor);
        $state = $this->state($tenant->id);

        return ['version' => $state['version'] ?? 0, 'published' => $this->policySnapshot($tenant->id),
            'draft' => $state['draft'] ?? null, 'defaults' => $this->defaults(),
            'revisions' => LandlordOperatorAction::forTenantId($tenant->id)->where('action_type', 'customer_outreach_policy_revision')
                ->latest('id')->limit(20)->get()->map(fn ($row) => ['version' => $row->result['version'],
                    'status' => $row->result['status'], 'reason' => $row->confirmation['reason'],
                    'actor_user_id' => $row->actor_user_id, 'created_at' => $row->created_at?->toIso8601String()])->all(),
            'scope' => 'New customer-profile campaigns only. Existing Everbranch email and SMS marketing delivery records share these limits.',
            'notice' => 'Publishing limits does not enable marketing, grant consent, approve a campaign or verify provider readiness. External or missing delivery history requires review.'];
    }

    public function authorize(Tenant $tenant, User $actor): void
    {
        abort_unless(app(CustomerCommerceLinkService::class)->canLink($actor, $tenant->id), 403);
    }

    /** All setting changes serialize with draft creation and dispatch on the tenant row first. */
    public function revise(Tenant $tenant, User $actor, array $input): array
    {
        $this->authorize($tenant, $actor);
        $valid = Validator::make($input, [
            'request_id' => 'required|uuid', 'expected_version' => 'required|integer|min:0',
            'publish' => 'required|boolean', 'reason' => 'required|string|min:8|max:1000',
            'rules' => 'required|array:timezone,daily_cap,rolling_7_day_cap,email_daily_cap,sms_daily_cap,cross_channel_cooldown_minutes,email_cooldown_minutes,sms_cooldown_minutes,delivery_history_verified_from,coverage_review_note',
            'rules.timezone' => 'required|timezone', 'rules.daily_cap' => 'required|integer|min:0|max:100',
            'rules.rolling_7_day_cap' => 'required|integer|min:0|max:500',
            'rules.email_daily_cap' => 'required|integer|min:0|max:100', 'rules.sms_daily_cap' => 'required|integer|min:0|max:100',
            'rules.cross_channel_cooldown_minutes' => 'required|integer|min:0|max:10080',
            'rules.email_cooldown_minutes' => 'required|integer|min:0|max:10080', 'rules.sms_cooldown_minutes' => 'required|integer|min:0|max:10080',
            'rules.delivery_history_verified_from' => 'required|array:email,sms',
            'rules.delivery_history_verified_from.email' => 'present|nullable|date',
            'rules.delivery_history_verified_from.sms' => 'present|nullable|date',
            'rules.coverage_review_note' => 'present|nullable|string|max:1000',
        ])->validate();
        $rules = $valid['rules'];
        foreach (['daily_cap', 'rolling_7_day_cap', 'email_daily_cap', 'sms_daily_cap', 'cross_channel_cooldown_minutes', 'email_cooldown_minutes', 'sms_cooldown_minutes'] as $key) {
            $rules[$key] = (int) $rules[$key];
        }
        foreach (['email', 'sms'] as $channel) {
            $since = $rules['delivery_history_verified_from'][$channel];
            $rules['delivery_history_verified_from'][$channel] = $since ? CarbonImmutable::parse($since)->utc()->toIso8601String() : null;
        }
        if ($valid['publish']) {
            Validator::make($rules, [
                'coverage_review_note' => 'required|string|min:8',
                'delivery_history_verified_from.email' => 'required|date|before_or_equal:now',
                'delivery_history_verified_from.sms' => 'required|date|before_or_equal:now',
            ])->validate();
        }
        $reason = trim($valid['reason']);
        abort_if(strlen($reason) < 8, 422, 'Record the reason for this policy revision.');
        $hash = $this->hash([$rules, (bool) $valid['publish'], $reason, (int) $valid['expected_version']]);

        return DB::transaction(function () use ($tenant, $actor, $valid, $rules, $reason, $hash) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($tenant, $actor);
            $prior = LandlordOperatorAction::forTenantId($tenant->id)->where('action_type', 'customer_outreach_policy_revision')
                ->where('target_id', $valid['request_id'])->first();
            if ($prior) {
                abort_unless(($prior->context['request_hash'] ?? null) === $hash && (int) $prior->actor_user_id === (int) $actor->id, 409, 'This request ID belongs to another policy revision.');

                return $prior->result;
            }
            $state = $this->state($tenant->id);
            abort_unless((int) ($state['version'] ?? 0) === (int) $valid['expected_version'], 409, 'The policy changed. Reload before saving.');
            $revision = ['contract_version' => 1, 'tenant_id' => (int) $tenant->id, 'version' => (int) $valid['expected_version'] + 1,
                'status' => $valid['publish'] ? 'published' : 'draft', 'rules' => $rules,
                'reviewed_at' => $valid['publish'] ? now()->toIso8601String() : null,
                'reviewed_by' => $valid['publish'] ? (int) $actor->id : null];
            $next = ['version' => $revision['version'], 'published' => $valid['publish'] ? $revision : ($state['published'] ?? null),
                'draft' => $valid['publish'] ? null : $revision];
            TenantMarketingSetting::forTenantId($tenant->id)->updateOrCreate(['tenant_id' => $tenant->id, 'key' => self::KEY],
                ['value' => $next, 'description' => 'Reviewed tenant-owned customer-profile outreach limits.']);
            app(LandlordOperatorActionAuditService::class)->record($tenant->id, $actor->id,
                'customer_outreach_policy_revision', 'success', 'customer_outreach_policy', $valid['request_id'],
                ['request_hash' => $hash, 'version' => $revision['version']], ['reason' => $reason],
                $state ?: null, $next, $revision);

            return $revision;
        });
    }

    /** Call again inside the tenant-locked transaction immediately before creating the existing delivery row. */
    public function decision(MarketingProfile $profile, string $channel, ?CarbonImmutable $at = null): array
    {
        abort_unless(in_array($channel, ['email', 'sms'], true), 422);
        $tenantId = (int) $profile->tenant_id;
        abort_unless($tenantId > 0, 404);
        $profile = MarketingProfile::forTenantId($tenantId)->findOrFail($profile->id);
        $canonical = app(CanonicalMarketingProfileResolver::class)->canonical($profile, $tenantId);
        abort_unless($canonical && ! $canonical->archived_at, 404);
        $at ??= CarbonImmutable::now('UTC');
        $at = $at->utc();
        $policy = $this->policySnapshot($tenantId);
        $rules = $policy['rules'];
        $reasons = $policy['status'] !== 'published' ? ['outreach_policy_unreviewed'] : [];
        if (config('marketing.customer_profile.'.$channel.'_dispatch_enabled') !== true) {
            $reasons[] = 'outreach_'.$channel.'_safety_review_required';
        }
        $day = $at->setTimezone($rules['timezone'])->startOfDay()->utc();
        $week = $at->subDays(7)->utc();
        $cutoff = $day->min($week)->min($at->subMinutes(max($rules['cross_channel_cooldown_minutes'], $rules['email_cooldown_minutes'], $rules['sms_cooldown_minutes'])))->utc();
        $coverage = [];
        foreach (['email', 'sms'] as $source) {
            $since = $rules['delivery_history_verified_from'][$source] ?? null;
            $available = Schema::hasTable($source === 'email' ? 'marketing_email_deliveries' : 'marketing_message_deliveries');
            $coverage[$source] = ['table_available' => $available, 'reviewed_from' => $since,
                'covers_required_window' => $available && $since && CarbonImmutable::parse($since)->lte($cutoff),
                'scope' => 'retained_everbranch_marketing_delivery_records'];
            if (! $coverage[$source]['covers_required_window']) {
                $reasons[] = 'outreach_'.$source.'_history_unavailable';
            }
        }
        $ids = $this->aliasIds($canonical, $tenantId);
        if ($ids === null) {
            $reasons[] = 'outreach_identity_history_ambiguous';
        }
        $records = $ids === null ? [] : $this->deliveries($tenantId, $ids, $cutoff, $at, $coverage);
        $bounded = collect($records)->contains(fn ($row) => $row['coverage_limit_reached'] ?? false);
        if ($bounded) {
            $reasons[] = 'outreach_delivery_evidence_limit_reached';
        }
        $promotions = array_values(array_filter($records, fn ($row) => $row['purpose'] !== 'transactional'));
        $accepted = array_values(array_filter($promotions, fn ($row) => $row['accepted_at'] !== null));
        $uncertain = array_values(array_filter($promotions, fn ($row) => $row['in_flight']));
        $today = array_values(array_filter($accepted, fn ($row) => CarbonImmutable::parse($row['accepted_at'])->gte($day)));
        $lastWeek = array_values(array_filter($accepted, fn ($row) => CarbonImmutable::parse($row['accepted_at'])->gt($week)));
        $channelToday = array_values(array_filter($today, fn ($row) => $row['channel'] === $channel));
        if ($uncertain !== []) {
            $reasons[] = 'outreach_delivery_outcome_unresolved';
        }
        foreach ([['outreach_daily_cap', count($today), $rules['daily_cap']], ['outreach_7_day_cap', count($lastWeek), $rules['rolling_7_day_cap']],
            ['outreach_'.$channel.'_daily_cap', count($channelToday), $rules[$channel.'_daily_cap']]] as [$code, $count, $limit]) {
            if ($count >= $limit) {
                $reasons[] = $code;
            }
        }
        foreach ($accepted as $row) {
            $sent = CarbonImmutable::parse($row['accepted_at']);
            if ($rules['cross_channel_cooldown_minutes'] > 0 && $sent->gt($at->subMinutes($rules['cross_channel_cooldown_minutes']))) {
                $reasons[] = 'outreach_cross_channel_cooldown';
            }
            if ($row['channel'] === $channel && $rules[$channel.'_cooldown_minutes'] > 0 && $sent->gt($at->subMinutes($rules[$channel.'_cooldown_minutes']))) {
                $reasons[] = 'outreach_'.$channel.'_cooldown';
            }
        }
        if ($channel === 'sms') {
            $eligible = app(MarketingSmsEligibilityService::class)->evaluateProfile($canonical, $tenantId);
            $reasons = [...$reasons, ...($eligible['reason_codes'] ?? [])];
        } elseif (! $canonical->accepts_email_marketing || $canonical->email_opted_out_at || ! filled($canonical->normalized_email ?: $canonical->email)) {
            $reasons[] = 'email_not_consented_or_missing';
        }
        $preferences = app(CustomerPreferenceService::class)->forStaff($canonical, $tenantId);
        $status = $preferences['communication'][$channel.'_marketing'] ?? 'unknown';
        if (! in_array($status, ['subscribed', 'unsubscribed', 'suppressed', 'bounced', 'unknown'], true)) {
            $status = 'unknown';
        }
        if ($status !== 'subscribed') {
            $reasons[] = 'outreach_'.$channel.'_preference_'.$status;
        }
        if (($preferences['communication']['preferred_channel'] ?? null) === 'none') {
            $reasons[] = 'outreach_customer_prefers_no_marketing';
        } elseif (in_array($preferences['communication']['preferred_channel'] ?? null, ['email', 'sms'], true)
            && $preferences['communication']['preferred_channel'] !== $channel) {
            $reasons[] = 'outreach_customer_prefers_other_channel';
        }
        $reasons = array_values(array_unique($reasons));
        $complete = ! collect($coverage)->contains(fn ($row) => ! $row['covers_required_window']) && $ids !== null && ! $bounded;

        return ['contract_version' => 1, 'tenant_id' => $tenantId, 'profile_id' => (int) $canonical->id, 'channel' => $channel,
            'purpose' => 'promotional', 'allowed' => $reasons === [], 'reason_codes' => $reasons,
            'evaluated_at' => $at->utc()->toIso8601String(), 'policy' => $policy,
            'preferences' => ['version' => $preferences['version'], 'revision' => $preferences['revision'],
                'preferred_channel' => $preferences['communication']['preferred_channel'] ?? null,
                'channel_status' => $status, 'rewards_communications' => $preferences['rewards']['communications'] ?? null],
            'delivery_evidence' => ['coverage' => $coverage, 'required_from' => $cutoff->toIso8601String(),
                'day_starts_at' => $day->toIso8601String(), 'rolling_7_days_starts_at' => $week->toIso8601String(),
                'daily_count' => $complete ? count($today) : null, 'rolling_7_day_count' => $complete ? count($lastWeek) : null,
                'channel_daily_count' => $complete ? count($channelToday) : null,
                'observed_daily_count' => count($today), 'observed_rolling_7_day_count' => count($lastWeek),
                'unresolved_count' => count($uncertain), 'transactional_receipts_excluded' => count($records) - count($promotions),
                'records' => $records, 'hash' => $this->hash($records)],
            'notice' => 'Limits use accepted Everbranch marketing attempts, including later delivery failures. Unresolved attempts block dispatch. Receipts are separate; external sends and unavailable history are not zero deliveries. Existing consent, suppression, approval, quiet-hours and provider checks still apply.'];
    }

    public function hash(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
    }

    private function state(int $tenantId): array
    {
        if (! Schema::hasTable('tenant_marketing_settings')) {
            return [];
        }

        return TenantMarketingSetting::forTenantId($tenantId)->where('key', self::KEY)->value('value') ?? [];
    }

    private function defaults(): array
    {
        return ['timezone' => 'UTC', 'daily_cap' => 1, 'rolling_7_day_cap' => 3, 'email_daily_cap' => 1, 'sms_daily_cap' => 1,
            'cross_channel_cooldown_minutes' => 1440, 'email_cooldown_minutes' => 1440, 'sms_cooldown_minutes' => 1440,
            'delivery_history_verified_from' => ['email' => null, 'sms' => null], 'coverage_review_note' => null];
    }

    /** Bounded reverse traversal retains explicit aliases without contact matching. */
    private function aliasIds(MarketingProfile $canonical, int $tenantId): ?array
    {
        $ids = [(int) $canonical->id];
        $frontier = $ids;
        for ($depth = 0; $depth < 32 && $frontier !== []; $depth++) {
            $frontier = MarketingProfile::forTenantId($tenantId)->whereIn('merged_into_profile_id', $frontier)
                ->whereNotIn('id', $ids)->limit(1001)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $ids = [...$ids, ...$frontier];
            if (count($ids) > 1000) {
                return null;
            }
        }

        return $frontier === [] ? $ids : null;
    }

    private function deliveries(int $tenantId, array $ids, CarbonImmutable $since, CarbonImmutable $at, array $coverage): array
    {
        $records = [];
        foreach (['email' => MarketingEmailDelivery::class, 'sms' => MarketingMessageDelivery::class] as $channel => $model) {
            if (! $coverage[$channel]['table_available']) {
                continue;
            }
            $query = $model::forTenantId($tenantId)->whereIn('marketing_profile_id', $ids);
            if ($channel === 'sms') {
                $query->where('channel', 'sms');
            }
            $query->where(function ($q) use ($since, $channel) {
                $q->where('sent_at', '>=', $since)->orWhere(function ($pending) use ($channel) {
                    $pending->whereNull('sent_at')->whereIn($channel === 'email' ? 'status' : 'send_status',
                        ['pending', 'queued', 'sending', 'submitting', 'unknown', 'accepted', 'sent', 'delivered', 'opened', 'clicked', 'undelivered']);
                });
                if ($channel === 'sms') {
                    $q->orWhere(function ($uncertain) {
                        $uncertain->whereNull('sent_at')->whereIn('error_code', ['exception', 'messaging_usage_unavailable', 'tenant_messaging_unavailable', '500', '502', '503', '504']);
                    });
                }
            })->where('created_at', '<=', $at)->orderBy('id');
            $rows = $query->limit(2001)->get();
            if ($rows->count() > 2000) {
                // Bounded evidence is incomplete; an unresolved sentinel prevents an accidental allow.
                $records[] = ['ledger' => $channel, 'id' => 0, 'channel' => $channel, 'profile_id' => $ids[0],
                    'purpose' => 'promotional', 'accepted_at' => null, 'in_flight' => true, 'coverage_limit_reached' => true];

                continue;
            }
            foreach ($rows as $row) {
                $status = strtolower((string) ($channel === 'email' ? $row->status : $row->send_status));
                $metadata = $channel === 'email' ? (array) $row->metadata : (array) $row->provider_payload;
                if (data_get($metadata, 'dry_run') === true || ($channel === 'email' && data_get($row->raw_payload, 'payload.dry_run') === true)) {
                    continue;
                }
                $source = strtolower((string) $row->source_label);
                $type = strtolower((string) ($metadata['message_type'] ?? ''));
                $transactional = $type === 'transactional' || in_array($source, ['pos_receipt', 'website_order_receipt', 'transactional_receipt'], true)
                    || ($channel === 'email' && in_array($row->campaign_type, ['receipt', 'transactional'], true));
                $accepted = $row->sent_at && $row->sent_at->lte($at) ? $row->sent_at->toIso8601String() : null;
                $inFlight = $status === 'unknown' || (! $accepted && (in_array($status, ['pending', 'queued', 'sending', 'submitting', 'unknown', 'accepted', 'sent', 'delivered', 'opened', 'clicked', 'undelivered'], true)
                    || in_array(data_get($metadata, 'ses_attempt.state'), ['submitting', 'unknown'], true)
                    || ($channel === 'sms' && in_array($row->error_code, ['exception', 'messaging_usage_unavailable', 'tenant_messaging_unavailable', '500', '502', '503', '504'], true))));
                if (! $accepted && ! $inFlight) {
                    continue;
                }
                $records[] = ['ledger' => $channel, 'id' => (int) $row->id, 'channel' => $channel,
                    'profile_id' => (int) $row->marketing_profile_id, 'purpose' => $transactional ? 'transactional' : 'promotional',
                    'accepted_at' => $accepted, 'in_flight' => $inFlight];
            }
        }

        return $records;
    }
}
