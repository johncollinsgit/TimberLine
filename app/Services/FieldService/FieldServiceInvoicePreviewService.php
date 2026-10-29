<?php

namespace App\Services\FieldService;

use App\Models\Tenant;
use App\Services\Tenancy\TenantBrandProfileService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FieldServiceInvoicePreviewService
{
    public function __construct(
        private TenantBrandProfileService $brands,
        private InvoicePreviewPaymentAdapter $payments,
        private InvoicePreviewEmailAdapter $email,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function preview(Tenant $tenant, array $input): array
    {
        $brand = $this->brands->previewPresentationFor($tenant);
        $lines = [];
        $subtotalCents = 0;

        foreach ($input['line_items'] as $line) {
            $quantity = (int) $line['quantity'];
            $unitCents = (int) $line['unit_amount_cents'];
            $lineCents = $quantity * $unitCents;
            if ($lineCents > 999_999_999 || $subtotalCents > 999_999_999 - $lineCents) {
                throw ValidationException::withMessages(['line_items' => 'Invoice preview total is too large.']);
            }
            $subtotalCents += $lineCents;
            $lines[] = [
                'description' => trim((string) $line['description']),
                'quantity' => $quantity,
                'unit_amount_cents' => $unitCents,
                'line_total_cents' => $lineCents,
                'line_total' => $this->money($lineCents),
            ];
        }

        $provider = (string) $input['invoice_provider'];
        $customerName = trim((string) $input['customer_name']);
        $total = $this->money($subtotalCents);

        return [
            'kind' => 'invoice_preview',
            'issued' => false,
            'persisted' => false,
            'invoice' => [
                'number' => 'PREVIEW',
                'status' => 'preview_only',
                'customer' => ['name' => $customerName, 'email' => $input['customer_email'] ?? null],
                'lines' => $lines,
                'subtotal_cents' => $subtotalCents,
                'tax_cents' => null,
                'tax_status' => 'not_calculated',
                'total_cents' => $subtotalCents,
                'total' => $total,
                'currency' => 'USD',
                'due_date' => isset($input['due_date']) ? Carbon::parse($input['due_date'])->toDateString() : null,
                'memo' => $input['memo'] ?? null,
            ],
            'tax' => ['status' => 'not_calculated', 'amount_cents' => null],
            'template' => [
                'business_name' => $brand['display_name'],
                'tagline' => $brand['tagline'],
                'logo_url' => $brand['light_logo_url'],
                'primary_color' => $brand['primary_color'],
                'accent_color' => $brand['accent_color'],
                'surface_color' => $brand['surface_color'],
                'text_color' => $brand['text_color'],
            ],
            'invoice_provider' => [
                'key' => $provider,
                'label' => $provider === 'everbranch_pay' ? 'Everbranch Pay' : 'QuickBooks',
                'status' => 'setup_unverified',
                'invoice_created' => false,
            ],
            'payment_processing' => $this->payments->preview($provider, $subtotalCents),
            'delivery' => $this->email->preview($input['customer_email'] ?? null, $brand['display_name'], $total),
            'accounting_sync' => $provider === 'quickbooks'
                ? ['provider' => 'quickbooks', 'status' => 'not_synced']
                : ['provider' => null, 'status' => 'not_selected'],
            'next_step' => 'This is a preview only. A separate confirmation is required before an invoice can be issued or sent.',
        ];
    }

    private function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2, '.', ',');
    }
}
