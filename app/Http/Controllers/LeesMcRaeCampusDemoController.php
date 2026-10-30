<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A static fictional prototype. Never resolves a college, tenant or account. */
class LeesMcRaeCampusDemoController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (config('enterprise.reviewer_demo_enabled')) {
            return app(\App\Enterprise\ReviewerPresentation::class)->respond($request);
        }
        if (config('campus_facilities.enabled') && ! $request->is('*/classic')) {
            return app(CampusFacilitiesDemoController::class)->index($request);
        }
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
        $html = preg_replace('/<\/head>/i', self::shareMetadata($canonical).'</head>', $html, 1);
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

    public static function shareMetadata(string $canonical): string
    {
        $origin = 'https://'.$canonical;
        $url = htmlspecialchars($origin.'/platform/demos/lees-mcrae', ENT_QUOTES, 'UTF-8');
        $image = htmlspecialchars($origin.'/images/demos/lees-mcrae-share-v1.jpg', ENT_QUOTES, 'UTF-8');
        $icon = base64_encode(file_get_contents(public_path('brand/everbranch-mark.png')));
        $metadata = <<<HTML
<link rel="icon" type="image/png" sizes="512x512" href="data:image/png;base64,{$icon}">
<meta name="description" content="Explore the Lees-McRae campus workspace demo by Everbranch. Fictional work orders, campus projects and team views.">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Everbranch">
<meta property="og:title" content="Lees-McRae · Campus workspace demo">
<meta property="og:description" content="A connected campus workspace by Everbranch. Explore the interactive demonstration.">
<meta property="og:url" content="{$url}">
<meta property="og:image" content="{$image}">
<meta property="og:image:secure_url" content="{$image}">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Lees-McRae College campus with the official college wordmark and Everbranch logo.">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Lees-McRae · Campus workspace demo">
<meta name="twitter:description" content="A connected campus workspace by Everbranch. Explore the interactive demonstration.">
<meta name="twitter:image" content="{$image}">
<meta name="twitter:image:alt" content="Lees-McRae College campus with the official college wordmark and Everbranch logo.">
HTML;

        return $metadata;
    }

    public function workflow(Request $request): Response
    {
        if (! config('enterprise.reviewer_demo_enabled')) {
            $host = strtolower($request->getHost());
            $canonical = strtolower((string) config('tenancy.domains.canonical.landlord_host'));
            $localPreview = app()->environment(['local', 'testing']) && in_array($host, ['localhost', '127.0.0.1'], true);
            abort_unless(($canonical !== '' && $host === $canonical) || $localPreview, 404);
            abort_if($request->query->count() !== 0 || (string) $request->server('QUERY_STRING', '') !== '', 404);

            // Keep the unpublished workflow unavailable, with a safe path back
            // to the existing fictional demo. No renderer or feature is enabled.
            $nonce = base64_encode(random_bytes(24));

            return response()->view('enterprise.workflow-preview-unavailable', ['nonce' => $nonce], 404)->withHeaders([
                'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private',
                'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow, noarchive',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; script-src 'none'; style-src 'nonce-{$nonce}'; connect-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'",
            ]);
        }

        return app(\App\Enterprise\ReviewerPresentation::class)->respond($request, true);
    }
}
