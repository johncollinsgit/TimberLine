<?php

namespace App\Services\Subscriptions;

use App\Models\MarketingProfile;
use App\Models\MarketingReviewHistory;
use App\Models\User;
use App\Services\Media\FreeStockPhotoService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SubscriptionModuleService
{
    public const MODULE_KEY = 'subscriptions';

    public const CANDLE_CLUB_TYPE = 'candle_club_scent';

    public function __construct(protected SubscriptionAccessService $access, protected SubscriptionIntentValidator $intents) {}

    protected function encryptedPayload(array $payload): string
    {
        return json_encode(['version' => 1, 'encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR);
    }

    protected function assertManager(int $tenantId, ?int $actorId): void
    {
        $this->access->assertManager($tenantId, $actorId ? User::find($actorId) : auth()->user());
    }

    protected function operationalContracts(int $tenantId)
    {
        return DB::table('subscription_contracts')->where('tenant_id', $tenantId)
            ->where('shopify_subscription_contract_gid', 'like', 'gid://shopify/SubscriptionContract/%')
            ->where(function ($query): void {
                $query->whereNull('metadata->app_review_demo')->orWhere('metadata->app_review_demo', false);
            });
    }

    protected function operationalVotes(int $tenantId)
    {
        return DB::table('subscription_votes')->where('subscription_votes.tenant_id', $tenantId)
            ->whereExists($this->operationalContracts($tenantId)
                ->whereColumn('subscription_contracts.id', 'subscription_votes.subscription_contract_id')
                ->whereColumn('subscription_contracts.shopify_subscription_contract_gid', 'subscription_votes.shopify_subscription_contract_gid')
                ->selectRaw('1'))
            ->whereIn('subscription_votes.subscription_poll_id', $this->operationalPolls($tenantId)->select('id'));
    }

    protected function operationalPolls(int $tenantId)
    {
        return DB::table('subscription_polls')->where('tenant_id', $tenantId)
            ->where(function ($query): void {
                $query->whereNull('metadata->app_review_demo')->orWhere('metadata->app_review_demo', false);
            })
            ->where(function ($query): void {
                $query->whereNull('description')->orWhere('description', '<>', 'App Review demo ballot for Candle Club scent voting.');
            });
    }

    protected function isDemoPoll(object $poll): bool
    {
        return (bool) ($this->decodeJson($poll->metadata ?? null)['app_review_demo'] ?? false)
            || ($poll->description ?? '') === 'App Review demo ballot for Candle Club scent voting.';
    }

    /**
     * @return array<string,mixed>
     */
    public function adminPayload(int $tenantId): array
    {
        $this->assertManager($tenantId, null);
        $settings = $this->moduleSettings($tenantId);
        $candleClub = $this->candleClubSettings($tenantId);
        $contracts = $this->operationalContracts($tenantId);
        $attempts = DB::table('subscription_billing_attempts')->where('tenant_id', $tenantId);
        $latestBatch = DB::table('subscription_migration_batches')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->first();

        $this->access->assertEntitled($tenantId);
        $poll = $this->activePoll($tenantId);

        return [
            'module' => $settings,
            'readiness' => $this->access->readiness($tenantId),
            'candle_club' => $candleClub,
            'metrics' => [
                'active_subscribers' => (clone $contracts)->where('status', 'active')->count(),
                'active_candle_club' => (clone $contracts)->where('status', 'active')->where('is_candle_club', true)->count(),
                'paused_subscribers' => (clone $contracts)->where('status', 'paused')->count(),
                'cancelled_subscribers' => (clone $contracts)->whereIn('status', ['cancelled', 'canceled'])->count(),
                'failed_payment_attempts' => (clone $attempts)->where('status', 'failed')->count(),
                'upcoming_orders' => (clone $contracts)
                    ->where('status', 'active')
                    ->whereNotNull('next_billing_date')
                    ->whereDate('next_billing_date', '>=', now()->toDateString())
                    ->whereDate('next_billing_date', '<=', now()->addDays(31)->toDateString())
                    ->count(),
            ],
            'upcoming' => (clone $contracts)
                ->where('status', 'active')
                ->whereNotNull('next_billing_date')
                ->orderBy('next_billing_date')
                ->limit(10)
                ->get()
                ->map(fn (object $row): array => $this->contractSummary($row))
                ->all(),
            'errors' => (clone $attempts)
                ->where('status', 'failed')
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'contract_gid' => (string) ($row->shopify_subscription_contract_gid ?? ''),
                    'message' => (string) ($row->error_message ?? 'Payment failed.'),
                    'billing_date' => $row->billing_date,
                    'created_at' => $row->created_at,
                ])
                ->all(),
            'latest_migration' => $latestBatch ? [
                'id' => (int) $latestBatch->id,
                'status' => (string) $latestBatch->status,
                'mode' => (string) $latestBatch->mode,
                'recharge_billing_paused_confirmed' => (bool) $latestBatch->recharge_billing_paused_confirmed,
                'summary' => $this->decodeJson($latestBatch->summary),
                'created_at' => $latestBatch->created_at,
            ] : null,
            'active_poll' => $poll,
            'recent_votes' => $poll
                ? $this->operationalVotes($tenantId)
                    ->where('subscription_poll_id', (int) $poll['id'])
                    ->count()
                : 0,
            'active_candle_club_customers' => $this->activeCandleClubCustomers($tenantId),
            'monthly_scents' => $this->monthlyScentCards($tenantId),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function saveCandleClubSettings(int $tenantId, array $payload, ?int $actorId = null): array
    {
        $this->assertManager($tenantId, $actorId);
        Validator::make(['payload' => $payload], ['payload' => ['array:commitment_months,allowed_pauses_per_commitment,pause_duration_options,renewal_reward_months,first_gift_product_variant_gid,first_gift_label,renewal_gift_product_variant_gid,renewal_gift_label,cancellation_prompt,voting_reward_candle_cash,poll_defaults']])->validate();
        Validator::make($payload, [
            'commitment_months' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'allowed_pauses_per_commitment' => ['sometimes', 'integer', 'min:0', 'max:24'],
            'pause_duration_options' => ['sometimes', 'array', 'min:1', 'max:12'],
            'pause_duration_options.*' => ['integer', 'min:1', 'max:12', 'distinct'],
            'renewal_reward_months' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'first_gift_product_variant_gid' => ['nullable', 'string', 'max:190', 'regex:/^gid:\/\/shopify\/ProductVariant\/[0-9]+$/D'],
            'renewal_gift_product_variant_gid' => ['nullable', 'string', 'max:190', 'regex:/^gid:\/\/shopify\/ProductVariant\/[0-9]+$/D'],
            'first_gift_label' => ['nullable', 'string', 'max:190'],
            'renewal_gift_label' => ['nullable', 'string', 'max:190'],
            'cancellation_prompt' => ['nullable', 'string', 'max:2000'],
            'voting_reward_candle_cash' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'poll_defaults' => ['sometimes', 'array:source_surfaces'],
            'poll_defaults.source_surfaces' => ['sometimes', 'array', 'max:3'],
            'poll_defaults.source_surfaces.*' => ['in:app,storefront,facebook', 'distinct'],
        ])->validate();
        $before = $this->candleClubSettings($tenantId);
        $payload = [...$before, ...$payload];
        $data = [
            'commitment_months' => max(1, (int) ($payload['commitment_months'] ?? 6)),
            'allowed_pauses_per_commitment' => max(0, (int) ($payload['allowed_pauses_per_commitment'] ?? 2)),
            'pause_duration_options' => json_encode(array_values((array) ($payload['pause_duration_options'] ?? [1, 2, 3])), JSON_THROW_ON_ERROR),
            'renewal_reward_months' => max(1, (int) ($payload['renewal_reward_months'] ?? 6)),
            'first_gift_product_variant_gid' => $this->nullableString($payload['first_gift_product_variant_gid'] ?? null),
            'first_gift_label' => $this->nullableString($payload['first_gift_label'] ?? null) ?: 'Free 8oz Coffeehouse candle',
            'renewal_gift_product_variant_gid' => $this->nullableString($payload['renewal_gift_product_variant_gid'] ?? null),
            'renewal_gift_label' => $this->nullableString($payload['renewal_gift_label'] ?? null) ?: 'Free renewal candle',
            'cancellation_prompt' => $this->nullableString($payload['cancellation_prompt'] ?? null) ?: $this->defaultCancellationPrompt(),
            'voting_reward_candle_cash' => max(0, (int) ($payload['voting_reward_candle_cash'] ?? 0)),
            'poll_defaults' => json_encode((array) ($payload['poll_defaults'] ?? []), JSON_THROW_ON_ERROR),
            'metadata' => json_encode([
                'updated_from' => 'shopify_embedded_subscriptions',
            ], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ];

        DB::table('subscription_candle_club_settings')->where('tenant_id', $tenantId)->update($data);
        DB::table('subscription_lifecycle_events')->insert([
            'tenant_id' => $tenantId, 'actor_user_id' => $actorId ?: auth()->id(),
            'event_type' => 'local_settings_updated', 'source' => 'everbranch_setup', 'status' => 'local_setup',
            'before_payload' => $this->encryptedPayload($before), 'after_payload' => $this->encryptedPayload($this->candleClubSettings($tenantId)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->candleClubSettings($tenantId);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    public function createMigrationDryRun(int $tenantId, ?int $actorId, array $rows = []): array
    {
        $actorId ??= auth()->id();
        $this->assertManager($tenantId, $actorId);
        Validator::make(['rows' => $rows], ['rows' => ['array', 'max:500'], 'rows.*' => ['array']])->validate();
        $summary = [
            'source_rows' => count($rows),
            'valid_rows' => 0,
            'error_rows' => 0,
            'active_subscriptions' => 0,
            'candle_club_rows' => 0,
        ];

        $batchId = DB::table('subscription_migration_batches')->insertGetId([
            'tenant_id' => $tenantId,
            'created_by_user_id' => $actorId,
            'source' => 'recharge_api',
            'mode' => 'dry_run',
            'status' => 'pending',
            'summary' => json_encode($summary, JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['created_from' => 'evergrove_admin'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($rows as $index => $row) {
            $normalized = $this->normalizeRechargeRow($row);
            $errors = $this->migrationRowErrors($normalized);
            $status = $errors === [] ? 'valid' : 'error';
            $summary[$status === 'valid' ? 'valid_rows' : 'error_rows']++;
            if (($normalized['status'] ?? '') === 'active') {
                $summary['active_subscriptions']++;
            }
            if ((bool) ($normalized['is_candle_club'] ?? false)) {
                $summary['candle_club_rows']++;
            }

            DB::table('subscription_migration_rows')->insert([
                'tenant_id' => $tenantId,
                'subscription_migration_batch_id' => $batchId,
                'source_type' => 'subscription',
                'source_id' => $normalized['recharge_subscription_id'] ?: ('row-'.$index),
                'status' => $status,
                'shopify_customer_gid' => $normalized['shopify_customer_gid'],
                'shopify_subscription_contract_gid' => $normalized['shopify_subscription_contract_gid'],
                'recharge_customer_id' => $normalized['recharge_customer_id'],
                'recharge_subscription_id' => $normalized['recharge_subscription_id'],
                'mapped_payload' => $this->encryptedPayload($normalized),
                'errors' => $errors === [] ? null : json_encode($errors, JSON_THROW_ON_ERROR),
                'metadata' => json_encode(['row_index' => $index], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $finalStatus = $summary['error_rows'] > 0 || $rows === [] ? 'needs_review' : 'ready_for_review';
        DB::table('subscription_migration_batches')
            ->where('id', $batchId)
            ->update([
                'status' => $finalStatus,
                'summary' => json_encode($summary, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

        return [
            'id' => $batchId,
            'status' => $finalStatus,
            'summary' => $summary,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function approveCutover(int $tenantId, int $batchId, ?int $actorId, bool $rechargeBillingPaused): array
    {
        $actorId ??= auth()->id();
        $this->assertManager($tenantId, $actorId);
        $batch = DB::table('subscription_migration_batches')
            ->where('tenant_id', $tenantId)
            ->where('id', $batchId)
            ->first();

        if (! $batch) {
            return ['ok' => false, 'status' => 'not_found', 'message' => 'Migration batch was not found.'];
        }

        if (! $rechargeBillingPaused) {
            return ['ok' => false, 'status' => 'recharge_billing_not_paused', 'message' => 'Confirm Recharge billing is paused before cutover.'];
        }

        if (! in_array((string) $batch->status, ['ready_for_review', 'ready_for_cutover', 'cutover_review_requested'], true)) {
            return ['ok' => false, 'status' => 'batch_not_ready', 'message' => 'Migration batch must be ready before cutover.'];
        }

        DB::table('subscription_migration_batches')
            ->where('id', $batchId)
            ->update([
                'status' => 'cutover_review_requested',
                'approved_by_user_id' => $actorId,
                'approved_at' => now(),
                'recharge_billing_paused_confirmed' => true,
                'updated_at' => now(),
            ]);

        // A checkbox is planning evidence only; it cannot authorize charging or replace a binding.
        DB::table('subscription_module_settings')->where('tenant_id', $tenantId)->where('module_key', self::MODULE_KEY)->update([
            'billing_scheduler_enabled' => false, 'updated_at' => now(),
        ]);

        return ['ok' => true, 'status' => 'cutover_review_requested', 'message' => 'Cutover review requested. No migration, Shopify change or billing activation was performed.'];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function recordAdminAction(int $tenantId, int $contractId, string $action, ?int $actorId, array $payload = []): array
    {
        $actorId ??= auth()->id();
        $this->assertManager($tenantId, $actorId);
        $binding = $this->access->assertBinding($tenantId);
        $contract = $this->operationalContracts($tenantId)
            ->where('tenant_id', $tenantId)
            ->where('id', $contractId)
            ->first();

        if (! $contract) {
            return ['ok' => false, 'status' => 'contract_not_found', 'message' => 'Subscription contract was not found.'];
        }

        $allowed = ['pause', 'resume', 'cancel', 'swap_product', 'update_next_billing_date', 'update_shipping_address', 'send_payment_update_email', 'admin_note'];
        $normalizedAction = Str::snake(strtolower(trim($action)));
        if (! in_array($normalizedAction, $allowed, true)) {
            return ['ok' => false, 'status' => 'unsupported_action', 'message' => 'This subscription action is not supported.'];
        }

        $payload = $this->intents->validate($normalizedAction, $payload);
        DB::table('subscription_lifecycle_events')->insert([
            'tenant_id' => $tenantId,
            'subscription_contract_id' => (int) $contract->id,
            'actor_user_id' => $actorId,
            'event_type' => $normalizedAction,
            'source' => 'shopify_embedded_admin',
            'status' => 'intent_recorded',
            'before_payload' => $this->encryptedPayload($this->contractSummary($contract)),
            'after_payload' => $this->encryptedPayload($payload),
            'metadata' => json_encode([
                'shopify_source_of_truth' => true,
                'provider_execution_enabled' => false,
                'provider_binding' => $binding,
                'contract_ownership_verified' => false,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['ok' => true, 'status' => 'intent_recorded', 'message' => 'Request saved for review. No Shopify change, payment email or charge was sent.'];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function recordCustomerCandleClubAction(MarketingProfile $profile, string $action, array $payload = [], bool $demo = false): array
    {
        $tenantId = (int) $profile->tenant_id;
        $normalizedAction = $this->normalizeCustomerCandleClubAction($action);

        if ($normalizedAction === null) {
            return ['ok' => false, 'status' => 'unsupported_action', 'message' => 'This Candle Club action is not supported yet.'];
        }

        $this->access->assertEntitled($tenantId);
        if ($demo) {
            return ['ok' => true, 'status' => 'preview_recorded', 'action' => $normalizedAction, 'message' => 'Demo request only. No subscription, vote, email or charge was changed.', 'candle_club' => $this->customerCandleClubPayload($profile, true)];
        }
        $contract = $this->activeCandleClubContractForProfile($profile);
        $isPreview = false;
        if (! $contract) {
            return [
                'ok' => false,
                'status' => 'not_eligible',
                'message' => 'Only active Candle Club members can manage Candle Club.',
                'candle_club' => $this->customerCandleClubPayload($profile),
            ];
        }

        $binding = $this->access->assertBinding($tenantId);
        $payload = $this->intents->validate($normalizedAction, $payload);
        $settings = $this->candleClubSettings($tenantId);
        $contractSummary = $this->contractSummary($contract);
        $validatedPayload = $this->validatedCustomerActionPayload($normalizedAction, $payload, $contract, $settings);
        if (! (bool) ($validatedPayload['ok'] ?? false)) {
            return [
                'ok' => false,
                'status' => (string) ($validatedPayload['status'] ?? 'invalid_payload'),
                'message' => (string) ($validatedPayload['message'] ?? 'Check the Candle Club request and try again.'),
                'candle_club' => $this->customerCandleClubPayload($profile),
            ];
        }

        if ($normalizedAction === 'vote') {
            return $this->recordAuthenticatedCandleClubVote($profile, $contract, (array) $validatedPayload['payload'], $isPreview);
        }

        DB::table('subscription_lifecycle_events')->insert([
            'tenant_id' => $tenantId,
            'subscription_contract_id' => $isPreview ? null : (int) $contract->id,
            'actor_user_id' => null,
            'event_type' => $normalizedAction,
            'source' => 'mobile_app',
            'status' => $isPreview ? 'shopify_preview_recorded' : 'intent_recorded',
            'before_payload' => $this->encryptedPayload($contractSummary),
            'after_payload' => $this->encryptedPayload((array) $validatedPayload['payload']),
            'metadata' => json_encode([
                'shopify_source_of_truth' => true,
                'shopify_mode' => $isPreview ? 'preview_no_live_mutation' : 'local_request_only',
                'requires_shopify_subscription_contract_gid' => $contractSummary['shopify_subscription_contract_gid'] === '',
                'marketing_profile_id' => (int) $profile->id,
                'provider_binding' => $binding,
                'provider_execution_enabled' => false,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'ok' => true,
            'status' => $isPreview ? 'preview_recorded' : 'intent_recorded',
            'action' => $normalizedAction,
            'message' => $this->customerActionMessage($normalizedAction, $isPreview),
            'shopify_mode' => $isPreview ? 'preview_no_live_mutation' : 'local_request_only',
            'provider_execution_enabled' => false,
            'candle_club' => $this->customerCandleClubPayload($profile),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function customerCandleClubPayload(MarketingProfile $profile, bool $demo = false): array
    {
        $tenantId = (int) $profile->tenant_id;
        if (! $this->access->entitled($tenantId)) {
            return ['eligible' => false, 'status' => 'module_locked', 'message' => 'Subscriptions is not enabled for this workspace.', 'contract' => null, 'active_poll' => null, 'actions' => [], 'readiness' => $this->access->readiness($tenantId)];
        }
        if ($demo) {
            return $this->previewCandleClubPayload($profile, null);
        }
        $contract = $this->activeCandleClubContractForProfile($profile);
        $poll = $this->activePoll($tenantId);
        $settings = $this->candleClubSettings($tenantId);
        $readiness = $this->access->readiness($tenantId);
        $canRequest = $contract !== null && $readiness['store_bound'];

        return [
            'readiness' => $readiness,
            'action_mode' => 'request_for_review',
            'eligible' => $contract !== null,
            'status' => $contract ? 'active' : 'not_active',
            'message' => $contract ? null : 'Candle Club voting is available for active Candle Club members.',
            'contract' => $contract ? $this->contractSummary($contract) : null,
            'active_poll' => $poll ? $this->pollPayload((int) $poll['id'], $contract) : null,
            'vote_history' => $contract ? $this->voteHistory($tenantId, (string) $contract->shopify_subscription_contract_gid) : [],
            'previous_chosen_scents' => $this->previousChosenScents($tenantId),
            'actions' => [
                'can_vote' => $canRequest && $poll !== null,
                'can_pause' => $canRequest,
                'can_cancel' => $canRequest,
                'can_update_address' => $canRequest,
                'can_update_card' => $canRequest,
                'can_swap_to_active_16oz_scent' => $canRequest,
            ],
            'commitment' => $contract ? $this->commitmentSummary($contract, $settings) : null,
            'payment_method' => $contract ? $this->paymentMethodSummary($tenantId, $contract) : null,
            'shipping_address' => $contract ? $this->shippingAddressPayload($contract) : null,
            'action_menus' => [
                'swap_options' => $contract ? $this->swapOptions($tenantId) : [],
                'pause_duration_options' => array_values((array) ($settings['pause_duration_options'] ?? [1, 2])),
            ],
            'monthly_scents' => $contract ? $this->monthlyScentCards($tenantId, 6) : [],
            'cancel_prompt' => $settings['cancellation_prompt'],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $poll
     * @return array<string,mixed>
     */
    protected function previewCandleClubPayload(MarketingProfile $profile, ?array $poll): array
    {
        $tenantId = (int) $profile->tenant_id;
        $contract = $this->previewCandleClubContract();

        $previousScents = [
            ['title' => 'Coffeehouse', 'body' => 'Demo scent: espresso and vanilla.', 'published_at' => now()->subMonth()->toDateString()],
            ['title' => 'Cabin Morning', 'body' => 'Demo scent: cedar and spice.', 'published_at' => now()->subMonths(2)->toDateString()],
        ];

        return [
            'eligible' => true,
            'status' => 'active',
            'message' => 'Fictional demo. Requests do not change a subscription, vote, review, email or payment.',
            'contract' => $this->contractSummary($contract),
            'active_poll' => $poll
                ? $this->pollPayload((int) $poll['id'], $contract)
                : $this->previewPollPayload(),
            'vote_history' => [
                [
                    'poll_title' => 'June Candle Club vote',
                    'option_label' => 'Coffeehouse',
                    'voted_at' => CarbonImmutable::now()->subWeeks(4)->toDateString(),
                ],
            ],
            'previous_chosen_scents' => $previousScents,
            'actions' => [
                'can_vote' => true,
                'can_pause' => true,
                'can_cancel' => true,
                'can_update_address' => true,
                'can_update_card' => true,
                'can_swap_to_active_16oz_scent' => true,
            ],
            'commitment' => $this->commitmentSummary($contract, ['commitment_months' => 6, 'allowed_pauses_per_commitment' => 2]),
            'payment_method' => [
                'status' => 'active',
                'brand' => 'Visa',
                'last_digits' => '4242',
                'expiry_month' => '12',
                'expiry_year' => '2028',
                'last_update_email_sent_at' => null,
            ],
            'shipping_address' => [
                'firstName' => 'App',
                'lastName' => 'Reviewer',
                'address1' => '123 Forest Lane',
                'city' => 'Asheville',
                'province' => 'North Carolina',
                'provinceCode' => 'NC',
                'zip' => '28801',
                'country' => 'United States',
                'countryCode' => 'US',
            ],
            'action_menus' => [
                'swap_options' => $this->previewSwapOptions($previousScents),
                'pause_duration_options' => [1, 2],
            ],
            'monthly_scents' => $this->previewMonthlyScents($previousScents),
            'cancel_prompt' => $this->defaultCancellationPrompt(),
            'preview' => true,
        ];
    }

    protected function previewCandleClubContract(): object
    {
        return (object) [
            'id' => 0,
            'shopify_subscription_contract_gid' => 'gid://evergrove/PreviewSubscriptionContract/john-collins-candle-club',
            'shopify_customer_gid' => 'gid://evergrove/PreviewCustomer/john-collins',
            'status' => 'active',
            'is_candle_club' => true,
            'next_billing_date' => CarbonImmutable::now()->addWeeks(2)->toDateString(),
            'next_shipping_date' => CarbonImmutable::now()->addWeeks(3)->toDateString(),
            'completed_cycles' => 3,
            'pause_count_current_commitment' => 0,
            'commitment_ends_on' => CarbonImmutable::now()->addMonths(3)->toDateString(),
        ];
    }

    protected function normalizeCustomerCandleClubAction(string $action): ?string
    {
        $normalized = Str::snake(strtolower(trim($action)));

        return [
            'pause' => 'pause',
            'cancel' => 'cancel',
            'swap_16oz_scent' => 'swap_product',
            'swap_to_active_16oz_scent' => 'swap_product',
            'swap_product' => 'swap_product',
            'update_shipping_address' => 'update_shipping_address',
            'update_address' => 'update_shipping_address',
            'update_payment_card' => 'send_payment_update_email',
            'update_card' => 'send_payment_update_email',
            'send_payment_update_email' => 'send_payment_update_email',
            'vote' => 'vote',
            'vote_for_next_month' => 'vote',
            'cast_vote' => 'vote',
        ][$normalized] ?? null;
    }

    protected function customerActionMessage(string $action, bool $isPreview): string
    {
        $suffix = $isPreview
            ? ' Preview recorded without changing a live Shopify contract.'
            : ' Saved for review only. No Shopify change, email or charge has been sent.';

        return match ($action) {
            'pause' => 'Pause request received.'.$suffix,
            'cancel' => 'Cancellation request received.'.$suffix,
            'swap_product' => 'Scent swap request received.'.$suffix,
            'update_shipping_address' => 'Shipping address update request received.'.$suffix,
            'send_payment_update_email' => 'Payment update request saved for review. No email has been sent.'.$suffix,
            'vote' => 'Your Candle Club vote has been recorded.',
            default => 'Candle Club request received.'.$suffix,
        };
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function recordAuthenticatedCandleClubVote(MarketingProfile $profile, object $contract, array $payload, bool $isPreview): array
    {
        if ($isPreview) {
            return [
                'ok' => true,
                'status' => 'preview_vote_recorded',
                'action' => 'vote',
                'message' => 'Preview vote recorded without changing a live poll.',
                'shopify_mode' => 'preview_no_live_mutation',
                'shopify_mutation' => 'none_vote_recorded_in_evergrove',
                'candle_club' => $this->customerCandleClubPayload($profile),
            ];
        }

        $tenantId = (int) $profile->tenant_id;
        $pollId = (int) ($payload['poll_id'] ?? 0);
        $optionId = (int) ($payload['option_id'] ?? 0);
        $contractGid = (string) ($contract->shopify_subscription_contract_gid ?? '');

        $existing = DB::table('subscription_votes')
            ->where('tenant_id', $tenantId)
            ->where('subscription_poll_id', $pollId)
            ->where('shopify_subscription_contract_gid', $contractGid)
            ->first();

        if ($existing) {
            return [
                'ok' => false,
                'status' => 'already_voted',
                'action' => 'vote',
                'message' => 'This Candle Club subscription has already voted.',
                'candle_club' => $this->customerCandleClubPayload($profile),
            ];
        }

        DB::transaction(function () use ($tenantId, $pollId, $optionId, $contract, $contractGid, $profile): void {
            DB::table('subscription_votes')->insert([
                'tenant_id' => $tenantId,
                'subscription_poll_id' => $pollId,
                'subscription_poll_option_id' => $optionId,
                'subscription_contract_id' => (int) $contract->id,
                'marketing_profile_id' => (int) $profile->id,
                'shopify_subscription_contract_gid' => $contractGid,
                'shopify_customer_gid' => $contract->shopify_customer_gid,
                'normalized_email' => $this->nullableString($profile->normalized_email ?? $profile->email ?? null),
                'normalized_phone' => $this->nullableString($profile->normalized_phone ?? $profile->phone ?? null),
                'source' => 'ios_app',
                'metadata' => json_encode(['authenticated_mobile_vote' => true], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('subscription_lifecycle_events')->insert([
                'tenant_id' => $tenantId,
                'subscription_contract_id' => (int) $contract->id,
                'actor_user_id' => null,
                'event_type' => 'vote',
                'source' => 'mobile_app',
                'status' => 'vote_recorded',
                'before_payload' => json_encode($this->contractSummary($contract), JSON_THROW_ON_ERROR),
                'after_payload' => json_encode(['poll_id' => $pollId, 'option_id' => $optionId], JSON_THROW_ON_ERROR),
                'metadata' => json_encode(['shopify_source_of_truth' => false, 'vote_source_of_truth' => 'evergrove'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return [
            'ok' => true,
            'status' => 'vote_recorded',
            'action' => 'vote',
            'message' => 'Your Candle Club vote has been recorded.',
            'shopify_mode' => 'not_applicable',
            'shopify_mutation' => 'none_vote_recorded_in_evergrove',
            'candle_club' => $this->customerCandleClubPayload($profile),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function previewPollPayload(): array
    {
        return [
            'id' => 0,
            'title' => 'Vote for next month',
            'description' => 'Preview ballot for testing the Candle Club app menu before subscription cutover.',
            'status' => 'open',
            'opens_at' => CarbonImmutable::now()->subDay()->toDateString(),
            'closes_at' => CarbonImmutable::now()->addWeek()->toDateString(),
            'share_url' => '/subscriptions/polls/preview-candle-club',
            'options' => [
                ['id' => 1, 'label' => 'Coffeehouse', 'votes' => 18],
                ['id' => 2, 'label' => 'Cabin Morning', 'votes' => 12],
                ['id' => 3, 'label' => 'Forest Rain', 'votes' => 9],
            ],
            'already_voted' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function storefrontPollPayload(int $tenantId): array
    {
        $this->access->assertEntitled($tenantId);
        $poll = $this->activePoll($tenantId);

        return [
            'tenant_id' => $tenantId,
            'eligible_poll' => $poll ? $this->pollPayload((int) $poll['id']) : null,
            'verification' => [
                'required' => true,
                'identifier_types' => ['email', 'phone'],
                'message' => 'Enter the email or phone tied to your active Candle Club subscription.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function requestVoteCode(int $tenantId, int $pollId, string $identifier, string $source = 'facebook'): array
    {
        $this->access->assertEntitled($tenantId);
        if (! app()->environment(['local', 'testing'])) {
            return ['ok' => false, 'status' => 'verification_delivery_unavailable', 'message' => 'Voting code delivery is not configured. No code has been sent.'];
        }
        $poll = $this->operationalPolls($tenantId)
            ->where('id', $pollId)
            ->first();
        if (! $this->pollIsOpen($poll)) {
            return ['ok' => false, 'status' => 'poll_not_open', 'message' => 'Voting is not open.'];
        }

        $normalized = $this->normalizeIdentifier($identifier);
        if ($normalized['value'] === '') {
            return ['ok' => false, 'status' => 'invalid_identifier', 'message' => 'Enter the email or phone tied to Candle Club.'];
        }

        $contract = $this->activeCandleClubContractForIdentifier($tenantId, $normalized['type'], $normalized['value']);
        if (! $contract) {
            return ['ok' => false, 'status' => 'not_eligible', 'message' => 'Only active Candle Club members can vote.'];
        }

        $code = (string) random_int(100000, 999999);
        $tokenId = DB::table('subscription_voter_verification_tokens')->insertGetId([
            'tenant_id' => $tenantId,
            'subscription_poll_id' => $pollId,
            'subscription_contract_id' => (int) $contract->id,
            'identifier_type' => $normalized['type'],
            'identifier_hash' => hash('sha256', $normalized['value']),
            'code_hash' => hash('sha256', $code),
            'delivery_channel' => $normalized['type'] === 'email' ? 'email' : 'sms',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
            'metadata' => json_encode(['source' => $source], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            'ok' => true,
            'status' => 'test_code_created',
            'verification_token_id' => $tokenId,
            'delivery_channel' => $normalized['type'] === 'email' ? 'email' : 'sms',
            'message' => 'Test code created locally. No email or text has been sent.',
        ];

        if (app()->environment(['local', 'testing'])) {
            $payload['debug_code'] = $code;
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function castVoteWithCode(int $tenantId, int $pollId, int $optionId, int $tokenId, string $code, string $source = 'facebook'): array
    {
        $this->access->assertEntitled($tenantId);
        if (! $this->pollIsOpen($this->operationalPolls($tenantId)->where('id', $pollId)->first())) {
            return ['ok' => false, 'status' => 'poll_not_open', 'message' => 'Voting is not open.'];
        }
        $token = DB::table('subscription_voter_verification_tokens')
            ->where('tenant_id', $tenantId)
            ->where('subscription_poll_id', $pollId)
            ->where('id', $tokenId)
            ->first();

        if (! $token || (string) $token->status !== 'pending') {
            return ['ok' => false, 'status' => 'invalid_token', 'message' => 'The voting code is no longer valid.'];
        }

        if (CarbonImmutable::parse((string) $token->expires_at)->isPast()) {
            return ['ok' => false, 'status' => 'expired_token', 'message' => 'The voting code expired.'];
        }

        if (! hash_equals((string) $token->code_hash, hash('sha256', trim($code)))) {
            return ['ok' => false, 'status' => 'invalid_code', 'message' => 'The voting code does not match.'];
        }

        $contract = $this->operationalContracts($tenantId)
            ->where('id', (int) $token->subscription_contract_id)
            ->where('status', 'active')
            ->where('is_candle_club', true)
            ->first();
        if (! $contract) {
            return ['ok' => false, 'status' => 'not_eligible', 'message' => 'Only active Candle Club members can vote.'];
        }

        $option = DB::table('subscription_poll_options')
            ->where('tenant_id', $tenantId)
            ->where('subscription_poll_id', $pollId)
            ->where('id', $optionId)
            ->first();
        if (! $option) {
            return ['ok' => false, 'status' => 'invalid_option', 'message' => 'Choose a valid scent option.'];
        }

        $existing = DB::table('subscription_votes')
            ->where('tenant_id', $tenantId)
            ->where('subscription_poll_id', $pollId)
            ->where('shopify_subscription_contract_gid', (string) $contract->shopify_subscription_contract_gid)
            ->first();
        if ($existing) {
            return ['ok' => false, 'status' => 'already_voted', 'message' => 'This Candle Club subscription has already voted.'];
        }

        DB::transaction(function () use ($tenantId, $pollId, $optionId, $tokenId, $contract, $source): void {
            DB::table('subscription_votes')->insert([
                'tenant_id' => $tenantId,
                'subscription_poll_id' => $pollId,
                'subscription_poll_option_id' => $optionId,
                'subscription_contract_id' => (int) $contract->id,
                'marketing_profile_id' => $contract->marketing_profile_id ? (int) $contract->marketing_profile_id : null,
                'shopify_subscription_contract_gid' => (string) $contract->shopify_subscription_contract_gid,
                'shopify_customer_gid' => $contract->shopify_customer_gid,
                'normalized_email' => $this->nullableString($contract->metadata ? data_get($this->decodeJson($contract->metadata), 'normalized_email') : null),
                'normalized_phone' => $this->nullableString($contract->metadata ? data_get($this->decodeJson($contract->metadata), 'normalized_phone') : null),
                'source' => $source,
                'metadata' => json_encode(['verified_with_otp' => true], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('subscription_voter_verification_tokens')
                ->where('id', $tokenId)
                ->update([
                    'status' => 'verified',
                    'verified_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return ['ok' => true, 'status' => 'vote_recorded', 'message' => 'Your vote has been recorded.'];
    }

    /**
     * @param  object|array<string,mixed>|null  $contract
     * @return array<string,mixed>|null
     */
    public function pollPayload(int $pollId, object|array|null $contract = null): ?array
    {
        $poll = DB::table('subscription_polls')->where('id', $pollId)->first();
        if ($poll && $this->isDemoPoll($poll)) {
            return null;
        }
        if ($poll) {
            $this->access->assertEntitled((int) $poll->tenant_id);
        }
        if (! $poll) {
            return null;
        }

        $options = DB::table('subscription_poll_options')
            ->where('tenant_id', (int) $poll->tenant_id)
            ->where('subscription_poll_id', $pollId)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (object $option): array {
                $votes = $this->operationalVotes((int) $option->tenant_id)
                    ->where('subscription_poll_option_id', (int) $option->id)
                    ->count();

                return [
                    'id' => (int) $option->id,
                    'label' => (string) $option->label,
                    'votes' => $votes,
                ];
            })
            ->all();

        $contractGid = is_object($contract)
            ? (string) ($contract->shopify_subscription_contract_gid ?? '')
            : (string) data_get($contract, 'shopify_subscription_contract_gid', '');

        $alreadyVoted = $contractGid !== '' && DB::table('subscription_votes')
            ->where('tenant_id', (int) $poll->tenant_id)
            ->where('subscription_poll_id', $pollId)
            ->where('shopify_subscription_contract_gid', $contractGid)
            ->exists();

        return [
            'id' => (int) $poll->id,
            'title' => (string) $poll->title,
            'description' => (string) ($poll->description ?? ''),
            'status' => (string) $poll->status,
            'opens_at' => $poll->opens_at,
            'closes_at' => $poll->closes_at,
            'share_url' => route('subscriptions.public.poll', ['poll' => (int) $poll->id, 'token' => (string) $poll->share_token], false),
            'options' => $options,
            'already_voted' => $alreadyVoted,
        ];
    }

    /** Evidence only. No contract projection, email, provider mutation or billing job is dispatched. */
    public function recordShopifyWebhook(int $tenantId, string $topic, array $payload, array $store = [], string $webhookId = ''): bool
    {
        $topics = ['subscription_contracts/create', 'subscription_contracts/update', 'subscription_billing_attempts/success', 'subscription_billing_attempts/failure', 'customer_payment_methods/create', 'customer_payment_methods/update', 'customer_payment_methods/revoke'];
        if (! $this->access->entitled($tenantId) || ! in_array($topic, $topics, true)
            || ! preg_match('/^[a-zA-Z0-9-]{1,100}$/D', $webhookId)
            || strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 262144) {
            return false;
        }
        try {
            $binding = $this->access->assertBinding($tenantId, $store);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            return false;
        }
        $identity = hash('sha256', $binding['shop_domain'].'|'.$binding['app_client_id'].'|'.$webhookId);

        return DB::transaction(function () use ($tenantId, $topic, $payload, $binding, $identity): bool {
            // Serialize this evidence-only lane; Phase 2 requires an indexed unique inbox
            // before a worker may project contracts or perform financial operations.
            DB::table('subscription_module_settings')->where('tenant_id', $tenantId)->where('module_key', self::MODULE_KEY)->lockForUpdate()->first();
            if (DB::table('subscription_lifecycle_events')->where('tenant_id', $tenantId)
                ->where('source', 'shopify_webhook')->where('metadata->webhook_identity', $identity)->exists()) {
                return true;
            }
            DB::table('subscription_lifecycle_events')->insert([
                'tenant_id' => $tenantId, 'event_type' => str_replace('/', '_', $topic),
                'source' => 'shopify_webhook', 'status' => 'received_unprocessed',
                'after_payload' => $this->encryptedPayload($payload),
                'metadata' => json_encode(['topic' => $topic, 'webhook_identity' => $identity, 'provider_binding' => $binding, 'provider_execution_enabled' => false], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return true;
        });
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function submitCandleClubScentFeedback(MarketingProfile $profile, int $monthlyScentId, array $payload): array
    {
        $this->access->assertEntitled((int) $profile->tenant_id);
        if (! Schema::hasTable('subscription_candle_club_scent_feedback')) {
            return ['ok' => false, 'status' => 'feedback_not_ready', 'message' => 'Candle Club scent feedback is not ready yet.'];
        }

        $tenantId = (int) $profile->tenant_id;
        $this->access->assertEntitled($tenantId);
        $contract = $this->activeCandleClubContractForProfile($profile);

        if (! $contract) {
            return ['ok' => false, 'status' => 'not_eligible', 'message' => 'Only active Candle Club members can review monthly Candle Club scents.'];
        }

        $monthlyScent = DB::table('subscription_candle_club_monthly_scents')
            ->where('tenant_id', $tenantId)
            ->where('id', $monthlyScentId)
            ->first();
        if (! $monthlyScent) {
            return ['ok' => false, 'status' => 'scent_not_found', 'message' => 'That Candle Club scent was not found.'];
        }

        $body = $this->nullableString($payload['body'] ?? null);
        if ($body === null) {
            return ['ok' => false, 'status' => 'missing_review_body', 'message' => 'Add a few words before sending feedback.'];
        }
        $data = Validator::make(array_replace($payload, ['body' => $body, 'rating' => $payload['rating'] ?? 5]), [
            'request_id' => ['nullable', 'uuid'], 'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:190'], 'body' => ['required', 'string', 'max:2000'],
        ])->validate();
        $rating = (int) $data['rating'];
        $title = $this->nullableString($data['title'] ?? null);
        $requestId = isset($data['request_id']) ? strtolower($data['request_id']) : null;

        return DB::transaction(function () use ($tenantId, $profile, $monthlyScentId, $contract, $payload, $body, $title, $rating, $requestId): array {
            // Serialize retries on the owned scent, without adding a new feedback store.
            $ownedScent = DB::table('subscription_candle_club_monthly_scents')->where('tenant_id', $tenantId)
                ->where('id', $monthlyScentId)->lockForUpdate()->first();
            abort_unless($ownedScent, 404);
            if ($requestId) {
                $prior = DB::table('subscription_candle_club_scent_feedback')->where('tenant_id', $tenantId)
                    ->where('marketing_profile_id', $profile->id)->where('subscription_candle_club_monthly_scent_id', $monthlyScentId)
                    ->where('metadata->request_id', $requestId)->first();
                if ($prior) {
                    if ((int) $prior->rating !== $rating || $prior->title !== $title || $prior->body !== $body) {
                        return ['ok' => false, 'status' => 'request_conflict', 'message' => 'This feedback request already has different content. Reopen the comment to send a new note.'];
                    }

                    return ['ok' => true, 'status' => 'feedback_recorded', 'id' => (int) $prior->id, 'message' => 'Thanks! Your scent note was sent to our team for review.'];
                }
            }
            $feedbackId = DB::table('subscription_candle_club_scent_feedback')->insertGetId([
                'tenant_id' => $tenantId,
                'subscription_candle_club_monthly_scent_id' => $monthlyScentId,
                'subscription_contract_id' => (int) ($contract->id ?? 0) > 0 ? (int) $contract->id : null,
                'marketing_profile_id' => (int) $profile->id,
                'rating' => $rating, 'title' => $title, 'body' => $body,
                'visibility' => 'candle_club', 'status' => 'pending',
                'metadata' => json_encode(['source' => (string) ($payload['source'] ?? 'mobile_app'), 'private_first' => true, 'request_id' => $requestId], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['ok' => true, 'status' => 'feedback_recorded', 'id' => $feedbackId, 'message' => 'Thanks! Your scent note was sent to our team for review.'];
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function exportCandleClubScentFeedback(int $tenantId, int $feedbackId, ?int $actorId = null): array
    {
        $actorId ??= auth()->id();
        $this->assertManager($tenantId, $actorId);
        if (! Schema::hasTable('subscription_candle_club_scent_feedback')) {
            return ['ok' => false, 'status' => 'feedback_not_ready', 'message' => 'Candle Club scent feedback is not ready yet.'];
        }

        $feedback = DB::table('subscription_candle_club_scent_feedback')
            ->where('tenant_id', $tenantId)
            ->where('id', $feedbackId)
            ->first();
        if (! $feedback) {
            return ['ok' => false, 'status' => 'feedback_not_found', 'message' => 'Feedback was not found.'];
        }

        if ($feedback->exported_marketing_review_history_id) {
            return ['ok' => true, 'status' => 'already_exported', 'review_id' => (int) $feedback->exported_marketing_review_history_id];
        }

        $monthlyScent = DB::table('subscription_candle_club_monthly_scents')
            ->where('tenant_id', $tenantId)
            ->where('id', (int) $feedback->subscription_candle_club_monthly_scent_id)
            ->first();
        if (! $monthlyScent) {
            return ['ok' => false, 'status' => 'scent_not_found', 'message' => 'Monthly scent was not found.'];
        }

        $review = MarketingReviewHistory::query()->create([
            'marketing_profile_id' => $feedback->marketing_profile_id,
            'tenant_id' => $tenantId,
            'provider' => 'evergrove',
            'integration' => 'candle_club',
            'store_key' => 'retail',
            'external_customer_id' => 'candle-club-profile-'.($feedback->marketing_profile_id ?: 'unknown'),
            'external_review_id' => 'candle-club-feedback-'.$feedback->id,
            'rating' => $feedback->rating,
            'title' => $feedback->title,
            'body' => $feedback->body,
            'is_published' => false,
            'status' => 'pending',
            'submission_source' => 'candle_club_feedback_export',
            'is_verified_buyer' => true,
            'product_id' => $monthlyScent->shopify_product_gid,
            'product_handle' => $monthlyScent->shopify_product_handle,
            'product_title' => $monthlyScent->title,
            'submitted_at' => now(),
            'reviewed_at' => now(),
            'moderated_by' => $actorId,
            'raw_payload' => [
                'candle_club_feedback_id' => (int) $feedback->id,
                'monthly_scent_id' => (int) $monthlyScent->id,
                'private_first_export' => true,
            ],
        ]);

        DB::table('subscription_candle_club_scent_feedback')
            ->where('id', $feedbackId)
            ->update([
                'status' => 'exported',
                'exported_marketing_review_history_id' => (int) $review->id,
                'exported_at' => now(),
                'updated_at' => now(),
            ]);

        return ['ok' => true, 'status' => 'exported', 'review_id' => (int) $review->id];
    }

    /**
     * @return array<string,mixed>
     */
    protected function moduleSettings(int $tenantId): array
    {
        $row = DB::table('subscription_module_settings')->where('tenant_id', $tenantId)->where('module_key', self::MODULE_KEY)->first();
        if (! $row) {
            DB::table('subscription_module_settings')->insert([
                'tenant_id' => $tenantId,
                'module_key' => self::MODULE_KEY,
                'status' => 'setup',
                'shopify_store_key' => 'retail',
                'notification_settings' => json_encode(['payment_failed' => ['email' => true, 'sms' => true]], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = DB::table('subscription_module_settings')->where('tenant_id', $tenantId)->where('module_key', self::MODULE_KEY)->first();
        }

        return [
            'status' => (string) $row->status,
            'billing_scheduler_enabled' => (bool) $row->billing_scheduler_enabled,
            'shopify_store_key' => (string) ($row->shopify_store_key ?? 'retail'),
            'notification_settings' => $this->decodeJson($row->notification_settings),
            'metadata' => $this->decodeJson($row->metadata),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function candleClubSettings(int $tenantId): array
    {
        $row = DB::table('subscription_candle_club_settings')->where('tenant_id', $tenantId)->first();
        if (! $row) {
            DB::table('subscription_candle_club_settings')->insert([
                'tenant_id' => $tenantId,
                'pause_duration_options' => json_encode([1, 2, 3], JSON_THROW_ON_ERROR),
                'cancellation_prompt' => $this->defaultCancellationPrompt(),
                'poll_defaults' => json_encode(['source_surfaces' => ['app', 'storefront', 'facebook']], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = DB::table('subscription_candle_club_settings')->where('tenant_id', $tenantId)->first();
        }

        return [
            'commitment_months' => (int) $row->commitment_months,
            'allowed_pauses_per_commitment' => (int) $row->allowed_pauses_per_commitment,
            'pause_duration_options' => $this->decodeJson($row->pause_duration_options) ?: [1, 2, 3],
            'renewal_reward_months' => (int) $row->renewal_reward_months,
            'first_gift_product_variant_gid' => $row->first_gift_product_variant_gid,
            'first_gift_label' => (string) $row->first_gift_label,
            'renewal_gift_product_variant_gid' => $row->renewal_gift_product_variant_gid,
            'renewal_gift_label' => (string) $row->renewal_gift_label,
            'cancellation_prompt' => (string) ($row->cancellation_prompt ?: $this->defaultCancellationPrompt()),
            'voting_reward_candle_cash' => (int) $row->voting_reward_candle_cash,
            'poll_defaults' => $this->decodeJson($row->poll_defaults),
        ];
    }

    protected function activePoll(int $tenantId): ?array
    {
        $poll = $this->operationalPolls($tenantId)
            ->where('poll_type', self::CANDLE_CLUB_TYPE)
            ->where('status', 'open')
            ->orderByDesc('id')
            ->first();

        if (! $this->pollIsOpen($poll)) {
            return null;
        }

        return [
            'id' => (int) $poll->id,
            'title' => (string) $poll->title,
            'description' => (string) ($poll->description ?? ''),
            'share_token' => (string) $poll->share_token,
            'opens_at' => $poll->opens_at,
            'closes_at' => $poll->closes_at,
        ];
    }

    protected function activeCandleClubContractForProfile(MarketingProfile $profile): ?object
    {
        return $this->operationalContracts((int) $profile->tenant_id)
            ->where('marketing_profile_id', (int) $profile->id)
            ->where('status', 'active')
            ->where('is_candle_club', true)
            ->orderByDesc('id')
            ->first();
    }

    protected function activeCandleClubContractForIdentifier(int $tenantId, string $type, string $identifier): ?object
    {
        $customerQuery = DB::table('subscription_customers')
            ->where('tenant_id', $tenantId);

        if ($type === 'email') {
            $customerQuery->where('normalized_email', $identifier);
        } else {
            $customerQuery->where('normalized_phone', $identifier);
        }

        $customerIds = $customerQuery->pluck('id')->all();

        return $this->operationalContracts($tenantId)
            ->where('status', 'active')
            ->where('is_candle_club', true)
            ->where(function ($query) use ($customerIds, $type, $identifier): void {
                if ($customerIds !== []) {
                    $query->whereIn('subscription_customer_id', $customerIds);
                }

                $metadataKey = $type === 'email' ? 'normalized_email' : 'normalized_phone';
                $query->orWhere("metadata->{$metadataKey}", $identifier);
            })
            ->orderByDesc('id')
            ->first();
    }

    protected function pollIsOpen(?object $poll): bool
    {
        if (! $poll || (string) $poll->status !== 'open') {
            return false;
        }

        $now = CarbonImmutable::now();
        if ($poll->opens_at && CarbonImmutable::parse((string) $poll->opens_at)->greaterThan($now)) {
            return false;
        }

        if ($poll->closes_at && CarbonImmutable::parse((string) $poll->closes_at)->lessThan($now)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    protected function normalizeRechargeRow(array $row): array
    {
        $email = strtolower(trim((string) ($row['email'] ?? data_get($row, 'customer.email', ''))));
        $phone = preg_replace('/\D+/', '', (string) ($row['phone'] ?? data_get($row, 'customer.phone', ''))) ?: null;
        $productTitle = strtolower(trim((string) ($row['product_title'] ?? $row['title'] ?? '')));

        return [
            'recharge_subscription_id' => $this->nullableString($row['recharge_subscription_id'] ?? $row['subscription_id'] ?? $row['id'] ?? null),
            'recharge_customer_id' => $this->nullableString($row['recharge_customer_id'] ?? data_get($row, 'customer.id')),
            'shopify_customer_gid' => $this->nullableString($row['shopify_customer_gid'] ?? null),
            'shopify_subscription_contract_gid' => $this->nullableString($row['shopify_subscription_contract_gid'] ?? null),
            'shopify_product_variant_gid' => $this->nullableString($row['shopify_product_variant_gid'] ?? $row['variant_gid'] ?? null),
            'shopify_selling_plan_gid' => $this->nullableString($row['shopify_selling_plan_gid'] ?? $row['selling_plan_gid'] ?? null),
            'email' => $email !== '' ? $email : null,
            'normalized_email' => $email !== '' ? $email : null,
            'phone' => $phone,
            'normalized_phone' => $phone,
            'status' => strtolower(trim((string) ($row['status'] ?? 'active'))) ?: 'active',
            'next_billing_date' => $this->nullableString($row['next_billing_date'] ?? $row['next_charge_scheduled_at'] ?? null),
            'product_title' => $productTitle,
            'is_candle_club' => str_contains($productTitle, 'candle club') || str_contains($productTitle, 'subscription'),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<int,string>
     */
    protected function migrationRowErrors(array $row): array
    {
        $errors = [];
        if (($row['recharge_subscription_id'] ?? '') === '') {
            $errors[] = 'Missing Recharge subscription id.';
        }
        if (($row['shopify_customer_gid'] ?? '') === '' && ($row['normalized_email'] ?? '') === '' && ($row['normalized_phone'] ?? '') === '') {
            $errors[] = 'Missing Shopify customer gid, email, or phone for matching.';
        }
        if (($row['shopify_product_variant_gid'] ?? '') === '') {
            $errors[] = 'Missing Shopify product variant gid.';
        }
        if (($row['shopify_selling_plan_gid'] ?? '') === '') {
            $errors[] = 'Missing Shopify selling plan gid.';
        }

        return $errors;
    }

    protected function normalizeIdentifier(string $identifier): array
    {
        $value = trim($identifier);
        if ($value === '') {
            return ['type' => 'unknown', 'value' => ''];
        }

        if (str_contains($value, '@')) {
            return ['type' => 'email', 'value' => strtolower($value)];
        }

        return ['type' => 'phone', 'value' => preg_replace('/\D+/', '', $value) ?: ''];
    }

    protected function contractSummary(object $contract): array
    {
        return [
            'id' => (int) $contract->id,
            'shopify_subscription_contract_gid' => (string) ($contract->shopify_subscription_contract_gid ?? ''),
            'shopify_customer_gid' => (string) ($contract->shopify_customer_gid ?? ''),
            'status' => (string) ($contract->status ?? ''),
            'is_candle_club' => (bool) ($contract->is_candle_club ?? false),
            'next_billing_date' => $contract->next_billing_date ?? null,
            'next_shipping_date' => $contract->next_shipping_date ?? null,
            'completed_cycles' => (int) ($contract->completed_cycles ?? 0),
            'pause_count_current_commitment' => (int) ($contract->pause_count_current_commitment ?? 0),
            'commitment_ends_on' => $contract->commitment_ends_on ?? null,
        ];
    }

    protected function voteHistory(int $tenantId, string $contractGid): array
    {
        return $this->operationalVotes($tenantId)
            ->join('subscription_polls', 'subscription_polls.id', '=', 'subscription_votes.subscription_poll_id')
            ->join('subscription_poll_options', 'subscription_poll_options.id', '=', 'subscription_votes.subscription_poll_option_id')
            ->where('subscription_votes.tenant_id', $tenantId)
            ->where('subscription_votes.shopify_subscription_contract_gid', $contractGid)
            ->orderByDesc('subscription_votes.id')
            ->limit(12)
            ->get([
                'subscription_polls.title as poll_title',
                'subscription_poll_options.label as option_label',
                'subscription_votes.created_at',
            ])
            ->map(fn (object $row): array => [
                'poll_title' => (string) $row->poll_title,
                'option_label' => (string) $row->option_label,
                'voted_at' => $row->created_at,
            ])
            ->all();
    }

    protected function previousChosenScents(int $tenantId): array
    {
        return DB::table('subscription_announcements')
            ->where('tenant_id', $tenantId)
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->limit(3)
            ->get()
            ->map(fn (object $row): array => [
                'title' => (string) $row->title,
                'body' => (string) ($row->body ?? ''),
                'published_at' => $row->published_at,
            ])
            ->all();
    }

    protected function activeCandleClubCustomers(int $tenantId): array
    {
        $settings = $this->candleClubSettings($tenantId);

        return DB::table('subscription_contracts')
            ->leftJoin('subscription_customers', function ($join): void {
                $join->on('subscription_customers.id', '=', 'subscription_contracts.subscription_customer_id')->on('subscription_customers.tenant_id', '=', 'subscription_contracts.tenant_id');
            })
            ->leftJoin('marketing_profiles', function ($join): void {
                $join->on('marketing_profiles.id', '=', 'subscription_contracts.marketing_profile_id')->on('marketing_profiles.tenant_id', '=', 'subscription_contracts.tenant_id');
            })
            ->where('subscription_contracts.tenant_id', $tenantId)
            ->where('subscription_contracts.shopify_subscription_contract_gid', 'like', 'gid://shopify/SubscriptionContract/%')
            ->where(function ($query): void {
                $query->whereNull('subscription_contracts.metadata->app_review_demo')->orWhere('subscription_contracts.metadata->app_review_demo', false);
            })
            ->where('subscription_contracts.status', 'active')
            ->where('subscription_contracts.is_candle_club', true)
            ->orderBy('subscription_contracts.next_billing_date')
            ->limit(100)
            ->get([
                'subscription_contracts.*',
                'subscription_customers.email as customer_email',
                'subscription_customers.phone as customer_phone',
                'marketing_profiles.first_name as profile_first_name',
                'marketing_profiles.last_name as profile_last_name',
            ])
            ->map(function (object $row) use ($tenantId, $settings): array {
                $latestEvent = DB::table('subscription_lifecycle_events')
                    ->where('tenant_id', $tenantId)
                    ->where('subscription_contract_id', (int) $row->id)
                    ->orderByDesc('id')
                    ->first();

                $name = trim((string) (($row->profile_first_name ?? '').' '.($row->profile_last_name ?? '')));

                return [
                    ...$this->contractSummary($row),
                    'customer_name' => $name !== '' ? $name : 'Candle Club member',
                    'customer_email' => $row->customer_email,
                    'customer_phone' => $row->customer_phone,
                    'commitment' => $this->commitmentSummary($row, $settings),
                    'payment_method' => $this->paymentMethodSummary($tenantId, $row),
                    'latest_event' => $latestEvent ? [
                        'event_type' => (string) $latestEvent->event_type,
                        'status' => (string) $latestEvent->status,
                        'created_at' => $latestEvent->created_at,
                    ] : null,
                ];
            })
            ->all();
    }

    protected function commitmentSummary(object $contract, array $settings): array
    {
        $totalMonths = max(1, (int) ($settings['commitment_months'] ?? 6));
        $completedCycles = max(0, (int) ($contract->completed_cycles ?? 0));
        $allowedPauses = max(0, (int) ($settings['allowed_pauses_per_commitment'] ?? 2));
        $pausesUsed = max(0, (int) ($contract->pause_count_current_commitment ?? 0));

        return [
            'total_months' => $totalMonths,
            'completed_cycles' => $completedCycles,
            'months_remaining' => $this->monthsRemaining($contract, $totalMonths, $completedCycles),
            'allowed_pauses' => $allowedPauses,
            'pauses_used' => $pausesUsed,
            'pauses_remaining' => max(0, $allowedPauses - $pausesUsed),
        ];
    }

    protected function monthsRemaining(object $contract, int $totalMonths, int $completedCycles): int
    {
        if (! empty($contract->commitment_ends_on)) {
            $end = CarbonImmutable::parse((string) $contract->commitment_ends_on)->startOfMonth();
            $now = CarbonImmutable::now()->startOfMonth();

            return max(0, $now->diffInMonths($end, false));
        }

        return max(0, $totalMonths - $completedCycles);
    }

    protected function paymentMethodSummary(int $tenantId, object $contract): ?array
    {
        $gid = (string) ($contract->shopify_payment_method_gid ?? '');
        if ($gid === '') {
            return null;
        }

        $method = DB::table('subscription_payment_methods')
            ->where('tenant_id', $tenantId)
            ->where('shopify_payment_method_gid', $gid)
            ->first();

        if (! $method) {
            return [
                'status' => 'unknown',
                'brand' => null,
                'last_digits' => null,
                'expiry_month' => null,
                'expiry_year' => null,
                'last_update_email_sent_at' => null,
            ];
        }

        return [
            'status' => (string) $method->status,
            'brand' => $method->brand,
            'last_digits' => $method->last_digits,
            'expiry_month' => $method->expiry_month,
            'expiry_year' => $method->expiry_year,
            'last_update_email_sent_at' => $method->last_update_email_sent_at,
        ];
    }

    protected function shippingAddressPayload(object $contract): ?array
    {
        $address = $this->decodeJson($contract->shipping_address ?? null);

        return $address === [] ? null : $address;
    }

    protected function swapOptions(int $tenantId): array
    {
        $lineOptions = DB::table('subscription_contract_lines')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('shopify_product_variant_gid')
            ->where(function ($query): void {
                $query->where('product_title', 'like', '%16oz%')
                    ->orWhere('variant_title', 'like', '%16oz%')
                    ->orWhere('product_title', 'like', '%16 oz%')
                    ->orWhere('variant_title', 'like', '%16 oz%');
            })
            ->orderBy('product_title')
            ->limit(12)
            ->get()
            ->unique('shopify_product_variant_gid')
            ->map(fn (object $row): array => [
                'id' => (string) $row->shopify_product_variant_gid,
                'title' => trim((string) (($row->product_title ?: '16oz Candle').' '.($row->variant_title ? ' / '.$row->variant_title : ''))),
                'body' => 'Locally recorded 16oz variant. Shopify availability needs verification.',
                'product_variant_gid' => (string) $row->shopify_product_variant_gid,
                'source' => 'active_16oz',
            ])
            ->values()
            ->all();

        $previousOptions = collect($this->previousChosenScents($tenantId))
            ->take(3)
            ->map(fn (array $scent): array => [
                'id' => Str::slug((string) $scent['title']).'-previous',
                'title' => (string) $scent['title'],
                'body' => (string) ($scent['body'] ?? 'Previously chosen Candle Club scent.'),
                'product_variant_gid' => null,
                'source' => 'previous_chosen_scent',
            ])
            ->all();

        return array_values([...$lineOptions, ...$previousOptions]);
    }

    protected function previewSwapOptions(array $previousScents): array
    {
        $defaults = [
            ['title' => 'Coffeehouse', 'body' => 'Rich espresso, vanilla cream, and warm woods.'],
            ['title' => 'Forest Rain', 'body' => 'Rain-washed fir, moss, and soft amber.'],
        ];

        return collect($defaults)
            ->merge($previousScents)
            ->take(5)
            ->map(fn (array $scent, int $index): array => [
                'id' => Str::slug((string) $scent['title']).'-preview-'.$index,
                'title' => (string) $scent['title'],
                'body' => (string) ($scent['body'] ?? 'Preview Candle Club scent.'),
                'product_variant_gid' => $index < 2 ? 'gid://evergrove/PreviewVariant/'.Str::slug((string) $scent['title']) : null,
                'source' => $index < 2 ? 'active_16oz' : 'previous_chosen_scent',
            ])
            ->values()
            ->all();
    }

    protected function monthlyScentCards(int $tenantId, int $limit = 12): array
    {
        if (! Schema::hasTable('subscription_candle_club_monthly_scents')) {
            return collect($this->previousChosenScents($tenantId))
                ->map(fn (array $scent): array => [
                    'id' => Str::slug((string) $scent['title']),
                    'month_label' => $scent['published_at'] ?: 'Recent Candle Club',
                    'title' => (string) $scent['title'],
                    'body' => (string) ($scent['body'] ?? ''),
                    'photo_url' => null,
                    'average_rating' => null,
                    'review_count' => 0,
                    'shopify_product_gid' => null,
                    'product_status' => 'not_created',
                ])
                ->values()
                ->all();
        }

        return DB::table('subscription_candle_club_monthly_scents')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit($limit)
            ->get()
            ->map(function (object $row) use ($tenantId): array {
                $row = $this->ensureMonthlyScentPhoto($row);
                $feedback = DB::table('subscription_candle_club_scent_feedback')
                    ->where('tenant_id', $tenantId)
                    ->where('subscription_candle_club_monthly_scent_id', (int) $row->id)
                    ->whereIn('status', ['approved', 'exported'])
                    ->selectRaw('count(*) as review_count, avg(rating) as average_rating')
                    ->first();

                return [
                    'id' => (string) $row->id,
                    'month_label' => CarbonImmutable::create((int) $row->year, (int) $row->month, 1)->format('F Y'),
                    'title' => (string) $row->title,
                    'body' => (string) ($row->description ?? ''),
                    'photo_url' => $row->photo_url,
                    'average_rating' => $feedback?->average_rating ? round((float) $feedback->average_rating, 1) : null,
                    'review_count' => (int) ($feedback->review_count ?? 0),
                    'shopify_product_gid' => $row->shopify_product_gid,
                    'product_status' => (string) ($row->shopify_product_status ?? 'draft'),
                    'photo_source' => $row->photo_source,
                    'photo_author' => $row->photo_author,
                ];
            })
            ->all();
    }

    protected function ensureMonthlyScentPhoto(object $row): object
    {
        if (! empty($row->photo_url)) {
            return $row;
        }

        $query = $this->stockPhotoQueryForScent($row);
        $photo = app(FreeStockPhotoService::class)->firstMatch($query);
        $url = is_array($photo) ? ($photo['url'] ?? null) : null;
        if (! is_string($url) || trim($url) === '') {
            return $row;
        }

        DB::table('subscription_candle_club_monthly_scents')
            ->where('id', (int) $row->id)
            ->update([
                'photo_url' => $url,
                'photo_source' => $photo['source'] ?? null,
                'photo_author' => $photo['author'] ?? null,
                'photo_query' => $photo['query'] ?? $query,
                'photo_metadata' => json_encode($photo['metadata'] ?? [], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

        $row->photo_url = $url;
        $row->photo_source = $photo['source'] ?? null;
        $row->photo_author = $photo['author'] ?? null;

        return $row;
    }

    protected function stockPhotoQueryForScent(object $row): string
    {
        $parts = [
            (string) ($row->title ?? ''),
            (string) ($row->description ?? ''),
        ];

        $query = trim(implode(' ', array_filter($parts)));

        return $query !== '' ? Str::limit($query, 120, '') : 'candle fragrance';
    }

    protected function previewMonthlyScents(array $previousScents): array
    {
        return collect($previousScents)
            ->take(3)
            ->values()
            ->map(fn (array $scent, int $index): array => [
                'id' => 'preview-'.Str::slug((string) $scent['title']),
                'month_label' => CarbonImmutable::now()->subMonths($index + 1)->format('F Y'),
                'title' => (string) $scent['title'],
                'body' => (string) ($scent['body'] ?? ''),
                'photo_url' => null,
                'average_rating' => $index === 0 ? 4.8 : null,
                'review_count' => $index === 0 ? 12 : 0,
                'shopify_product_gid' => 'gid://evergrove/PreviewProduct/'.Str::slug((string) $scent['title']),
                'product_status' => 'draft',
            ])
            ->all();
    }

    protected function validatedCustomerActionPayload(string $action, array $payload, object $contract, array $settings): array
    {
        $clean = [
            'source' => (string) ($payload['source'] ?? 'mobile_app'),
        ];

        if ($action === 'swap_product') {
            $scent = $this->nullableString($payload['scent'] ?? null);
            $variant = $this->nullableString($payload['product_variant_gid'] ?? data_get($payload, 'metadata.product_variant_gid'));
            if (! $scent && ! $variant) {
                return ['ok' => false, 'status' => 'missing_swap_choice', 'message' => 'Choose a scent before sending a swap request.'];
            }

            $clean['scent'] = $scent;
            $clean['product_variant_gid'] = $variant;
            $clean['metadata'] = (array) ($payload['metadata'] ?? []);
        }

        if ($action === 'pause') {
            $commitment = $this->commitmentSummary($contract, $settings);
            if ((int) $commitment['pauses_remaining'] <= 0) {
                return ['ok' => false, 'status' => 'pause_allowance_used', 'message' => 'This Candle Club has no pauses left in the current commitment.'];
            }

            $duration = max(1, (int) ($payload['duration_months'] ?? data_get($payload, 'metadata.duration_months', 1)));
            $allowedDurations = array_map('intval', (array) ($settings['pause_duration_options'] ?? [1, 2]));
            if (! in_array($duration, $allowedDurations, true)) {
                return ['ok' => false, 'status' => 'invalid_pause_duration', 'message' => 'Choose one of the available pause durations.'];
            }

            $clean['duration_months'] = $duration;
        }

        if ($action === 'update_shipping_address') {
            $address = (array) ($payload['address'] ?? []);
            if ($this->nullableString($address['address1'] ?? null) === null || $this->nullableString($address['city'] ?? null) === null) {
                return ['ok' => false, 'status' => 'invalid_address', 'message' => 'Shipping address needs at least street and city.'];
            }

            $clean['address'] = $address;
        }

        if ($action === 'cancel') {
            $reason = $this->nullableString($payload['reason'] ?? null);
            if ($reason === null) {
                return ['ok' => false, 'status' => 'missing_cancel_feedback', 'message' => 'Tell us what would make Candle Club better before sending cancellation.'];
            }

            $clean['reason'] = $reason;
            $clean['commitment'] = $this->commitmentSummary($contract, $settings);
        }

        if ($action === 'send_payment_update_email') {
            $clean['shopify_payment_method_gid'] = (string) ($contract->shopify_payment_method_gid ?? '');
            $clean['security_note'] = 'Shopify sends the secure payment method update email; Evergrove stores no raw card data.';
        }

        if ($action === 'vote') {
            $tenantId = (int) ($contract->tenant_id ?? 0);
            $pollId = (int) ($payload['poll_id'] ?? data_get($payload, 'metadata.poll_id', 0));
            $optionId = (int) ($payload['option_id'] ?? data_get($payload, 'metadata.option_id', 0));

            if ($pollId <= 0 && str_contains((string) ($contract->shopify_subscription_contract_gid ?? ''), 'PreviewSubscriptionContract')) {
                $clean['poll_id'] = 0;
                $clean['option_id'] = $optionId;

                return ['ok' => true, 'payload' => $clean];
            }

            $activePoll = $this->activePoll($tenantId);
            if (! $activePoll || (int) $activePoll['id'] !== $pollId) {
                return ['ok' => false, 'status' => 'poll_not_open', 'message' => 'Voting is not open for that Candle Club poll.'];
            }

            $option = DB::table('subscription_poll_options')
                ->where('tenant_id', $tenantId)
                ->where('subscription_poll_id', $pollId)
                ->where('id', $optionId)
                ->first();

            if (! $option) {
                return ['ok' => false, 'status' => 'invalid_vote_option', 'message' => 'Choose one of the current Candle Club voting options.'];
            }

            $clean['poll_id'] = $pollId;
            $clean['option_id'] = $optionId;
            $clean['option_label'] = (string) $option->label;
        }

        return ['ok' => true, 'payload' => $clean];
    }

    protected function defaultCancellationPrompt(): string
    {
        return 'Candle Club gets extra Candle Cash, exclusive access to new scents, random surprises thrown in. How can we make Candle Club better in a way that would keep you?';
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
