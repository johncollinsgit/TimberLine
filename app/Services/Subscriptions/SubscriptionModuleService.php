<?php

namespace App\Services\Subscriptions;

use App\Models\MarketingProfile;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SubscriptionModuleService
{
    public const MODULE_KEY = 'subscriptions';
    public const CANDLE_CLUB_TYPE = 'candle_club_scent';

    /**
     * @return array<string,mixed>
     */
    public function adminPayload(int $tenantId): array
    {
        $settings = $this->moduleSettings($tenantId);
        $candleClub = $this->candleClubSettings($tenantId);
        $contracts = DB::table('subscription_contracts')->where('tenant_id', $tenantId);
        $attempts = DB::table('subscription_billing_attempts')->where('tenant_id', $tenantId);
        $latestBatch = DB::table('subscription_migration_batches')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->first();

        $poll = $this->activePoll($tenantId);

        return [
            'module' => $settings,
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
                ? DB::table('subscription_votes')
                    ->where('tenant_id', $tenantId)
                    ->where('subscription_poll_id', (int) $poll['id'])
                    ->count()
                : 0,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function saveCandleClubSettings(int $tenantId, array $payload): array
    {
        $data = [
            'commitment_months' => max(1, (int) ($payload['commitment_months'] ?? 6)),
            'allowed_pauses_per_commitment' => max(0, (int) ($payload['allowed_pauses_per_commitment'] ?? 1)),
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

        DB::table('subscription_candle_club_settings')->updateOrInsert(
            ['tenant_id' => $tenantId],
            ['created_at' => now(), ...$data]
        );

        return $this->candleClubSettings($tenantId);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    public function createMigrationDryRun(int $tenantId, ?int $actorId, array $rows = []): array
    {
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
                'mapped_payload' => json_encode($normalized, JSON_THROW_ON_ERROR),
                'errors' => $errors === [] ? null : json_encode($errors, JSON_THROW_ON_ERROR),
                'metadata' => json_encode(['row_index' => $index], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $finalStatus = $summary['error_rows'] > 0 ? 'needs_review' : 'ready_for_cutover';
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

        if (! in_array((string) $batch->status, ['ready_for_cutover', 'approved'], true)) {
            return ['ok' => false, 'status' => 'batch_not_ready', 'message' => 'Migration batch must be ready before cutover.'];
        }

        DB::table('subscription_migration_batches')
            ->where('id', $batchId)
            ->update([
                'status' => 'approved',
                'approved_by_user_id' => $actorId,
                'approved_at' => now(),
                'recharge_billing_paused_confirmed' => true,
                'updated_at' => now(),
            ]);

        DB::table('subscription_module_settings')->updateOrInsert(
            ['tenant_id' => $tenantId, 'module_key' => self::MODULE_KEY],
            [
                'status' => 'cutover_approved',
                'billing_scheduler_enabled' => false,
                'metadata' => json_encode(['approved_batch_id' => $batchId], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return ['ok' => true, 'status' => 'approved', 'message' => 'Cutover approved. Billing scheduler remains off until explicitly enabled.'];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function recordAdminAction(int $tenantId, int $contractId, string $action, ?int $actorId, array $payload = []): array
    {
        $contract = DB::table('subscription_contracts')
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

        DB::table('subscription_lifecycle_events')->insert([
            'tenant_id' => $tenantId,
            'subscription_contract_id' => (int) $contract->id,
            'actor_user_id' => $actorId,
            'event_type' => $normalizedAction,
            'source' => 'shopify_embedded_admin',
            'status' => 'intent_recorded',
            'before_payload' => json_encode($this->contractSummary($contract), JSON_THROW_ON_ERROR),
            'after_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'metadata' => json_encode([
                'shopify_source_of_truth' => true,
                'live_shopify_mutation_deferred_until_cutover' => true,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['ok' => true, 'status' => 'intent_recorded', 'message' => 'Action recorded for Shopify-sourced processing.'];
    }

    /**
     * @return array<string,mixed>
     */
    public function customerCandleClubPayload(MarketingProfile $profile): array
    {
        $tenantId = (int) $profile->tenant_id;
        $contract = $this->activeCandleClubContractForProfile($profile);
        $poll = $this->activePoll($tenantId);

        if (! $contract && $this->isCandleClubPreviewProfile($profile)) {
            return $this->previewCandleClubPayload($profile, $poll);
        }

        return [
            'eligible' => $contract !== null,
            'status' => $contract ? 'active' : 'not_active',
            'message' => $contract ? null : 'Candle Club voting is available for active Candle Club members.',
            'contract' => $contract ? $this->contractSummary($contract) : null,
            'active_poll' => $poll ? $this->pollPayload((int) $poll['id'], $contract) : null,
            'vote_history' => $contract ? $this->voteHistory($tenantId, (string) $contract->shopify_subscription_contract_gid) : [],
            'previous_chosen_scents' => $this->previousChosenScents($tenantId),
            'actions' => [
                'can_vote' => $contract !== null && $poll !== null,
                'can_pause' => $contract !== null,
                'can_cancel' => $contract !== null,
                'can_update_address' => $contract !== null,
                'can_update_card' => $contract !== null,
                'can_swap_to_active_16oz_scent' => $contract !== null,
            ],
            'cancel_prompt' => $this->candleClubSettings($tenantId)['cancellation_prompt'],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $poll
     * @return array<string,mixed>
     */
    protected function previewCandleClubPayload(MarketingProfile $profile, ?array $poll): array
    {
        $tenantId = (int) $profile->tenant_id;
        $contract = (object) [
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

        $previousScents = $this->previousChosenScents($tenantId);
        if ($previousScents === []) {
            $previousScents = [
                [
                    'title' => 'Coffeehouse',
                    'body' => 'Rich espresso, vanilla cream, and warm woods.',
                    'published_at' => CarbonImmutable::now()->subMonth()->toDateString(),
                ],
                [
                    'title' => 'Walking on Sunshine',
                    'body' => 'Bright citrus, agave, and clean summer air.',
                    'published_at' => CarbonImmutable::now()->subMonths(2)->toDateString(),
                ],
                [
                    'title' => 'Cabin Morning',
                    'body' => 'Cedar, soft spice, and a quiet first cup.',
                    'published_at' => CarbonImmutable::now()->subMonths(3)->toDateString(),
                ],
            ];
        }

        return [
            'eligible' => true,
            'status' => 'active',
            'message' => 'Preview mode is unlocked for this account so the Candle Club menus can be tested before cutover.',
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
            'cancel_prompt' => $this->candleClubSettings($tenantId)['cancellation_prompt'],
            'preview' => true,
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
        $poll = DB::table('subscription_polls')
            ->where('tenant_id', $tenantId)
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
            'status' => 'code_sent',
            'verification_token_id' => $tokenId,
            'delivery_channel' => $normalized['type'] === 'email' ? 'email' : 'sms',
            'message' => 'We sent a one-time code.',
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

        $contract = DB::table('subscription_contracts')
            ->where('tenant_id', $tenantId)
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
        if (! $poll) {
            return null;
        }

        $options = DB::table('subscription_poll_options')
            ->where('subscription_poll_id', $pollId)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (object $option): array {
                $votes = DB::table('subscription_votes')
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

    public function recordShopifyWebhook(int $tenantId, string $topic, array $payload): void
    {
        DB::table('subscription_lifecycle_events')->insert([
            'tenant_id' => $tenantId,
            'event_type' => Str::of($topic)->replace('/', '_')->toString(),
            'source' => 'shopify_webhook',
            'status' => 'received',
            'after_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'metadata' => json_encode(['topic' => $topic], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    protected function moduleSettings(int $tenantId): array
    {
        $row = DB::table('subscription_module_settings')->where('tenant_id', $tenantId)->first();
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
            $row = DB::table('subscription_module_settings')->where('tenant_id', $tenantId)->first();
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
        $poll = DB::table('subscription_polls')
            ->where('tenant_id', $tenantId)
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
        return DB::table('subscription_contracts')
            ->where('tenant_id', (int) $profile->tenant_id)
            ->where('marketing_profile_id', (int) $profile->id)
            ->where('status', 'active')
            ->where('is_candle_club', true)
            ->orderByDesc('id')
            ->first();
    }

    protected function isCandleClubPreviewProfile(MarketingProfile $profile): bool
    {
        if ((int) $profile->tenant_id !== 1) {
            return false;
        }

        $email = Str::lower(trim((string) ($profile->normalized_email ?: $profile->email)));

        return in_array($email, ['johncollinsemail@gmail.com', 'johncollinesmail@gmail.com'], true);
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

        return DB::table('subscription_contracts')
            ->where('tenant_id', $tenantId)
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
        return DB::table('subscription_votes')
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
