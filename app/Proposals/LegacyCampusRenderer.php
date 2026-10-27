<?php

namespace App\Proposals;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Fixed v5 fictional browser only. The current controller remains responsible for access and fresh feedback. */
final class LegacyCampusRenderer
{
    private LegacyCampusArtifact $artifact;

    public function __construct(?LegacyCampusArtifact $artifact = null)
    {
        $this->artifact = $artifact ?? new LegacyCampusArtifact;
    }

    public function respond(Request $request, bool $workflow, array $campusReviewContext): Response
    {
        $manifest = $this->artifact->manifest();
        abort_unless(($campusReviewContext['build_id'] ?? null) === $manifest['build_id']
            && ($campusReviewContext['source_hash'] ?? null) === $manifest['source_hash']
            && ($campusReviewContext['client_reference'] ?? null) === $manifest['client_reference']
            && ($campusReviewContext['pages'] ?? null) === $manifest['pages']
            && in_array($campusReviewContext['page'] ?? null, array_column($manifest['pages'], 'id'), true)
            && $workflow === ($campusReviewContext['page'] === 'workflows'), 409);
        $views = new LegacyCampusViews($this->artifact);
        abort_unless(config('enterprise.reviewer_demo_enabled') && config('service_proposals.campus_review.enabled') === true, 404);
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
            'locations' => $this->reviewCatalog(), 'scope' => $scope,
            'navigation' => ['area_id' => $scope['area_id'], 'section' => 'home']];
        if ($review) {
            $presentation['navigation']['section'] = $campusReviewContext['page'];
            $presentation['routes']['back'] = $workflow ? ($campusReviewContext['urls']['pages']['projects'] ?? $campusReviewContext['urls']['current']) : $campusReviewContext['urls']['current'];
            $presentation['routes']['workflowWorkspace'] = $campusReviewContext['urls']['pages']['workflows'] ?? $campusReviewContext['urls']['current'];
        }
        $bootstrap = ['project' => ['id' => $scope['project_id'], 'name' => 'Campus event readiness - sample'], 'members' => [],
            'can_design' => false, 'can_run' => false, 'items' => [], 'templates' => []];
        $html = $views->render($workflow ? 'enterprise.workspace' : 'enterprise.app', ['presentation' => $presentation, 'enterpriseRole' => 'sample perspective',
            'scope' => $scope, 'bootstrap' => $bootstrap, 'inlineAssets' => $workflow ? $this->assets() : null,
            'voiceAssets' => null, 'campusReviewContext' => $review ? $campusReviewContext : null]);
        if ($review) {
            $overlay = $views->render('enterprise.campus-review-assets', ['campusReviewContext' => $campusReviewContext, 'campusReviewLayout' => $workflow ? 'canvas' : 'shell']);
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
        $catalog = json_decode($this->artifact->bytes('resources/data/enterprise/lees-mcrae-locations.json'), true, 64, JSON_THROW_ON_ERROR);
        $catalog['count'] = count($catalog['entries']);

        return $catalog;
    }

    private function assets(): array
    {
        $prefix = 'apps/enterprise/public/build/';
        $manifest = json_decode($this->artifact->bytes($prefix.'manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        $entry = $manifest['resources/js/enterprise/workspace.tsx'] ?? [];
        $read = function ($name) use ($prefix): string {
            abort_unless(is_string($name) && preg_match('/^assets\/[A-Za-z0-9._-]+$/D', $name), 503);

            return str_ireplace('</script', '<\\/script', $this->artifact->bytes($prefix.$name));
        };

        return ['js' => $read($entry['file'] ?? null), 'css' => implode("\n", array_map($read, $entry['css'] ?? []))];
    }
}
