<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Jobs\RenderMarketplaceVideo;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\MakerVideo;
use App\Services\Marketplace\MakerVideoCaptions;
use App\Services\Marketplace\MakerVideoCatalog;
use App\Services\Marketplace\MakerVideoSales;
use App\Services\Marketplace\MarketplaceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class MakerVideoController extends Controller
{
    public function index(Request $request, int $id, MarketplaceAccess $access)
    {
        $maker = $access->maker($request, $id);

        return view('marketplace.videos.studio', [
            'maker' => $maker, 'invited' => MakerVideo::invited($id),
            'videos' => MakerVideo::where('maker_id', $id)->orderByDesc('updated_at')->paginate(20),
        ]);
    }

    public function edit(Request $request, int $id, MarketplaceAccess $access, MakerVideoCatalog $catalog, MakerVideoSales $sales, ?int $videoId = null)
    {
        $maker = $access->maker($request, $id);
        $video = $videoId ? $this->ownedVideo($maker, $videoId) : new MakerVideo(['format' => 'short', 'revision' => 0]);
        $invited = MakerVideo::invited($id);
        abort_unless($video->exists || $invited, 403);
        $tags = $video->exists ? $video->tags()->orderBy('id')->get() : collect();
        $tagIds = $tags->pluck('id')->all();
        $options = $invited ? $catalog->options($maker) : [];
        $byTag = $sales->forTags($maker, $tagIds);
        $lanes = array_column($options, 'lane', 'key');
        foreach ($tags as $tag) {
            if (str_starts_with($tag->product_key, 'marketplace:') || ($lanes[$tag->product_key] ?? null) === 'marketplace') {
                $byTag[$tag->id] ??= ['orders' => 0, 'net_merchandise_cents' => 0];
            }
        }

        return view('marketplace.videos.editor', [
            'maker' => $maker, 'video' => $video, 'invited' => $invited, 'options' => $options,
            'tags' => $tags, 'sales' => $byTag, 'summary' => $sales->summary($maker, $tagIds),
        ]);
    }

    public function save(Request $request, int $id, MarketplaceAccess $access, MakerVideoCatalog $catalog, MakerVideoCaptions $captions, ?int $videoId = null)
    {
        $maker = $access->maker($request, $id);
        abort_unless(MakerVideo::invited($id) && $maker->status === 'approved', 403);
        if ($videoId !== null) {
            $this->ownedVideo($maker, $videoId);
        }
        $maxKb = config('marketplace.video_upload_mb') * 1024;
        $data = $request->validate([
            'title' => 'required|string|max:160', 'description' => 'nullable|string|max:5000', 'format' => 'required|in:short,long',
            'source' => [($videoId ? 'nullable' : 'required'), 'file', 'mimetypes:video/mp4,video/quicktime,video/webm', 'max:'.$maxKb],
            'source_sha256' => ['nullable', 'regex:/^[a-f0-9]{64}$/iD'],
            'revision' => 'required|integer|min:0', 'action' => 'required|in:draft,submit',
            'trim_start' => 'nullable|integer|min:0|max:14399', 'trim_end' => 'nullable|integer|min:1|max:14400',
            'captions' => 'nullable|string|max:1000000', 'tags' => 'nullable|array|max:12',
            'tags.*.key' => 'nullable|string|max:145', 'tags.*.start' => 'nullable|integer|min:0|max:14399',
            'tags.*.end' => 'nullable|integer|min:1|max:14400',
        ]);
        $start = (int) ($data['trim_start'] ?? 0);
        $end = isset($data['trim_end']) ? (int) $data['trim_end'] : null;
        if ($end !== null && $end <= $start) {
            throw ValidationException::withMessages(['trim_end' => 'End the clip after the start time.']);
        }
        $length = $end === null ? null : $end - $start;
        $vtt = $captions->normalize($data['captions'] ?? null, $length);
        $tags = [];
        foreach ($data['tags'] ?? [] as $row) {
            $key = $row['key'] ?? null;
            if (! $key) {
                continue;
            }
            $card = $catalog->resolve($maker, $key);
            if (! $card || isset($tags[$key])) {
                throw ValidationException::withMessages(['tags' => 'Choose each product once from your connected catalog.']);
            }
            $from = isset($row['start']) ? (int) $row['start'] : null;
            $to = isset($row['end']) ? (int) $row['end'] : null;
            if (($to !== null && $to <= ($from ?? 0)) || ($length !== null && (($from ?? 0) >= $length || $to > $length))) {
                throw ValidationException::withMessages(['tags' => 'Product times must fall in order within the edited video.']);
            }
            $tags[$key] = ['label' => mb_substr(trim($card['label'].' '.($card['variant'] ?? '')), 0, 255), 'start_seconds' => $from, 'end_seconds' => $to, 'active' => true];
        }
        $file = $request->file('source');
        if (! empty($data['source_sha256']) && (! $file || ! hash_equals(strtolower($data['source_sha256']), hash_file('sha256', $file->getRealPath())))) {
            throw ValidationException::withMessages(['source' => 'The uploaded video differs from the verified Studio export.']);
        }
        $disk = Storage::disk('marketplace_video');
        $path = $file ? $file->store('sources/'.$id, 'marketplace_video') : null;
        $obsolete = [];
        $submit = $data['action'] === 'submit';
        try {
            $video = DB::transaction(function () use ($maker, $videoId, $data, $path, $file, $tags, $vtt, $start, $end, $submit, &$obsolete) {
                $video = $videoId ? MakerVideo::where('maker_id', $maker->id)->lockForUpdate()->findOrFail($videoId) : new MakerVideo(['maker_id' => $maker->id]);
                abort_if((int) $video->revision !== (int) $data['revision'], 409, 'This draft changed. Reload before saving.');
                if ($path) {
                    $obsolete[] = $video->source_path;
                    $video->source_path = $path;
                    $video->source_type = $file->getMimeType();
                }
                if ($video->render_path) {
                    $obsolete[] = $video->render_path;
                    $obsolete[] = $video->render_path.'.jpg';
                }
                $video->fill([
                    'title' => $data['title'], 'description' => $data['description'] ?? null, 'format' => $data['format'],
                    'trim_start' => $start, 'trim_end' => $end, 'captions' => $vtt, 'revision' => (int) $video->revision + 1,
                    'first_submitted_at' => $video->first_submitted_at ?? ($submit ? now() : null),
                    'status' => $submit ? 'processing' : 'draft', 'duration' => null, 'publish_at' => null,
                    'render_path' => null, 'review_note' => null, 'reviewer_user_id' => null,
                ])->save();
                $video->tags()->whereNotIn('product_key', array_keys($tags))->update(['active' => false]);
                foreach ($tags as $key => $tag) {
                    $video->tags()->updateOrCreate(['product_key' => $key], $tag);
                }

                return $video;
            });
        } catch (Throwable $error) {
            if ($path) {
                $disk->delete($path);
            }
            throw $error;
        }
        if ($submit) {
            try {
                RenderMarketplaceVideo::dispatch($video->id, $video->revision);
            } catch (Throwable $error) {
                report($error);
                MakerVideo::whereKey($video->id)->where('revision', $video->revision)->where('status', 'processing')
                    ->update(['status' => 'changes', 'review_note' => 'Processing could not start. Submit the draft again.']);
            }
        }
        foreach (array_filter($obsolete) as $oldPath) {
            $disk->delete($oldPath);
        }

        return redirect()->route('marketplace.public.maker.videos.edit', [$id, $video->id])->with('status', $submit ? 'Submission saved. Check the processing and review status below.' : 'Draft saved.');
    }

    public function source(Request $request, int $id, int $videoId, MarketplaceAccess $access)
    {
        $video = $this->ownedVideo($access->maker($request, $id), $videoId);

        return response()->file(Storage::disk('marketplace_video')->path($video->source_path), ['Content-Type' => $video->source_type]);
    }

    private function ownedVideo(Maker $maker, int $videoId): MakerVideo
    {
        return MakerVideo::where('maker_id', $maker->id)->findOrFail($videoId);
    }
}
