<?php

return [
    'templates' => [
        'wholesale_application' => [
            'key' => 'wholesale_application',
            'name' => 'Wholesale Application',
            'description' => 'Wholesale-only intake form for prospective stockists and buyers.',
            'status' => 'active',
            'visibility' => 'internal',
            'handler_key' => 'wholesale_application',
            'schema' => [
                'version' => 1,
                'fields' => [
                    ['key' => 'name', 'type' => 'text', 'label' => 'Full name', 'required' => true],
                    ['key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
                    ['key' => 'phone', 'type' => 'text', 'label' => 'Phone', 'required' => true],
                    ['key' => 'company', 'type' => 'text', 'label' => 'Company', 'required' => true],
                    ['key' => 'store_type', 'type' => 'text', 'label' => 'Store type', 'required' => false],
                    ['key' => 'website', 'type' => 'url', 'label' => 'Website', 'required' => false],
                    ['key' => 'address', 'type' => 'text', 'label' => 'Address', 'required' => false],
                    ['key' => 'address2', 'type' => 'text', 'label' => 'Address line 2', 'required' => false],
                    ['key' => 'city', 'type' => 'text', 'label' => 'City', 'required' => false],
                    ['key' => 'state', 'type' => 'text', 'label' => 'State', 'required' => false],
                    ['key' => 'zip', 'type' => 'text', 'label' => 'Postal / ZIP', 'required' => false],
                    ['key' => 'country', 'type' => 'text', 'label' => 'Country', 'required' => false],
                    ['key' => 'position', 'type' => 'text', 'label' => 'Position', 'required' => false],
                    ['key' => 'referral', 'type' => 'text', 'label' => 'Referral source', 'required' => false],
                    ['key' => 'current_suppliers', 'type' => 'text', 'label' => 'Current suppliers', 'required' => false],
                    ['key' => 'retail_license_number', 'type' => 'text', 'label' => 'Retail license / resale number', 'required' => false],
                    ['key' => 'contact_preference', 'type' => 'select', 'label' => 'Contact preference', 'required' => false],
                    ['key' => 'business_info', 'type' => 'textarea', 'label' => 'Business info', 'required' => false],
                    ['key' => 'agreement', 'type' => 'checkbox', 'label' => 'Agreement accepted', 'required' => true],
                ],
            ],
            'default_form' => [
                'slug' => 'wholesale-application',
                'name' => 'Wholesale Application',
                'description' => 'Modern Forestry Wholesale application intake.',
                'status' => 'active',
                'channel' => 'wholesale_storefront',
                'destination' => [
                    'review_email' => env('WHOLESALE_APPLICATION_REVIEW_EMAIL', 'modernforestryteam@gmail.com'),
                    'create_shopify_customer' => true,
                    'apply_wholesale_tag_on_submit' => false,
                ],
            ],
        ],
    ],
];
