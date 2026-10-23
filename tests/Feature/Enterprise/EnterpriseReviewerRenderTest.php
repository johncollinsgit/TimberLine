<?php

use App\Enterprise\LocationCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function reviewerRenderPresentation(): array
{
    return ['runtime' => 'sample', 'identity' => ['tenantSlug' => 'fictional-campus', 'viewer' => 'sample', 'workspace' => 'sample-campus',
        'name' => 'Fictional reviewer campus', 'userName' => 'Fictional reviewer perspective'],
        'routes' => ['data' => '/enterprise/data?tenant=fictional-campus', 'commands' => '/enterprise/commands?tenant=fictional-campus',
            'history' => '/enterprise/requests', 'enterprise' => '/enterprise', 'team' => '/team', 'workflows' => '/enterprise/workflows',
            'workflowWorkspace' => '/platform/demos/lees-mcrae/workflows', 'briefing' => '/enterprise/briefing', 'briefingPdf' => '/enterprise/briefing.pdf',
            'back' => '/platform/demos/lees-mcrae', 'logout' => null],
        'scope' => ['area_id' => '10000000-0000-4000-8000-000000000001', 'project_id' => '20000000-0000-4000-8000-000000000001'],
        'initial' => ['project' => ['id' => '20000000-0000-4000-8000-000000000001', 'name' => 'Fictional project'],
            'members' => [], 'can_design' => false, 'can_run' => false, 'items' => [], 'templates' => []],
        'locations' => app(LocationCatalog::class)->all()];
}

test('the actual Enterprise shell renders an explicit sample presentation without tenant account or auth route context', function (): void {
    Http::preventStrayRequests();
    expect(auth()->check())->toBeFalse();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $html = view('enterprise.app', ['presentation' => reviewerRenderPresentation(), 'enterpriseRole' => 'sample perspective'])->render();
    expect($html)->toContain('Fictional reviewer campus', 'EverbranchSampleTransport', 'SAMPLE DATA', 'Demo · Sample data', 'Email preview · not sent');
    expect($html)->not->toContain('id="enterprise-logout"');
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    Http::assertNothingSent();
});

test('the actual workflow document uses the sample adapter and explicit bootstrap without native auth or asset lookup', function (): void {
    Http::preventStrayRequests();
    expect(auth()->check())->toBeFalse();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $html = view('enterprise.workspace', ['presentation' => reviewerRenderPresentation(), 'inlineAssets' => ['css' => '/* synthetic test asset */', 'js' => '/* synthetic test asset */']])->render();
    expect($html)->toContain('EverbranchSampleTransport', 'id="enterprise-workflow"', 'Fictional project', 'synthetic test asset', 'Fictional reviewer campus');
    expect($html)->not->toContain('/auth/google', 'id="enterprise-logout"', 'vite/client');
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    Http::assertNothingSent();
});

test('private workspace tour requires both server flags and never includes sample adapter or perspective controls', function (): void {
    Http::preventStrayRequests();
    $presentation = reviewerRenderPresentation();
    $presentation['runtime'] = 'private';
    $presentation['identity']['userName'] = 'Signed-in reviewer';
    $presentation['routes']['logout'] = '/logout';
    config(['enterprise.runtime' => true]);
    foreach ([[false, false], [true, false], [false, true], [true, true]] as [$enabled, $sampleData]) {
        config(['enterprise.tour_enabled' => $enabled, 'enterprise.sample_data' => $sampleData]);
        $html = view('enterprise.app', ['presentation' => $presentation, 'enterpriseRole' => 'current member'])->render();
        expect($html)->not->toContain('EverbranchSampleTransport =', 'id="reviewer-perspective"', 'id="reviewer-reset-open"', 'sample-request-audio');
        if ($enabled && $sampleData) {
            expect($html)->toContain('Demo · Sample data', 'EverbranchWorkspaceTour.mount', 'DEMO · SAMPLE DATA', 'Email preview unavailable in this navigation tour');
            $document = new DOMDocument;
            @$document->loadHTML($html);
            expect((new DOMXPath($document))->query('//*[@data-tour-email-cta]')->length)->toBe(0);
            $xpath = new DOMXPath($document);
            expect($xpath->query('//*[@data-tour-open]')->item(0)->textContent)->toBe('Tour');
            expect($xpath->query('//*[contains(@class,"ert-word")]/small')->item(0)->textContent)->toContain($presentation['identity']['name'])->not->toContain('Lees-McRae');
            expect($html)->not->toContain('class="ert-email-paper"');
        } else {
            expect($html)->not->toContain('data-reviewer-tour-root', 'EverbranchWorkspaceTour.mount');
        }
    }
    config(['enterprise.runtime' => false, 'enterprise.tour_enabled' => true, 'enterprise.sample_data' => true]);
    $html = view('enterprise.app', ['presentation' => $presentation, 'enterpriseRole' => 'current member'])->render();
    expect($html)->not->toContain('data-reviewer-tour-root', 'EverbranchWorkspaceTour.mount');
    Http::assertNothingSent();
});
