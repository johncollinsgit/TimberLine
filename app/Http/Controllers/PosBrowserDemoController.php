<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Fictional browser-only demonstration; never resolves or operates a merchant. */
class PosBrowserDemoController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $host = strtolower($request->getHost());
        $canonical = strtolower((string) config('tenancy.domains.canonical.landlord_host'));
        $localPreview = app()->environment(['local', 'testing']) && in_array($host, ['localhost', '127.0.0.1'], true);
        abort_unless(($canonical !== '' && $host === $canonical) || $localPreview, 404);
        // This surface accepts no tenant selection, customer identifiers or provider callbacks.
        abort_if($request->query->count() !== 0 || (string) $request->server('QUERY_STRING', '') !== '', 404);

        $nonce = base64_encode(random_bytes(24));

        return response()->view('pos.browser-demo', ['nonce' => $nonce])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; img-src 'self' data:; connect-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'; worker-src 'none'; frame-src 'none'; font-src 'none'; manifest-src 'none'",
        ]);
    }
}
