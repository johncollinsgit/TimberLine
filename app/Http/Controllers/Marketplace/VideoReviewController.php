<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\MakerVideo;
use App\Services\Marketplace\MakerVideoCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class VideoReviewController extends Controller
{
    public function index(\App\Services\Marketplace\MakerVideoPilotReport $report)
    {
        $videos = MakerVideo::with('maker')->whereIn('status', ['review', 'approved', 'changes', 'processing'])
            ->orderByRaw("CASE WHEN status = 'review' THEN 0 ELSE 1 END")->orderByDesc('updated_at')->paginate(20);
        $makers = Maker::where('status', 'approved')->orderBy('name')->get(['id', 'name']);
        $invited = DB::table('marketplace_video_invitations')->pluck('maker_id')->all();

        return view('marketplace.videos.review-queue', ['videos' => $videos, 'makers' => $makers, 'invited' => $invited, 'pilot' => $report->lastSixtyDays()]);
    }

    public function invite(Request $request)
    {
        $data = $request->validate(['maker_id' => 'required|integer', 'action' => 'required|in:invite,remove']);
        $maker = Maker::where('status', 'approved')->findOrFail($data['maker_id']);
        $table = DB::table('marketplace_video_invitations');
        if ($data['action'] === 'invite') {
            $table->insertOrIgnore(['maker_id' => $maker->id, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            $table->where('maker_id', $maker->id)->delete();
        }

        return back()->with('status', 'Studio access updated for '.$maker->name.'.');
    }

    public function show(int $videoId, MakerVideoCatalog $catalog)
    {
        $video = MakerVideo::with('maker')->findOrFail($videoId);
        $cards = $video->tags()->where('active', true)->get()->map(fn ($tag) => ['tag' => $tag, 'card' => $catalog->resolve($video->maker, $tag->product_key)]);

        return view('marketplace.videos.review', compact('video', 'cards'));
    }

    public function preview(int $videoId, string $asset)
    {
        $video = MakerVideo::whereNotNull('render_path')->findOrFail($videoId);
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($asset === 'captions') {
            abort_unless($video->captions, 404);

            return response($video->captions, 200, ['Content-Type' => 'text/vtt; charset=UTF-8', ...$headers]);
        }

        return response()->file(Storage::disk('marketplace_video')->path($video->render_path), ['Content-Type' => 'video/mp4', ...$headers]);
    }

    public function costs(Request $request, int $videoId)
    {
        $data = $request->validate(['minutes' => 'nullable|integer|min:0|max:100000', 'cost' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/']]);
        MakerVideo::findOrFail($videoId)->update(['production_minutes' => $data['minutes'] ?? null,
            'production_cost_cents' => isset($data['cost']) ? \App\Services\Marketplace\MarketplaceShopify::cents($data['cost']) : null]);

        return back()->with('status', 'Production figures saved.');
    }

    public function decide(Request $request, int $videoId)
    {
        $data = $request->validate([
            'revision' => 'required|integer', 'action' => 'required|in:approve,changes',
            'note' => [Rule::requiredIf($request->input('action') === 'changes'), 'nullable', 'string', 'max:2000'],
            'publish_at' => 'nullable|date_format:Y-m-d\TH:i',
        ]);
        DB::transaction(function () use ($request, $videoId, $data) {
            $video = MakerVideo::with('maker')->lockForUpdate()->findOrFail($videoId);
            abort_unless($video->revision === (int) $data['revision'] && $video->render_path && in_array($video->status, ['review', 'approved'], true), 409, 'Reload and review the current edit.');
            $approved = $data['action'] === 'approve';
            abort_if($approved && ($video->maker->status !== 'approved' || ! MakerVideo::invited($video->maker_id)), 422, 'This maker needs approval and a Studio invitation.');
            $date = ! empty($data['publish_at']) ? \Illuminate\Support\Carbon::createFromFormat('Y-m-d\TH:i', $data['publish_at'], config('marketplace.timezone'))->utc() : now();
            $video->update(['status' => $approved ? 'approved' : 'changes', 'reviewer_user_id' => $request->user()->id,
                'review_note' => $data['note'] ?? null, 'publish_at' => $approved ? $date : null]);
        });

        return redirect()->route('marketplace.operator.videos')->with('status', 'Review saved.');
    }
}
