<?php

namespace App\Services\ManagedWebsite;

use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteDiscount;
use App\Models\WebsiteOrder;
use App\Models\WebsiteProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WebsiteDiscountService
{
    public function save(TenantSite $site, array $input, User $actor): WebsiteDiscount
    {
        app(WebsiteQuoteOrderService::class)->assertEditor($site, $actor);
        $input['code'] = strtoupper(trim((string) ($input['code'] ?? '')));
        $data = Validator::make($input, [
            'code' => 'required|string|max:64|regex:/^[A-Z0-9_-]+$/', 'type' => 'required|in:fixed,percent',
            'value' => 'required|integer|min:1|max:100000000', 'minimum_subtotal_cents' => 'sometimes|integer|min:0|max:100000000',
            'maximum_issues' => 'nullable|integer|min:1|max:1000000', 'enabled' => 'required|boolean',
            'eligible_variant_ids' => 'sometimes|array|max:100', 'eligible_variant_ids.*' => 'integer|distinct',
            'starts_at' => 'nullable|date', 'expires_at' => 'nullable|date',
        ])->validate();
        $data['eligible_variant_ids'] = array_map('intval', $data['eligible_variant_ids'] ?? []);
        abort_if(! empty($data['starts_at']) && ! empty($data['expires_at']) && \Carbon\CarbonImmutable::parse($data['expires_at'])->lte(\Carbon\CarbonImmutable::parse($data['starts_at'])), 422, 'The discount must expire after its start.');
        abort_if($data['type'] === 'percent' && $data['value'] > 100, 422, 'Percentage discounts cannot exceed 100%.');
        foreach ($data['eligible_variant_ids'] ?? [] as $id) {
            abort_unless(WebsiteProductVariant::query()->forTenantId($site->tenant_id)->whereHas('product', fn ($q) => $q->where('tenant_site_id', $site->id))->whereKey($id)->exists(), 422, 'Discount items must belong to this website.');
        }

        return DB::transaction(function () use ($site, $data) {
            TenantSite::query()->forTenantId($site->tenant_id)->whereKey($site->id)->lockForUpdate()->firstOrFail();
            $discount = WebsiteDiscount::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->where('code', $data['code'])->lockForUpdate()->first();
            abort_if($discount && $discount->issued_count > 0, 409, 'This code has been issued. Disable it or create a new code to change its terms.');
            $discount ??= new WebsiteDiscount(['tenant_id' => $site->tenant_id, 'tenant_site_id' => $site->id]);
            $discount->fill($data)->save();

            return $discount;
        });
    }

    public function disable(TenantSite $site, int $id, User $actor): void
    {
        app(WebsiteQuoteOrderService::class)->assertEditor($site, $actor);
        WebsiteDiscount::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->findOrFail($id)->forceFill(['enabled' => false])->save();
    }

    /** Lines must already be server-priced. This service never trusts browser totals. */
    public function calculate(TenantSite $site, array $lines, ?string $code = null, ?array $manual = null): array
    {
        abort_if(filled($code) && ! empty($manual), 422, 'Choose one discount; discounts cannot be combined.');
        $subtotal = (int) array_sum(array_column($lines, 'line_total_cents'));
        $discount = null;
        if (filled($code)) {
            $discount = WebsiteDiscount::query()->forTenantId($site->tenant_id)->where('tenant_site_id', $site->id)->where('code', strtoupper(trim($code)))->firstOrFail();
            $this->assertAvailable($discount, $subtotal);
            $terms = ['id' => $discount->id, 'code' => $discount->code, 'type' => $discount->type, 'value' => $discount->value, 'eligible_variant_ids' => $discount->eligible_variant_ids ?? []];
        } elseif (! empty($manual)) {
            $terms = Validator::make($manual, ['type' => 'required|in:fixed,percent', 'value' => 'required|integer|min:1|max:100000000', 'reason' => 'required|string|max:1000'])->validate();
            $terms['eligible_variant_ids'] = [];
        } else {
            return ['amount_cents' => 0, 'snapshot' => null];
        }
        abort_if($terms['type'] === 'percent' && $terms['value'] > 100, 422, 'Percentage discounts cannot exceed 100%.');
        $eligible = (int) collect($lines)->filter(fn ($line) => empty($terms['eligible_variant_ids']) || in_array((int) ($line['website_product_variant_id'] ?? 0), $terms['eligible_variant_ids'], true))->sum('line_total_cents');
        abort_unless($eligible > 0, 422, 'No items qualify for this discount.');
        $amount = $terms['type'] === 'percent' ? intdiv($eligible * (int) $terms['value'], 100) : min($eligible, (int) $terms['value']);

        return ['amount_cents' => $amount, 'snapshot' => [...$terms, 'eligible_subtotal_cents' => $eligible, 'amount_cents' => $amount]];
    }

    /** Runs under the order transaction; one issued order reserves one code use. */
    public function reserveIssue(WebsiteOrder $order): void
    {
        $snapshot = data_get($order->service_request, 'invoice.discount');
        if (empty($snapshot['id'])) {
            return;
        }
        $discount = WebsiteDiscount::query()->forTenantId($order->tenant_id)->where('tenant_site_id', $order->tenant_site_id)->lockForUpdate()->findOrFail($snapshot['id']);
        $this->assertAvailable($discount, $order->subtotal_cents);
        abort_unless($discount->type === $snapshot['type'] && $discount->value === $snapshot['value'] && ($discount->eligible_variant_ids ?? []) === ($snapshot['eligible_variant_ids'] ?? []), 409, 'Discount terms changed. Revise the draft before issuing it.');
        $discount->increment('issued_count');
    }

    private function assertAvailable(WebsiteDiscount $discount, int $subtotal): void
    {
        abort_unless($discount->enabled && (! $discount->starts_at || $discount->starts_at->lte(now())) && (! $discount->expires_at || $discount->expires_at->gt(now())), 422, 'This discount code is inactive or expired.');
        abort_unless($subtotal >= $discount->minimum_subtotal_cents, 422, 'This order does not meet the discount minimum.');
        abort_if($discount->maximum_issues !== null && $discount->issued_count >= $discount->maximum_issues, 422, 'This discount code has reached its issue limit.');
    }
}
