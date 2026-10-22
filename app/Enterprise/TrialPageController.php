<?php

namespace App\Enterprise;

use App\Enterprise\Trial\ReviewerTrial;
use App\Enterprise\Trial\TrialStore;
use Illuminate\Http\Request;

/** Dedicated, controlled reviewer pages. The public fictional demo never renders these. */
final class TrialPageController
{
    private function guard(Request $request): void
    {
        abort_unless(config('enterprise.runtime') === true && config('enterprise.trial.enabled') === true, 404);
        $origin = config('enterprise.trial.canonical_origin');
        $parts = is_string($origin) ? parse_url($origin) : false;
        abort_unless(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && isset($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['path']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && $origin === rtrim((string) config('app.url'), '/') && $request->getSchemeAndHttpHost() === $origin, 404);
        // Invitations, contacts and capability authority never come from query parameters.
        abort_unless($request->query() === [], 404);
        app(TrialStore::class)->tenant();
    }

    private function page(string $view, array $boot)
    {
        $nonce = base64_encode(random_bytes(24));
        $html = view($view, ['boot' => $boot])->render();
        $html = str_replace(['<script>', '<style>'], ['<script nonce="'.$nonce.'">', '<style nonce="'.$nonce.'">'], $html);

        return response($html)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; style-src-attr 'unsafe-inline'; connect-src 'self'; img-src 'self' data: blob:; frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'");
    }

    public function index(Request $request)
    {
        $this->guard($request);

        return $this->page('enterprise.trial', ['base' => '/enterprise/trial']);
    }

    public function task(Request $request, string $token, ReviewerTrial $trial)
    {
        $this->guard($request);
        $trial->capability($token); // Does not mark an open; the separate data request does.

        return $this->page('enterprise.trial-task', [
            'base' => '/enterprise/trial/task/'.rawurlencode($token),
            'storage_key' => 'everbranch-controlled-task:'.hash('sha256', $token),
        ]);
    }
}
