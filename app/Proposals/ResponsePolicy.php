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
        if (($request->attributes->get('service_proposal_campus_review') !== true
            && $request->attributes->get('service_proposal_legacy_capture_response') !== true) || ! $response->headers->has('Content-Security-Policy')) {
            $artwork = $request->getSchemeAndHttpHost().'/images/proposals/evergrove-mountain-corner.svg';
            $wordmark = $request->getSchemeAndHttpHost().'/images/proposals/evergrove-software-wordmark.jpg';
            $content = $response->getContent();
            $hashes = [];
            $hasActivity = false;
            foreach (['proposal-navigation' => 'mobile-navigation.js', 'review-activity-script' => 'activity.js'] as $marker => $file) {
                $script = file_get_contents(resource_path('js/service-proposals/'.$file));
                if (is_string($content) && str_contains($content, '<script data-'.$marker.'>'.$script.'</script>')) {
                    $hashes[] = "'sha256-".base64_encode(hash('sha256', $script, true))."'";
                    $hasActivity = $hasActivity || $marker === 'review-activity-script';
                }
            }
            $scripts = $hashes ? ' script-src '.implode(' ', $hashes)."; script-src-attr 'none';" : '';
            $connections = $hasActivity ? " connect-src 'self';" : '';
            $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src {$artwork} {$wordmark}; style-src 'unsafe-inline';{$scripts}{$connections} form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        }

        return $response;
    }
}
