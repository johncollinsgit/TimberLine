<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\OperatorRecurringCost;
use App\Models\TenantBillingReceipt;
use App\Models\TenantBillingRefund;
use App\Services\Billing\LandlordTransactionRefundService;
use App\Services\Billing\StripeAccountTransactionFeedService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class LandlordTransactionController extends Controller
{
    public function index(Request $request, StripeAccountTransactionFeedService $stripeFeed): View
    {
        Gate::authorize('manage-landlord-commercial');
        $direction = strtolower(trim((string) $request->query('direction', 'all')));
        $direction = in_array($direction, ['all', 'incoming', 'outgoing'], true) ? $direction : 'all';
        $query = trim((string) $request->query('q', ''));

        $feed = $stripeFeed->get($request->boolean('refresh_stripe'));
        if ($feed['ok']) {
            $paymentRows = $this->stripePaymentIntentRows(collect($feed['transactions']));
            $incomingCents = (int) collect($feed['transactions'])->sum('amount_received_cents');
            $stripeActivityCount = count($feed['transactions']);
        } else {
            $receipts = $this->verifiedWebhookReceipts();
            $confirmedReceiptIds = $this->confirmedStripeReceiptIds($receipts);
            $paymentRows = $this->paymentRows($receipts, $confirmedReceiptIds);
            $incomingCents = (int) $receipts->whereIn('id', $confirmedReceiptIds)->sum('total_amount_cents');
            $stripeActivityCount = $receipts->count();
        }
        $refunds = TenantBillingRefund::query()
            ->with(['tenant:id,name', 'receipt:id,invoice_number,total_amount_cents,currency', 'billingOrder:id,agreement_id', 'directInvoice:id,customer_name'])
            ->latest('created_at')->limit(250)->get();
        $costs = OperatorRecurringCost::query()->orderByDesc('effective_on')->orderByDesc('id')->limit(250)->get();

        $transactions = $paymentRows
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
            'stripeFeed' => $feed,
            'summary' => [
                'incoming_cents' => $incomingCents,
                'stripe_activity_count' => $stripeActivityCount,
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
    private function stripePaymentIntentRows(Collection $intents): Collection
    {
        $paymentIntentIds = $intents->pluck('payment_intent_id')->filter()->unique()->values();
        $receipts = TenantBillingReceipt::query()
            ->with(['refunds:id,tenant_billing_receipt_id,status,amount_cents', 'billingOrder:id,provider_payment_intent_id', 'directInvoice:id,provider_payment_intent_id'])
            ->where(function ($query) use ($paymentIntentIds): void {
                $query->whereHas('billingOrder', fn ($orders) => $orders->whereIn('provider_payment_intent_id', $paymentIntentIds))
                    ->orWhereHas('directInvoice', fn ($invoices) => $invoices->whereIn('provider_payment_intent_id', $paymentIntentIds));
            })
            ->orderBy('id')
            ->get()
            ->keyBy(fn (TenantBillingReceipt $receipt): string => trim((string) ($receipt->billingOrder?->provider_payment_intent_id ?? $receipt->directInvoice?->provider_payment_intent_id)));

        return $intents->map(function (array $intent) use ($receipts): array {
            $reference = trim((string) ($intent['invoice_id'] ?? '')) ?: (string) $intent['payment_intent_id'];
            /** @var TenantBillingReceipt|null $receipt */
            $receipt = $receipts->get((string) $intent['payment_intent_id']);
            $localRefunded = $receipt
                ? (int) $receipt->refunds->whereIn('status', ['pending', 'succeeded'])->sum('amount_cents')
                : 0;
            $refunded = max($localRefunded, (int) $intent['amount_refunded_cents']);
            $remaining = max(0, (int) $intent['amount_received_cents'] - $refunded);

            return [
                'key' => 'stripe-'.$intent['payment_intent_id'],
                'direction' => 'incoming',
                'kind' => 'payment',
                'title' => 'Stripe payment',
                'counterparty' => (string) $intent['customer'],
                'reference' => $reference,
                'status' => (string) $intent['status'],
                'status_label' => (string) $intent['status_label'],
                'received' => (bool) $intent['received'],
                'amount_cents' => (int) $intent['amount_cents'],
                'currency' => (string) $intent['currency'],
                'occurred_at' => $intent['occurred_at'],
                'items' => [['label' => (string) $intent['description'], 'amount_cents' => (int) $intent['amount_cents']]],
                'payment_method' => (string) $intent['payment_method'],
                'receipt_url' => $intent['receipt_url'],
                'refund' => $receipt ? [
                    'eligible' => (bool) $intent['received'] && strtolower((string) $receipt->status) === 'paid' && $remaining > 0,
                    'remaining_cents' => $remaining,
                    'receipt_id' => $receipt->id,
                ] : null,
            ];
        });
    }

    /** @return Collection<int,TenantBillingReceipt> */
    private function verifiedWebhookReceipts(): Collection
    {
        $livemode = $this->configuredStripeLivemode();
        if ($livemode === null) {
            return collect();
        }

        return TenantBillingReceipt::query()
            ->stripeActivityRecorded($livemode)
            ->with(['tenant:id,name', 'refunds:id,tenant_billing_receipt_id,status,amount_cents', 'billingOrder:id,tenant_id,agreement_id,line_items,provider_payment_intent_id,status,metadata', 'billingOrder.agreement:id,tenant_id', 'directInvoice:id,tenant_id,customer_name,customer_email,line_items,provider_payment_intent_id,status,metadata'])
            ->orderByDesc('paid_at')->orderByDesc('billed_at')->orderByDesc('id')->limit(250)->get();
    }

    /** @return Collection<int,int> */
    private function confirmedStripeReceiptIds(Collection $receipts): Collection
    {
        $livemode = $this->configuredStripeLivemode();
        if ($livemode === null || $receipts->isEmpty()) {
            return collect();
        }

        return TenantBillingReceipt::query()
            ->stripePaymentConfirmed($livemode)
            ->whereKey($receipts->modelKeys())
            ->pluck('id');
    }

    /** @return Collection<int,array<string,mixed>> */
    private function paymentRows(Collection $receipts, Collection $confirmedReceiptIds): Collection
    {
        return $receipts->map(function (TenantBillingReceipt $receipt) use ($confirmedReceiptIds): array {
            $items = $this->lineItems(
                (array) ($receipt->billingOrder?->line_items ?: $receipt->directInvoice?->line_items),
                (int) $receipt->subtotal_amount_cents,
                (int) $receipt->tax_amount_cents,
            );
            $reference = trim((string) ($receipt->invoice_number ?: $receipt->provider_receipt_id));
            $title = $receipt->directInvoice ? 'Invoice payment' : 'Agreement payment';
            $paymentIntent = trim((string) ($receipt->billingOrder?->provider_payment_intent_id ?? $receipt->directInvoice?->provider_payment_intent_id));
            $refunded = (int) $receipt->refunds->whereIn('status', ['pending', 'succeeded'])->sum('amount_cents');
            $status = strtolower((string) $receipt->status);
            $received = $confirmedReceiptIds->contains($receipt->id);
            if ($status === 'paid' && ! $received) {
                $status = 'processing';
            }

            return [
                'key' => 'receipt-'.$receipt->id,
                'direction' => 'incoming',
                'kind' => 'payment',
                'title' => $title,
                'counterparty' => (string) ($receipt->tenant?->name ?? 'Workspace'),
                'reference' => $reference,
                'status' => $status,
                'status_label' => $this->stripeStatusLabel($status),
                'received' => $received,
                'amount_cents' => (int) $receipt->total_amount_cents,
                'currency' => (string) $receipt->currency,
                'occurred_at' => $receipt->paid_at ?? $receipt->billed_at ?? $receipt->created_at,
                'items' => $items,
                'payment_method' => '—',
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
                'status_label' => ucfirst(str_replace('_', ' ', (string) $refund->status)),
                'received' => false,
                'amount_cents' => (int) $refund->amount_cents,
                'currency' => (string) $refund->currency,
                'occurred_at' => $refund->processed_at ?? $refund->created_at,
                'items' => [['label' => 'Reason: '.str_replace('_', ' ', $refund->reason), 'amount_cents' => (int) $refund->amount_cents]],
                'payment_method' => 'Stripe',
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
                'status_label' => $cost->active ? 'Scheduled' : 'Inactive',
                'received' => false,
                'amount_cents' => (int) $cost->amount_cents,
                'currency' => (string) $cost->currency,
                'occurred_at' => $cost->effective_on ?? $cost->created_at,
                'items' => [['label' => ucfirst($cost->cadence).' operating cost', 'amount_cents' => (int) $cost->amount_cents]],
                'payment_method' => '—',
                'note' => $cost->notes,
                'refund' => null,
            ];
        });
    }

    /** @param array<int,mixed> $raw @return array<int,array{label:string,amount_cents:int}> */
    private function lineItems(array $raw, int $subtotalCents, int $taxCents): array
    {
        $items = collect($raw)->filter(fn (mixed $line): bool => is_array($line))->map(function (array $line): array {
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $amount = array_key_exists('unit_amount_cents', $line)
                ? (int) ($line['amount_cents'] ?? ((int) $line['unit_amount_cents'] * $quantity))
                : (int) ($line['amount_cents'] ?? $line['amount'] ?? 0) * $quantity;

            return [
                'label' => trim((string) ($line['label'] ?? $line['name'] ?? $line['description'] ?? 'Service')) ?: 'Service',
                'amount_cents' => $amount,
                'payment_timing' => (string) ($line['payment_timing'] ?? ''),
            ];
        })->filter(fn (array $line): bool => $line['amount_cents'] !== 0)->values()->all();

        $candidateGroups = [
            collect($items)->filter(fn (array $line): bool => in_array($line['payment_timing'], ['due_on_acceptance', 'recurring_current'], true))->values(),
            collect($items)->filter(fn (array $line): bool => $line['payment_timing'] === 'recurring_current')->values(),
            collect($items)->filter(fn (array $line): bool => $line['payment_timing'] === 'recurring_future')->values(),
            collect($items),
        ];
        $matching = collect($candidateGroups)->first(
            fn (Collection $group): bool => $group->isNotEmpty() && (int) $group->sum('amount_cents') === $subtotalCents
        );
        $items = $matching instanceof Collection
            ? $matching->map(fn (array $line): array => [
                'label' => $line['label'],
                'amount_cents' => $line['amount_cents'],
            ])->all()
            : [['label' => 'Stripe-confirmed payment', 'amount_cents' => $subtotalCents]];

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

    private function configuredStripeLivemode(): ?bool
    {
        $secret = trim((string) config('services.stripe.secret'));

        return match (true) {
            str_starts_with($secret, 'sk_live_') => true,
            str_starts_with($secret, 'sk_test_') => false,
            default => null,
        };
    }

    private function stripeStatusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'Succeeded',
            'open', 'processing' => 'Incomplete',
            'payment_failed', 'failed', 'send_failed' => 'Failed',
            'refunded' => 'Refunded',
            'void' => 'Voided',
            'uncollectible' => 'Uncollectible',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
