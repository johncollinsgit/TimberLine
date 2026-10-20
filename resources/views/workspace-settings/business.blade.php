<x-layouts::app title="Business profile">
<div class="ws-settings">
    @include('workspace-settings.header', ['heading' => 'Make Everbranch fit your work', 'description' => 'A few useful details, one small step at a time. Each saved step is here when you return.'])
    <p><a href="{{ route('workspace-settings.index', ['tenant' => $tenant->slug, 'category' => 'organization']) }}">← Organization settings</a></p>
    <nav aria-label="Business profile steps"><ol class="ws-steps">
        @foreach(['business' => '1 · Your business', 'model' => '2 · How you work', 'goal' => '3 · Your first goal'] as $key => $label)
            <li><a href="{{ route('workspace-settings.business', ['tenant' => $tenant->slug, 'step' => $key]) }}" @if($step === $key) aria-current="step" @endif>{{ $label }}</a></li>
        @endforeach
    </ol></nav>
    <div class="ws-form">
    @if(!$canSave)
        <div class="ws-notice">Your existing workspace needs a configuration check before this profile can be saved. Ask Everbranch for help; you can keep using your current tools.</div>
    @else
    <form class="ws-card" method="post" action="{{ route('workspace-settings.business.save', ['tenant' => $tenant->slug]) }}" data-ws-form>
        @csrf @method('PUT')
        <input type="hidden" name="revision" value="{{ old('revision', $revision) }}">
        <input type="hidden" name="step" value="{{ $step }}">
        @if($step === 'business')
            <h2>What kind of organization are you?</h2>
            <p class="ws-muted">Choose the closest fit. You can be more specific below, and change this later.</p>
            <div class="ws-field"><label for="business-category">Organization type</label>
                <select id="business-category" name="category" required data-ws-specialties="{{ json_encode($options['specialties']) }}">
                    <option value="">Choose an organization type</option>
                    @foreach($options['categories'] as $key => $label)<option value="{{ $key }}" @selected(old('category', $profile['category'] ?? '') === $key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="ws-field"><label for="business-specialty">Your specialty <span class="ws-muted">(optional)</span></label><input id="business-specialty" name="specialty" value="{{ old('specialty', $profile['specialty'] ?? '') }}" maxlength="80" list="business-specialties" placeholder="For example, electrical contracting"><datalist id="business-specialties"></datalist><small>Use your own description if none of the suggestions fit.</small></div>
            <div class="ws-field"><label for="business-description">What does your organization do? <span class="ws-muted">(optional)</span></label><textarea id="business-description" name="description" rows="3" maxlength="300" placeholder="A sentence about the work you do">{{ old('description', $profile['description'] ?? '') }}</textarea><small>Keep this about your business. Leave out private customer information.</small></div>
        @elseif($step === 'model')
            <h2>How does your business work?</h2><p class="ws-muted">Selling products and providing services? Keep both. You don’t have to fit a single template.</p>
            <div class="ws-field"><label for="business-model">What do you offer?</label><select id="business-model" name="model" required><option value="">Choose what you offer</option>@foreach($options['models'] as $key => $label)<option value="{{ $key }}" @selected(old('model', $profile['model'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
            @foreach(['channels' => ['Where do you work or sell?', $options['channels']], 'customer_types' => ['Who do you serve?', $options['customer_types']]] as $field => [$label, $choices])
                <fieldset class="ws-field"><legend>{{ $label }} <span class="ws-muted">(optional)</span></legend><div class="ws-choices">@foreach($choices as $key => $choice)<label class="ws-choice"><input type="checkbox" name="{{ $field }}[]" value="{{ $key }}" @checked(in_array($key, old($field, $profile[$field] ?? []), true))>{{ $choice }}</label>@endforeach</div></fieldset>
            @endforeach
        @else
            <h2>What would you like to accomplish first?</h2><p class="ws-muted">Choose a starting point from the tools you can use today. Nothing is purchased, published or sent by choosing a goal.</p>
            <fieldset class="ws-field"><legend class="sr-only">First goal</legend>
                @foreach($goals as $key => $goal)<label class="ws-choice" style="margin-bottom:10px!important"><input type="radio" name="goal" value="{{ $key }}" @checked(old('goal', $profile['goal'] ?? '') === $key)><span><strong>{{ $goal['label'] }}</strong><small>{{ $goal['description'] }}</small></span></label>@endforeach
                <label class="ws-choice"><input type="radio" name="goal" value="" @checked(!old('goal', $profile['goal'] ?? ''))>I’ll decide later</label>
            </fieldset>
            @if($goals === [])<p class="ws-muted">No first-action tools are available to your role yet. You can save your profile and review your branches with your workspace owner.</p>@endif
            <details><summary>What will changing this profile affect?</summary><p class="ws-muted">It changes suggested next steps and guidance. Your saved work, customized labels, selected branches, purchased access, permissions and accepted prices stay as they are.</p></details>
        @endif
        <div class="ws-actions"><button class="ws-button" type="submit">{{ $step === 'goal' ? 'Save profile & see next steps' : 'Save & continue' }}</button><a class="ws-button secondary" href="{{ route('workspace-settings.setup', ['tenant' => $tenant->slug]) }}">Finish later</a></div>
        @if($errors->has('revision'))<p><a href="{{ route('workspace-settings.business', ['tenant' => $tenant->slug, 'step' => $step]) }}">Reload the latest profile</a></p>@endif
    </form>
    @endif
    </div>
</div>
@vite('resources/js/workspace-settings.js')
</x-layouts::app>
