<?php

namespace App\Http\Controllers;

use App\Jobs\UploadStudioYouTubeVideo;
use App\Models\IntegrationConnection;
use App\Models\StudioEpisodeDelivery;
use App\Services\ManagedWebsite\StudioEpisodeFeed;
use App\Services\ManagedWebsite\StudioYouTubeAccount;
use App\Services\Marketing\Desk\MarketingDeskAccess;
use App\Services\Marketing\Desk\SocialPostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudioDistributionController extends Controller
{
    private function tenant(Request $request, bool $owner = false): int
    {
        $id = (int) $request->attributes->get('current_tenant_id');
        app(MarketingDeskAccess::class)->ensure($id, $request->user(), $owner);
        app(StudioEpisodeFeed::class)->url($id);

        return $id;
    }

    public function index(Request $request, StudioEpisodeFeed $feed, StudioYouTubeAccount $youtube)
    {
        $id = $this->tenant($request);
        $episodes = $feed->episodes($id);
        $channels = IntegrationConnection::forTenantId($id)->where('provider', 'youtube_studio')->where('status', 'connected')->get();
        $receipts = StudioEpisodeDelivery::forTenantId($id)->orderByDesc('updated_at')->limit(50)->get();

        return view('studio.distribution', ['episodes' => $episodes, 'channels' => $channels, 'receipts' => $receipts, 'youtubeReady' => $youtube->ready(), 'feedUrl' => $feed->url($id)]);
    }

    public function connect(Request $request, StudioYouTubeAccount $youtube)
    {
        $tenant = $this->tenant($request, true);
        $state = Str::random(64);
        $verifier = Str::random(64);
        $request->session()->put('studio_google_auth', ['state' => $state, 'verifier' => $verifier, 'tenant' => $tenant,
            'actor' => $request->user()->id, 'until' => now()->addMinutes(10)->timestamp]);

        return redirect()->away($youtube->authorize($state, $verifier));
    }

    public function callback(Request $request, StudioYouTubeAccount $youtube, MarketingDeskAccess $access, StudioEpisodeFeed $feed)
    {
        $input = $request->validate(['state' => 'required|string|max:100', 'code' => 'required|string|max:3000']);
        $pending = $request->session()->get('studio_google_auth');
        abort_unless($pending && hash_equals($pending['state'], $input['state']) && $pending['actor'] === $request->user()->id
            && $pending['until'] >= now()->timestamp, 403);
        $feed->url($pending['tenant']);
        $access->ensure($pending['tenant'], $request->user(), true);
        $request->session()->forget('studio_google_auth');
        $youtube->connect($pending['tenant'], $request->user(), $input['code'], $pending['verifier']);

        return redirect()->route('studio.distribution.index', ['tenant' => \App\Models\Tenant::findOrFail($pending['tenant'])->slug])->with('status', 'Creator channel connected.');
    }

    public function disconnect(Request $request, int $channel)
    {
        $tenant = $this->tenant($request, true);
        $record = IntegrationConnection::forTenantId($tenant)->where('provider', 'youtube_studio')->findOrFail($channel);
        $record->update(['status' => 'disconnected', 'access_token' => null, 'refresh_token' => null, 'expires_at' => null]);

        return back()->with('status', 'YouTube channel disconnected.');
    }

    private function selection(Request $request, int $tenant): array
    {
        $data = $request->validate(['episode' => 'required|uuid', 'revision' => 'required|uuid', 'asset' => ['required', 'regex:/^[a-f0-9]{64}$/D']]);

        return app(StudioEpisodeFeed::class)->asset($tenant, $data['episode'], $data['revision'], $data['asset']);
    }

    public function meta(Request $request, SocialPostService $posts)
    {
        $tenant = $this->tenant($request);
        [$episode, $asset] = $this->selection($request, $tenant);
        $caption = $request->validate(['caption' => 'required|string|max:2200'])['caption'];
        $id = StudioEpisodeDelivery::receiptId($tenant, 'meta', $episode['id'], $episode['revision'], $asset['digest']);
        DB::transaction(function () use ($id, $tenant, $episode, $asset, $caption, $request, $posts) {
            $receipt = StudioEpisodeDelivery::firstOrCreate(['id' => $id, 'tenant_id' => $tenant], ['destination' => 'meta', 'status' => 'preparing', 'details' => []]);
            $receipt = StudioEpisodeDelivery::forTenantId($tenant)->lockForUpdate()->findOrFail($receipt->id);
            if ($receipt->status !== 'draft') {
                $post = $posts->save($tenant, $request->user(), ['caption' => $caption, 'media_type' => 'video', 'media_urls' => [$asset['url']]]);
                $receipt->update(['status' => 'draft', 'details' => ['title' => $episode['title'], 'post' => $post->id]]);
            }
        });

        return redirect()->route('marketing.desk.index', ['view' => 'calendar'])->with('status', 'Studio draft saved. Review the caption, select connected accounts and approve a publish time.');
    }

    public function youtube(Request $request)
    {
        $tenant = $this->tenant($request);
        [$episode, $asset] = $this->selection($request, $tenant);
        $input = $request->validate(['channel' => 'required|integer|min:1', 'title' => 'required|string|max:100',
            'description' => 'nullable|string|max:5000', 'privacy' => 'required|in:private,unlisted,public',
            'publish_at' => 'nullable|date|after:now', 'made_for_kids' => 'required|boolean', 'confirmed' => 'accepted']);
        $channel = IntegrationConnection::forTenantId($tenant)->where('provider', 'youtube_studio')->where('status', 'connected')->findOrFail($input['channel']);
        abort_if(! empty($input['publish_at']) && $input['privacy'] !== 'private', 422, 'A scheduled upload must start private.');
        $id = StudioEpisodeDelivery::receiptId($tenant, 'youtube:'.$channel->id, $episode['id'], $episode['revision'], $asset['digest']);
        $receipt = StudioEpisodeDelivery::firstOrCreate(['id' => $id, 'tenant_id' => $tenant], ['destination' => 'youtube', 'status' => 'queued',
            'details' => ['episode' => $episode['id'], 'revision' => $episode['revision'], 'asset' => $asset['digest'], 'title' => $input['title'],
                'description' => $input['description'] ?? '', 'channel' => $channel->id, 'privacy' => $input['privacy'],
                'made_for_kids' => (bool) $input['made_for_kids'], 'publish_at' => empty($input['publish_at']) ? null : \Carbon\CarbonImmutable::parse($input['publish_at'], 'UTC')->utc()->toIso8601String()]]);
        if ($receipt->wasRecentlyCreated || $receipt->status === 'queued') {
            UploadStudioYouTubeVideo::dispatch($tenant, $id)->onQueue('studio-distribution');
        }

        return back()->with('status', 'YouTube upload recorded. See its status under deliveries.');
    }

    public function resume(Request $request, string $receipt)
    {
        $tenant = $this->tenant($request);
        $delivery = StudioEpisodeDelivery::forTenantId($tenant)->where('destination', 'youtube')->findOrFail($receipt);
        abort_unless(in_array($delivery->status, ['interrupted', 'uploading', 'queued'], true), 409);
        $resumable = StudioEpisodeDelivery::forTenantId($tenant)->whereKey($receipt)->whereIn('status', ['interrupted', 'uploading', 'queued']);
        $resumable->update(['status' => 'queued']);
        UploadStudioYouTubeVideo::dispatch($tenant, $receipt)->onQueue('studio-distribution');

        return back()->with('status', 'Upload queued to resume from YouTube’s last confirmed byte.');
    }
}
