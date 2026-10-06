<?php

return [
    'sender' => [
        'name' => env('LANDLORD_PROSPECT_SENDER_NAME', 'John Collins'),
        'email' => env('LANDLORD_PROSPECT_SENDER_EMAIL', 'john@evergrovesoftware.com'),
        'company' => env('LANDLORD_PROSPECT_SENDER_COMPANY', 'Evergrove Software'),
        'location' => env('LANDLORD_PROSPECT_SENDER_LOCATION', 'Powdersville'),
        'booking_url' => env(
            'LANDLORD_PROSPECT_BOOKING_URL',
            'https://calendar.google.com/calendar/u/0/appointments/schedules/AcZssZ3Oj4ptqrCIm0a2aVd1ud7GtVJszMBUYKIqCYME5NP7YnoUr16UtKpslsPJNs2b117OnQqce7X0'
        ),
    ],

    'default_follow_up_days' => 4,

    'cadence' => [
        ['day' => 1, 'label' => 'Relevant first touch', 'template' => 'first_touch', 'channel' => 'Email or call'],
        ['day' => 4, 'label' => 'Useful follow-up', 'template' => 'follow_up', 'channel' => 'Email'],
        ['day' => 10, 'label' => 'Different angle', 'template' => 'website_gap', 'channel' => 'Email or call'],
        ['day' => 21, 'label' => 'Close the loop', 'template' => 'close_loop', 'channel' => 'Email'],
    ],

    'photo' => [
        'url' => 'https://images.unsplash.com/photo-1778074762033-c6595907684d?auto=format&fit=crop&w=1600&q=82',
        'alt' => 'Two construction workers reviewing plans on a tablet',
        'credit_label' => 'Photo by alan boyce on Unsplash',
        'credit_url' => 'https://unsplash.com/photos/two-construction-workers-review-plans-on-a-tablet-BNWApafHwgI',
    ],
];
