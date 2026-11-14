<!DOCTYPE html>
<html lang="en">
<head>@include('partials.head', ['title' => 'Build your Everbranch'])</head>
<body class="fb-public-body eb-studio-support-body">
    @include('platform.partials.studio-support-nav')
    <main class="eb-builder">
        <header class="eb-builder-heading">
            <h1>Make it yours.</h1>
            <p>Choose a few branches. Add more when you need them.</p>
            <p class="eb-builder-note">{{ config('product_surfaces.public_offer.summary') }}</p>
        </header>
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert">{{ $errors->first() }}</div>@endif
        <form action="{{ route('platform.build.save') }}" method="post" class="eb-builder-layout">
            @csrf
            <div>
                <fieldset class="eb-builder-branches">
                    <legend>Included branches</legend>
                    @foreach($modules as $branch)
                        <label class="eb-branch-choice">
                            <input type="checkbox" name="branches[]" value="{{ $branch['key'] }}" @checked(in_array($branch['key'], old('branches', $summary['selected']), true))>
                            <span><strong>{{ $branch['display_name'] }}</strong><span>{{ $branch['short_description'] }}</span><small>Included · {{ $branch['setup_effort_label'] }}</small>
                            @if($branch['dependencies'] !== [])<small>Needs {{ implode(', ', $branch['dependencies']) }}</small>@endif</span>
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
                        <div><dt>Core branches</dt><dd>Included</dd></div>
                        <div class="eb-builder-total"><dt>Setup + first month</dt><dd>${{ number_format($summary['due_before_tax_cents'] / 100) }}</dd></div>
                    </dl>
                    <p class="eb-builder-note">Before applicable tax. {{ $summary['usage_note'] }}</p>
                    <button class="fb-btn fb-btn-primary" name="continue" value="1">{{ $accountsAvailable ? 'Continue to account' : 'Continue with help' }}</button>
                    <button class="fb-btn fb-btn-secondary" type="submit">Save choices</button>
                    <p class="eb-builder-note">Your choices are a setup plan. Payment and activation are separate steps.</p>
                    <details><summary>Need a hand?</summary><p><a href="{{ route('platform.request-access') }}">Request assisted setup</a></p><p>Teams, Trajectory, and Markets &amp; Makers are priced separately. Existing agreements keep their terms.</p></details>
                </div>
            </aside>
        </form>
    </main>
</body>
</html>
