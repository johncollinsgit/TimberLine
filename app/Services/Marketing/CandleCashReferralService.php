<?php

namespace App\Services\Marketing;

use App\Models\CandleCashReferral;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\MarketingSetting;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Support\Str;

class CandleCashReferralService
{
    public function __construct(
        protected CandleCashService $candleCashService,
        protected CandleCashTaskService $taskService,
        protected MarketingIdentityNormalizer $normalizer,
        protected MarketingStorefrontEventLogger $eventLogger
    ) {
    }

    public function config(): array
    {
        return (array) optional(MarketingSetting::query()->where('key', 'candle_cash_referral_config')->first())->value;
    }

    public function isEnabled(): bool
    {
        return (bool) data_get($this->config(), 'enabled', true);
    }

    public function referralCodeForProfile(MarketingProfile $profile): string
    {
        return 'FOREST-' . Str::upper(base_convert((string) ($profile->id + 100000), 10, 36));
    }

    public function profileFromCode(string $code): ?MarketingProfile
    {
        $normalized = Str::upper(trim($code));
        $normalized = preg_replace('/[^A-Z0-9-]/', '', $normalized) ?: '';
        if (! str_starts_with($normalized, 'FOREST-')) {
            return null;
        }

        $encoded = substr($normalized, 7);
        if ($encoded === '') {
            return null;
        }

        $decoded = base_convert($encoded, 36, 10);
        if (! is_string($decoded) || $decoded === '') {
            return null;
        }

        $profileId = ((int) $decoded) - 100000;
        if ($profileId <= 0) {
            return null;
        }

        return MarketingProfile::query()->find($profileId);
    }

    public function referralLinkForProfile(MarketingProfile $profile, string $path = '/pages/rewards'): string
    {
        $base = rtrim((string) config('marketing.candle_cash.storefront_base_url', 'https://theforestrystudio.com'), '/');

        return $base . $path . '?ref=' . rawurlencode($this->referralCodeForProfile($profile));
    }

    public function captureReferral(?MarketingProfile $referredProfile, string $code, array $identity = [], array $context = []): ?CandleCashReferral
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $referrer = $this->profileFromCode($code);
        if (! $referrer) {
            return null;
        }

        if ($referredProfile && $referrer->id === $referredProfile->id) {
            return null;
        }

        $identityKey = $this->identityKey($referredProfile, $identity);
        if ($identityKey === null) {
            return null;
        }

        $referral = CandleCashReferral::query()->firstOrNew([
            'referral_code' => $this->referralCodeForProfile($referrer),
            'referred_identity_key' => $identityKey,
        ]);

        $referral->forceFill([
            'referrer_marketing_profile_id' => $referrer->id,
            'referred_marketing_profile_id' => $referredProfile?->id ?: $referral->referred_marketing_profile_id,
            'referred_email' => $this->cleanString($identity['email'] ?? $referredProfile?->email),
            'normalized_email' => $this->normalizer->normalizeEmail($identity['email'] ?? $referredProfile?->email),
            'referred_phone' => $this->cleanString($identity['phone'] ?? $referredProfile?->phone),
            'normalized_phone' => $this->normalizer->normalizePhone($identity['phone'] ?? $referredProfile?->phone),
            'status' => 'captured',
            'first_seen_at' => $referral->first_seen_at ?: now(),
            'metadata' => is_array($context['metadata'] ?? null) ? $context['metadata'] : $referral->metadata,
        ])->save();

        return $referral->fresh();
    }

    public function qualifyFromOrder(Order $order, ?MarketingProfile $referredProfile, array $context = []): ?CandleCashReferral
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $code = Str::upper(trim((string) ($context['referral_code'] ?? '')));
        if ($code === '') {
            return null;
        }

        $referrer = $this->profileFromCode($code);
        if (! $referrer) {
            $this->eventLogger->log('candle_cash_referral_unmatched', [
                'status' => 'error',
                'issue_type' => 'referral_code_not_found',
                'source_surface' => 'ingestion',
                'endpoint' => 'shopify_order_ingest',
                'source_type' => 'referral_order',
                'source_id' => (string) $order->id,
                'meta' => ['referral_code' => $code],
                'resolution_status' => 'open',
            ]);

            return null;
        }

        if ($referredProfile && $referrer->id === $referredProfile->id) {
            return null;
        }

        $identity = [
            'email' => $referredProfile?->email ?: $order->email ?: $order->customer_email,
            'phone' => $referredProfile?->phone ?: $order->phone ?: $order->customer_phone,
            'shopify_customer_id' => $order->shopify_customer_id,
        ];
        $identityKey = $this->identityKey($referredProfile, $identity);
        if ($identityKey === null) {
            return null;
        }

        $referral = CandleCashReferral::query()->firstOrCreate(
            [
                'referral_code' => $this->referralCodeForProfile($referrer),
                'referred_identity_key' => $identityKey,
            ],
            [
                'referrer_marketing_profile_id' => $referrer->id,
                'referred_marketing_profile_id' => $referredProfile?->id,
                'referred_email' => $this->cleanString($identity['email'] ?? null),
                'normalized_email' => $this->normalizer->normalizeEmail($identity['email'] ?? null),
                'referred_phone' => $this->cleanString($identity['phone'] ?? null),
                'normalized_phone' => $this->normalizer->normalizePhone($identity['phone'] ?? null),
                'status' => 'captured',
                'first_seen_at' => now(),
            ]
        );

        $referral->forceFill([
            'referred_marketing_profile_id' => $referredProfile?->id ?: $referral->referred_marketing_profile_id,
            'qualifying_order_source' => 'shopify_order',
            'qualifying_order_id' => (string) $order->id,
            'qualifying_order_number' => (string) ($order->order_number ?: $order->shopify_name ?: ''),
            'qualifying_order_total' => $order->current_total_price ?? $order->total_price ?? $referral->qualifying_order_total,
        ])->save();

        if (! $this->qualifiesOnCurrentOrder($order, $referredProfile)) {
            return $referral->fresh();
        }

        $referrerResult = $this->taskService->awardSystemTask($referrer, 'refer-a-friend', [
            'source_type' => 'referral_conversion',
            'source_id' => 'referrer:' . $referral->id . ':order:' . $order->id,
            'source_event_key' => 'referral:referrer:' . $referral->id . ':order:' . $order->id,
            'metadata' => [
                'referral_code' => $referral->referral_code,
                'qualifying_order_id' => $order->id,
            ],
        ]);

        $referredResult = null;
        if ($referredProfile) {
            $referredResult = $this->taskService->awardSystemTask($referredProfile, 'referred-friend-bonus', [
                'source_type' => 'referral_conversion',
                'source_id' => 'referred:' . $referral->id . ':order:' . $order->id,
                'source_event_key' => 'referral:referred:' . $referral->id . ':order:' . $order->id,
                'metadata' => [
                    'referral_code' => $referral->referral_code,
                    'qualifying_order_id' => $order->id,
                ],
            ]);
        }

        $referral->forceFill([
            'status' => 'qualified',
            'qualified_at' => $referral->qualified_at ?: now(),
            'rewarded_at' => now(),
            'referrer_completion_id' => $referrerResult['completion']?->id,
            'referred_completion_id' => $referredResult['completion']?->id,
            'referrer_transaction_id' => $referrerResult['completion']?->candle_cash_transaction_id,
            'referred_transaction_id' => $referredResult['completion']?->candle_cash_transaction_id,
            'referrer_reward_status' => $referrerResult['ok'] ? 'awarded' : $referral->referrer_reward_status,
            'referred_reward_status' => $referredResult && ($referredResult['ok'] ?? false) ? 'awarded' : $referral->referred_reward_status,
        ])->save();

        return $referral->fresh();
    }

    protected function qualifiesOnCurrentOrder(Order $order, ?MarketingProfile $profile): bool
    {
        $config = $this->config();
        $minTotal = data_get($config, 'qualifying_min_order_total');
        $orderTotal = (float) ($order->current_total_price ?? $order->total_price ?? 0);
        if ($minTotal !== null && $minTotal !== '' && $orderTotal < (float) $minTotal) {
            return false;
        }

        if (! $profile) {
            return false;
        }

        $linkedOrderCount = $profile->links()->where('source_type', 'order')->count();
        if ($linkedOrderCount > 0) {
            return $linkedOrderCount === 1;
        }

        if ($order->shopify_customer_id) {
            $count = Order::query()
                ->where('shopify_store_key', $order->shopify_store_key)
                ->where('shopify_customer_id', $order->shopify_customer_id)
                ->count();

            return $count === 1;
        }

        if ($profile->normalized_email) {
            $count = Order::query()->where('email', $profile->email)->count();

            return $count === 1;
        }

        return false;
    }

    protected function identityKey(?MarketingProfile $profile, array $identity = []): ?string
    {
        if ($profile?->id) {
            return 'profile:' . $profile->id;
        }

        $shopifyCustomerId = trim((string) ($identity['shopify_customer_id'] ?? ''));
        if ($shopifyCustomerId !== '') {
            return 'shopify:' . $shopifyCustomerId;
        }

        $normalizedEmail = $this->normalizer->normalizeEmail($identity['email'] ?? null);
        if ($normalizedEmail) {
            return 'email:' . $normalizedEmail;
        }

        $normalizedPhone = $this->normalizer->normalizePhone($identity['phone'] ?? null);
        if ($normalizedPhone) {
            return 'phone:' . $normalizedPhone;
        }

        return null;
    }

    protected function cleanString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
