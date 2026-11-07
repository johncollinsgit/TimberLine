<x-layouts::app :title="'Customer profile'">
    <main class="mx-auto max-w-6xl space-y-5 p-5">
        <a href="{{ route('customers.directory', ['tenant' => $tenant->slug]) }}">All customers</a>
        <h1 class="text-2xl font-semibold">{{ $detail['customer']['name'] }}</h1>
        <p>{{ $detail['customer']['phone'] }} · {{ $detail['customer']['email'] }}</p>
        <x-customer-commerce :snapshot="$detail['commerce']" :rewards-label="$detail['commerce']['rewards']['label']" />
        @include('customers.preferences', compact('preferences', 'tenant', 'profile', 'canEditPreferences'))
        @if($journey) @include('customers.journey', compact('journey', 'tenant', 'profile', 'canReviewAttribution')) @endif
        @if($squareSource) @include('customers.square-source', compact('squareSource', 'tenant')) @endif
        @if($canPrepare)
            <section class="space-y-3 rounded border p-4" aria-label="Personalized messages">
                <h2 class="text-xl font-semibold">Personalized email or text</h2>
                <a href="{{ route('customers.outreach.policy', ['tenant' => $tenant->slug]) }}">Company contact limits and delivery setup</a>
                @if($outreachDrafts->isNotEmpty())<details><summary>Saved personal drafts</summary><ul>@foreach($outreachDrafts as $draft)<li><a href="{{ route('customers.outreach.review', ['profile' => $profile->id, 'campaign' => $draft->id, 'tenant' => $tenant->slug]) }}">{{ $draft->name }} · {{ $draft->channel }} · {{ $draft->status }}</a></li>@endforeach</ul></details>@endif
                <p>Build a draft from this customer's profile, then review its content and delivery checks before sending.</p>
                <p>Recorded permissions: email {{ $profile->accepts_email_marketing ? 'opted in' : 'not opted in' }} · text {{ $profile->accepts_sms_marketing ? 'opted in' : 'not opted in' }}.</p>
                @if($errors->any())<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
                <form method="POST" action="{{ route('customers.outreach', ['profile' => $profile->id, 'tenant' => $tenant->slug]) }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="request_id" value="{{ old('request_id', $form['request_id']) }}">
                    <label class="block">Channel<select class="block w-full rounded border p-2" name="channel"><option value="email" @selected(old('channel', $form['channel']) === 'email')>Email</option><option value="sms" @selected(old('channel', $form['channel']) === 'sms')>Text message</option></select></label>
                    <label class="block">Email subject<input class="block w-full rounded border p-2" name="subject" maxlength="250" value="{{ old('subject', $form['subject']) }}"></label>
                    <label class="block">Message<textarea class="block w-full rounded border p-2" rows="6" name="body" maxlength="10000" required>{{ old('body', $form['body']) }}</textarea></label>
                    <details><summary>Email formatting</summary>
                        <label class="block">Heading<input class="block w-full rounded border p-2" name="heading" value="{{ old('heading', $form['heading']) }}"></label>
                        <label class="block">Button text<input class="block w-full rounded border p-2" name="button_label" value="{{ old('button_label', $form['button_label']) }}"></label>
                        <label class="block">Button HTTPS link<input class="block w-full rounded border p-2" name="button_url" type="url" value="{{ old('button_url', $form['button_url']) }}"></label>
                    </details>
                    <p class="text-sm">Profile fields: @verbatim{{first_name}}, {{company_name}}, {{most_purchased_scent}}, {{most_purchased_product}}, {{rewards_name}}, {{rewards_balance}}, {{last_purchase_date}}, {{favorite_scents}}.@endverbatim</p>
                    <button class="rounded border p-2" name="action" value="preview">Preview personalized message</button>
                    <button class="rounded border p-2" name="action" value="save">Save campaign draft for review</button>
                </form>
                @if($preview)
                    <h3 class="font-semibold">Preview</h3><strong>{{ $preview['subject'] }}</strong><p class="whitespace-pre-wrap">{{ $preview['body'] }}</p>
                    @if($form['channel'] === 'email')<iframe title="Formatted email preview" sandbox="" referrerpolicy="no-referrer" class="h-96 w-full rounded border" srcdoc="{{ $preview['html'] }}"></iframe>@endif
                    @if($preview['missing_variables'])<p>Missing profile fields: {{ implode(', ', $preview['missing_variables']) }}. Replace these before saving.</p>@endif
                    <p>{{ $preview['notice'] }}</p><p>{{ $preview['basis'] }}</p>
                    <details><summary>Why this draft was prepared and current delivery checks</summary><p>Prepared for the customer you selected, using the profile fields in your message.</p><p>Contact policy version {{ $preview['outreach_decision']['policy']['version'] }} · Preference revision {{ $preview['outreach_decision']['preferences']['revision'] }}.</p><ul>@foreach($preview['outreach_decision']['reason_codes'] as $reason)<li>{{ str($reason)->replace('_', ' ')->ucfirst() }}</li>@endforeach</ul><p>History completeness and synchronization freshness are separate from purchase dates.</p></details>
                @endif
                @if($explanationHistory)<details><summary>Preparation and delivery explanation history</summary><ul class="space-y-3">@foreach($explanationHistory as $entry)<li><strong>{{ str($entry['action'])->replace('_', ' ')->title() }}</strong> · {{ $entry['status'] }} · {{ $entry['created_at'] }}
                    @if($entry['explanation'])<p>Policy version {{ data_get($entry, 'explanation.preparation_decision.policy.version') }} · Profile preference revision {{ data_get($entry, 'explanation.preparation_decision.preferences.revision') }}</p><p>Profile fields: {{ implode(', ', data_get($entry, 'explanation.personalization_variable_names', [])) }}</p><ul>@foreach(data_get($entry, 'explanation.quality.issues', []) as $issue)<li>{{ str($issue)->replace('_', ' ')->ucfirst() }}</li>@endforeach</ul>@endif
                    @if($entry['decision'])<ul>@foreach($entry['decision']['reason_codes'] as $reason)<li>{{ str($reason)->replace('_', ' ')->ucfirst() }}</li>@endforeach</ul>@endif
                </li>@endforeach</ul></details>@endif
            </section>
        @endif
    </main>
</x-layouts::app>
