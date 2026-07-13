<?php

return [
    'identity' => [
        'first_name' => 'Faith',
        'last_name' => 'Crocker',
        'email' => 'faith@example.com',
        'normalized_email' => 'faith@example.com',
    ],
    'shopify_link' => [
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:123',
        'canonical_meta' => ['canonical' => true],
        'legacy_meta' => ['legacy' => true],
    ],
    'canonical_birthday' => [
        'birth_month' => 4,
    ],
    'legacy_birthday' => [
        'birth_month' => 4,
        'birth_day' => 12,
        'source' => 'legacy_import',
    ],
];
