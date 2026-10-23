<?php

namespace App\Proposals;

use Closure;
use Illuminate\Http\Request;

class ResponsePolicy
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('service_proposals.enabled'), 404);
        // Standalone proposal documents must not inherit another response's Livewire assets.
        if (app()->bound('livewire')) {
            app('livewire')->flushState();
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        if ($request->attributes->get('service_proposal_campus_review') !== true || ! $response->headers->has('Content-Security-Policy')) {
            $artwork = $request->getSchemeAndHttpHost().'/images/proposals/evergrove-mountain-corner.svg';
            $wordmark = $request->getSchemeAndHttpHost().'/images/proposals/evergrove-software-wordmark.jpg';
            $navigation = file_get_contents(resource_path('js/service-proposals/mobile-navigation.js'));
            $content = $response->getContent();
            $script = is_string($content) && str_contains($content, '<script data-proposal-navigation>'.$navigation.'</script>')
                ? " script-src 'sha256-".base64_encode(hash('sha256', $navigation, true))."'; script-src-attr 'none';"
                : '';
            $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src {$artwork} {$wordmark}; style-src 'unsafe-inline';{$script} form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        }

        return $response;
    }
}
