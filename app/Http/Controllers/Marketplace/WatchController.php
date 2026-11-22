<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\MakerVideo;
use App\Services\Marketplace\MakerVideoCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class WatchController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['format' => 'nullable|in:short,long', 'theme' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(MakerVideo::THEMES))], 'maker' => 'nullable|string|max:160']);
        $maker = isset($filters['maker']) ? Maker::where('status', 'approved')->where('slug', $filters['maker'])->firstOrFail() : null;
        $videos = MakerVideo::published()->with('maker')->when($filters['format'] ?? null, fn ($q, $format) => $q->where('format', $format))
            ->when($filters['theme'] ?? null, fn ($q, $theme) => $q->where('theme', $theme))
            ->when($maker, fn ($q) => $q->where('maker_id', $maker->id))
            ->orderByDesc('publish_at')->orderByDesc('id')->paginate(12)->withQueryString();

        return view('marketplace.videos.watch', compact('videos', 'filters', 'maker'));
    }

    public function show(int $videoId, MakerVideoCatalog $catalog)
    {
        $video = MakerVideo::published()->with('maker')->findOrFail($videoId);
        $cards = $video->tags()->where('active', true)->orderBy('start_seconds')->orderBy('id')->get()
            ->map(fn ($tag) => ['tag' => $tag, 'card' => $catalog->resolve($video->maker, $tag->product_key)]);

        return view('marketplace.videos.player', compact('video', 'cards'));
    }

    public function file(int $videoId)
    {
        $video = MakerVideo::published()->findOrFail($videoId);

        return response()->file(Storage::disk('marketplace_video')->path($video->render_path), ['Content-Type' => 'video/mp4']);
    }

    public function poster(int $videoId)
    {
        $video = MakerVideo::published()->findOrFail($videoId);

        return response()->file(Storage::disk('marketplace_video')->path($video->render_path.'.jpg'), ['Content-Type' => 'image/jpeg']);
    }

    public function captions(int $videoId)
    {
        $video = MakerVideo::published()->whereNotNull('captions')->findOrFail($videoId);

        return response($video->captions, 200, ['Content-Type' => 'text/vtt; charset=UTF-8']);
    }

    public function viewed(Request $request, int $videoId)
    {
        $video = MakerVideo::published()->findOrFail($videoId);
        $key = 'mm:video:play:'.$video->id.':'.hash('sha256', $request->session()->getId());
        if (Cache::add($key, true, now()->addDay())) {
            $video->increment('views');
        }

        return response()->noContent();
    }

    public function shop(Request $request, int $videoId, int $tagId, MakerVideoCatalog $catalog)
    {
        $video = MakerVideo::published()->with('maker')->findOrFail($videoId);
        $tag = $video->tags()->where('active', true)->findOrFail($tagId);
        $card = $catalog->resolve($video->maker, $tag->product_key);
        if (! $card || ! $card['available'] || ! $card['href']) {
            return redirect()->route('marketplace.public.watch.show', $video->id)->with('status', 'This product is currently unavailable.');
        }
        if ($card['lane'] === 'marketplace') {
            $request->session()->put('marketplace.video_tags.'.$card['listing_id'], $tag->id);
        }
        $tag->increment('clicks');

        return redirect()->away($card['href'], 303);
    }
}
