<?php

namespace App\Services\Shopify;

use RuntimeException;

class ShopifyWholesaleCustomerApprovalService
{
    protected const WHOLESALE_TAG = 'wholesale';

    protected const CUSTOMER_LOOKUP_QUERY = <<<'GRAPHQL'
query FindWholesaleCustomerByEmail($query: String!) {
  customers(first: 10, query: $query) {
    edges {
      node {
        id
        legacyResourceId
        email
        firstName
        lastName
        phone
        tags
      }
    }
  }
}
GRAPHQL;

    protected const CUSTOMER_SET_MUTATION = <<<'GRAPHQL'
mutation UpsertWholesaleCustomer($identifier: CustomerSetIdentifiers, $input: CustomerSetInput!) {
  customerSet(identifier: $identifier, input: $input) {
    customer {
      id
      legacyResourceId
      email
      firstName
      lastName
      phone
      tags
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

    protected const TAGS_ADD_MUTATION = <<<'GRAPHQL'
mutation AddWholesaleCustomerTag($id: ID!, $tags: [String!]!) {
  tagsAdd(id: $id, tags: $tags) {
    node {
      ... on Customer {
        id
        legacyResourceId
        email
        firstName
        lastName
        phone
        tags
      }
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

    /**
     * Create or update a Shopify customer for a wholesale approval and ensure the wholesale tag exists.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    public function syncByEmail(string $email, array $attributes = []): array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        if ($normalizedEmail === null) {
            throw new RuntimeException('Wholesale customer sync requires a valid email address.');
        }

        $store = $this->resolveWholesaleStore();
        $client = $this->client($store);

        $exactMatches = $this->findExactMatchesByEmail($client, $normalizedEmail);
        if (count($exactMatches) > 1) {
            throw new RuntimeException("Multiple Shopify customers matched '{$normalizedEmail}'.");
        }

        $customer = $this->upsertCustomer($client, $normalizedEmail, $attributes);
        $tagAdded = false;

        if (! $this->customerHasTag($customer, self::WHOLESALE_TAG)) {
            $customer = $this->addTag($client, $customer, self::WHOLESALE_TAG);
            $tagAdded = true;
        }

        $customerId = $this->customerLegacyId($customer);
        if ($customerId === null) {
            throw new RuntimeException('Shopify returned a wholesale customer without a usable customer ID.');
        }

        return [
            'status' => $exactMatches !== []
                ? ($tagAdded ? 'updated_tagged' : 'already_tagged')
                : 'created_tagged',
            'store_key' => 'wholesale',
            'shop_domain' => (string) ($store['shop'] ?? ''),
            'email' => $normalizedEmail,
            'customer_id' => $customerId,
            'customer_gid' => $this->customerGid($customer),
            'tag_added' => $tagAdded,
            'customer_created' => $exactMatches === [],
            'customer_updated' => $exactMatches !== [],
            'customer_tags' => $this->customerTags($customer),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function findExactMatchesByEmail(ShopifyGraphqlClient $client, string $normalizedEmail): array
    {
        $payload = $client->query(self::CUSTOMER_LOOKUP_QUERY, [
            'query' => 'email:' . $normalizedEmail,
        ]);

        $edges = $payload['customers']['edges'] ?? null;
        if (! is_array($edges)) {
            return [];
        }

        $matches = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $node = $edge['node'] ?? null;
            if (! is_array($node)) {
                continue;
            }

            if ($this->normalizeEmail($node['email'] ?? null) === $normalizedEmail) {
                $matches[] = $node;
            }
        }

        return $matches;
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    protected function upsertCustomer(ShopifyGraphqlClient $client, string $normalizedEmail, array $attributes): array
    {
        $input = array_filter([
            'email' => $normalizedEmail,
            'firstName' => $this->resolveFirstName($attributes),
            'lastName' => $this->resolveLastName($attributes),
            'phone' => $this->resolvePhone($attributes),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $payload = $client->query(self::CUSTOMER_SET_MUTATION, [
            'identifier' => [
                'email' => $normalizedEmail,
            ],
            'input' => $input,
        ]);

        $result = $payload['customerSet'] ?? null;
        if (! is_array($result)) {
            throw new RuntimeException('Shopify customerSet returned an invalid payload.');
        }

        $userErrors = $result['userErrors'] ?? null;
        if (is_array($userErrors) && $userErrors !== []) {
            throw new RuntimeException('Shopify customerSet failed: '.$this->formatUserErrors($userErrors));
        }

        $customer = $result['customer'] ?? null;
        if (! is_array($customer)) {
            throw new RuntimeException('Shopify customerSet did not return a customer object.');
        }

        return $customer;
    }

    /**
     * @param  array<string,mixed>  $customer
     * @return array<string,mixed>
     */
    protected function addTag(ShopifyGraphqlClient $client, array $customer, string $tag): array
    {
        $customerGid = $this->customerGid($customer);
        if ($customerGid === null) {
            throw new RuntimeException('Shopify customer tag sync requires a customer ID.');
        }

        $payload = $client->query(self::TAGS_ADD_MUTATION, [
            'id' => $customerGid,
            'tags' => [$tag],
        ]);

        $result = $payload['tagsAdd'] ?? null;
        if (! is_array($result)) {
            throw new RuntimeException('Shopify tagsAdd returned an invalid payload.');
        }

        $node = $result['node'] ?? null;
        if (! is_array($node)) {
            throw new RuntimeException('Shopify tagsAdd did not return an updated customer.');
        }

        $userErrors = $result['userErrors'] ?? null;
        if (is_array($userErrors) && $userErrors !== []) {
            $resolvedTags = $this->customerTags($node);
            if (! in_array($tag, $resolvedTags, true)) {
                throw new RuntimeException('Shopify tagsAdd failed: '.$this->formatUserErrors($userErrors));
            }
        }

        if (! $this->customerHasTag($node, $tag)) {
            throw new RuntimeException('Shopify tagsAdd did not persist the wholesale tag.');
        }

        return $node;
    }

    /**
     * @param  array<string,mixed>  $customer
     * @return array<int,string>
     */
    protected function customerTags(array $customer): array
    {
        $tags = $customer['tags'] ?? [];
        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $tags
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<string,mixed>  $customer
     */
    protected function customerHasTag(array $customer, string $tag): bool
    {
        return in_array($tag, $this->customerTags($customer), true);
    }

    /**
     * @param  array<string,mixed>  $customer
     */
    protected function customerLegacyId(array $customer): ?string
    {
        $legacyId = $this->normalizeString($customer['legacyResourceId'] ?? null);
        if ($legacyId !== null && preg_match('/^\d+$/', $legacyId) === 1) {
            return $legacyId;
        }

        $gid = $this->customerGid($customer);
        if ($gid !== null && preg_match('/(\d+)$/', $gid, $matches) === 1) {
            return (string) $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $customer
     */
    protected function customerGid(array $customer): ?string
    {
        return $this->normalizeString($customer['id'] ?? null);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    protected function resolveFirstName(array $attributes): ?string
    {
        $firstName = $this->normalizeString($attributes['first_name'] ?? null)
            ?? $this->normalizeString($attributes['firstName'] ?? null);
        if ($firstName !== null) {
            return $firstName;
        }

        $fullName = $this->normalizeString($attributes['name'] ?? null)
            ?? $this->normalizeString($attributes['full_name'] ?? null);
        if ($fullName === null) {
            return null;
        }

        [$resolvedFirstName] = $this->splitName($fullName);

        return $resolvedFirstName;
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    protected function resolveLastName(array $attributes): ?string
    {
        $lastName = $this->normalizeString($attributes['last_name'] ?? null)
            ?? $this->normalizeString($attributes['lastName'] ?? null);
        if ($lastName !== null) {
            return $lastName;
        }

        $fullName = $this->normalizeString($attributes['name'] ?? null)
            ?? $this->normalizeString($attributes['full_name'] ?? null);
        if ($fullName === null) {
            return null;
        }

        [, $resolvedLastName] = $this->splitName($fullName);

        return $resolvedLastName;
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    protected function resolvePhone(array $attributes): ?string
    {
        $phone = $this->normalizeString($attributes['phone'] ?? null);
        if ($phone === null) {
            return null;
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $phone) === 1 ? $phone : null;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    protected function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return [null, null];
        }

        $firstName = array_shift($parts);
        $lastName = $parts === [] ? null : implode(' ', $parts);

        return [$firstName !== '' ? $firstName : null, $lastName !== '' ? $lastName : null];
    }

    protected function resolveWholesaleStore(): array
    {
        $store = ShopifyStores::find('wholesale');
        if (is_array($store)) {
            return $store;
        }

        $issues = ShopifyStores::unresolvedMessages('wholesale');
        $message = $issues !== []
            ? implode(' ', $issues)
            : 'Wholesale Shopify store is not configured.';

        throw new RuntimeException($message);
    }

    /**
     * @param  array<string,mixed>  $store
     */
    protected function client(array $store): ShopifyGraphqlClient
    {
        $shopDomain = $this->normalizeString($store['shop'] ?? null);
        $token = $this->normalizeString($store['token'] ?? null);
        $apiVersion = $this->normalizeString($store['api_version'] ?? null) ?? '2026-01';

        if ($shopDomain === null || $token === null) {
            throw new RuntimeException('Wholesale Shopify store credentials are incomplete.');
        }

        return new ShopifyGraphqlClient($shopDomain, $token, $apiVersion);
    }

    protected function normalizeEmail(mixed $value): ?string
    {
        $email = strtolower(trim((string) $value));

        return $email !== '' ? $email : null;
    }

    protected function normalizeString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $errors
     */
    protected function formatUserErrors(array $errors): string
    {
        $messages = [];

        foreach ($errors as $error) {
            if (! is_array($error)) {
                $messages[] = trim((string) $error);

                continue;
            }

            $message = trim((string) ($error['message'] ?? 'unknown_error'));
            $field = is_array($error['field'] ?? null)
                ? implode('.', array_map('strval', $error['field']))
                : null;

            $messages[] = $field ? "{$message} (field={$field})" : $message;
        }

        return implode(' | ', array_filter($messages, static fn (string $value): bool => $value !== ''));
    }
}
