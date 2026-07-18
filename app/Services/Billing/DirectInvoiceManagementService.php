<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\TenantDirectInvoice;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DirectInvoiceManagementService
{
    public function __construct(protected LandlordOperatorActionAuditService $audit) {}

    /** @param array<string,mixed> $input */
    public function createDraft(Tenant $tenant, array $input, ?int $actorId): TenantDirectInvoice
    {
        $snapshot = $this->snapshot($input);

        return DB::transaction(function () use ($tenant, $snapshot, $actorId): TenantDirectInvoice {
            $invoice = TenantDirectInvoice::query()->create([
                'tenant_id' => (int) $tenant->id,
                'created_by' => $actorId,
                'status' => 'draft',
                ...$snapshot,
            ]);
            $this->audit->record((int) $tenant->id, $actorId, 'tenant_billing.direct_invoice.create', targetType: 'tenant_direct_invoice', targetId: $invoice->id, afterState: $this->auditSnapshot($invoice));

            return $invoice;
        });
    }

    /** @param array<string,mixed> $input */
    public function updateDraft(TenantDirectInvoice $invoice, array $input, ?int $actorId): TenantDirectInvoice
    {
        if (! $invoice->isEditable()) {
            throw new InvalidArgumentException('Only an unsent draft invoice can be edited.');
        }
        $snapshot = $this->snapshot($input);

        return DB::transaction(function () use ($invoice, $snapshot, $actorId): TenantDirectInvoice {
            $locked = TenantDirectInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isEditable()) {
                throw new InvalidArgumentException('Only an unsent draft invoice can be edited.');
            }
            $before = $this->auditSnapshot($locked);
            $locked->forceFill($snapshot)->save();
            $this->audit->record((int) $locked->tenant_id, $actorId, 'tenant_billing.direct_invoice.update', targetType: 'tenant_direct_invoice', targetId: $locked->id, beforeState: $before, afterState: $this->auditSnapshot($locked));

            return $locked;
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    protected function snapshot(array $input): array
    {
        $lines = collect((array) ($input['lines'] ?? []))->map(function (mixed $line, int $index): array {
            if (! is_array($line)) {
                throw new InvalidArgumentException('Every invoice line must be structured data.');
            }
            $category = strtolower(trim((string) ($line['category'] ?? '')));
            if (! in_array($category, TenantDirectInvoice::LINE_CATEGORIES, true)) {
                throw new InvalidArgumentException('Shopify, third-party, and unknown expenses cannot be charged through Everbranch invoices.');
            }
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $unitAmount = $this->cents($line['unit_amount'] ?? null);
            $isDiscount = $category === 'discount';
            if ($isDiscount && $unitAmount >= 0) {
                throw new InvalidArgumentException('Discount line amounts must be negative.');
            }
            if (! $isDiscount && $unitAmount < 1) {
                throw new InvalidArgumentException('Charge line amounts must be greater than zero.');
            }

            return [
                'key' => 'line_'.($index + 1),
                'category' => $category,
                'description' => trim((string) ($line['description'] ?? '')),
                'quantity' => $quantity,
                'unit_amount_cents' => $unitAmount,
                'amount_cents' => $quantity * $unitAmount,
                'frequency' => 'one_time',
                'tax_treatment' => $isDiscount ? 'non_taxable_adjustment' : 'stripe_determined',
            ];
        })->values()->all();
        if ($lines === []) {
            throw new InvalidArgumentException('At least one invoice line is required.');
        }
        $subtotalCents = (int) collect($lines)->sum('amount_cents');
        if ($subtotalCents < 1) {
            throw new InvalidArgumentException('Discounts must leave a positive invoice total.');
        }

        return [
            'currency' => 'USD',
            'customer_name' => trim((string) $input['customer_name']),
            'customer_email' => strtolower(trim((string) $input['customer_email'])),
            'billing_address' => [
                'line1' => trim((string) data_get($input, 'billing_address.line1')),
                'line2' => trim((string) data_get($input, 'billing_address.line2')) ?: null,
                'city' => trim((string) data_get($input, 'billing_address.city')),
                'state' => strtoupper(trim((string) data_get($input, 'billing_address.state'))),
                'postal_code' => trim((string) data_get($input, 'billing_address.postal_code')),
                'country' => strtoupper(trim((string) data_get($input, 'billing_address.country', 'US'))),
            ],
            'days_until_due' => (int) ($input['days_until_due'] ?? 30),
            'authorization_reference' => trim((string) $input['authorization_reference']),
            'memo' => trim((string) ($input['memo'] ?? '')) ?: null,
            'footer' => trim((string) ($input['footer'] ?? '')) ?: null,
            'line_items' => $lines,
            'authorized_subtotal_cents' => $subtotalCents,
            'metadata' => ['pricing_source' => 'landlord_approved_draft', 'provider_tax_authority' => 'stripe'],
        ];
    }

    protected function cents(mixed $value): int
    {
        $normalized = trim((string) $value);
        if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException('Amounts must use no more than two decimal places.');
        }

        $negative = str_starts_with($normalized, '-');
        [$whole, $decimal] = array_pad(explode('.', ltrim($normalized, '-'), 2), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad($decimal, 2, '0');

        return $negative ? -$cents : $cents;
    }

    /** @return array<string,mixed> */
    protected function auditSnapshot(TenantDirectInvoice $invoice): array
    {
        return [
            'tenant_id' => (int) $invoice->tenant_id,
            'status' => (string) $invoice->status,
            'customer_email' => (string) $invoice->customer_email,
            'days_until_due' => (int) $invoice->days_until_due,
            'authorization_reference' => (string) $invoice->authorization_reference,
            'line_items' => (array) $invoice->line_items,
            'authorized_subtotal_cents' => (int) $invoice->authorized_subtotal_cents,
        ];
    }
}
