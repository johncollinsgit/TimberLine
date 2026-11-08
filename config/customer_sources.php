<?php

return [
    'square' => [
        // Register this dedicated HTTPS callback on the existing Square application.
        // Source reads do not require or enable the Pay checkout capability.
        'redirect_uri' => env('CUSTOMER_SQUARE_OAUTH_REDIRECT_URI'),
        'environment' => env('CUSTOMER_SQUARE_ENVIRONMENT', env('POS_SQUARE_ENVIRONMENT', 'sandbox')),
    ],
];
