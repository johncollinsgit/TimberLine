<?php

namespace App\SoftwareSupport\Transport;

use Illuminate\Http\Request;

final class TestController
{
    public function __invoke(Request $request)
    {
        abort_unless(app()->environment('testing') && config('software_support.test_transport_enabled', false), 404);
        abort_unless($request->isJson() && strlen($request->getContent()) <= 49152 && ! $request->getQueryString(), 422);
        // Container trust, bindings, signer and inbox must be explicitly installed by a test fixture.
        abort_unless(app()->bound(TestReceiver::class), 503);

        return response()->json(app(TestReceiver::class)->receive($request->json()->all()))
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
