<?php

namespace App\Services\Mobile;

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Shopify\ShopifyStores;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Support\Facades\Http;

class ModernForestryMobileCheckoutService
{
    public const TENANT_ID = 1;

    public const MAX_LINES = 20;

    public const MAX_QUANTITY = 99;

    protected const COUNTRY_CODE_ALIASES = [
        'AUSTRALIA' => 'AU',
        'CANADA' => 'CA',
        'ENGLAND' => 'GB',
        'GREATBRITAIN' => 'GB',
        'MEXICO' => 'MX',
        'NEWZEALAND' => 'NZ',
        'UNITEDKINGDOM' => 'GB',
        'UNITEDSTATES' => 'US',
        'UNITEDSTATESOFAMERICA' => 'US',
        'USA' => 'US',
    ];

    public function __construct(
        protected ModernForestryMobileProductCatalogService $catalog,
        protected MarketingIdentityNormalizer $identityNormalizer
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
        ?string $customerPhone = null,
        ?ModernForestryMobileCustomerSession $session = null,
        ?string $buyerIp = null
    ): array
    {
        $this->assertModernForestryTenant();

        $store = $this->modernForestryRetailStore();
        $lines = $this->normalizeLines($items);
        $discountCodes = $this->normalizeDiscountCodes($discountCode);
        $customerContext = $this->customerContext(
            $session?->profile,
            $this->normalizeCustomerAccessToken($customerAccessToken ?? $session?->accessToken),
            $customerEmail,
            $customerPhone
        );
        $storefrontToken = $this->storefrontAccessToken($store);

        if ($storefrontToken === null) {
            return $this->checkoutPermalink($store, $lines, $discountCodes);
        }

        $headers = [
            'X-Shopify-Storefront-Access-Token' => $storefrontToken,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $buyerIp = $this->normalizeBuyerIp($buyerIp);
        if ($buyerIp !== null) {
            $headers['Shopify-Storefront-Buyer-IP'] = $buyerIp;
        }

        $response = Http::withHeaders($headers)
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
                        'buyerIdentity' => $this->buyerIdentityInput($customerContext),
                        'delivery' => $this->deliveryInput($customerContext),
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

        if (($customerContext['accessToken'] ?? null) !== null) {
            $cart = $this->refreshCheckoutUrlForCart($store, $headers, $cart) ?? $cart;
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
            'authenticated' => $customerContext['accessToken'] !== null,
            'prefilledCustomer' => $this->prefilledCustomer($cart, $customerContext),
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
        countryCode
        email
        phone
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

    protected function cartCheckoutUrlQuery(): string
    {
        return <<<'GRAPHQL'
query ModernForestryMobileCartCheckoutUrl($id: ID!) {
  cart(id: $id) {
    checkoutUrl
    buyerIdentity {
      countryCode
      email
      phone
    }
  }
}
GRAPHQL;
    }

    /**
     * @return array{
     *   accessToken:?string,
     *   email:?string,
     *   phone:?string,
     *   countryCode:?string,
     *   delivery:?array<string,mixed>|null,
     *   prefilled:bool
     * }
     */
    protected function customerContext(
        ?MarketingProfile $profile,
        ?string $customerAccessToken,
        ?string $customerEmail,
        ?string $customerPhone
    ): array {
        $email = $this->normalizeCustomerEmail($customerEmail) ?? $this->profileEmail($profile);
        $phone = $this->preferredBuyerPhone($customerPhone)
            ?? $this->preferredBuyerPhone($profile?->normalized_phone ?: $profile?->phone);
        $countryCode = $this->countryCodeFromProfile($profile);
        $delivery = $this->deliveryAddressInput($profile, $customerPhone);

        return [
            'accessToken' => $customerAccessToken,
            'email' => $email,
            'phone' => $phone,
            'countryCode' => $countryCode,
            'delivery' => $delivery,
            'prefilled' => $customerAccessToken !== null
                || $email !== null
                || $phone !== null
                || $delivery !== null,
        ];
    }

    /**
     * @param  array{
     *   accessToken:?string,
     *   email:?string,
     *   phone:?string,
     *   countryCode:?string
     * }  $customerContext
     * @return array<string,mixed>|null
     */
    protected function buyerIdentityInput(array $customerContext): ?array
    {
        $buyerIdentity = array_filter([
            'customerAccessToken' => $customerContext['accessToken'] ?? null,
            'email' => $customerContext['email'] ?? null,
            'phone' => $customerContext['phone'] ?? null,
            'countryCode' => $customerContext['countryCode'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return $buyerIdentity !== [] ? $buyerIdentity : null;
    }

    /**
     * @param  array{delivery:?array<string,mixed>}  $customerContext
     * @return array<string,mixed>|null
     */
    protected function deliveryInput(array $customerContext): ?array
    {
        $delivery = $customerContext['delivery'] ?? null;

        return is_array($delivery) && $delivery !== [] ? $delivery : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function deliveryAddressInput(?MarketingProfile $profile, ?string $customerPhone = null): ?array
    {
        if (! $profile instanceof MarketingProfile) {
            return null;
        }

        $countryCode = $this->countryCodeFromProfile($profile);
        if ($countryCode === null) {
            return null;
        }

        $address = array_filter([
            'address1' => $this->nullableString($profile->address_line_1),
            'address2' => $this->nullableString($profile->address_line_2),
            'city' => $this->nullableString($profile->city),
            'countryCode' => $countryCode,
            'firstName' => $this->nullableString($profile->first_name),
            'lastName' => $this->nullableString($profile->last_name),
            'phone' => $this->deliveryPhone($customerPhone, $profile),
            'provinceCode' => $this->nullableString($profile->state),
            'zip' => $this->nullableString($profile->postal_code),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        if (! $this->addressHasDestination($address)) {
            return null;
        }

        return [
            'addresses' => [
                [
                    'address' => [
                        'deliveryAddress' => $address,
                    ],
                    'selected' => true,
                    'oneTimeUse' => true,
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $address
     */
    protected function addressHasDestination(array $address): bool
    {
        return ($address['countryCode'] ?? null) !== null
            && array_filter([
                $address['address1'] ?? null,
                $address['city'] ?? null,
                $address['provinceCode'] ?? null,
                $address['zip'] ?? null,
            ], static fn (?string $value): bool => $value !== null && $value !== '') !== [];
    }

    protected function deliveryPhone(?string $customerPhone, MarketingProfile $profile): ?string
    {
        return $this->e164Phone($customerPhone)
            ?? $this->e164Phone((string) ($profile->normalized_phone ?: $profile->phone));
    }

    protected function preferredBuyerPhone(?string $value): ?string
    {
        return $this->e164Phone($value);
    }

    protected function e164Phone(?string $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        if (preg_match('/\A\+[1-9]\d{7,14}\z/', $value) === 1) {
            return $value;
        }

        return $this->identityNormalizer->toE164($value);
    }

    protected function profileEmail(?MarketingProfile $profile): ?string
    {
        return $this->normalizeCustomerEmail(
            $profile?->email ?: $profile?->normalized_email
        );
    }

    protected function countryCodeFromProfile(?MarketingProfile $profile): ?string
    {
        return $this->countryCode($profile?->country);
    }

    protected function countryCode(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $country = strtoupper(trim((string) $value));
        if ($country === '') {
            return null;
        }

        if (preg_match('/\A[A-Z]{2}\z/', $country) === 1) {
            return $country;
        }

        $lookup = preg_replace('/[^A-Z]/', '', $country) ?? '';

        return self::COUNTRY_CODE_ALIASES[$lookup] ?? null;
    }

    protected function normalizeBuyerIp(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $ip = trim((string) $value);

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
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
     * @param  array<string,mixed>  $store
     * @param  array<string,string>  $headers
     * @param  array<string,mixed>  $cart
     * @return array<string,mixed>|null
     */
    protected function refreshCheckoutUrlForCart(array $store, array $headers, array $cart): ?array
    {
        $cartId = trim((string) ($cart['id'] ?? ''));
        if ($cartId === '') {
            return null;
        }

        $response = Http::withHeaders($headers)
            ->timeout(15)
            ->post($this->storefrontGraphqlUrl($store), [
                'query' => $this->cartCheckoutUrlQuery(),
                'variables' => [
                    'id' => $cartId,
                ],
            ]);

        if ($response->failed()) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload) || ! empty($payload['errors'])) {
            return null;
        }

        $refreshedCart = $payload['data']['cart'] ?? null;
        if (! is_array($refreshedCart)) {
            return null;
        }

        $checkoutUrl = trim((string) ($refreshedCart['checkoutUrl'] ?? ''));
        if ($checkoutUrl === '') {
            return null;
        }

        return array_replace_recursive($cart, $refreshedCart);
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
    protected function prefilledCustomer(array $cart, array $customerContext = []): bool
    {
        $buyerIdentity = $cart['buyerIdentity'] ?? null;
        if (! is_array($buyerIdentity)) {
            return (bool) ($customerContext['prefilled'] ?? false);
        }

        $buyerEmail = trim((string) ($buyerIdentity['email'] ?? ''));
        if ($buyerEmail !== '') {
            return true;
        }

        $buyerPhone = trim((string) ($buyerIdentity['phone'] ?? ''));
        if ($buyerPhone !== '') {
            return true;
        }

        $countryCode = trim((string) ($buyerIdentity['countryCode'] ?? ''));
        if ($countryCode !== '') {
            return true;
        }

        return is_array($buyerIdentity['customer'] ?? null)
            || (bool) ($customerContext['prefilled'] ?? false);
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

    protected function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
