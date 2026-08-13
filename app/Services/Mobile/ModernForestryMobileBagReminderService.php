<?php

namespace App\Services\Mobile;

use App\Mail\ModernForestryBagReminderMail;
use App\Models\MarketingEmailDelivery;
use App\Models\ModernForestryMobileBagSnapshot;
use App\Services\Shopify\ShopifyAppContentService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ModernForestryMobileBagReminderService
{
    public const CAMPAIGN_TYPE = 'modern_forestry_bag_reminder';

    public function __construct(
        protected ShopifyAppContentService $appContentService
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    public function sync(
        ModernForestryMobileCustomerSession $session,
        array $items,
        ?string $subtotalAmount = null,
        ?string $currencyCode = null
    ): array {
        $profile = $session->profile;
        $tenantId = (int) ($profile->tenant_id ?: 1);
        $settings = $this->settingsForTenant($tenantId);
        $email = $this->resolvedEmail($profile);
        $normalizedItems = $this->normalizedItems($items);
        $itemCount = array_sum(array_map(
            static fn (array $item): int => max(1, (int) ($item['quantity'] ?? 1)),
            $normalizedItems
        ));
        $subtotal = $this->normalizedAmount($subtotalAmount);
        $hash = sha1(json_encode([
            'items' => $normalizedItems,
            'subtotal' => $subtotal,
            'currency' => $currencyCode,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        $snapshot = ModernForestryMobileBagSnapshot::query()->firstOrNew([
            'tenant_id' => $tenantId,
            'marketing_profile_id' => (int) $profile->id,
        ]);

        $contentChanged = $snapshot->content_hash !== $hash;
        $enabled = (bool) ($settings['enabled'] ?? true);
        $wasCompleted = $snapshot->completed_at !== null;
        $hasActiveBag = $enabled && $itemCount > 0 && $email !== null;
        $nextReminderAt = $enabled && $itemCount > 0 && $email !== null
            ? now()->addHours((int) ($settings['frequency_hours'] ?? 24))
            : null;

        $snapshot->forceFill([
            'email' => $email,
            'currency_code' => $currencyCode ? strtoupper(trim($currencyCode)) : 'USD',
            'item_count' => $itemCount,
            'subtotal_amount' => $subtotal,
            'items' => $normalizedItems,
            'content_hash' => $hash,
            // A stale iOS bag can be re-synced after checkout. It must not revive
            // a completed bag unless its contents actually changed into a new bag.
            'is_active' => $hasActiveBag && (! $wasCompleted || $contentChanged),
            'last_synced_at' => now(),
            'next_reminder_at' => ! $hasActiveBag
                ? null
                : ($contentChanged ? $nextReminderAt : ($snapshot->next_reminder_at ?? $nextReminderAt)),
            'reminder_count' => $contentChanged ? 0 : (int) ($snapshot->reminder_count ?? 0),
            'cart_started_at' => $contentChanged && $hasActiveBag
                ? now()
                : $snapshot->cart_started_at,
            'completed_at' => $contentChanged ? null : $snapshot->completed_at,
            'meta' => [
                'source' => 'modern_forestry_ios',
                'settings' => $settings,
            ],
        ])->save();

        return [
            'ok' => true,
            'snapshot_id' => (int) $snapshot->id,
            'active' => (bool) $snapshot->is_active,
            'item_count' => (int) $snapshot->item_count,
            'next_reminder_at' => optional($snapshot->next_reminder_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function settingsForTenant(int $tenantId): array
    {
        $effective = (array) data_get($this->appContentService->forTenant($tenantId), 'effective', []);

        return [
            'enabled' => (bool) ($effective['mobile_bag_reminders_enabled'] ?? true),
            'frequency_hours' => max(6, min(168, (int) ($effective['mobile_bag_reminder_frequency_hours'] ?? 24))),
            'max_emails' => max(1, min(10, (int) ($effective['mobile_bag_reminder_max_emails'] ?? 3))),
            'subject' => (string) ($effective['mobile_bag_reminder_subject'] ?? 'Your Modern Forestry bag is still waiting'),
            'headline' => (string) ($effective['mobile_bag_reminder_headline'] ?? 'Your bag is still waiting'),
            'body' => (string) ($effective['mobile_bag_reminder_body'] ?? 'The candles you picked are still in your bag if you want to come back and finish checkout.'),
            'cta_label' => (string) ($effective['mobile_bag_reminder_cta_label'] ?? 'Finish checkout'),
            'cta_url' => (string) ($effective['mobile_bag_reminder_cta_url'] ?? 'https://theforestrystudio.com/cart'),
            'brand_name' => (string) ($effective['brand_name'] ?? 'Modern Forestry'),
        ];
    }

    /**
     * @return array{sent:int,skipped:int}
     */
    public function sendDueReminders(?int $tenantId = 1, int $limit = 100): array
    {
        $sent = 0;
        $skipped = 0;

        $query = ModernForestryMobileBagSnapshot::query()
            ->where('is_active', true)
            ->whereNotNull('email')
            ->where('item_count', '>', 0)
            ->whereNull('completed_at')
            ->whereNotNull('next_reminder_at')
            ->where('next_reminder_at', '<=', now())
            ->orderBy('next_reminder_at')
            ->limit(max(1, min(500, $limit)));

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        /** @var Collection<int,ModernForestryMobileBagSnapshot> $snapshots */
        $snapshots = $query->get();

        foreach ($snapshots as $snapshot) {
            $settings = $this->settingsForTenant((int) $snapshot->tenant_id);
            if (! (bool) ($settings['enabled'] ?? true)) {
                $snapshot->forceFill([
                    'is_active' => false,
                    'next_reminder_at' => null,
                ])->save();
                $skipped++;

                continue;
            }

            if ((int) $snapshot->reminder_count >= (int) ($settings['max_emails'] ?? 3)) {
                $snapshot->forceFill([
                    'is_active' => false,
                    'next_reminder_at' => null,
                ])->save();
                $skipped++;

                continue;
            }

            Mail::to((string) $snapshot->email)->send(new ModernForestryBagReminderMail($snapshot, $settings));

            MarketingEmailDelivery::query()->create([
                'marketing_profile_id' => (int) $snapshot->marketing_profile_id,
                'tenant_id' => (int) $snapshot->tenant_id,
                'store_key' => 'retail',
                'source_label' => 'modern_forestry_ios_bag',
                'message_subject' => $settings['subject'],
                'provider' => config('mail.default', 'mail'),
                'campaign_type' => self::CAMPAIGN_TYPE,
                'template_key' => 'modern_forestry_bag_reminder',
                'email' => (string) $snapshot->email,
                'status' => 'sent',
                'sent_at' => now(),
                'metadata' => [
                    'snapshot_id' => (int) $snapshot->id,
                    'item_count' => (int) $snapshot->item_count,
                ],
            ]);

            $snapshot->forceFill([
                'last_reminded_at' => now(),
                'reminder_count' => (int) $snapshot->reminder_count + 1,
                'next_reminder_at' => now()->addHours((int) ($settings['frequency_hours'] ?? 24)),
            ])->save();

            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * Mark pre-purchase mobile bags as complete after Shopify confirms payment.
     *
     * The customer app may post its last local bag again after checkout. Keeping
     * the completion marker means that stale post-checkout sync cannot reactivate
     * the reminder; a changed bag starts a new reminder lifecycle instead.
     *
     * @param  array<int,string|null>  $emails
     */
    public function completeBagsForConfirmedPurchase(
        int $tenantId,
        array $emails,
        CarbonInterface $purchasedAt,
        ?int $shopifyOrderId = null
    ): int {
        $normalizedEmails = collect($emails)
            ->map(fn (?string $email): string => strtolower(trim((string) $email)))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values();

        if ($tenantId <= 0 || $normalizedEmails->isEmpty()) {
            return 0;
        }

        $snapshots = ModernForestryMobileBagSnapshot::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNull('completed_at')
            ->whereIn(DB::raw('LOWER(email)'), $normalizedEmails->all())
            ->get();

        $completed = 0;
        foreach ($snapshots as $snapshot) {
            $cartStartedAt = $snapshot->cart_started_at ?? $snapshot->created_at;
            if ($cartStartedAt instanceof CarbonInterface && $cartStartedAt->isAfter($purchasedAt)) {
                continue;
            }

            $meta = (array) ($snapshot->meta ?? []);
            $meta['completion'] = array_filter([
                'source' => 'shopify_confirmed_order',
                'purchased_at' => $purchasedAt->toIso8601String(),
                'shopify_order_id' => $shopifyOrderId,
            ], static fn ($value): bool => $value !== null);

            $snapshot->forceFill([
                'is_active' => false,
                'next_reminder_at' => null,
                'completed_at' => $purchasedAt,
                'meta' => $meta,
            ])->save();

            $completed++;
        }

        return $completed;
    }

    protected function resolvedEmail(object $profile): ?string
    {
        $email = trim((string) ($profile->normalized_email ?? $profile->email ?? ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    protected function normalizedItems(array $items): array
    {
        return array_values(array_map(static function (array $item): array {
            return [
                'productHandle' => trim((string) ($item['productHandle'] ?? '')),
                'productTitle' => trim((string) ($item['productTitle'] ?? '')),
                'variantId' => trim((string) ($item['variantId'] ?? '')),
                'variantTitle' => trim((string) ($item['variantTitle'] ?? '')),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'price' => trim((string) ($item['price'] ?? '')),
            ];
        }, array_filter($items, 'is_array')));
    }

    protected function normalizedAmount(?string $value): ?float
    {
        $normalized = trim((string) $value);
        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }
}
