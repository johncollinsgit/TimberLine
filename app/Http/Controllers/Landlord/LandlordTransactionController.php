<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\OperatorRecurringCost;
use App\Models\TenantBillingReceipt;
use App\Models\TenantBillingRefund;
use App\Services\Billing\LandlordTransactionRefundService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class LandlordTransactionController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('manage-landlord-commercial');
        $direction = strtolower(trim((string) $request->query('direction', 'all')));
        $direction = in_array($direction, ['all', 'incoming', 'outgoing'], true) ? $direction : 'all';
        $query = trim((string) $request->query('q', ''));

        $receipts = TenantBillingReceipt::query()
            ->with(['tenant:id,name', 'refunds:id,tenant_billing_receipt_id,status,amount_cents', 'billingOrder:id,tenant_id,agreement_id,line_items,provider_payment_intent_id,status,metadata', 'billingOrder.agreement:id,tenant_id', 'directInvoice:id,tenant_id,customer_name,customer_email,line_items,provider_payment_intent_id,status,metadata'])
            ->orderByDesc('paid_at')->orderByDesc('billed_at')->limit(250)->get();
        $refunds = TenantBillingRefund::query()
            ->with(['tenant:id,name', 'receipt:id,invoice_number,total_amount_cents,currency', 'billingOrder:id,agreement_id', 'directInvoice:id,customer_name'])
            ->latest('created_at')->limit(250)->get();
        $costs = OperatorRecurringCost::query()->orderByDesc('effective_on')->orderByDesc('id')->limit(250)->get();

        $transactions = $this->paymentRows($receipts)
            ->merge($this->refundRows($refunds))
            ->merge($this->costRows($costs))
            ->filter(function (array $row) use ($direction, $query): bool {
                if ($direction !== 'all' && $row['direction'] !== $direction) {
                    return false;
                }
                if ($query === '') {
                    return true;
                }

                return str(implode(' ', [
                    $row['title'], $row['counterparty'], $row['reference'], $row['status'],
                    collect($row['items'])->pluck('label')->implode(' '),
                ]))->contains($query, true);
            })
            ->sortByDesc('occurred_at')
            ->values();

        return view('landlord.transactions.index', [
            'transactions' => $transactions,
            'direction' => $direction,
            'query' => $query,
            'summary' => [
                'incoming_cents' => $receipts->where('status', 'paid')->sum('total_amount_cents'),
                'refund_cents' => $refunds->where('status', 'succeeded')->sum('amount_cents'),
                'weekly_commitments_cents' => $this->weeklyCommitmentCents($costs->where('active', true)),
            ],
        ]);
    }

    public function refund(Request $request, TenantBillingReceipt $receipt, LandlordTransactionRefundService $refunds): RedirectResponse
    {
        Gate::authorize('manage-landlord-commercial');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'reason' => ['required', 'in:duplicate,fraudulent,requested_by_customer'],
            'note' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        try {
            $refund = $refunds->refund(
                $receipt,
                (int) round(((float) $data['amount']) * 100),
                $data['reason'],
                filled($data['note'] ?? null) ? trim((string) $data['note']) : null,
                (string) $data['idempotency_key'],
                $request->user(),
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['refund' => $exception->getMessage()]);
        }

        return redirect()->route('landlord.transactions.index')->with('status', 'Refund '.($refund->status === 'succeeded' ? 'issued' : 'submitted to Stripe').' and recorded in the ledger.');
    }

    /** @return Collection<int,array<string,mixed>> */
    private function paymentRows(Collection $receipts): Collection
    {
        return $receipts->map(function (TenantBillingReceipt $receipt): array {
            $items = $this->lineItems((array) ($receipt->billingOrder?->line_items ?: $receipt->directInvoice?->line_items), (int) $receipt->tax_amount_cents);
            $reference = trim((string) ($receipt->invoice_number ?: $receipt->provider_receipt_id));
            $title = $receipt->directInvoice ? 'Invoice payment' : 'Agreement payment';
            $paymentIntent = trim((string) ($receipt->billingOrder?->provider_payment_intent_id ?? $receipt->directInvoice?->provider_payment_intent_id));
            $refunded = (int) $receipt->refunds->whereIn('status', ['pending', 'succeeded'])->sum('amount_cents');

            return [
                'key' => 'receipt-'.$receipt->id,
                'direction' => 'incoming',
                'kind' => 'payment',
                'title' => $title,
                'counterparty' => (string) ($receipt->tenant?->name ?? 'Workspace'),
                'reference' => $reference,
                'status' => strtolower((string) $receipt->status),
                'amount_cents' => (int) $receipt->total_amount_cents,
                'currency' => (string) $receipt->currency,
                'occurred_at' => $receipt->paid_at ?? $receipt->billed_at ?? $receipt->created_at,
                'items' => $items,
                'receipt_url' => $receipt->receipt_url ?: $receipt->hosted_invoice_url,
                'refund' => [
                    'eligible' => strtolower((string) $receipt->provider) === 'stripe' && strtolower((string) $receipt->status) === 'paid' && $paymentIntent !== '' && $refunded < (int) $receipt->total_amount_cents,
                    'remaining_cents' => max(0, (int) $receipt->total_amount_cents - $refunded),
                    'receipt_id' => $receipt->id,
                ],
            ];
        });
    }

    /** @return Collection<int,array<string,mixed>> */
    private function refundRows(Collection $refunds): Collection
    {
        return $refunds->map(function (TenantBillingRefund $refund): array {
            return [
                'key' => 'refund-'.$refund->id,
                'direction' => 'outgoing',
                'kind' => 'refund',
                'title' => 'Customer refund',
                'counterparty' => (string) ($refund->tenant?->name ?? 'Workspace'),
                'reference' => (string) ($refund->provider_refund_id ?: 'Refund request #'.$refund->id),
                'status' => (string) $refund->status,
                'amount_cents' => (int) $refund->amount_cents,
                'currency' => (string) $refund->currency,
                'occurred_at' => $refund->processed_at ?? $refund->created_at,
                'items' => [['label' => 'Reason: '.str_replace('_', ' ', $refund->reason), 'amount_cents' => (int) $refund->amount_cents]],
                'note' => $refund->note,
                'refund' => null,
            ];
        });
    }

    /** @return Collection<int,array<string,mixed>> */
    private function costRows(Collection $costs): Collection
    {
        return $costs->map(function (OperatorRecurringCost $cost): array {
            return [
                'key' => 'cost-'.$cost->id,
                'direction' => 'outgoing',
                'kind' => 'commitment',
                'title' => 'Operating commitment',
                'counterparty' => $cost->vendor,
                'reference' => (string) ($cost->receipt_reference ?: ucfirst($cost->source).' entry'),
                'status' => $cost->active ? 'scheduled' : 'inactive',
                'amount_cents' => (int) $cost->amount_cents,
                'currency' => (string) $cost->currency,
                'occurred_at' => $cost->effective_on ?? $cost->created_at,
                'items' => [['label' => ucfirst($cost->cadence).' operating cost', 'amount_cents' => (int) $cost->amount_cents]],
                'note' => $cost->notes,
                'refund' => null,
            ];
        });
    }

    /** @param array<int,mixed> $raw @return array<int,array{label:string,amount_cents:int}> */
    private function lineItems(array $raw, int $taxCents): array
    {
        $items = collect($raw)->filter(fn (mixed $line): bool => is_array($line))->map(function (array $line): array {
            $quantity = max(1, (int) ($line['quantity'] ?? 1));

            return [
                'label' => trim((string) ($line['label'] ?? $line['name'] ?? $line['description'] ?? 'Service')) ?: 'Service',
                'amount_cents' => (int) ($line['amount_cents'] ?? $line['amount'] ?? 0) * $quantity,
            ];
        })->filter(fn (array $line): bool => $line['amount_cents'] !== 0)->values()->all();
        if ($items === []) {
            $items[] = ['label' => 'Stripe-confirmed payment', 'amount_cents' => 0];
        }
        if ($taxCents > 0) {
            $items[] = ['label' => 'Tax', 'amount_cents' => $taxCents];
        }

        return $items;
    }

    private function weeklyCommitmentCents(Collection $costs): int
    {
        return (int) $costs->sum(fn (OperatorRecurringCost $cost): int => match ($cost->cadence) {
            'weekly' => (int) $cost->amount_cents,
            'annual' => (int) round($cost->amount_cents / 52),
            default => (int) round($cost->amount_cents / 4.333),
        });
    }
}
