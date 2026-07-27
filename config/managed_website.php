<?php

$bool = static fn (string $key, bool $default = false): bool => filter_var(
    env($key, $default),
    FILTER_VALIDATE_BOOL,
    FILTER_NULL_ON_FAILURE
) ?? $default;

$tenantIds = static function (string $key): array {
    return collect(explode(',', (string) env($key, '')))
        ->map(static fn (string $value): int => (int) trim($value))
        ->filter(static fn (int $id): bool => $id > 0)
        ->unique()
        ->values()
        ->all();
};

return [
    // Every gate defaults to false. Empty allowlists never mean "everyone".
    'commerce_enabled' => $bool('MANAGED_WEBSITE_COMMERCE_ENABLED'),
    'editor_enabled' => $bool('MANAGED_WEBSITE_EDITOR_ENABLED'),
    'publishing_enabled' => $bool('MANAGED_WEBSITE_PUBLISHING_ENABLED'),
    'public_render_enabled' => $bool('MANAGED_WEBSITE_PUBLIC_RENDER_ENABLED'),
    'editor_tenant_ids' => $tenantIds('MANAGED_WEBSITE_EDITOR_TENANT_IDS'),
    // Commerce is a distinct, tenant-owned lane. Its webhooks and checkout
    // fail closed until the global gate, tenant entitlement, Connect readiness,
    // tax decision, and dedicated endpoint secret are all present.
    'stripe_webhook_secret' => env('MANAGED_WEBSITE_STRIPE_WEBHOOK_SECRET'),
    'allowed_blocks' => ['announcement', 'header', 'hero', 'text', 'image', 'services', 'testimonial', 'faq', 'contact_form', 'cta', 'product_grid', 'footer'],
    'cache_seconds' => max(60, (int) env('MANAGED_WEBSITE_PUBLIC_CACHE_SECONDS', 300)),
];
