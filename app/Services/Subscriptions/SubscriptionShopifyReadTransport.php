<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionCredentialRevision;
use App\Models\SubscriptionInstallation;
use Illuminate\Support\Facades\Http;

/** A closed set of query documents; no caller can supply GraphQL source or a URL. */
class SubscriptionShopifyReadTransport
{
    public const API_VERSION = '2026-10';

    public const MAX_RESPONSE_BYTES = 1048576;

    public function query(SubscriptionInstallation $installation, SubscriptionCredentialRevision $revision, string $operation, array $variables = []): array
    {
        $document = self::document($operation);
        $this->variables($operation, $variables);
        $domain = $installation->shop_domain;
        if (! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', $domain)
            || $installation->api_version !== self::API_VERSION || $revision->tenant_id !== $installation->tenant_id
            || $revision->installation_id !== $installation->id || $revision->revoked_at || $revision->expires_at?->isPast()) {
            throw new SubscriptionProviderReadException('credential_or_identity_unavailable');
        }
        $token = $revision->credentials['access_token'] ?? null;
        if (! is_string($token) || trim($token) === '' || strlen($token) > 4096 || preg_match('/[\r\n]/', $token)) {
            throw new SubscriptionProviderReadException('credential_unavailable');
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $started = microtime(true);
                $response = Http::withHeaders(['X-Shopify-Access-Token' => $token, 'Accept' => 'application/json'])
                    ->connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 3])
                    ->post('https://'.$domain.'/admin/api/'.self::API_VERSION.'/graphql.json', [
                        'operationName' => $operation, 'query' => $document, 'variables' => (object) $variables,
                    ]);
                $body = $response->toPsrResponse()->getBody();
                $raw = '';
                while (! $body->eof()) {
                    if (microtime(true) - $started > 12) {
                        throw new SubscriptionProviderReadException('provider_read_timeout');
                    }
                    $raw .= $body->read(min(8192, self::MAX_RESPONSE_BYTES + 1 - strlen($raw)));
                    if (microtime(true) - $started > 12) {
                        throw new SubscriptionProviderReadException('provider_read_timeout');
                    }
                    if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
                        throw new SubscriptionProviderReadException('response_too_large');
                    }
                }
                $body->close();
                if ($response->status() === 429 && $attempt === 0) {
                    // Never sleep for an untrusted/unbounded Retry-After value.
                    usleep(100000);

                    continue;
                }
                if (in_array($response->status(), [401, 403], true)) {
                    throw new SubscriptionProviderReadException('provider_access_denied');
                }
                if (! $response->successful()) {
                    throw new SubscriptionProviderReadException('provider_http_error');
                }
                if ($response->header('X-Shopify-API-Version') !== self::API_VERSION) {
                    throw new SubscriptionProviderReadException('api_version_mismatch');
                }
                $decoded = json_decode($raw, true, 64, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw new SubscriptionProviderReadException('invalid_provider_response');
                }
                if (! empty($decoded['errors'])) {
                    $codes = array_column(array_column((array) $decoded['errors'], 'extensions'), 'code');
                    if ($codes && count(array_unique($codes)) === 1 && $codes[0] === 'THROTTLED' && $attempt === 0) {
                        usleep(100000);

                        continue;
                    }
                    throw new SubscriptionProviderReadException(in_array('ACCESS_DENIED', $codes, true) ? 'provider_access_denied' : 'provider_graphql_error');
                }
                if (! isset($decoded['data']) || ! is_array($decoded['data'])) {
                    throw new SubscriptionProviderReadException('incomplete_provider_response');
                }

                return $decoded['data'];
            } catch (SubscriptionProviderReadException $e) {
                throw $e;
            } catch (\Throwable) {
                throw new SubscriptionProviderReadException('provider_transport_error');
            }
        }
        throw new SubscriptionProviderReadException('provider_throttled');
    }

    private function variables(string $operation, array $variables): void
    {
        $keys = match ($operation) {
            'SubscriptionInstallationIdentity' => [],
            'SubscriptionOwnedContracts' => ['after'],
            'SubscriptionContractCore' => ['id'],
            'SubscriptionContractLines', 'SubscriptionContractDiscounts', 'SubscriptionContractAttempts' => ['id', 'after'],
            'SubscriptionContractCycles' => ['id', 'after', 'start', 'end'],
        };
        if (array_diff(array_keys($variables), $keys) || array_diff($keys, array_keys($variables))) {
            throw new SubscriptionProviderReadException('invalid_query_variables');
        }
        foreach ($variables as $key => $value) {
            $valid = match ($key) {
                'id' => is_string($value) && preg_match('/^gid:\/\/shopify\/SubscriptionContract\/[1-9][0-9]*$/D', $value) && strlen($value) <= 190,
                'after' => $value === null || (is_string($value) && $value !== '' && strlen($value) <= 2048 && ! preg_match('/[\x00-\x1f]/', $value)),
                'start', 'end' => is_int($value) && $value >= 1 && $value <= 1000000,
            };
            if (! $valid) {
                throw new SubscriptionProviderReadException('invalid_query_variables');
            }
        }
        if (isset($variables['start']) && ($variables['end'] < $variables['start'] || $variables['end'] - $variables['start'] > 99)) {
            throw new SubscriptionProviderReadException('invalid_query_variables');
        }
    }

    public static function document(string $operation): string
    {
        $identity = 'id app { id } revisionId';
        $page = 'pageInfo { hasNextPage endCursor }';

        return match ($operation) {
            'SubscriptionInstallationIdentity' => 'query SubscriptionInstallationIdentity { shop { id myshopifyDomain ianaTimezone } currentAppInstallation { id app { id apiKey webhookApiVersion } accessScopes { handle } } }',
            'SubscriptionOwnedContracts' => 'query SubscriptionOwnedContracts($after: String) { subscriptionContracts(first: 25, after: $after, sortKey: UPDATED_AT) { nodes { '.$identity.' } '.$page.' } }',
            'SubscriptionContractCore' => 'query SubscriptionContractCore($id: ID!) { subscriptionContract(id: $id) { '.$identity.' status createdAt updatedAt currencyCode nextBillingDate customer { id } billingPolicy { interval intervalCount minCycles maxCycles anchors { type day month cutoffDay } } deliveryPolicy { interval intervalCount anchors { type day month cutoffDay } } deliveryMethod { __typename } deliveryPrice { amount currencyCode } customerPaymentMethod(showRevoked: true) { id customer { id } revokedAt revokedReason instrument { __typename ... on CustomerCreditCard { brand lastDigits expiryMonth expiryYear } } } } }',
            'SubscriptionContractLines' => 'query SubscriptionContractLines($id: ID!, $after: String) { subscriptionContract(id: $id) { '.$identity.' lines(first: 25, after: $after) { nodes { id title variantTitle sku quantity productId variantId sellingPlanId sellingPlanName requiresShipping taxable currentPrice { amount currencyCode } lineDiscountedPrice { amount currencyCode } discountAllocations { amount { amount currencyCode } discount { __typename ... on SubscriptionManualDiscount { id } } } } '.$page.' } } }',
            'SubscriptionContractDiscounts' => 'query SubscriptionContractDiscounts($id: ID!, $after: String) { subscriptionContract(id: $id) { '.$identity.' discounts(first: 25, after: $after) { nodes { id title type targetType usageCount recurringCycleLimit rejectionReason value { __typename ... on SubscriptionDiscountFixedAmountValue { amount { amount currencyCode } appliesOnEachItem } ... on SubscriptionDiscountPercentageValue { percentage } } } '.$page.' } } }',
            'SubscriptionContractAttempts' => 'query SubscriptionContractAttempts($id: ID!, $after: String) { subscriptionContract(id: $id) { '.$identity.' billingAttempts(first: 25, after: $after) { nodes { id createdAt completedAt originTime subscriptionContract { id app { id } } state { __typename ... on SubscriptionBillingAttemptPendingState { __typename } ... on SubscriptionBillingAttemptActionRequiredState { action { __typename ... on SubscriptionBillingAttemptPaymentChallenge { nextActionUrl status } } } ... on SubscriptionBillingAttemptFailedState { error { __typename ... on SubscriptionBillingAttemptGeneralError { generalCode: code } ... on SubscriptionBillingAttemptInventoryError { inventoryCode: code } ... on SubscriptionBillingAttemptPaymentError { paymentCode: code } ... on SubscriptionBillingAttemptUnexpectedError { message } } } ... on SubscriptionBillingAttemptSuccessState { order { id customer { id } } } } } '.$page.' } } }',
            'SubscriptionContractCycles' => 'query SubscriptionContractCycles($id: ID!, $after: String, $start: Int!, $end: Int!) { subscriptionBillingCycles(contractId: $id, first: 25, after: $after, billingCyclesIndexRangeSelector: { startIndex: $start, endIndex: $end }) { nodes { cycleIndex billingAttemptExpectedDate cycleStartAt cycleEndAt edited skipped status sourceContract { '.$identity.' } } '.$page.' } }',
            default => throw new SubscriptionProviderReadException('unsupported_query'),
        };
    }
}
