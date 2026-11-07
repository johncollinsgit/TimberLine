@extends('managed-website.store-layout')
@section('store-content')
<section class="store-shell" style="max-width:760px;padding:50px 20px">
    <a href="{{ route('managed-website.store.account') }}" style="text-decoration:underline">Back to your account</a>
    <h1 style="font:500 36px Georgia,serif;margin:20px 0">Your preferences</h1>
    <p>Saved for your relationship with this business across its connected Everbranch accounts.</p>
    @if(session('account_status'))<p class="store-notice" role="status">{{ session('account_status') }}</p>@endif
    @if($errors->any())<p class="store-notice" role="alert">{{ $errors->first() }}</p>@endif
    @if(!$customerPreferences)
        <p class="store-notice">Your Website account has not been connected to an unambiguous shared customer profile. Contact the business to review that association. Your order history is still available in your account.</p>
    @else
        @if(in_array('alias_preference_review_required', $customerPreferences['issues'], true))
            <p class="store-notice">Some earlier customer records have different preferences. Those choices remain unknown until you explicitly choose them here.</p>
        @endif
        <p>Signed in as {{ $customer->email }}. Changes to shared sign-in email or phone require contact verification and identity review.</p>
        <form method="POST" action="{{ route('managed-website.store.account.preferences.update') }}" style="display:grid;gap:20px;margin-top:28px">
            @csrf
            <input type="hidden" name="request_id" value="{{ old('request_id', (string) \Illuminate\Support\Str::uuid()) }}">
            <input type="hidden" name="expected_version" value="{{ $customerPreferences['version'] }}">
            <fieldset style="display:grid;gap:14px;padding:18px;border:1px solid #d9ded6">
                <legend>Contact name</legend>
                @foreach(['first_name' => 'First name', 'last_name' => 'Last name'] as $field => $label)
                    <label>{{ $label }}<input name="changes[{{ $field }}]" maxlength="120" autocomplete="{{ $field === 'first_name' ? 'given-name' : 'family-name' }}" value="{{ old('changes.'.$field, data_get($customerPreferences, 'contact.'.$field)) }}" style="display:block;width:100%;padding:10px"></label>
                @endforeach
            </fieldset>
            <fieldset style="display:grid;gap:14px;padding:18px;border:1px solid #d9ded6">
                <legend>Your declared scent preferences</legend>
                <p>These are choices you tell us. Most-purchased scents, gifts, returns, bundles and quiz results do not automatically become your favorites.</p>
                <label>Favorite scents (one per line)<textarea name="favorite_scents_text" rows="4" maxlength="3000" style="display:block;width:100%;padding:10px">{{ old('favorite_scents_text', implode("\n", data_get($customerPreferences, 'declared.favorite_scents') ?? [])) }}</textarea></label>
                <label>Scents you prefer to avoid (one per line)<textarea name="avoided_scents_text" rows="3" maxlength="3000" style="display:block;width:100%;padding:10px">{{ old('avoided_scents_text', implode("\n", data_get($customerPreferences, 'declared.avoided_scents') ?? [])) }}</textarea></label>
                <p style="font-size:13px">Leave a list empty and save to record that you have no declared scents in that list.</p>
            </fieldset>
            <fieldset style="display:grid;gap:14px;padding:18px;border:1px solid #d9ded6">
                <legend>Communication and receipts</legend>
                <label>Preferred marketing channel<select name="changes[preferred_channel]" style="display:block;width:100%;padding:10px">
                    @foreach(['' => 'No preference recorded', 'email' => 'Email', 'sms' => 'Text message', 'none' => 'No marketing contact'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('changes.preferred_channel', data_get($customerPreferences, 'communication.preferred_channel')) === $value)>{{ $label }}</option>
                    @endforeach
                </select></label>
                <p>Email marketing: <strong>{{ str(data_get($customerPreferences, 'communication.email_marketing'))->replace('_', ' ')->title() }}</strong>. Text marketing: <strong>{{ str(data_get($customerPreferences, 'communication.sms_marketing'))->replace('_', ' ')->title() }}</strong>.</p>
                <label><input type="checkbox" name="changes[email_marketing]" value="0" @checked(old('changes.email_marketing') === '0')> Unsubscribe from email marketing</label>
                <label><input type="checkbox" name="changes[sms_marketing]" value="0" @checked(old('changes.sms_marketing') === '0')> Unsubscribe from text marketing</label>
                <p>To opt in, use the business’s existing verified subscription flow. Choosing a channel or requesting receipts does not subscribe you to marketing.</p>
                @foreach(['receipt_email' => ['Email receipts', 'receipts.email'], 'receipt_sms' => ['Text receipts', 'receipts.sms'], 'rewards_communications' => ['Rewards marketing updates', 'rewards.communications']] as $field => [$label, $path])
                    @php($choice = old('changes.'.$field, data_get($customerPreferences, $path)))
                    <label>{{ $label }}<select name="changes[{{ $field }}]" style="display:block;width:100%;padding:10px">
                        <option value="" @selected($choice === null || $choice === '')>No preference recorded</option>
                        <option value="1" @selected($choice === true || $choice === 1 || $choice === '1')>Yes</option>
                        <option value="0" @selected($choice === false || $choice === 0 || $choice === '0')>No</option>
                    </select></label>
                @endforeach
                <p style="font-size:13px">Receipt settings record your choice for connected services. They do not send a receipt, verify a phone, enroll you in a program or change an earlier transaction. Marketing updates still require separate consent.</p>
            </fieldset>
            <button class="store-button" type="submit" style="justify-self:start">Save your preferences</button>
        </form>
        <p style="font-size:13px;margin-top:20px">{{ $customerPreferences['updated_at'] ? 'Last preference update: '.$customerPreferences['updated_at'] : 'No explicit preference update recorded yet.' }}</p>
    @endif
</section>
@endsection
