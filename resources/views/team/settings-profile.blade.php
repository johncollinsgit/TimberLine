<div class="teams-settings-profile">
    <h3>Your profile</h3>
    <p>Your name and email are shared across your Everbranch applications.</p>
    <form wire:submit="updateProfileInformation" method="post" data-team-profile-form data-saved-name="{{ auth()->user()->fresh()->name }}" data-saved-email="{{ auth()->user()->fresh()->email }}">
        <label>Name<input wire:model="name" name="name" type="text" value="{{ $name }}" required maxlength="255" autocomplete="name" wire:loading.attr="disabled" wire:target="updateProfileInformation"></label>
        @error('name')<p class="teams-settings-error" role="alert">{{ $message }}</p>@enderror
        <label>Email address<input wire:model="email" name="email" type="email" value="{{ $email }}" required maxlength="255" autocomplete="email" wire:loading.attr="disabled" wire:target="updateProfileInformation"></label>
        @error('email')<p class="teams-settings-error" role="alert">{{ $message }}</p>@enderror
        <p class="muted">Changing your email requires verification before you can continue accessing Teams.</p>
        <div class="actions"><button class="primary" type="submit" wire:loading.attr="disabled" wire:target="updateProfileInformation">Save profile</button><span wire:loading wire:target="updateProfileInformation" role="status">Saving…</span></div>
        @if($profileSaved)<p class="teams-settings-saved" role="status">Profile saved.@if(! auth()->user()->hasVerifiedEmail()) Verify your updated email to continue using Teams. <a href="{{ route('verification.notice') }}">Open email verification</a>@endif</p>@endif
    </form>
</div>
