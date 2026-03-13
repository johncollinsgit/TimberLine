<?php

namespace App\Services\Marketing;

use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use Carbon\CarbonInterface;
use RuntimeException;

class ShopifyBirthdayMetafieldService
{
    protected const METAFIELDS_SET_MUTATION = <<<'GRAPHQL'
mutation SetCustomerBirthdayMetafields($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    metafields {
      namespace
      key
      value
      type
    }
    userErrors {
      field
      message
      code
    }
  }
}
GRAPHQL;

    /**
     * @param array<string,mixed> $options
     * @return array{updated:int,stores:array<int,string>,errors:array<int,string>}
     */
    public function writeBirthdayForProfile(MarketingProfile $profile, CustomerBirthdayProfile $birthday, array $options = []): array
    {
        $restrictStoreKeys = array_values(array_filter(array_map(
            fn ($value): string => strtolower(trim((string) $value)),
            (array) ($options['store_keys'] ?? [])
        )));

        $links = MarketingProfileLink::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'shopify_customer')
            ->orderBy('id')
            ->get();

        $updated = 0;
        $stores = [];
        $errors = [];

        foreach ($links as $link) {
            $parsed = $this->parseSourceId((string) $link->source_id);
            if (! $parsed) {
                continue;
            }

            $storeKey = $parsed['store_key'];
            $customerId = $parsed['customer_id'];
            if ($restrictStoreKeys !== [] && ! in_array($storeKey, $restrictStoreKeys, true)) {
                continue;
            }

            $store = ShopifyStores::find($storeKey);
            if (! $store) {
                $errors[] = "Shopify store '{$storeKey}' is not configured for birthday write-back.";
                continue;
            }

            try {
                $this->writeBirthdayMetafields($store, $customerId, $birthday);
                $updated++;
                $stores[] = $storeKey;
            } catch (\Throwable $e) {
                $errors[] = "{$storeKey}:{$customerId} => {$e->getMessage()}";
            }
        }

        return [
            'updated' => $updated,
            'stores' => array_values(array_unique($stores)),
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string,mixed> $store
     */
    public function writeBirthdayMetafields(array $store, string $shopifyCustomerId, CustomerBirthdayProfile $birthday): void
    {
        $storeKey = trim((string) ($store['key'] ?? ''));
        $shop = trim((string) ($store['shop'] ?? ''));
        $token = trim((string) ($store['token'] ?? ''));
        $apiVersion = trim((string) ($store['api_version'] ?? '')) ?: '2026-01';

        if ($storeKey === '' || $shop === '' || $token === '') {
            throw new RuntimeException('Shopify store config is missing key/shop/token for birthday write-back.');
        }

        $customerId = trim($shopifyCustomerId);
        if ($customerId === '' || ! preg_match('/^\d+$/', $customerId)) {
            throw new RuntimeException('Shopify customer ID is invalid for birthday write-back.');
        }

        $ownerId = 'gid://shopify/Customer/'.$customerId;
        $metafields = $this->buildMetafieldsPayload($ownerId, $birthday);

        if ($metafields === []) {
            return;
        }

        $client = new ShopifyGraphqlClient($shop, $token, $apiVersion);
        $data = $client->query(self::METAFIELDS_SET_MUTATION, [
            'metafields' => $metafields,
        ]);

        $setPayload = $data['metafieldsSet'] ?? null;
        if (! is_array($setPayload)) {
            throw new RuntimeException('Shopify birthday metafield mutation returned an invalid payload.');
        }

        $userErrors = $setPayload['userErrors'] ?? null;
        if (is_array($userErrors) && $userErrors !== []) {
            $messages = [];
            foreach ($userErrors as $error) {
                if (! is_array($error)) {
                    continue;
                }

                $message = trim((string) ($error['message'] ?? 'unknown_error'));
                if ($message !== '') {
                    $messages[] = $message;
                }
            }

            throw new RuntimeException(
                'Shopify birthday metafield mutation failed: '.($messages !== [] ? implode(' | ', $messages) : 'unknown error')
            );
        }
    }

    /**
     * @return array<int,array{ownerId:string,namespace:string,key:string,type:string,value:string}>
     */
    protected function buildMetafieldsPayload(string $ownerId, CustomerBirthdayProfile $birthday): array
    {
        $namespace = trim((string) config('marketing.shopify.birthday.namespace', 'forestry_marketing'));
        if ($namespace === '') {
            $namespace = 'forestry_marketing';
        }

        $rows = [];

        $rows[] = [
            'ownerId' => $ownerId,
            'namespace' => $namespace,
            'key' => 'birth_month',
            'type' => 'number_integer',
            'value' => $birthday->birth_month !== null ? (string) (int) $birthday->birth_month : '',
        ];

        $rows[] = [
            'ownerId' => $ownerId,
            'namespace' => $namespace,
            'key' => 'birth_day',
            'type' => 'number_integer',
            'value' => $birthday->birth_day !== null ? (string) (int) $birthday->birth_day : '',
        ];

        $rows[] = [
            'ownerId' => $ownerId,
            'namespace' => $namespace,
            'key' => 'birth_year',
            'type' => 'number_integer',
            'value' => $birthday->birth_year !== null ? (string) (int) $birthday->birth_year : '',
        ];

        $rows[] = [
            'ownerId' => $ownerId,
            'namespace' => $namespace,
            'key' => 'birthday_full_date',
            'type' => 'date',
            'value' => $birthday->birthday_full_date ? (string) $birthday->birthday_full_date->toDateString() : '',
        ];

        $rows[] = [
            'ownerId' => $ownerId,
            'namespace' => $namespace,
            'key' => 'birthday_source',
            'type' => 'single_line_text_field',
            'value' => (string) ($birthday->source ?: ''),
        ];

        $rows[] = [
            'ownerId' => $ownerId,
            'namespace' => $namespace,
            'key' => 'birthday_source_captured_at',
            'type' => 'date_time',
            'value' => $this->dateTimeString($birthday->source_captured_at),
        ];

        if ((bool) config('marketing.shopify.birthday.write_growave_aliases', true)) {
            $rows[] = [
                'ownerId' => $ownerId,
                'namespace' => 'growave',
                'key' => 'birthday',
                'type' => 'single_line_text_field',
                'value' => $this->growaveBirthdayValue($birthday),
            ];

            $rows[] = [
                'ownerId' => $ownerId,
                'namespace' => 'ssw',
                'key' => 'birthday',
                'type' => 'single_line_text_field',
                'value' => $this->growaveBirthdayValue($birthday),
            ];
        }

        return array_values(array_filter($rows, function (array $row): bool {
            return trim((string) ($row['value'] ?? '')) !== '';
        }));
    }

    protected function growaveBirthdayValue(CustomerBirthdayProfile $birthday): string
    {
        if ($birthday->birthday_full_date) {
            return (string) $birthday->birthday_full_date->toDateString();
        }

        $month = $birthday->birth_month !== null ? (int) $birthday->birth_month : null;
        $day = $birthday->birth_day !== null ? (int) $birthday->birth_day : null;

        if ($month && $day) {
            return sprintf('%02d/%02d', $month, $day);
        }

        return '';
    }

    protected function dateTimeString(?CarbonInterface $value): string
    {
        return $value ? $value->toIso8601String() : '';
    }

    /**
     * @return array{store_key:string,customer_id:string}|null
     */
    protected function parseSourceId(string $sourceId): ?array
    {
        $sourceId = trim($sourceId);
        if ($sourceId === '') {
            return null;
        }

        if (preg_match('/^(retail|wholesale):(\d+)$/i', $sourceId, $matches) === 1) {
            return [
                'store_key' => strtolower((string) $matches[1]),
                'customer_id' => (string) $matches[2],
            ];
        }

        return null;
    }
}
