<?php

return [
    'shopify_drafts_enabled' => (bool) env('WEBSITE_DROPSHIP_SHOPIFY_DRAFTS_ENABLED', false),

    // Supplier-owned connections indexed by seller tenant ID, site ID and supplier key.
    // Each entry has shop_domain, shop_id and admin_token. Automatic submission requires "automatic": true.
    // Use a dedicated supplier connection.
    'shopify_suppliers' => json_decode((string) env('WEBSITE_DROPSHIP_SHOPIFY_SUPPLIERS', ''), true) ?? [],
];
