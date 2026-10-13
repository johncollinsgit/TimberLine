@php
    $content = is_array($content ?? null) ? $content : [];
    $brandAssets = (array) ($content['brand_assets'] ?? []);
    $mark = asset((string) ($brandAssets['mark'] ?? 'brand/evergrove-mark.svg'));
    $goals = ['website' => 'A website that brings in business', 'operations' => 'A simpler way to run daily work', 'automation' => 'Less repetitive work & fewer handoffs', 'customers' => 'Better customer follow-up', 'ai' => 'Practical AI for my business', 'guidance' => 'Help figuring out where to start'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'app_name' => 'Evergrove Software', 'title' => 'Book a Consultation | Evergrove Software', 'exact_title' => true,
        'description' => 'I help business owners run their business better. Tell us about your business and book a 1-hour video consultation with Evergrove Software.', 'brand_assets' => $brandAssets,
    ])
    @vite(['resources/css/evergrove-booking.css', 'resources/js/evergrove-booking.js'])
</head>
<body class="eg-public-body eg-public-body--launch eg-consultation">
    @include('evergrove.partials.nav')
    <main id="main-content" class="eg-consultation-main">
        <section id="business-brief" class="eg-consultation-hero eg-booking-first" aria-labelledby="booking-title">
            <div class="eg-consultation-copy">
                <p class="eg-kicker">Book your 1-hour video consultation</p>
                <h1 id="booking-title">I help business owners run their business <em>better.</em></h1>
                <p class="eg-consultation-lede">Tell me about your business and what you’d like to improve, then choose a time for your video consultation.</p>
                <p class="eg-consultation-meta">60 minutes <span aria-hidden="true">·</span> Google Meet <span aria-hidden="true">·</span> No obligation</p>
                @if($bookingUrl)<a class="eg-availability-link" href="#choose-time">See available dates &amp; times ↓</a>@endif
                @if($briefSaved)<p>Your brief is saved. Choose your appointment time below to finish booking.</p>@endif
            </div>
            @if (! $briefSaved)
            <form method="POST" action="{{ route('evergrove.book.brief') }}" class="eg-brief-form" data-consultation-form aria-label="Business consultation brief">
                @csrf
                <input type="hidden" name="submission_token" value="{{ $submissionToken }}">
                <div class="eg-form-trap" aria-hidden="true"><label>Leave this blank<input name="contact_fax" autocomplete="off" tabindex="-1"></label></div>
                @if ($errors->getBag('default')->any())<div class="eg-form-errors" role="alert" tabindex="-1"><strong>Please check these answers.</strong><ul>@foreach ($errors->getBag('default')->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <div class="eg-form-progress" data-form-progress hidden><span data-step-label aria-live="polite">1 of 3 · Your business</span><div><i data-progress-fill></i></div></div>
                <fieldset data-brief-step><legend>Your business</legend>
                    <div class="eg-field-pair"><label>Your name <input name="name" value="{{ old('name') }}" autocomplete="name" maxlength="190" required></label><label>Email address <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" maxlength="190" required></label></div>
                    <label>Business name <input name="company" value="{{ old('company') }}" autocomplete="organization" maxlength="190" required></label>
                    <label>What type of business do you run? <input name="business_type" value="{{ old('business_type') }}" placeholder="For example: electrical contractor, shop, nonprofit, consulting" maxlength="190" required></label>
                    <div class="eg-field-pair"><label>Website <span>(optional)</span><input type="url" name="website" value="{{ old('website') }}" placeholder="https://yourbusiness.com" autocomplete="url" maxlength="300"></label><label>How big is your team? <span>(optional)</span><select name="business_size"><option value="">Select team size</option>@foreach(($content['business_sizes'] ?? []) as $value => $label)<option value="{{ $value }}" @selected(old('business_size') === $value)>{{ $label }}</option>@endforeach</select></label></div>
                </fieldset>
                <fieldset data-brief-step><legend>What would you like to improve?</legend><p class="eg-fieldset-note" id="goals-help">Choose anything that fits. It’s okay if you’re still figuring it out.</p>
                    <div class="eg-goal-options" role="group" aria-labelledby="goals-help">@foreach($goals as $value => $label)<label class="eg-goal-option"><input type="checkbox" name="goals[]" value="{{ $value }}" @checked(in_array($value, (array) old('goals', []), true))><span>{{ $label }}</span></label>@endforeach</div>
                    <p data-goal-error class="eg-goal-error" role="alert" hidden>Choose at least one area you’d like help with.</p>
                    <label>What’s the biggest bottleneck in your business right now? <textarea name="pain_point" rows="3" maxlength="3000" placeholder="What takes too much time, falls through the cracks, or frustrates you and your team?" required>{{ old('pain_point') }}</textarea></label>
                    <label>If we could improve one thing, what would success look like? <textarea name="desired_outcome" rows="3" maxlength="1000" placeholder="For example: fewer missed leads, less paperwork, or seeing all our jobs in one place." required>{{ old('desired_outcome') }}</textarea></label>
                </fieldset>
                <fieldset data-brief-step><legend>A little context</legend><p class="eg-fieldset-note">No technical homework needed. Tell me what you know.</p>
                    <label>What tools do you use today? <span>(optional)</span><textarea name="current_tools" rows="3" maxlength="1000" placeholder="Spreadsheets, QuickBooks, Shopify, a CRM, paper, or anything else your team relies on.">{{ old('current_tools') }}</textarea></label>
                    <label>When would you like to get started? <select name="timeline" required><option value="">Choose a timeframe</option>@foreach(($content['timeline_options'] ?? []) as $value => $label)<option value="{{ $value }}" @selected(old('timeline') === $value)>{{ $label }}</option>@endforeach</select></label>
                    <label>Do you have a budget range in mind? <span>(optional)</span><select name="budget_range"><option value="">No range selected</option>@foreach(($content['budget_ranges'] ?? []) as $value => $label)<option value="{{ $value }}" @selected(old('budget_range') === $value)>{{ $label }}</option>@endforeach</select></label>
                    <p class="eg-brief-note">By continuing, you’re sending this brief to Evergrove to prepare for and follow up on your consultation. Please don’t include passwords or sensitive customer information. <a href="/privacy">Privacy policy</a></p>
                </fieldset>
                <div class="eg-form-actions"><button type="button" class="eg-form-back" data-step-back hidden>← Back</button><button type="button" class="eg-button eg-button-primary" data-step-next hidden>Continue <span aria-hidden="true">→</span></button><button type="submit" class="eg-button eg-button-primary" data-brief-submit>{{ $bookingUrl ? 'Save my brief & choose a time' : 'Request my 1-hour video consultation' }} <span aria-hidden="true">→</span></button></div>
                <p class="eg-brief-note">{{ $bookingUrl ? 'Next: choose your appointment in Google Calendar. Your meeting is confirmed only after you finish booking there.' : 'Online scheduling is being finalized. Send your brief and we’ll follow up to arrange your 1-hour video consultation.' }}</p>
            </form>
            @endif
        </section>
        @if($bookingUrl || $briefSaved)
        <section id="choose-time" class="eg-schedule-section" aria-labelledby="schedule-title"><p class="eg-kicker">{{ $briefSaved ? 'Your brief is saved' : 'Live Google Calendar availability' }}</p>
            @if ($bookingUrl)
                <h2 id="schedule-title">Choose a time that works for you.</h2><p>Book your 1-hour Google Meet video consultation below. {{ $briefSaved ? 'Use the same email you entered in your brief.' : 'Send your business brief above so I can prepare, then use the same email to book.' }} Google will send the confirmation and meeting details when you finish booking.</p>
                <p class="eg-brief-note">The calendar shows current available dates and times in your selected time zone. Unavailable times cannot be booked.</p>
                <a href="{{ $bookingUrl }}" class="eg-button eg-button-primary" target="_blank" rel="noopener noreferrer">Choose a Time in Google <span aria-hidden="true">↗</span></a>
                <p class="eg-brief-note">If the calendar doesn’t load below, use the button to open it in a new tab.</p>
                <iframe src="{{ $bookingUrl }}{{ str_contains($bookingUrl, '?') ? '&' : '?' }}gv=true" title="Choose a time for your 1-hour video consultation in Google Calendar" class="eg-google-schedule" loading="lazy" referrerpolicy="no-referrer"></iframe>
            @else
                <h2 id="schedule-title">Let’s find a time to talk.</h2><p>Your consultation request is in. We’ll follow up by email to arrange your 1-hour video conversation. An appointment has not been reserved yet.</p>
            @endif
        </section>
        @endif
        <section id="business-hurdle" class="eg-hurdle-section" aria-labelledby="hurdle-title">
            <div><p class="eg-kicker">Start with a quick note</p><h2 id="hurdle-title">What’s your biggest <em>business hurdle?</em></h2><p>Not ready to book? Tell me what’s making your business harder to run. I’ll follow up to talk about how I can help.</p></div>
            @if(session('hurdle_saved'))<div class="eg-hurdle-success" role="status">{{ session('hurdle_saved') }}</div>@else
            <form class="eg-brief-form eg-hurdle-form" method="POST" action="{{ route('evergrove.book.hurdle') }}">
                @csrf<input type="hidden" name="hurdle_token" value="{{ $hurdleToken }}">
                <div class="eg-form-trap" aria-hidden="true"><label>Leave this blank<input name="contact_fax" autocomplete="off" tabindex="-1"></label></div>
                @if($errors->hurdle->any())<div class="eg-form-errors" role="alert"><strong>Please check your note.</strong><ul>@foreach($errors->hurdle->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <div class="eg-field-pair"><label>Your name<input name="name" autocomplete="name" maxlength="190" required value="{{ $errors->hurdle->any() ? old('name') : '' }}"></label><label>Email address<input type="email" name="email" autocomplete="email" maxlength="190" required value="{{ $errors->hurdle->any() ? old('email') : '' }}"></label></div>
                <label>Business name <span>(optional)</span><input name="company" autocomplete="organization" maxlength="190" value="{{ $errors->hurdle->any() ? old('company') : '' }}"></label>
                <label>What’s the biggest thing making your business harder to run?<textarea name="pain_point" rows="3" maxlength="3000" required placeholder="Missed leads? Too much paperwork? Tools that don’t connect? Tell me in your own words.">{{ $errors->hurdle->any() ? old('pain_point') : '' }}</textarea></label>
                <div class="eg-form-actions"><button type="submit" class="eg-button eg-button-primary">Let’s talk about my business <span aria-hidden="true">→</span></button></div>
                <p class="eg-brief-note">This sends your note to Evergrove so I can respond by email. It does not book an appointment. <a href="/privacy">Privacy policy</a></p>
            </form>@endif
        </section>
        <section class="eg-consultation-agenda" aria-label="What we will cover">
            <div><span>01 / UNDERSTAND</span><h2>Start with your business.</h2><p>How you work today, where you’re headed, and what keeps getting in the way.</p></div>
            <div><span>02 / FIND THE OPPORTUNITY</span><h2>Focus on what matters.</h2><p>Look for practical ways to save time, serve customers, and connect your work.</p></div>
            <div><span>03 / NEXT STEPS</span><h2>Leave with a direction.</h2><p>Discuss the options, priorities, and whether Evergrove is the right fit.</p></div>
        </section>
        <section class="eg-consultation-support" aria-label="How connected tools help your business">
            <div><p class="eg-kicker">A practical first conversation</p><h2>Let’s make your work <em>flow.</em></h2><p>I’m John Collins of Evergrove Software. We’ll talk about what’s slowing you down and how websites, software, automation, or AI could make your day easier.</p></div>
            <div class="eg-work-map" aria-hidden="true">
                <div class="eg-map-label">MAKE THE PIECES WORK TOGETHER</div>
                <svg viewBox="0 0 520 400" fill="none" class="eg-map-lines"><path d="M105 95C260 95 130 205 260 205M415 95C260 95 390 205 260 205M105 305C260 305 130 205 260 205M415 305C260 305 390 205 260 205" stroke="currentColor" stroke-width="2"/><circle r="5" fill="#c7ff69"><animateMotion dur="5s" repeatCount="indefinite" path="M105 95C260 95 130 205 260 205"/></circle><circle r="5" fill="#c7ff69"><animateMotion dur="5s" begin="2s" repeatCount="indefinite" path="M415 305C260 305 390 205 260 205"/></circle></svg>
                <div class="eg-map-node eg-map-node--one"><span>↗</span>Your customers<small>A clearer next step</small></div>
                <div class="eg-map-node eg-map-node--two"><span>◎</span>Your team<small>Work in sync</small></div>
                <div class="eg-map-center"><img src="{{ $mark }}" alt=""><strong>Your business</strong><small>Connected. Clear. Moving.</small></div>
                <div class="eg-map-node eg-map-node--three"><span>⌁</span>Your tools<small>Less double entry</small></div>
                <div class="eg-map-node eg-map-node--four"><span>✓</span>Your time<small>More room to grow</small></div>
                <p class="eg-map-caption">Good technology should make your day easier.</p>
            </div>
        </section>
        <section class="eg-consultation-close"><p>You don’t need to have it all figured out.</p><h2>Start with the business.<br>We’ll work through the technology.</h2></section>
    </main>
    @include('evergrove.partials.footer')
</body>
</html>
