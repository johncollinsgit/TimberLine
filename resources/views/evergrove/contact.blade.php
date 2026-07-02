@php
    $content = is_array($content ?? null) ? $content : [];
    $brandAssets = (array) ($content['brand_assets'] ?? []);
    $businessSizes = is_array($content['business_sizes'] ?? null) ? $content['business_sizes'] : [];
    $timelines = is_array($content['timeline_options'] ?? null) ? $content['timeline_options'] : [];
    $budgetRanges = is_array($content['budget_ranges'] ?? null) ? $content['budget_ranges'] : [];
    $contactEmail = (string) ($content['contact_email'] ?? 'hello@evergrovesoftware.com');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head', [
        'app_name' => 'Evergrove Software',
        'title' => 'Contact Evergrove Software | Start with a workflow audit',
        'description' => 'Talk with Evergrove about practical software, portals, automations, and workflow systems for your business.',
        'brand_assets' => $brandAssets,
    ])
</head>
<body class="eg-public-body eg-public-body--contact">
    @include('evergrove.partials.nav')

    <main class="eg-contact-page">
        <section class="eg-contact-hero" aria-label="Contact Evergrove">
            <p class="eg-kicker">Workflow audit</p>
            <h1>Bring the messy version of the problem.</h1>
            <p>Tell us what your team keeps losing, repeating, or patching by hand. Evergrove will help decide whether the right answer is an app, portal, automation, product lane, or simpler process.</p>
            <a href="mailto:{{ $contactEmail }}" class="eg-text-link">{{ $contactEmail }}</a>
        </section>

        <section class="eg-contact-layout eg-contact-layout--page">
            <div class="eg-contact-steps" aria-label="What happens next">
                <article><span>1</span><strong>Send the notes</strong><p>Share the messy workflow, tools, and handoffs.</p></article>
                <article><span>2</span><strong>Map the system</strong><p>We separate the real problem from the noise.</p></article>
                <article><span>3</span><strong>Choose the build</strong><p>You get a practical next step, not a bloated pitch.</p></article>
            </div>

            <form method="POST" action="{{ route('evergrove.inquiries.store') }}" class="eg-form-card">
                @csrf
                <input type="hidden" name="source_page" value="evergrove_contact" />

                @if (session('status'))
                    <div class="fb-state fb-state--success text-sm">{{ session('status') }}</div>
                @endif

                <div class="eg-form-grid">
                    <label>
                        Name
                        <input name="name" type="text" value="{{ old('name') }}" required class="fb-input" />
                        @error('name') <span>{{ $message }}</span> @enderror
                    </label>
                    <label>
                        Email
                        <input name="email" type="email" value="{{ old('email') }}" required class="fb-input" />
                        @error('email') <span>{{ $message }}</span> @enderror
                    </label>
                </div>

                <div class="eg-form-grid">
                    <label>
                        Company
                        <input name="company" type="text" value="{{ old('company') }}" class="fb-input" />
                    </label>
                    <label>
                        Website
                        <input name="website" type="url" value="{{ old('website') }}" class="fb-input" placeholder="https://example.com" />
                        @error('website') <span>{{ $message }}</span> @enderror
                    </label>
                </div>

                <div class="eg-form-grid eg-form-grid-3">
                    <label>
                        Business size
                        <select name="business_size" class="fb-input">
                            <option value="">Select one</option>
                            @foreach($businessSizes as $key => $label)
                                <option value="{{ $key }}" @selected(old('business_size') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        Timeline
                        <select name="timeline" class="fb-input">
                            <option value="">Select one</option>
                            @foreach($timelines as $key => $label)
                                <option value="{{ $key }}" @selected(old('timeline') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        Budget range
                        <select name="budget_range" class="fb-input">
                            <option value="">Select one</option>
                            @foreach($budgetRanges as $key => $label)
                                <option value="{{ $key }}" @selected(old('budget_range') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <label>
                    Current tools
                    <input name="current_tools" type="text" value="{{ old('current_tools') }}" class="fb-input" placeholder="Shopify, spreadsheets, QuickBooks, email, Asana..." />
                </label>

                <label>
                    What should be easier?
                    <textarea name="pain_point" rows="6" class="fb-input">{{ old('pain_point') }}</textarea>
                    @error('pain_point') <span>{{ $message }}</span> @enderror
                </label>

                <button type="submit" class="eg-button eg-button-primary">Send workflow notes</button>
            </form>
        </section>
    </main>
</body>
</html>
