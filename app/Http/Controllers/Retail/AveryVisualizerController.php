<?php

namespace App\Http\Controllers\Retail;

use App\Http\Controllers\Controller;
use App\Services\Retail\AveryGuestAccess;
use Illuminate\Http\Request;

class AveryVisualizerController extends Controller
{
    private const HEADERS = [
        'Cache-Control' => 'private, no-store',
        'X-Robots-Tag' => 'noindex, nofollow',
        'X-Content-Type-Options' => 'nosniff',
        'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=()',
    ];

    public function show(Request $request)
    {
        abort_unless(AveryGuestAccess::enabled($request), 404);
        abort_unless($request->query->count() === 0, 404);
        if (! AveryGuestAccess::granted($request)) {
            return redirect('/avery')->withHeaders(self::HEADERS);
        }

        return response()->view('retail.avery-visualizer')->withHeaders(self::HEADERS);
    }

    public function model(Request $request)
    {
        abort_unless(AveryGuestAccess::granted($request), 404);
        abort_unless($request->query->count() === 0, 404);

        // A single fictional fixture, outside public/. No tenant/model/path input.
        return response()->file(resource_path('retail-visualizer/illustrative-knife.glb'), [
            ...self::HEADERS,
            'Content-Type' => 'model/gltf-binary',
            'Content-Disposition' => 'inline; filename="illustrative-knife.glb"',
        ]);
    }
}
