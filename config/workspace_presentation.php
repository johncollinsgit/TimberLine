<?php

return [
    // Fail closed until the Everbranch owner is explicitly configured.
    'owner_email' => env('EVERBRANCH_WORKSPACE_TEMPLATE_OWNER_EMAIL'),
    'templates' => [
        'generic' => ['label' => 'General workspace', 'collection' => 'Everbranch', 'photo' => null],
        'field' => ['label' => 'Field & service', 'collection' => 'Everbranch Field', 'photo' => 'images/public-site/everbranch-industry-field-service-exterior.jpg'],
        'care' => ['label' => 'Care & practices', 'collection' => 'Everbranch Care', 'photo' => 'images/workspace-templates/care.webp'],
        'studio' => ['label' => 'Studio & creative', 'collection' => 'Everbranch Studio', 'photo' => 'images/public-site/everbranch-industry-studios.jpg'],
        'nonprofit' => ['label' => 'Nonprofit & community', 'collection' => 'Everbranch Nonprofit', 'photo' => 'images/workspace-templates/nonprofit.webp'],
        'retail' => ['label' => 'Retail & products', 'collection' => 'Everbranch Retail', 'photo' => 'images/public-site/everbranch-industry-retail.jpg'],
    ],
];
