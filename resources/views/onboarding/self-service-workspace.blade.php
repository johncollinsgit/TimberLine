<x-layouts::auth :title="__('Your business')">
    <h1 class="fb-auth-title">Your business</h1>
    <form method="post" action="{{ route('workspace.first-login.store') }}" class="flex flex-col gap-5">
        @csrf
        <input type="hidden" name="start_path" value="self">
        <input type="hidden" name="template_key" value="{{ $selectedBusinessTemplate['blueprint_template'] }}">
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white">
            <img src="{{ asset($selectedBusinessTemplate['image']) }}" alt="" class="h-36 w-full object-cover" width="640" height="360">
            <div class="flex items-center justify-between gap-4 p-4">
                <strong>{{ $selectedBusinessTemplate['label'] }}</strong>
                <a href="{{ route('platform.build') }}" class="text-sm underline">Change template</a>
            </div>
        </div>
        <flux:input name="workspace_name" label="Business name" :value="old('workspace_name', $workspaceName)" required maxlength="120" autocomplete="organization" />
        @error('template_key')<p role="alert">{{ $message }}</p>@enderror
        <flux:button type="submit" variant="primary">Review plan</flux:button>
    </form>
</x-layouts::auth>
