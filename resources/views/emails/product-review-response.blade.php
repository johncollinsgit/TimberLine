@php
    /** @var \App\Models\MarketingReviewHistory $review */
    $response = (string) ($review->admin_response ?? '');
@endphp

@component('mail::message')
# We replied to your review

Hi {{ $customerName }},

Thanks for sharing your thoughts about {{ $productTitle }}. Our team just left a response:

@component('mail::panel')
{{ $response }}
@endcomponent

Review summary:
- Rating: {{ $review->rating }} star{{ (int) $review->rating === 1 ? '' : 's' }}
@if($review->title)
- Title: {{ $review->title }}
@endif

@if($reviewUrl)
You can view your review here:

@component('mail::button', ['url' => $reviewUrl])
View review
@endcomponent
@endif

Thank you for helping {{ $merchantName }} improve.

— {{ $merchantName }} Team
@endcomponent
