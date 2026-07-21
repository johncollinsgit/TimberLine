<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class StripeAccountTransactionFeedService
{
    /** @return array{ok:bool,transactions:array<int,array<string,mixed>>,has_more:bool,message:?string} */
    public function get(bool $refresh = false, int $maximum = 250): array
    {
        $secret = trim((string) config('services.stripe.secret'));
        if (! str_starts_with($secret, 'sk_test_') && ! str_starts_with($secret, 'sk_live_')) {
            return ['ok' => false, 'transactions' => [], 'has_more' => false, 'message' => 'Stripe is not configured.'];
        }

        $maximum = min(500, max(1, $maximum));
        $cacheKey = 'billing:stripe-account-transactions:'.hash('sha256', $secret.'|'.$this->apiBase().'|'.$maximum);
        if ($refresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addSeconds(30), fn (): array => $this->fetch($maximum));
    }

    /** @return array{ok:bool,transactions:array<int,array<string,mixed>>,has_more:bool,message:?string} */
    private function fetch(int $maximum): array
    {
        $transactions = [];
        $startingAfter = null;
        $hasMore = false;

        try {
            do {
                $remaining = $maximum - count($transactions);
                $parameters = [
                    'limit' => min(100, $remaining),
                    'expand' => ['data.customer', 'data.latest_charge'],
                ];
                if ($startingAfter !== null) {
                    $parameters['starting_after'] = $startingAfter;
                }

                $response = $this->stripeRequest()->get($this->apiBase().'/v1/payment_intents', $parameters);
                $payload = is_array($response->json()) ? $response->json() : [];
                if ($response->failed() || ! is_array($payload['data'] ?? null)) {
                    return ['ok' => false, 'transactions' => [], 'has_more' => false, 'message' => 'Stripe activity could not be refreshed. Showing verified webhook records instead.'];
                }

                $data = $payload['data'];
                $page = collect($data)
                    ->filter(fn (mixed $intent): bool => is_array($intent) && ($intent['object'] ?? '') === 'payment_intent')
                    ->map(fn (array $intent): array => $this->normalize($intent))
                    ->filter(fn (array $intent): bool => $intent['amount_cents'] > 0)
                    ->values()
                    ->all();
                $transactions = [...$transactions, ...$page];
                $hasMore = (bool) ($payload['has_more'] ?? false);
                $last = end($data);
                $startingAfter = is_array($last) && filled($last['id'] ?? null) ? (string) $last['id'] : null;
            } while ($hasMore && $startingAfter !== null && count($transactions) < $maximum);
        } catch (Throwable) {
            return ['ok' => false, 'transactions' => [], 'has_more' => false, 'message' => 'Stripe activity could not be refreshed. Showing verified webhook records instead.'];
        }

        return [
            'ok' => true,
            'transactions' => array_slice($transactions, 0, $maximum),
            'has_more' => $hasMore,
            'message' => null,
        ];
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    private function normalize(array $intent): array
    {
        $status = strtolower(trim((string) ($intent['status'] ?? 'unknown')));
        $charge = is_array($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : [];
        $customer = is_array($intent['customer'] ?? null) ? $intent['customer'] : [];
        $amountRefunded = max(0, (int) ($charge['amount_refunded'] ?? 0));
        $amountReceived = max(0, (int) ($intent['amount_received'] ?? 0));
        $amount = max(0, (int) ($intent['amount'] ?? 0));
        $received = $status === 'succeeded' && $amountReceived > 0;
        $displayStatus = match (true) {
            (bool) ($charge['disputed'] ?? false) => 'disputed',
            $received && $amountRefunded >= $amountReceived => 'refunded',
            $received && $amountRefunded > 0 => 'partially_refunded',
            default => $status,
        };
        $customerLabel = trim((string) ($customer['name'] ?? ''))
            ?: trim((string) ($customer['email'] ?? $intent['receipt_email'] ?? ''))
            ?: (is_string($intent['customer'] ?? null) ? (string) $intent['customer'] : 'Stripe customer');
        $paymentMethod = data_get($charge, 'payment_method_details.type');

        return [
            'payment_intent_id' => trim((string) ($intent['id'] ?? '')),
            'invoice_id' => is_string($intent['invoice'] ?? null) ? (string) $intent['invoice'] : null,
            'status' => $displayStatus,
            'status_label' => $this->statusLabel($displayStatus),
            'received' => $received,
            'amount_cents' => $received ? $amountReceived : $amount,
            'amount_received_cents' => $received ? $amountReceived : 0,
            'amount_refunded_cents' => $amountRefunded,
            'currency' => strtoupper(trim((string) ($intent['currency'] ?? 'USD'))),
            'description' => trim((string) ($intent['description'] ?? '')) ?: 'Stripe payment',
            'customer' => $customerLabel,
            'payment_method' => filled($paymentMethod) ? str((string) $paymentMethod)->replace('_', ' ')->headline()->toString() : '—',
            'occurred_at' => is_numeric($intent['created'] ?? null) ? Carbon::createFromTimestamp((int) $intent['created']) : now(),
            'receipt_url' => $this->safeUrl($charge['receipt_url'] ?? null),
            'metadata' => is_array($intent['metadata'] ?? null) ? $intent['metadata'] : [],
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'succeeded' => 'Succeeded',
            'requires_payment_method', 'requires_confirmation', 'requires_action', 'processing', 'requires_capture' => 'Incomplete',
            'canceled' => 'Canceled',
            'refunded' => 'Refunded',
            'partially_refunded' => 'Partially refunded',
            'disputed' => 'Disputed',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function safeUrl(mixed $url): ?string
    {
        $url = trim((string) $url);

        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) && str_starts_with(strtolower($url), 'https://') ? $url : null;
    }

    private function stripeRequest(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(max(5, (int) config('services.stripe.timeout', 20)))
            ->retry(1, 250, throw: false)
            ->withBasicAuth((string) config('services.stripe.secret'), '');
    }

    private function apiBase(): string
    {
        return rtrim((string) config('services.stripe.api_base', 'https://api.stripe.com'), '/');
    }
}
