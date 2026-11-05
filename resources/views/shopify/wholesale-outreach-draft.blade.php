<x-shopify-embedded-shell :authorized="$authorized" :shopify-api-key="$shopifyApiKey" :shop-domain="$shopDomain" :host="$host" :store-label="$storeLabel" :headline="$headline" :subheadline="$subheadline" :app-navigation="$appNavigation" :page-subnav="$pageSubnav ?? []" :page-actions="$pageActions">
    @if($authorized)
        @include('shopify.partials.wholesale-reporting-status')
        @if($draft)
            <div class="grid items-start gap-6 xl:grid-cols-[minmax(280px,360px)_minmax(0,1fr)]" data-wholesale-outreach-editor>
                <section class="fb-page-surface space-y-5 p-6">
                    <div><p class="text-xs font-semibold uppercase text-zinc-500">To</p><p class="mt-1 break-all font-semibold">{{ $draft['recipient'] }}</p></div>
                    <label class="block"><span class="text-xs font-semibold uppercase text-zinc-500">Subject</span><input class="mt-2 w-full rounded-xl border border-zinc-300 px-3 py-2" maxlength="200" value="{{ $draft['subject'] }}" data-outreach-subject></label>
                    <label class="block"><span class="text-xs font-semibold uppercase text-zinc-500">Personal message</span><textarea class="mt-2 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm" rows="10" maxlength="4000" data-outreach-message-input>{{ $draft['message'] }}</textarea></label>
                    @if($draft['catalog_issue'])<p class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">Product photos couldn’t load. Reload to try again before copying this draft.</p>@endif
                    <button type="button" class="w-full rounded-full bg-zinc-950 px-4 py-3 font-semibold text-white" data-outreach-copy>1. Copy formatted email</button>
                    <a href="https://mail.google.com/mail/?view=cm&amp;fs=1&amp;to={{ rawurlencode($draft['recipient']) }}&amp;su={{ rawurlencode($draft['subject']) }}" target="_blank" rel="noopener" class="block w-full rounded-full border border-zinc-300 px-4 py-3 text-center font-semibold" data-outreach-gmail>2. Open Gmail</a>
                    <p class="text-sm text-zinc-600" role="status" aria-live="polite" data-outreach-copy-status>Copy the email, open Gmail, then paste into the message body with Command+V (Ctrl+V on Windows). Photos and product links are included. Check the sending account and review before sending.</p>
                    <p class="text-xs text-zinc-500">This page creates no customer send. Return to Suggestions to snooze or dismiss the account, or update its follow-up after contact.</p>
                </section>
                <section class="min-w-0 overflow-hidden rounded-xl border border-zinc-200 bg-white" aria-label="Customer email preview" data-outreach-email>@include('emails.wholesale.customer-outreach', ['draft' => $draft])</section>
            </div>
            @include('shopify.partials.wholesale-outreach-copy')
        @else
            <section class="fb-page-surface p-6"><h2 class="text-lg font-semibold">Outreach needs another look</h2><p class="mt-3 text-sm text-zinc-600">{{ $problem ?: 'Current wholesale history could not be verified.' }}</p><a class="mt-4 inline-block font-semibold underline" href="{{ $embeddedUrl(route('shopify.app.wholesale.customers.show', ['accountKey' => $accountKey], false)) }}">Review this wholesale account</a></section>
        @endif
    @endif
</x-shopify-embedded-shell>
