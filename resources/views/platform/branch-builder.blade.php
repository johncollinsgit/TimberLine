<!DOCTYPE html>
<html lang="en">
<head>@include('partials.head', ['title' => 'Choose your template'])</head>
<body class="fb-public-body eb-studio-support-body">
    @include('platform.partials.studio-support-nav')
    <main class="eb-builder">
        <header class="eb-builder-heading">
            <h1>Choose a template.</h1>
            <p>Start with your business. Make it yours later.</p>
            <p class="eb-builder-note">{{ config('product_surfaces.public_offer.summary') }}</p>
        </header>
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert">{{ $errors->first() }}</div>@endif
        <form action="{{ route('platform.build.save') }}" method="post" class="eb-builder-layout">
            @csrf
            <div>
                <fieldset class="eb-template-grid">
                    <legend>Your business</legend>
                    @foreach($templates as $template)
                        <label class="eb-template-choice">
                            <input type="radio" name="template" value="{{ $template['key'] }}" required @checked(old('template', $selectedTemplate) === $template['key']) aria-label="{{ $template['label'] }}" aria-describedby="template-description-{{ $template['key'] }}">
                            <img src="{{ asset($template['image']) }}" width="720" height="450" alt="" loading="{{ $loop->index < 2 ? 'eager' : 'lazy' }}" decoding="async">
                            <span class="eb-template-copy"><strong>{{ $template['label'] }}</strong><span id="template-description-{{ $template['key'] }}" title="{{ $template['description'] }}">{{ $template['description'] }}</span></span>
                        </label>
                    @endforeach
                </fieldset>
            </div>
            <aside class="eb-builder-summary" aria-label="Your setup">
                <img src="{{ asset('images/public-site/everbranch-field-owner-office.jpg') }}" width="640" height="400" alt="A business owner planning the day" decoding="async">
                <div>
                    <h2>{{ $summary['name'] }}</h2>
                    <dl>
                        <div><dt>Setup</dt><dd>${{ number_format($summary['setup_cents'] / 100) }}</dd></div>
                        <div><dt>Monthly</dt><dd>${{ number_format($summary['monthly_cents'] / 100) }}</dd></div>
                        <div><dt>Included tools</dt><dd>Ready to set up</dd></div>
                        <div class="eb-builder-total"><dt>Setup + first month</dt><dd>${{ number_format($summary['due_before_tax_cents'] / 100) }}</dd></div>
                    </dl>
                    <p class="eb-builder-note">Before applicable tax. {{ $summary['usage_note'] }}</p>
                    <button class="fb-btn fb-btn-primary" name="continue" value="1">{{ $accountsAvailable ? 'Continue to account' : 'Continue with help' }}</button>
                    <button class="fb-btn fb-btn-secondary" type="submit">Save template</button>
                    <p class="eb-builder-note">Payment and activation are separate steps.</p>
                    <details><summary>Need a hand?</summary><p><a href="{{ route('platform.request-access') }}">Request assisted setup</a></p><p>Teams, Trajectory, and Markets &amp; Makers are priced separately. Existing agreements keep their terms.</p></details>
                </div>
            </aside>
        </form>
    </main>
</body>
</html>
