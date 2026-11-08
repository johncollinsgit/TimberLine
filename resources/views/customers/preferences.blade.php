<section class="space-y-4 rounded border p-4" aria-label="Customer preferences">
    <h2 class="text-xl font-semibold">Customer preferences</h2>
    <p>Declared favorites are choices the customer reports. Purchase history, gifts, returns, bundles and quiz results do not automatically become declared preferences.</p>
    <dl class="grid gap-3 sm:grid-cols-2">
        <div><dt class="font-semibold">Declared favorite scents</dt><dd>{{ data_get($preferences, 'declared.favorite_scents') === null ? 'Unknown — no current declared choice' : (implode(', ', $preferences['declared']['favorite_scents']) ?: 'Customer declared no favorites') }}</dd></div>
        <div><dt class="font-semibold">Scents to avoid</dt><dd>{{ data_get($preferences, 'declared.avoided_scents') === null ? 'Unknown — no current declared choice' : (implode(', ', $preferences['declared']['avoided_scents']) ?: 'Customer declared none to avoid') }}</dd></div>
        <div><dt class="font-semibold">Preferred marketing channel</dt><dd>{{ str(data_get($preferences, 'communication.preferred_channel') ?? 'unknown')->replace('_', ' ')->title() }}</dd></div>
        <div><dt class="font-semibold">Current marketing permission</dt><dd>Email: {{ data_get($preferences, 'communication.email_marketing', 'unknown') }} · Text: {{ data_get($preferences, 'communication.sms_marketing', 'unknown') }}</dd></div>
        @foreach(['Email receipts' => 'receipts.email', 'Text receipts' => 'receipts.sms', 'Rewards marketing updates' => 'rewards.communications'] as $label => $path)
            @php($choice = data_get($preferences, $path))
            <div><dt class="font-semibold">{{ $label }}</dt><dd>{{ $choice === null ? 'Unknown — no choice recorded' : ($choice ? 'Yes' : 'No') }}</dd></div>
        @endforeach
    </dl>
    <p class="text-sm">Receipt delivery choices are separate from email and SMS marketing consent. They do not send a receipt, verify a phone or enroll a customer in rewards. Rewards updates still require separate channel consent.</p>
    <p class="text-sm">Preference revision {{ $preferences['revision'] }} · {{ $preferences['updated_at'] ? 'Last saved '.$preferences['updated_at'] : 'No explicit preference update recorded' }}</p>
    @if($preferences['provenance'])
        <details><summary>Recorded choice sources</summary><ul class="list-disc space-y-1 pl-5 text-sm">@foreach($preferences['provenance'] as $field => $source)<li>{{ str($field)->replace('_', ' ')->title() }}: {{ str($source['source'] ?? 'unknown')->replace('_', ' ') }} · {{ $source['recorded_at'] ?? 'Date unknown' }}</li>@endforeach</ul></details>
    @endif
    @if(in_array('alias_preference_review_required', $preferences['issues'], true))<p role="status">Retained customer records have different declared choices. Conflicting choices remain unknown until the customer explicitly confirms them.</p>@endif
    @if($canEditPreferences)
        @if($errors->getBag('customerPreferences')->any())<ul role="alert" class="list-disc pl-5">@foreach($errors->getBag('customerPreferences')->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
        <form method="POST" action="{{ route('customers.preferences.update', ['profile' => $profile->id, 'tenant' => $tenant->slug]) }}" class="space-y-4">
            @csrf
            <input type="hidden" name="request_id" value="{{ old('request_id', (string) \Illuminate\Support\Str::uuid()) }}">
            <input type="hidden" name="expected_version" value="{{ $preferences['version'] }}">
            <fieldset class="space-y-3 rounded border p-3">
                <legend class="font-semibold">Customer-reported scents</legend>
                @foreach(['favorite_scents' => 'Favorite scents', 'avoided_scents' => 'Scents to avoid'] as $field => $label)
                    <label class="block"><input type="checkbox" name="apply_{{ $field }}" value="1" @checked(old('apply_'.$field))> Apply this customer’s {{ strtolower($label) }} choice</label>
                    <label class="block">{{ $label }} (one per line)<textarea name="{{ $field }}_text" rows="3" maxlength="3000" class="block w-full rounded border p-2">{{ old($field.'_text', implode("\n", data_get($preferences, 'declared.'.$field) ?? [])) }}</textarea></label>
                @endforeach
                <p class="text-sm">An unchecked Apply box preserves the existing choice, including unknown. Check Apply and save an empty list only when the customer explicitly declares none.</p>
            </fieldset>
            <fieldset class="space-y-3 rounded border p-3">
                <legend class="font-semibold">Contact name</legend>
                <label class="block"><input type="checkbox" name="apply_contact_names" value="1" @checked(old('apply_contact_names'))> Apply the customer’s name correction</label>
                @foreach(['first_name' => 'First name', 'last_name' => 'Last name'] as $field => $label)<label class="block">{{ $label }}<input name="changes[{{ $field }}]" maxlength="120" value="{{ old('changes.'.$field, data_get($preferences, 'contact.'.$field)) }}" class="block w-full rounded border p-2"></label>@endforeach
                <p class="text-sm">Email and shared phone changes require the existing verified contact and identity review flow. A receipt, purchase, matching name or card does not verify account ownership.</p>
            </fieldset>
            <fieldset class="space-y-3 rounded border p-3">
                <legend class="font-semibold">Communication and receipts</legend>
                <label class="block">Preferred marketing channel<select name="changes[preferred_channel]" class="block w-full rounded border p-2">
                    @foreach(['' => 'Unknown — no choice recorded', 'email' => 'Email', 'sms' => 'Text message', 'none' => 'No marketing contact (unsubscribe both channels)'] as $value => $label)<option value="{{ $value }}" @selected((old('changes.preferred_channel', data_get($preferences, 'communication.preferred_channel')) ?? '') === $value)>{{ $label }}</option>@endforeach
                </select></label>
                @foreach(['receipt_email' => ['Email receipts', 'receipts.email'], 'receipt_sms' => ['Text receipts', 'receipts.sms'], 'rewards_communications' => ['Rewards marketing updates', 'rewards.communications']] as $field => [$label, $path])
                    @php($choice = old('changes.'.$field, data_get($preferences, $path)))
                    <label class="block">{{ $label }}<select name="changes[{{ $field }}]" class="block w-full rounded border p-2">
                        <option value="" @selected($choice === null || $choice === '')>Unknown — no choice recorded</option><option value="1" @selected($choice === true || $choice === 1 || $choice === '1')>Yes</option><option value="0" @selected($choice === false || $choice === 0 || $choice === '0')>No</option>
                    </select></label>
                @endforeach
                <label class="block"><input type="checkbox" name="revoke_email_marketing" value="1" @checked(old('revoke_email_marketing'))> Customer requests email marketing unsubscribe</label>
                <label class="block"><input type="checkbox" name="revoke_sms_marketing" value="1" @checked(old('revoke_sms_marketing'))> Customer requests text marketing unsubscribe</label>
                <p class="text-sm">Positive opt-in uses the existing verified subscription flow. Saving a preferred channel, receipt choice or reward preference does not grant consent.</p>
            </fieldset>
            <label class="block"><input type="checkbox" name="customer_confirmed" value="1" required @checked(old('customer_confirmed'))> The customer explicitly reported the choices I am applying.</label>
            <button type="submit" class="rounded border p-2">Save customer choices</button>
        </form>
    @else
        <p class="text-sm">An active verified customer manager can record customer-reported choices.</p>
    @endif
</section>
