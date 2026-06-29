<?php

namespace App\Services\Mobile;

use App\Models\Tenant;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ModernForestryMobileCheckoutService
{
    public const TENANT_ID = 1;

    public const MAX_LINES = 20;

    public const MAX_QUANTITY = 99;

    public function __construct(
        protected ModernForestryMobileProductCatalogService $catalog
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    public function checkout(
        array $items,
        ?string $discountCode = null,
        ?string $customerAccessToken = null,
        ?string $customerEmail = null,
        ?string $customerPhone = null
    ): array
    {
        $this->assertModernForestryTenant();

        $store = $this->modernForestryRetailStore();
        $lines = $this->normalizeLines($items);
        $discountCodes = $this->normalizeDiscountCodes($discountCode);
        $customerAccessToken = $this->normalizeCustomerAccessToken($customerAccessToken);
        $customerEmail = $this->normalizeCustomerEmail($customerEmail);
        $customerPhone = $this->normalizeCustomerPhone($customerPhone);
        $storefrontToken = $this->storefrontAccessToken($store);

        if ($storefrontToken === null) {
            return $this->checkoutPermalink($store, $lines, $discountCodes);
        }

        $response = Http::withHeaders([
            'X-Shopify-Storefront-Access-Token' => $storefrontToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])
            ->timeout(30)
            ->post($this->storefrontGraphqlUrl($store), [
                'query' => $this->cartCreateMutation(),
                'variables' => [
                    'input' => array_filter([
                        'lines' => array_map(
                            static fn (array $line): array => array_filter([
                                'merchandiseId' => $line['merchandiseId'],
                                'quantity' => $line['quantity'],
                                'attributes' => ! empty($line['attributes']) ? array_map(
                                    static fn (array $attribute): array => [
                                        'key' => (string) ($attribute['key'] ?? ''),
                                        'value' => (string) ($attribute['value'] ?? ''),
                                    ],
                                    $line['attributes']
                                ) : null,
                            ], static fn (mixed $value): bool => $value !== null && $value !== []),
                            $lines
                        ),
                        'discountCodes' => $discountCodes,
                        'buyerIdentity' => array_filter([
                            'customerAccessToken' => $customerAccessToken,
                            'email' => $customerEmail,
                            'phone' => $customerPhone,
                        ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                        'attributes' => [
                            [
                                'key' => 'source',
                                'value' => 'modern_forestry_ios',
                            ],
                            [
                                'key' => 'tenant_id',
                                'value' => (string) self::TENANT_ID,
                            ],
                        ],
                    ], static fn (mixed $value): bool => $value !== [] && $value !== null),
                ],
            ]);

        if ($response->failed()) {
            throw new ModernForestryMobileCheckoutException(
                'checkout_unavailable',
                'Checkout is temporarily unavailable.',
                503
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new ModernForestryMobileCheckoutException(
                'checkout_unavailable',
                'Checkout is temporarily unavailable.',
                503
            );
        }

        $errors = $payload['errors'] ?? [];
        if (is_array($errors) && $errors !== []) {
            throw new ModernForestryMobileCheckoutException(
                'checkout_unavailable',
                'Checkout is temporarily unavailable.',
                503
            );
        }

        $cartCreate = $payload['data']['cartCreate'] ?? null;
        if (! is_array($cartCreate)) {
            throw new ModernForestryMobileCheckoutException(
                'checkout_unavailable',
                'Checkout is temporarily unavailable.',
                503
            );
        }

        $userErrors = $this->userErrors($cartCreate['userErrors'] ?? []);
        if ($userErrors !== []) {
            throw new ModernForestryMobileCheckoutException(
                'shopify_user_error',
                $userErrors[0],
                422
            );
        }

        $cart = $cartCreate['cart'] ?? null;
        if (! is_array($cart)) {
            throw new ModernForestryMobileCheckoutException(
                'checkout_unavailable',
                'Checkout is temporarily unavailable.',
                503
            );
        }

        $checkoutUrl = trim((string) ($cart['checkoutUrl'] ?? ''));
        if ($checkoutUrl === '') {
            throw new ModernForestryMobileCheckoutException(
                'checkout_unavailable',
                'Checkout is temporarily unavailable.',
                503
            );
        }

        return [
            'checkoutUrl' => $checkoutUrl,
            'cartId' => $this->publicId((string) ($cart['id'] ?? '')),
            'lines' => $this->lineSummaries($lines),
            'subtotal' => $this->moneyAmount($cart['cost']['subtotalAmount'] ?? null),
            'tax' => $this->moneyAmount($cart['cost']['totalTaxAmount'] ?? null),
            'shipping' => $this->deliveryEstimateAmount($cart['deliveryGroups'] ?? null),
            'discount' => $this->discountAmountForCart($cart),
            'total' => $this->moneyAmount($cart['cost']['totalAmount'] ?? null),
            'currencyCode' => $this->currencyCodeForCart($cart),
            'authenticated' => $customerAccessToken !== null,
            'prefilledCustomer' => $this->prefilledCustomer($cart, $customerEmail),
            'discountCodes' => $discountCodes,
            'discountAccepted' => $this->discountCodesAccepted($cart, $discountCodes),
            'errors' => [],
        ];
    }

    protected function assertModernForestryTenant(): Tenant
    {
        $tenant = Tenant::query()
            ->whereKey(self::TENANT_ID)
            ->where('slug', ModernForestryMobileProductCatalogService::TENANT_SLUG)
            ->first();

        if (! $tenant instanceof Tenant) {
            throw new ModernForestryMobileCheckoutException(
                'tenant_unavailable',
                'Modern Forestry checkout is temporarily unavailable.',
                503
            );
        }

        return $tenant;
    }

    /**
     * @return array<string,mixed>
     */
    protected function modernForestryRetailStore(): array
    {
        $store = ShopifyStores::find('retail');

        if (! is_array($store) || (int) ($store['tenant_id'] ?? 0) !== self::TENANT_ID) {
            throw new ModernForestryMobileCheckoutException(
                'store_unavailable',
                'Modern Forestry checkout is temporarily unavailable.',
                503
            );
        }

        return $store;
    }

    /**
     * @param  array<string,mixed>  $store
     */
    protected function storefrontAccessToken(array $store): ?string
    {
        $token = trim((string) ($store['storefront_access_token'] ?? ''));

        if ($token === '') {
            return null;
        }

        return $token;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    protected function normalizeLines(array $items): array
    {
        if ($items === [] || count($items) > self::MAX_LINES) {
            throw new ModernForestryMobileCheckoutException(
                'invalid_items',
                'Add at least one available product to your bag.'
            );
        }

        $lines = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new ModernForestryMobileCheckoutException('invalid_items', 'Your bag needs a quick refresh.');
            }

            $handle = $this->normalizeHandle($item['productHandle'] ?? null);
            $variantId = $this->numericId($item['variantId'] ?? null);
            $quantity = $this->normalizeQuantity($item['quantity'] ?? null);

            if ($handle === null || $variantId === null || $quantity === null) {
                throw new ModernForestryMobileCheckoutException('invalid_items', 'Your bag needs a quick refresh.');
            }

            $product = $this->catalog->productDetail($handle);
            if (! is_array($product)) {
                throw new ModernForestryMobileCheckoutException('product_not_found', 'This product is no longer available.');
            }

            $variant = $this->variantById($product, $variantId);
            if ($variant === null || ! (bool) ($variant['available'] ?? false)) {
                throw new ModernForestryMobileCheckoutException('variant_unavailable', 'One item in your bag is no longer available.');
            }

            $key = $handle.':'.$variantId;
            if (isset($lines[$key])) {
                $lines[$key]['quantity'] = min(self::MAX_QUANTITY, $lines[$key]['quantity'] + $quantity);
                if (! empty($item['attributes']) && $lines[$key]['attributes'] === []) {
                    $lines[$key]['attributes'] = $this->normalizeAttributes($item['attributes']);
                }
                continue;
            }

            $lines[$key] = [
                'productHandle' => $handle,
                'productTitle' => (string) ($product['title'] ?? ''),
                'variantId' => $variantId,
                'variantTitle' => (string) ($variant['title'] ?? ''),
                'quantity' => $quantity,
                'price' => $variant['price'] ?? null,
                'merchandiseId' => $this->variantGid($variantId),
                'attributes' => $this->normalizeAttributes($item['attributes'] ?? []),
            ];
        }

        return array_values($lines);
    }

    /**
     * @param mixed $attributes
     * @return array<int,array{key:string,value:string}>
     */
    protected function normalizeAttributes(mixed $attributes): array
    {
        if (! is_array($attributes)) {
            return [];
        }

        $normalized = [];

        foreach ($attributes as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $key = trim((string) ($attribute['key'] ?? ''));
            $value = trim((string) ($attribute['value'] ?? ''));
            if ($key === '' || $value === '') {
                continue;
            }

            $normalized[] = [
                'key' => $key,
                'value' => $value,
            ];
        }

        return $normalized;
    }

    protected function normalizeHandle(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $handle = strtolower(trim((string) $value));

        return preg_match('/\A[a-z0-9][a-z0-9-]*\z/', $handle) === 1 ? $handle : null;
    }

    protected function normalizeQuantity(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $quantity = (int) $value;

        return $quantity >= 1 && $quantity <= self::MAX_QUANTITY ? $quantity : null;
    }

    protected function numericId(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $candidate = trim((string) $value);
        $parts = explode('/', $candidate);
        $id = trim((string) end($parts));

        return preg_match('/\A[0-9]+\z/', $id) === 1 ? $id : null;
    }

    /**
     * @param  array<string,mixed>  $product
     * @return array<string,mixed>|null
     */
    protected function variantById(array $product, string $variantId): ?array
    {
        $variants = $product['variants'] ?? [];
        if (! is_array($variants)) {
            return null;
        }

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            if ($this->numericId($variant['id'] ?? null) === $variantId) {
                return $variant;
            }
        }

        return null;
    }

    protected function variantGid(string $variantId): string
    {
        return 'gid://shopify/ProductVariant/'.$variantId;
    }

    /**
     * @return array<int,string>
     */
    protected function normalizeDiscountCodes(?string $discountCode): array
    {
        $code = strtoupper(trim((string) $discountCode));

        return $code !== '' && strlen($code) <= 80 ? [$code] : [];
    }

    protected function normalizeCustomerAccessToken(?string $token): ?string
    {
        $token = trim((string) $token);

        return $token !== '' && strlen($token) <= 4096 ? $token : null;
    }

    protected function normalizeCustomerEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    protected function normalizeCustomerPhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        return $phone !== '' && preg_match('/\A[0-9+().\-\s]{7,40}\z/', $phone) === 1 ? $phone : null;
    }

    /**
     * @param  array<string,mixed>  $store
     */
    protected function storefrontGraphqlUrl(array $store): string
    {
        return 'https://'.rtrim((string) $store['shop'], '/').'/api/'
            .((string) ($store['api_version'] ?? config('services.shopify.api_version', '2026-01')))
            .'/graphql.json';
    }

    /**
     * @param  array<string,mixed>  $store
     * @param  array<int,array<string,mixed>>  $lines
     * @param  array<int,string>  $discountCodes
     * @return array<string,mixed>
     */
    protected function checkoutPermalink(array $store, array $lines, array $discountCodes): array
    {
        $cartPath = implode(',', array_map(
            static fn (array $line): string => rawurlencode((string) $line['variantId']).':'.((int) $line['quantity']),
            $lines
        ));

        $query = array_filter([
            'discount' => $discountCodes[0] ?? null,
            'attributes[source]' => 'modern_forestry_ios',
            'attributes[tenant_id]' => (string) self::TENANT_ID,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        $checkoutUrl = 'https://'.rtrim((string) $store['shop'], '/').'/cart/'.$cartPath;
        if ($query !== []) {
            $checkoutUrl .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return [
            'checkoutUrl' => $checkoutUrl,
            'cartId' => 'cart-permalink',
            'lines' => $this->lineSummaries($lines),
            'subtotal' => $this->estimatedSubtotal($lines),
            'tax' => null,
            'shipping' => null,
            'discount' => null,
            'total' => null,
            'currencyCode' => 'USD',
            'authenticated' => false,
            'prefilledCustomer' => false,
            'discountCodes' => $discountCodes,
            'discountAccepted' => $discountCodes === [],
            'errors' => [],
        ];
    }

    protected function cartCreateMutation(): string
    {
        return <<<'GRAPHQL'
mutation ModernForestryMobileCartCreate($input: CartInput!) {
  cartCreate(input: $input) {
    cart {
      id
      checkoutUrl
      cost {
        subtotalAmount {
          amount
          currencyCode
        }
        totalTaxAmount {
          amount
          currencyCode
        }
        totalAmount {
          amount
          currencyCode
        }
      }
      buyerIdentity {
        email
        phone
        customer {
          id
        }
      }
      discountCodes {
        code
        applicable
      }
      deliveryGroups(first: 1) {
        edges {
          node {
            deliveryOptions {
              estimatedCost {
                amount
                currencyCode
              }
            }
          }
        }
      }
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;
    }

    /**
     * @param  array<int,array<string,mixed>>  $lines
     * @return array<int,array<string,mixed>>
     */
    protected function lineSummaries(array $lines): array
    {
        return array_map(static fn (array $line): array => [
            'productHandle' => $line['productHandle'],
            'productTitle' => $line['productTitle'],
            'variantId' => $line['variantId'],
            'variantTitle' => $line['variantTitle'],
            'quantity' => $line['quantity'],
            'price' => $line['price'],
        ], $lines);
    }

    /**
     * @param  mixed  $money
     * @return array{amount:string,currencyCode:string}|null
     */
    protected function moneyAmount(mixed $money): ?array
    {
        if (! is_array($money)) {
            return null;
        }

        $amount = trim((string) ($money['amount'] ?? ''));
        $currencyCode = trim((string) ($money['currencyCode'] ?? ''));

        if ($amount === '' || $currencyCode === '') {
            return null;
        }

        return [
            'amount' => $amount,
            'currencyCode' => $currencyCode,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $lines
     * @return array{amount:string,currencyCode:string}|null
     */
    protected function estimatedSubtotal(array $lines): ?array
    {
        $subtotal = 0.0;

        foreach ($lines as $line) {
            $price = $line['price'] ?? null;
            if (! is_numeric($price)) {
                return null;
            }

            $subtotal += ((float) $price) * ((int) ($line['quantity'] ?? 1));
        }

        return [
            'amount' => number_format($subtotal, 2, '.', ''),
            'currencyCode' => 'USD',
        ];
    }

    /**
     * @param  mixed  $deliveryGroups
     * @return array{amount:string,currencyCode:string}|null
     */
    protected function deliveryEstimateAmount(mixed $deliveryGroups): ?array
    {
        if (! is_array($deliveryGroups)) {
            return null;
        }

        $edges = $deliveryGroups['edges'] ?? null;
        if (! is_array($edges)) {
            return null;
        }

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $node = $edge['node'] ?? null;
            if (! is_array($node)) {
                continue;
            }

            $deliveryOptions = $node['deliveryOptions'] ?? null;
            if (! is_array($deliveryOptions)) {
                continue;
            }

            foreach ($deliveryOptions as $option) {
                if (! is_array($option)) {
                    continue;
                }

                $estimate = $this->moneyAmount($option['estimatedCost'] ?? null);
                if ($estimate !== null) {
                    return $estimate;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $cart
     * @return array{amount:string,currencyCode:string}|null
     */
    protected function discountAmountForCart(array $cart): ?array
    {
        $subtotal = $this->moneyAmount($cart['cost']['subtotalAmount'] ?? null);
        $tax = $this->moneyAmount($cart['cost']['totalTaxAmount'] ?? null);
        $shipping = $this->deliveryEstimateAmount($cart['deliveryGroups'] ?? null);
        $total = $this->moneyAmount($cart['cost']['totalAmount'] ?? null);

        if ($subtotal === null || $total === null) {
            return null;
        }

        $currencyCode = $subtotal['currencyCode'];
        $baseline = (float) $subtotal['amount']
            + (float) ($tax['amount'] ?? 0)
            + (float) ($shipping['amount'] ?? 0);
        $discount = max(0, round($baseline - (float) $total['amount'], 2));

        if ($discount <= 0) {
            return null;
        }

        return [
            'amount' => number_format($discount, 2, '.', ''),
            'currencyCode' => $currencyCode,
        ];
    }

    /**
     * @param  array<string,mixed>  $cart
     * @param  array<int,string>  $requestedCodes
     */
    protected function discountCodesAccepted(array $cart, array $requestedCodes): bool
    {
        if ($requestedCodes === []) {
            return true;
        }

        $discountCodes = $cart['discountCodes'] ?? null;
        if (! is_array($discountCodes)) {
            return false;
        }

        $applicableCodes = collect($discountCodes)
            ->filter(fn (mixed $code): bool => is_array($code) && (bool) ($code['applicable'] ?? false))
            ->map(fn (array $code): string => strtoupper(trim((string) ($code['code'] ?? ''))))
            ->filter()
            ->values()
            ->all();

        foreach ($requestedCodes as $requestedCode) {
            if (! in_array(strtoupper($requestedCode), $applicableCodes, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $cart
     */
    protected function currencyCodeForCart(array $cart): ?string
    {
        return $this->moneyAmount($cart['cost']['totalAmount'] ?? null)['currencyCode']
            ?? $this->moneyAmount($cart['cost']['subtotalAmount'] ?? null)['currencyCode']
            ?? null;
    }

    /**
     * @param  array<string,mixed>  $cart
     */
    protected function prefilledCustomer(array $cart, ?string $customerEmail = null): bool
    {
        $buyerIdentity = $cart['buyerIdentity'] ?? null;
        if (! is_array($buyerIdentity)) {
            return $customerEmail !== null;
        }

        $buyerEmail = trim((string) ($buyerIdentity['email'] ?? ''));
        if ($buyerEmail !== '') {
            return true;
        }

        return is_array($buyerIdentity['customer'] ?? null);
    }

    /**
     * @return array<int,string>
     */
    protected function userErrors(mixed $errors): array
    {
        if (! is_array($errors)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $error): ?string => is_array($error) && trim((string) ($error['message'] ?? '')) !== ''
                ? trim((string) $error['message'])
                : null,
            $errors
        )));
    }

    protected function publicId(string $gid): string
    {
        $parts = explode('/', trim($gid));
        $id = trim((string) end($parts));

        return $id !== '' ? $id : trim($gid);
    }
}
