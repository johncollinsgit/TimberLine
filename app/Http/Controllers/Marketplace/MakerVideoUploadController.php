<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\MakerVideo;
use App\Services\Marketplace\MakerVideoUploads;
use App\Services\Marketplace\MarketplaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MakerVideoUploadController extends Controller
{
    public function __construct(private MarketplaceAccess $access, private MakerVideoUploads $uploads) {}

    private function authorizeMaker(Request $request, int $id): void
    {
        $maker = $this->access->maker($request, $id);
        abort_unless($maker->status === 'approved' && MakerVideo::invited($id), 403);
    }

    public function start(Request $request, int $id, string $token): JsonResponse
    {
        $this->authorizeMaker($request, $id);
        $data = $request->validate([
            'size' => ['required', 'integer', 'min:1', 'max:'.(config('marketplace.video_upload_mb') * 1024 ** 2)],
            'fingerprint' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'video_id' => ['nullable', 'integer', 'min:1'], 'revision' => ['required', 'integer', 'min:0'],
        ]);
        $video = isset($data['video_id']) ? (int) $data['video_id'] : null;
        if ($video !== null) {
            MakerVideo::where('maker_id', $id)->findOrFail($video);
        }

        return response()->json($this->uploads->start($id, $token, (int) $data['size'], $data['fingerprint'], $video, (int) $data['revision']));
    }

    public function chunk(Request $request, int $id, string $token, int $index): JsonResponse
    {
        $this->authorizeMaker($request, $id);
        $data = $request->validate(['part' => 'required|file|max:1024', 'checksum' => ['required', 'regex:/^[a-f0-9]{64}$/D']]);

        return response()->json($this->uploads->chunk($id, $token, $index, $request->file('part'), $data['checksum']));
    }

    public function finish(Request $request, int $id, string $token): JsonResponse
    {
        $this->authorizeMaker($request, $id);

        return response()->json($this->uploads->finish($id, $token));
    }

    public function cancel(Request $request, int $id, string $token): JsonResponse
    {
        $this->authorizeMaker($request, $id);
        $this->uploads->cancel($id, $token);

        return response()->json(['cancelled' => true]);
    }
}
