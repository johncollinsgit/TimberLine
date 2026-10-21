<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A static fictional prototype. Never resolves a college, tenant or account. */
class LeesMcRaeCampusDemoController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $host = strtolower($request->getHost());
        $canonical = strtolower((string) config('tenancy.domains.canonical.landlord_host'));
        $localPreview = app()->environment(['local', 'testing']) && in_array($host, ['localhost', '127.0.0.1'], true);
        abort_unless(($canonical !== '' && $host === $canonical) || $localPreview, 404);
        abort_if($request->query->count() !== 0 || (string) $request->server('QUERY_STRING', '') !== '', 404);

        // Follow the existing standalone POS demo: retained Livewire assets must
        // not enter this document or change later ordinary asset injection.
        app('livewire')->flushState();

        $nonce = base64_encode(random_bytes(24));
        $html = file_get_contents(resource_path('demos/lees-mcrae-campus.html'));
        $html = preg_replace('/<(script|style)(?=[\s>])/i', '<$1 nonce="'.$nonce.'"', $html);

        return response($html)->withHeaders([
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'X-Content-Type-Options' => 'nosniff',
            // The existing prototype/Leaflet renderer uses inline style
            // attributes. Scripts and stylesheet elements still require nonce.
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; style-src-attr 'unsafe-inline'; img-src data: blob:; connect-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'; worker-src 'none'; frame-src 'none'; font-src 'none'; manifest-src 'none'",
        ]);
    }
}
