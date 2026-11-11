<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\MarketingProfileLink;
use App\Models\SquareCustomer;
use App\Services\Marketing\CanonicalMarketingProfileResolver;

/** Provider customer IDs suggest order attribution, never account access or permission to market. */
final class PosCustomerSuggestion
{
    public function __construct(private PosStore $store, private PosAccess $access, private SquareClient $square) {}

    public function read(string $register, string $id): array
    {
        $scope = $this->access->register($register);
        $service = app(PosService::class);
        $sale = $service->sale($scope, $id);
        $snapshot = $service->snapshot($sale);
        if ($sale->state !== 'succeeded' || ! empty(app(PosReceiptCustomers::class)->decorate($sale, $snapshot)['customer']['id'])
            || ($snapshot['tender'] ?? 'card') === 'cash' || ($snapshot['reward_only'] ?? false)) {
            return ['customer' => null, 'state' => 'not_needed'];
        }
        $evidence = app(PosSalePaymentEvidence::class)->read($sale, $snapshot);
        if (! $evidence || $evidence['state'] !== 'succeeded' || empty($evidence['provider_id'])) {
            throw new PosException('paid_receipt_required');
        }
        $connection = $this->square->connection($scope['tenant_id'], $snapshot['connection_id']);
        $binding = $snapshot['binding'];
        if ($connection->merchant_id !== $binding['merchant_account'] || $connection->provider_location_id !== $binding['location_id']) {
            throw new PosException('original_merchant_unavailable', 503);
        }
        $payment = $this->square->get($connection, '/v2/payments/'.rawurlencode($evidence['provider_id']))['payment'] ?? [];
        // Recheck access after network retrieval. No identity writes or automatic receipt sends.
        $this->access->register($register);
        if (($payment['id'] ?? null) !== $evidence['provider_id'] || ($payment['status'] ?? null) !== 'COMPLETED'
            || ($payment['location_id'] ?? null) !== $connection->provider_location_id
            || ($payment['amount_money']['amount'] ?? null) !== (int) $sale->amount_minor
            || ($payment['amount_money']['currency'] ?? null) !== 'USD') {
            throw new PosException('payment_sale_mismatch', 409);
        }
        $sourceId = $payment['customer_id'] ?? null;
        if (! is_string($sourceId) || $sourceId === '') {
            return ['customer' => null, 'state' => 'pending_provider'];
        }
        $source = SquareCustomer::query()->forTenantId($scope['tenant_id'])->where('square_customer_id', $sourceId)->first();
        // Require the customer to have been admitted by the tenant's source import, not a cross-app card guess.
        if (! $source) {
            return ['customer' => null, 'state' => 'source_import_required'];
        }
        $links = MarketingProfileLink::query()->forTenantId($scope['tenant_id'])->where('source_type', 'square_customer')->where('source_id', $sourceId)->with('marketingProfile')->get();
        $profile = app(CanonicalMarketingProfileResolver::class)->oneCanonical($links->pluck('marketingProfile'), $scope['tenant_id']);
        if (! $profile || $profile->archived_at !== null) {
            return ['customer' => null, 'state' => 'identity_review_required'];
        }

        return ['customer' => ['id' => (int) $profile->id, 'name' => trim($profile->first_name.' '.$profile->last_name) ?: ($profile->email ?: $profile->phone ?: 'Customer'),
            'email' => $profile->email, 'phone' => $profile->phone], 'state' => 'suggested', 'confirmation_required' => true];
    }
}
