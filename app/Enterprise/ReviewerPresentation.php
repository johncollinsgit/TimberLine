<?php

namespace App\Enterprise;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Public fictional presentation only: no tenant, account, session, operational SQL or provider resolution. */
class ReviewerPresentation
{
    public function respond(Request $request, bool $workflow = false, ?array $campusReviewContext = null): Response
    {
        abort_unless(config('enterprise.reviewer_demo_enabled'), 404);
        $host = strtolower($request->getHost());
        $canonical = strtolower((string) config('tenancy.domains.canonical.landlord_host'));
        $local = app()->environment(['local', 'testing']) && in_array($host, ['localhost', '127.0.0.1'], true);
        // Only the password/version-authorized proposal controller supplies this
        // context. Query strings and persona names never enable review access.
        $review = $campusReviewContext !== null && config('service_proposals.campus_review.enabled') === true;
        abort_unless(($canonical !== '' && $host === $canonical) || $local
            || ($review && in_array($host, config('service_proposals.recipient_hosts', []), true)), 404);
        abort_if($request->query->count() !== 0 || (string) $request->server('QUERY_STRING', '') !== '', 404);
        if (app()->bound('livewire')) {
            app('livewire')->flushState();
        }
        $scope = ['area_id' => '10000000-0000-4000-8000-000000000001', 'project_id' => '20000000-0000-4000-8000-000000000001'];
        $presentation = ['runtime' => 'sample', 'defaultPersona' => 'demo_manager', 'identity' => ['tenantSlug' => 'fictional-campus', 'viewer' => 'sample', 'workspace' => 'sample-campus',
            'name' => 'Lees-McRae campus demonstration', 'userName' => 'Fictional reviewer perspective'],
            'routes' => ['data' => '/enterprise/data?tenant=fictional-campus', 'commands' => '/enterprise/commands?tenant=fictional-campus',
                'history' => '/enterprise/requests', 'enterprise' => '/enterprise', 'team' => '/team', 'workflows' => '/enterprise/workflows',
                'workflowWorkspace' => '/platform/demos/lees-mcrae/workflows', 'briefing' => '/enterprise/briefing', 'briefingPdf' => '/enterprise/briefing.pdf',
                'back' => '/platform/demos/lees-mcrae', 'logout' => null],
            'locations' => $review ? $this->reviewCatalog() : app(LocationCatalog::class)->all(), 'scope' => $scope,
            'navigation' => ['area_id' => $scope['area_id'], 'section' => 'home']];
        if (($trial = $this->trialDestination($request)) !== null) {
            $presentation['routes']['trial'] = $trial;
        }
        if ($review) {
            $presentation['navigation']['section'] = $campusReviewContext['page'];
            $presentation['routes']['back'] = $workflow ? ($campusReviewContext['urls']['pages']['projects'] ?? $campusReviewContext['urls']['current']) : $campusReviewContext['urls']['current'];
            $presentation['routes']['workflowWorkspace'] = $campusReviewContext['urls']['pages']['workflows'] ?? $campusReviewContext['urls']['current'];
        }
        $bootstrap = ['project' => ['id' => $scope['project_id'], 'name' => 'Campus event readiness - sample'], 'members' => [],
            'can_design' => false, 'can_run' => false, 'items' => [], 'templates' => []];
        $html = view($workflow ? 'enterprise.workspace' : 'enterprise.app', ['presentation' => $presentation, 'enterpriseRole' => 'sample perspective',
            'scope' => $scope, 'bootstrap' => $bootstrap, 'inlineAssets' => $workflow ? $this->assets() : null,
            'voiceAssets' => null, 'campusReviewContext' => $review ? $campusReviewContext : null])->render();
        if ($review) {
            $overlay = view('enterprise.campus-review-assets', ['campusReviewContext' => $campusReviewContext, 'campusReviewLayout' => $workflow ? 'canvas' : 'shell'])->render();
            $html = str_replace('</body>', $overlay.'</body>', $html);
        }
        $nonce = base64_encode(random_bytes(24));
        // Skip whole element bodies: compiled JS can contain literal opening-tag
        // strings, which must not be rewritten as if they were document elements.
        $html = preg_replace_callback('/<(script|style)\b([^>]*)>([\s\S]*?)<\/\1\s*>/i',
            fn ($match) => '<'.$match[1].' nonce="'.$nonce.'"'.$match[2].'>'.$match[3].'</'.$match[1].'>', $html);

        $connect = $review ? "'self'" : "'none'";
        $forms = $review ? "'self'" : "'none'";

        return response($html)->withHeaders(['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; style-src-attr 'unsafe-inline'; img-src data: blob:; connect-src {$connect}; form-action {$forms}; base-uri 'none'; frame-ancestors 'none'; worker-src 'none'; frame-src 'none'; font-src 'none'; manifest-src 'none'"]);
    }

    /** The review canvas uses public map provenance, never the owner silo's private locations or employee ACLs. */
    private function reviewCatalog(): array
    {
        $catalog = json_decode(file_get_contents(resource_path('data/enterprise/lees-mcrae-locations.json')), true, 64, JSON_THROW_ON_ERROR);
        $catalog['count'] = count($catalog['entries']);

        return $catalog;
    }

    /** Link publication is separate operator approval, never trial/provider authority. No operational resolution. */
    private function trialDestination(Request $request): ?string
    {
        if (config('enterprise.reviewer_trial_destination_approved') !== true) {
            return null;
        }
        $destination = config('enterprise.reviewer_trial_destination');
        $origin = MountUrls::origin();
        $parts = is_string($destination) ? parse_url($destination) : false;
        if ($origin === null || $request->getSchemeAndHttpHost() !== $origin || ! is_array($parts)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! is_string($parts['path'] ?? null) || ! preg_match('~^(?:/[A-Za-z0-9_-]+)*/enterprise/trial$~D', $parts['path'])
            || $destination !== $origin.$parts['path']) {
            return null;
        }

        return $destination;
    }

    private function assets(): array
    {
        $directory = base_path('apps/enterprise/public/build');
        abort_unless(is_file($directory.'/manifest.json'), 503, 'The reviewed Enterprise asset bundle must be built before the sample workflow can open.');
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        $entry = $manifest['resources/js/enterprise/workspace.tsx'] ?? [];
        $read = function ($name) use ($directory): string {
            abort_unless(is_string($name) && preg_match('/^assets\/[A-Za-z0-9._-]+$/D', $name) && is_file($directory.'/'.$name)
                && filesize($directory.'/'.$name) <= 2000000, 503, 'The reviewed workflow bundle is unavailable.');

            return str_ireplace('</script', '<\/script', file_get_contents($directory.'/'.$name));
        };

        return ['js' => $read($entry['file'] ?? null), 'css' => implode("\n", array_map($read, $entry['css'] ?? []))];
    }

    private function voiceAssets(): ?string
    {
        $directory = base_path('apps/enterprise/public/build');
        if (! is_file($directory.'/manifest.json')) {
            return null;
        }
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        $entry = $manifest['resources/js/enterprise/voice-jobs-entry.js'] ?? [];
        $file = $entry['file'] ?? null;
        // A fixed, self-contained reviewed entry; no external imports or generated path.
        if (! is_string($file) || ! preg_match('/^assets\/[A-Za-z0-9._-]+$/D', $file) || ! empty($entry['imports'])
            || ! is_file($directory.'/'.$file) || filesize($directory.'/'.$file) > 250000) {
            return null;
        }

        return str_ireplace('</script', '<\/script', file_get_contents($directory.'/'.$file));
    }
}
