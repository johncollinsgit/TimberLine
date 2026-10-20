<?php

return [
    // Profile choices describe the organization; they never grant product access.
    'categories' => [
        'field_service' => 'Trades & field service',
        'retail' => 'Retail & makers',
        'nonprofit' => 'Nonprofit & community',
        'education' => 'School & co-op',
        'other' => 'Other organization',
    ],
    'specialties' => [
        'field_service' => ['Electrical', 'Plumbing', 'HVAC', 'Landscaping', 'Other'],
        'retail' => ['Handmade goods', 'Specialty retail', 'Food & drink', 'Other'],
        'nonprofit' => ['Community services', 'Membership organization', 'Other'],
        'education' => ['School', 'Co-op', 'Classes & activities', 'Other'],
    ],
    'models' => ['services' => 'Services', 'products' => 'Products', 'both' => 'Products and services'],
    'channels' => ['online' => 'Online', 'in_person' => 'In person', 'on_site' => 'At a customer’s location', 'wholesale' => 'Wholesale'],
    'customer_types' => ['consumers' => 'Individuals & families', 'businesses' => 'Businesses & organizations'],
];
