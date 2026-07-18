<?php

namespace App\Services\Billing;

use App\Models\Agreement;
use App\Models\AgreementAcceptance;
use App\Models\SubscriptionAuthorization;
use App\Models\TenantBillingOrder;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use InvalidArgumentException;

class AgreementBillingOrderService
{
    public function __construct(protected LandlordOperatorActionAuditService $audit) {}

    public function authorize(Agreement $agreement, AgreementAcceptance $acceptance, SubscriptionAuthorization $authorization): TenantBillingOrder
    {
        $agreement->loadMissing('currentVersion');
        if ((int) $agreement->tenant_id !== (int) $acceptance->tenant_id
            || (int) $agreement->tenant_id !== (int) $authorization->tenant_id
            || (int) $agreement->current_version_id !== (int) $acceptance->agreement_version_id
            || (int) $agreement->current_version_id !== (int) $authorization->agreement_version_id) {
            throw new InvalidArgumentException('Billing authorization must match the accepted tenant and immutable agreement version.');
        }

        $type = match ((string) $agreement->agreement_type) {
            'supplemental_work' => 'supplemental_work',
            'milestone' => 'milestone',
            default => 'initial',
        };
        $lines = $this->collectibleLines($agreement);
        $subtotal = collect($lines)
            ->filter(fn (array $line): bool => in_array($line['payment_timing'], ['due_on_acceptance', 'recurring_current'], true))
            ->sum(fn (array $line): int => (int) $line['amount_cents'] * (int) $line['quantity']);

        $order = TenantBillingOrder::query()->firstOrCreate(
            ['agreement_version_id' => (int) $agreement->current_version_id, 'order_type' => $type],
            [
                'tenant_id' => (int) $agreement->tenant_id,
                'agreement_id' => (int) $agreement->id,
                'agreement_acceptance_id' => (int) $acceptance->id,
                'subscription_authorization_id' => (int) $authorization->id,
                'status' => 'authorized',
                'provider' => 'stripe',
                'currency' => strtoupper((string) $authorization->currency),
                'line_items' => $lines,
                'authorized_subtotal_cents' => $subtotal,
                'provider_total_cents' => $subtotal,
                'authorized_at' => now(),
                'metadata' => [
                    'content_hash' => (string) $agreement->currentVersion->content_hash,
                    'validation_only' => $agreement->agreement_type === Agreement::TYPE_SANDBOX_VALIDATION,
                ],
            ]
        );

        $this->audit->record((int) $agreement->tenant_id, null, 'tenant_billing.order.authorize', targetType: 'tenant_billing_order', targetId: $order->id, afterState: [
            'agreement_id' => (int) $agreement->id,
            'agreement_version_id' => (int) $agreement->current_version_id,
            'order_type' => $type,
            'status' => (string) $order->status,
            'authorized_subtotal_cents' => (int) $order->authorized_subtotal_cents,
        ]);

        return $order;
    }

    /** @return array<int,array<string,mixed>> */
    protected function collectibleLines(Agreement $agreement): array
    {
        $version = $agreement->currentVersion;
        $pricing = (array) $version->pricing_payload;
        $subscription = (array) $version->subscription_payload;
        $cards = collect((array) ($pricing['cards'] ?? []))->keyBy('key');
        $lines = [];

        foreach ((array) ($subscription['authorized_line_items'] ?? []) as $authorized) {
            $key = (string) ($authorized['key'] ?? '');
            $card = (array) ($cards[$key] ?? []);
            if ($key === '' || ($card['collectible_by_everbranch'] ?? true) !== true) {
                continue;
            }
            $frequency = (string) ($authorized['frequency'] ?? $card['frequency'] ?? 'one_time');
            $timing = $frequency === 'one_time' ? 'due_on_acceptance' : (($authorized['starts_cycle'] ?? null) ? 'recurring_future' : 'recurring_current');
            $lines[] = $this->line($key, $card, (int) ($authorized['amount_cents'] ?? 0), $frequency, $timing, [
                'cycles' => isset($authorized['cycles']) ? (int) $authorized['cycles'] : null,
                'starts_cycle' => isset($authorized['starts_cycle']) ? (int) $authorized['starts_cycle'] : null,
            ]);
        }

        $due = (int) data_get($pricing, 'implementation_payment_schedule.due_on_acceptance_cents', 0);
        $implementation = (array) ($cards['shopify_implementation'] ?? []);
        if ($due > 0 && $implementation !== []) {
            $lines[] = $this->line('shopify_implementation_due_on_acceptance', $implementation, $due, 'one_time', 'due_on_acceptance');
        }

        return array_values(array_filter($lines, fn (array $line): bool => $line['amount_cents'] > 0));
    }

    /** @param array<string,mixed> $card @param array<string,mixed> $extra */
    protected function line(string $key, array $card, int $amount, string $frequency, string $timing, array $extra = []): array
    {
        return array_filter([
            'key' => $key,
            'label' => (string) ($card['label'] ?? str($key)->headline()),
            'description' => (string) ($card['detail'] ?? ''),
            'cost_category' => (string) ($card['cost_category'] ?? 'everbranch_service'),
            'owner' => (string) ($card['owner'] ?? 'Everbranch'),
            'amount_cents' => max(0, $amount),
            'quantity' => 1,
            'frequency' => $frequency,
            'payment_timing' => $timing,
            'tax_code' => $card['tax_code'] ?? null,
            ...$extra,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
