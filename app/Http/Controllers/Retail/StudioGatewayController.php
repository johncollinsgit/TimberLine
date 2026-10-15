<?php

namespace App\Http\Controllers\Retail;

use App\Http\Controllers\Controller;
use App\Services\Retail\RetailShowroomService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class StudioGatewayController extends Controller
{
    public function page(Request $r, RetailShowroomService $showroom)
    {
        abort_unless($showroom->owner($r->user()), 403);

        return response()->view('retail.studio')->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function proxy(Request $r, RetailShowroomService $showroom, string $path = '')
    {
        abort_unless($showroom->owner($r->user()), 403);
        abort_unless(in_array($path, ['', 'login', 'books', 'pair', 'pairing-code', 'entry', 'history', 'restore', 'audio', 'audio/live', 'publication', 'foundation', 'import', 'photo', 'transcribe', 'devices', 'logout'], true), 404);
        $client = Http::withoutRedirecting()->timeout(90)->withHeaders(array_filter(['Authorization' => $r->header('Authorization'), 'X-Audio-Size' => $r->header('X-Audio-Size')]))->withQueryParameters($r->query());
        $response = $client->withBody($r->getContent(), $r->header('Content-Type', 'application/json'))->send($r->method(), 'https://levelfoundations.org/api/studio'.($path !== '' ? '/'.$path : ''));

        // No upstream cookies, redirects, credentials or public caching are forwarded.
        return response($response->body(), $response->status())->header('Content-Type', $response->header('Content-Type') ?: 'application/json')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options','nosniff');
    }
}
