<?php

namespace App\Services\Shopify;

use App\Services\Marketing\CustomerMergeException;
use Throwable;

class ShopifyCustomerMergeApi
{
    /** @return array<string,mixed> */
    public function preview(array $store, string $customerOneId, string $customerTwoId, array $overrideFields = []): array
    {
        $this->requireScope($store, 'read_customer_merge');
        $data = $this->query($store, <<<'GRAPHQL'
query CustomerMergePreview($customerOneId: ID!, $customerTwoId: ID!, $overrideFields: CustomerMergeOverrideFields) {
  customerMergePreview(customerOneId: $customerOneId, customerTwoId: $customerTwoId, overrideFields: $overrideFields) {
    resultingCustomerId
    customerMergeErrors { errorFields message }
    blockingFields { note tags }
    defaultFields { displayName firstName lastName note tags orderCount email { emailAddress } phoneNumber { phoneNumber } }
  }
}
GRAPHQL, [
            'customerOneId' => $customerOneId,
            'customerTwoId' => $customerTwoId,
            'overrideFields' => (object) $this->allowedOverrides($overrideFields),
        ]);

        $preview = (array) ($data['customerMergePreview'] ?? []);
        $preview['consent_result'] = [
            'controlled_by' => 'shopify',
            'message' => 'Shopify determines the surviving customer and marketing consent result.',
        ];

        return $preview;
    }

    /** @return array<string,mixed> */
    public function merge(array $store, string $customerOneId, string $customerTwoId, array $overrideFields = []): array
    {
        $this->requireScope($store, 'write_customer_merge');
        $data = $this->query($store, <<<'GRAPHQL'
mutation CustomerMerge($customerOneId: ID!, $customerTwoId: ID!, $overrideFields: CustomerMergeOverrideFields) {
  customerMerge(customerOneId: $customerOneId, customerTwoId: $customerTwoId, overrideFields: $overrideFields) {
    resultingCustomerId
    job { id done }
    userErrors { code field message }
  }
}
GRAPHQL, [
            'customerOneId' => $customerOneId,
            'customerTwoId' => $customerTwoId,
            'overrideFields' => (object) $this->allowedOverrides($overrideFields),
        ]);

        return (array) ($data['customerMerge'] ?? []);
    }

    /** @return array<string,mixed> */
    public function jobStatus(array $store, string $jobId): array
    {
        $data = $this->query($store, <<<'GRAPHQL'
query CustomerMergeJobStatus($jobId: ID!) {
  customerMergeJobStatus(jobId: $jobId) {
    jobId status resultingCustomerId
    customerMergeErrors { errorFields message }
  }
}
GRAPHQL, ['jobId' => $jobId]);

        return (array) ($data['customerMergeJobStatus'] ?? []);
    }

    private function client(array $store): ShopifyGraphqlClient
    {
        $shop = trim((string) ($store['shop'] ?? ''));
        $token = trim((string) ($store['token'] ?? ''));
        if ($shop === '' || $token === '') {
            throw new CustomerMergeException('This Shopify store must be reauthorized before customer merges can run.', 'shopify_reauthorization_required');
        }

        return new ShopifyGraphqlClient($shop, $token, (string) ($store['api_version'] ?? config('services.shopify.api_version', '2026-01')));
    }

    private function query(array $store, string $query, array $variables): array
    {
        try {
            return $this->client($store)->query($query, $variables);
        } catch (CustomerMergeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new CustomerMergeException('Shopify could not complete the customer merge request: '.$exception->getMessage(), 'shopify_api_error');
        }
    }

    private function requireScope(array $store, string $required): void
    {
        $scopes = collect(preg_split('/[\s,]+/', (string) ($store['scopes'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $scope): string => strtolower(trim($scope)));
        if (! $scopes->contains($required)) {
            throw new CustomerMergeException('Retail must be reauthorized with '.$required.' before customer merges can run.', 'shopify_reauthorization_required');
        }
    }

    private function allowedOverrides(array $overrides): array
    {
        return array_intersect_key($overrides, array_flip([
            'customerIdOfDefaultAddressToKeep', 'customerIdOfEmailToKeep', 'customerIdOfFirstNameToKeep',
            'customerIdOfLastNameToKeep', 'customerIdOfPhoneNumberToKeep', 'note', 'tags',
        ]));
    }
}
