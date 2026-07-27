@php
    $sourcePage = (string) ($sourcePage ?? 'everbranch_contact');
@endphp

<form method="POST" action="{{ route('evergrove.inquiries.store') }}" class="fb-contact-form">
    @csrf
    <input type="hidden" name="source_page" value="{{ $sourcePage }}" />

    @if (session('status'))
        <div class="fb-state fb-state--success text-sm">{{ session('status') }}</div>
    @endif

    <div class="fb-contact-form__grid">
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

    <div class="fb-contact-form__grid">
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

    <label>
        What should be easier?
        <textarea name="pain_point" rows="5" class="fb-input" placeholder="Customers, jobs, messages, files, tasks, follow-ups...">{{ old('pain_point') }}</textarea>
        @error('pain_point') <span>{{ $message }}</span> @enderror
    </label>

    <label>
        What are you using now?
        <input name="current_tools" type="text" value="{{ old('current_tools') }}" class="fb-input" placeholder="Texts, email, spreadsheets, Shopify, QuickBooks..." />
    </label>

    <button type="submit" class="fb-btn fb-btn-primary">Send message</button>
</form>
