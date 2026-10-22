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
    expect($html)->toContain('Fictional reviewer campus', 'EverbranchSampleTransport', 'SAMPLE DATA', 'Show me Lees-McRae', 'Email preview · not sent');
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
