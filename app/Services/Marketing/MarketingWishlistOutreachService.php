<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfileWishlistItem;
use App\Models\MarketingWishlistOutreachQueue;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MarketingWishlistOutreachService
{
    public function __construct(
        protected MarketingWishlistDiscountService $discountService,
        protected TwilioSmsService $twilioSmsService
    ) {
    }

    public function prepare(MarketingProfileWishlistItem $item, array $payload, ?int $actorId = null): MarketingWishlistOutreachQueue
    {
        $channel = $this->normalizedChannel($payload['channel'] ?? 'sms');
        $offerType = $this->normalizedOfferType($payload['offer_type'] ?? 'amount_off');
        $offerValue = $this->positiveMoney($payload['offer_value'] ?? null);
        $messageBody = $this->nullableString($payload['message_body'] ?? null);

        if ($item->marketing_profile_id === null) {
            throw new InvalidArgumentException('Wishlist outreach requires a saved item attached to a customer profile.');
        }

        $existing = MarketingWishlistOutreachQueue::query()
            ->forTenantId((int) ($item->tenant_id ?? 0) ?: null)
            ->where('wishlist_item_id', $item->id)
            ->where('channel', $channel)
            ->whereIn('queue_status', [
                MarketingWishlistOutreachQueue::STATUS_QUEUED,
                MarketingWishlistOutreachQueue::STATUS_PREPARED,
                MarketingWishlistOutreachQueue::STATUS_FAILED,
            ])
            ->latest('id')
            ->first();

        $offerCode = $this->offerCode($item, $offerType, $existing?->offer_code);
        $queue = $existing ?: new MarketingWishlistOutreachQueue();
        $queue->fill([
            'tenant_id' => $item->tenant_id,
            'marketing_profile_id' => $item->marketing_profile_id,
            'wishlist_list_id' => $item->wishlist_list_id,
            'wishlist_item_id' => $item->id,
            'store_key' => $item->store_key,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'product_handle' => $item->product_handle,
            'product_title' => $item->product_title,
            'channel' => $channel,
            'queue_status' => MarketingWishlistOutreachQueue::STATUS_PREPARED,
            'offer_type' => $offerType,
            'offer_value' => $offerValue,
            'offer_code' => $offerCode,
            'provider' => $channel === 'sms' ? 'twilio' : 'manual',
            'message_body' => $messageBody ?: $this->defaultMessage($item, $channel, $offerType, $offerValue, $offerCode),
            'delivery_error' => null,
            'last_updated_by' => $actorId,
            'created_by' => $queue->exists ? ($queue->created_by ?? $actorId) : $actorId,
            'metadata' => [
                ...((array) $queue->metadata),
                'prepared_at' => now()->toIso8601String(),
                'offer_label' => $this->offerLabel($offerType, $offerValue),
            ],
        ]);
        $queue->save();

        return $queue->fresh(['profile', 'wishlistItem', 'wishlistList']) ?? $queue;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array{ok:bool,queue:MarketingWishlistOutreachQueue,error:?string}
     */
    public function send(MarketingWishlistOutreachQueue $queue, array $overrides = [], ?int $actorId = null): array
    {
        $channel = $this->normalizedChannel($overrides['channel'] ?? $queue->channel);
        $messageBody = $this->nullableString($overrides['message_body'] ?? null)
            ?: $this->nullableString($queue->message_body)
            ?: $this->defaultMessage($queue->wishlistItem ?: null, $channel, (string) $queue->offer_type, (float) $queue->offer_value, $queue->offer_code);

        $queue->forceFill([
            'channel' => $channel,
            'message_body' => $messageBody,
            'last_updated_by' => $actorId,
            'last_attempt_at' => now(),
        ])->save();

        if ($channel !== 'sms') {
            $queue->forceFill([
                'queue_status' => MarketingWishlistOutreachQueue::STATUS_FAILED,
                'delivery_error' => 'Only SMS wishlist outreach is implemented right now.',
            ])->save();

            return [
                'ok' => false,
                'queue' => $queue,
                'error' => 'Only SMS wishlist outreach is implemented right now.',
            ];
        }

        $profile = $queue->profile ?: $queue->profile()->first();
        $phone = $this->nullableString($profile?->phone);
        if ($phone === null) {
            $queue->forceFill([
                'queue_status' => MarketingWishlistOutreachQueue::STATUS_FAILED,
                'delivery_error' => 'Customer phone number is missing.',
            ])->save();

            return [
                'ok' => false,
                'queue' => $queue,
                'error' => 'Customer phone number is missing.',
            ];
        }

        try {
            $discount = $this->discountService->ensureDiscountForQueue($queue);
        } catch (\Throwable $exception) {
            $queue->forceFill([
                'queue_status' => MarketingWishlistOutreachQueue::STATUS_FAILED,
                'delivery_error' => $exception->getMessage(),
                'metadata' => [
                    ...((array) $queue->metadata),
                    'discount_sync_status' => 'failed',
                    'discount_sync_error' => $exception->getMessage(),
                ],
            ])->save();

            return [
                'ok' => false,
                'queue' => $queue,
                'error' => $exception->getMessage(),
            ];
        }

        $result = $this->twilioSmsService->sendSms($phone, $messageBody, [
            'status_callback_url' => url('/marketing/twilio/status'),
        ]);

        $success = (bool) ($result['success'] ?? false);
        $queue->forceFill([
            'queue_status' => $success ? MarketingWishlistOutreachQueue::STATUS_SENT : MarketingWishlistOutreachQueue::STATUS_FAILED,
            'provider' => 'twilio',
            'provider_message_id' => $result['provider_message_id'] ?? null,
            'delivery_error' => $success ? null : ($result['error_message'] ?? 'SMS send failed.'),
            'sent_at' => $success ? now() : null,
            'last_updated_by' => $actorId,
            'metadata' => [
                ...((array) $queue->metadata),
                'discount_sync_status' => 'ready',
                'discount_sync_payload' => $discount,
                'sms_result' => $result,
            ],
        ])->save();

        return [
            'ok' => $success,
            'queue' => $queue,
            'error' => $success ? null : ($result['error_message'] ?? 'SMS send failed.'),
        ];
    }

    protected function offerCode(MarketingProfileWishlistItem $item, string $offerType, ?string $existingCode = null): string
    {
        $existingCode = $this->nullableString($existingCode);
        if ($existingCode !== null) {
            return $existingCode;
        }

        $prefix = $offerType === 'percent_off' ? 'WLPCT' : 'WLOFF';

        return $prefix . '-' . $item->id . '-' . Str::upper(Str::random(6));
    }

    protected function defaultMessage(
        ?MarketingProfileWishlistItem $item,
        string $channel,
        string $offerType,
        float $offerValue,
        ?string $offerCode
    ): string {
        $productTitle = trim((string) ($item?->product_title ?? 'something you saved'));
        $productUrl = trim((string) ($item?->product_url ?? ''));
        $offerLabel = $this->offerLabel($offerType, $offerValue);
        $code = trim((string) ($offerCode ?? ''));

        return trim(implode(' ', array_filter([
            'Modern Forestry noticed you saved ' . $productTitle . '.',
            'Here is ' . $offerLabel . ' just for you.',
            $code !== '' ? 'Use code ' . $code . ' at checkout.' : null,
            $productUrl !== '' ? $productUrl : null,
        ])));
    }

    protected function offerLabel(string $offerType, float $offerValue): string
    {
        return $offerType === 'percent_off'
            ? rtrim(rtrim(number_format($offerValue, 2, '.', ''), '0'), '.') . '% off'
            : '$' . number_format($offerValue, 2) . ' off';
    }

    protected function normalizedChannel(mixed $value): string
    {
        $channel = strtolower(trim((string) $value));

        return in_array($channel, ['sms', 'email'], true) ? $channel : 'sms';
    }

    protected function normalizedOfferType(mixed $value): string
    {
        $offerType = strtolower(trim((string) $value));

        return in_array($offerType, ['amount_off', 'percent_off'], true) ? $offerType : 'amount_off';
    }

    protected function positiveMoney(mixed $value): float
    {
        $numeric = (float) $value;
        if ($numeric <= 0) {
            throw new InvalidArgumentException('Enter an offer value greater than zero.');
        }

        return round($numeric, 2);
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
