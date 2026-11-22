<x-layouts::app.sidebar title="Episode distribution">
<flux:main><main class="mx-auto max-w-4xl space-y-7">
<h1 class="text-3xl font-semibold">Episode distribution</h1>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<section class="space-y-2">
    <h2 class="text-xl font-semibold">Creator accounts</h2>
    @foreach($channels as $channel)
        <form class="flex gap-3" action="{{ route('studio.distribution.disconnect', $channel->id) }}" method="post">
            @csrf @method('DELETE') <span>YouTube · {{ $channel->external_account_label }}</span>
            <button class="underline">Disconnect</button>
        </form>
    @endforeach
    @if($youtubeReady)<form method="post" action="{{ route('studio.distribution.connect') }}">@csrf<button class="underline">Connect a YouTube channel</button></form>
    @else<p>YouTube uploads open when the workspace configures Google OAuth.</p>@endif
    <a class="underline" href="{{ route('marketing.desk.index', ['view'=>'settings']) }}">Manage Facebook and Instagram connections</a>
</section>
@forelse($episodes as $episode)
<section class="rounded-xl border border-zinc-300 p-6 space-y-4">
    <h2 class="text-2xl font-semibold">{{ $episode['title'] }}</h2>
    <p>{{ $episode['description'] }}</p>
    <details><summary class="cursor-pointer">Episode files and creator dashboards</summary>
        <ul class="my-3 space-y-1"><li><a class="underline" href="{{ $episode['audio'] }}">Episode audio (MP3)</a></li>
        @foreach($episode['assets'] as $asset)<li><a class="underline" href="{{ $asset['url'] }}">{{ ucfirst(str_replace('_', ' ', $asset['kind'])) }} · {{ substr($asset['digest'], 0, 8) }}</a></li>@endforeach</ul>
        <p>Apple Podcasts and Spotify can follow the <a class="underline" href="{{ $feedUrl }}">public episode feed</a>. For dashboard uploads, download the desired file above.</p>
        <p class="flex flex-wrap gap-3"><a class="underline" href="https://podcastsconnect.apple.com/">Apple Podcasts Connect</a>
        <a class="underline" href="https://creators.spotify.com/">Spotify for Creators</a>
        <a class="underline" href="https://rumble.com/upload.php">Rumble upload</a></p>
    </details>
    <details><summary class="cursor-pointer">Prepare a Facebook or Instagram post</summary>
        <form class="grid gap-3 mt-3" method="post" action="{{ route('studio.distribution.meta') }}">
            @csrf
            @include('studio.distribution-asset', ['episode' => $episode])
            <label>Caption<textarea class="block w-full" name="caption" required maxlength="2200">{{ \Illuminate\Support\Str::limit($episode['description'], 2200, '') }}</textarea></label>
            <button class="rounded-lg border p-2">Save draft to Marketing Desk</button>
        </form>
    </details>
    @if($channels->isNotEmpty())
    <details><summary class="cursor-pointer">Upload to YouTube</summary>
        <form class="grid gap-3 mt-3" method="post" action="{{ route('studio.distribution.youtube') }}">
            @csrf
            @include('studio.distribution-asset', ['episode' => $episode])
            <label>Creator channel<select class="block w-full" required name="channel">@foreach($channels as $channel)<option value="{{ $channel->id }}">{{ $channel->external_account_label }}</option>@endforeach</select></label>
            <label>Video title<input class="block w-full" name="title" required maxlength="100" value="{{ \Illuminate\Support\Str::limit($episode['title'], 100, '') }}"></label>
            <label>Description<textarea class="block w-full" name="description" maxlength="5000">{{ \Illuminate\Support\Str::limit($episode['description'], 5000, '') }}</textarea></label>
            <label>Visibility<select name="privacy"><option value="private">Private</option><option value="unlisted">Unlisted</option><option value="public">Public</option></select></label>
            <label>Publish a private upload later (optional, UTC)<input type="datetime-local" name="publish_at"></label>
            <label>Is this video made for kids?<select required name="made_for_kids"><option value="">Choose</option><option value="0">No</option><option value="1">Yes</option></select></label>
            <label><input type="checkbox" required name="confirmed" value="1"> I approve this upload to the selected channel.</label>
            <button class="rounded-lg border p-2">Queue YouTube upload</button>
        </form>
    </details>
    @endif
</section>
@empty
<p>Publish an approved public episode package from Studio to this workspace’s RSS feed to see its video, trailer and shorts here.</p>
@endforelse
@if($receipts->isNotEmpty())
<section class="space-y-3">
<h2 class="text-xl font-semibold">Recent deliveries</h2>
@foreach($receipts as $receipt)
<div class="flex flex-wrap items-baseline gap-2 border-b py-2">
    <strong>{{ $receipt->details['title'] ?? 'Episode' }}</strong> · {{ ucfirst($receipt->destination) }} · {{ $receipt->status }}
    @if($receipt->destination === 'meta')<a class="underline" href="{{ route('marketing.desk.index', ['view' => 'calendar']) }}">Review and schedule draft #{{ $receipt->details['post'] ?? '' }}</a>@endif
    @if($id = $receipt->details['video_id'] ?? null)<a class="underline" href="https://www.youtube.com/watch?v={{ $id }}">View on YouTube</a>@endif
    @if($receipt->destination === 'youtube' && in_array($receipt->status, ['queued','interrupted','uploading'], true))
        <form method="post" action="{{ route('studio.distribution.resume', $receipt->id) }}">@csrf<button class="underline">Resume upload</button></form>
    @elseif($receipt->status === 'review')<a class="underline" href="https://studio.youtube.com/">Check the expired session in YouTube Studio</a>@endif
</div>
@endforeach
</section>
@endif
</main></flux:main>
</x-layouts::app.sidebar>
