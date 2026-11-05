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
                    <section class="space-y-3 rounded-xl border border-zinc-200 p-4" aria-label="Send email" data-gmail-native
                        data-status-url="{{ route('shopify.app.wholesale.gmail.status', [], false) }}"
                        data-connect-url="{{ route('shopify.app.wholesale.gmail.connect', [], false) }}"
                        data-disconnect-url="{{ route('shopify.app.wholesale.gmail.disconnect', [], false) }}"
                        data-review-url="{{ route('shopify.app.wholesale.gmail.review', ['accountKey' => $accountKey], false) }}"
                        data-test-url="{{ url('/shopify/app/wholesale/gmail/test') }}"
                        data-send-url="{{ url('/shopify/app/wholesale/gmail/send') }}">
                        <p class="font-semibold">Send from Backstage</p>
                        <p class="whitespace-pre-line break-words text-sm" data-gmail-from></p>
                        <p class="text-sm text-zinc-600" role="status" aria-live="polite" data-gmail-status>Checking your email connection…</p>
                        <button type="button" class="w-full rounded-full bg-zinc-950 px-4 py-3 font-semibold text-white disabled:opacity-50" data-gmail-review disabled>Review and send</button>
                        <button type="button" class="w-full rounded-full border px-4 py-2 font-semibold" data-gmail-connect hidden>Connect Gmail</button>
                        <button type="button" class="text-sm font-semibold underline" data-gmail-refresh>Check connection</button>
                        <button type="button" class="ml-3 text-sm underline" data-gmail-disconnect hidden>Disconnect</button>
                    </section>
                    <dialog class="m-auto max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-xl border p-5 shadow-xl backdrop:bg-black/40" data-gmail-dialog aria-labelledby="gmail-review-title">
                        <h2 class="text-lg font-semibold" id="gmail-review-title">Review your email</h2>
                        <p class="mt-3 whitespace-pre-line break-words text-sm" data-gmail-envelope></p>
                        <iframe class="my-4 h-[55vh] w-full rounded border" sandbox="" title="Exact email preview" data-gmail-preview></iframe>
                        <p class="text-sm" role="status" aria-live="polite" data-gmail-send-status></p>
                        <p class="mt-2 text-sm text-zinc-600" data-gmail-test-recipient hidden></p>
                        <button type="button" class="mt-2 rounded-full border px-4 py-2 font-semibold" data-gmail-test hidden>Send internal test</button>
                        <div class="mt-4 flex gap-3"><button type="button" class="rounded-full bg-zinc-950 px-5 py-3 font-semibold text-white disabled:opacity-50" data-gmail-send>Send email</button>
                        <button type="button" class="rounded-full border px-5 py-3" data-gmail-cancel>Back to editing</button></div>
                    </dialog>
                    <p class="text-xs font-semibold uppercase text-zinc-500">Or copy to your email app</p>
                    <button type="button" class="w-full rounded-full bg-zinc-950 px-4 py-3 font-semibold text-white" data-outreach-copy>1. Copy formatted email</button>
                    <a href="https://mail.google.com/mail/?view=cm&amp;fs=1&amp;to={{ rawurlencode($draft['recipient']) }}&amp;su={{ rawurlencode($draft['subject']) }}" target="_blank" rel="noopener" class="block w-full rounded-full border border-zinc-300 px-4 py-3 text-center font-semibold" data-outreach-gmail>2. Open Gmail</a>
                    <p class="text-sm text-zinc-600" role="status" aria-live="polite" data-outreach-copy-status>Copy the email, open Gmail, then paste into the message body with Command+V (Ctrl+V on Windows). Photos and product links are included. Check the sending account and review before sending.</p>
                    <p class="text-xs text-zinc-500">Only Send email contacts this customer. Copying or opening Gmail does not send. Accepted sends are recorded in Backstage; delivery remains unconfirmed.</p>
                </section>
                <section class="min-w-0 overflow-hidden rounded-xl border border-zinc-200 bg-white" aria-label="Customer email preview" data-outreach-email>@include('emails.wholesale.customer-outreach', ['draft' => $draft])</section>
            </div>
            @include('shopify.partials.wholesale-outreach-copy')
            @include('shopify.partials.wholesale-outreach-gmail')
        @else
            <section class="fb-page-surface p-6"><h2 class="text-lg font-semibold">Outreach needs another look</h2><p class="mt-3 text-sm text-zinc-600">{{ $problem ?: 'Current wholesale history could not be verified.' }}</p><a class="mt-4 inline-block font-semibold underline" href="{{ $embeddedUrl(route('shopify.app.wholesale.customers.show', ['accountKey' => $accountKey], false)) }}">Review this wholesale account</a></section>
        @endif
    @endif
</x-shopify-embedded-shell>
