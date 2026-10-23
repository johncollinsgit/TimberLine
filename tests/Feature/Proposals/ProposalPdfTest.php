<?php

use App\Models\Tenant;
use App\Models\User;
use App\Proposals\PdfDocument;
use App\Proposals\ProposalService;
use App\Proposals\Version;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->pdfOriginalStoragePath = storage_path();
    $this->pdfOriginalCompiledViews = config('view.compiled');
    $this->pdfTestStoragePath = sys_get_temp_dir().'/proposal-pdf-test-'.Str::uuid();
    foreach (['app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
        (new \Illuminate\Filesystem\Filesystem)->makeDirectory($this->pdfTestStoragePath.'/'.$directory, 0700, true);
    }
    $this->app->useStoragePath($this->pdfTestStoragePath);
    config(['view.compiled' => $this->pdfTestStoragePath.'/framework/views']);
    $this->app->forgetInstance('blade.compiler');
    $this->app->make('view.engine.resolver')->forget('blade');
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    $this->tenant = Tenant::create(['name' => 'Private PDF test owner', 'slug' => 'proposal-pdf-test']);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    config(['service_proposals.enabled' => true, 'service_proposals.writes_enabled' => true, 'service_proposals.publish_enabled' => true,
        'service_proposals.owner_workspaces' => [$this->tenant->id => 'enterprise'],
        'service_proposals.recipient_hosts' => ['evergrove.test'], 'evergrove.hosts' => ['evergrove.test']]);
    $this->service = app(ProposalService::class);
    $this->draft = $this->service->leesMcRaeDraft($this->tenant, $this->owner->id);
    $this->ownerPdf = '/owner-workspaces/'.$this->tenant->slug.'/proposals/'.$this->draft->id.'/pdf';
});

afterEach(function (): void {
    $this->app->useStoragePath($this->pdfOriginalStoragePath);
    config(['view.compiled' => $this->pdfOriginalCompiledViews]);
    $this->app->forgetInstance('blade.compiler');
    $this->app->make('view.engine.resolver')->forget('blade');
    (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->pdfTestStoragePath);
});

test('PDF requires the exact recipient unlock and host and expires with its version', function (): void {
    $published = $this->service->publish($this->tenant, $this->owner->id, $this->draft->id, 1, 'A synthetic PDF password', 7, Str::uuid()->toString(), 'reviewer');
    $url = 'https://evergrove.test/service-proposals/'.$published['token'];
    $this->get($url.'/pdf')->assertForbidden()->assertDontSee('%PDF');
    $this->get('https://other.test/service-proposals/'.$published['token'].'/pdf')->assertNotFound();
    $this->post($url.'/unlock', ['password' => 'A synthetic PDF password'])->assertRedirect();
    $response = $this->get($url.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->getContent())->toStartWith('%PDF-')->and($response->headers->get('Cache-Control'))->toContain('private', 'no-store')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment;', '.pdf')
        ->and(Version::count())->toBe(1);
    $published['version']->update(['revoked_at' => now()]);
    $this->get($url.'/pdf')->assertGone();
    $another = $this->service->publish($this->tenant, $this->owner->id, $this->draft->id, 1, 'A synthetic PDF password', 7, Str::uuid()->toString(), 'reviewer');
    $otherUrl = 'https://evergrove.test/service-proposals/'.$another['token'];
    $this->post($otherUrl.'/unlock', ['password' => 'A synthetic PDF password']);
    $this->travel(8)->days();
    $this->get($otherUrl.'/pdf')->assertGone();
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('PDF owner download rechecks exact active membership and scoped draft ownership', function (): void {
    $this->get($this->ownerPdf)->assertRedirect();
    $this->actingAs($this->owner)->get($this->ownerPdf)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $other = Tenant::create(['name' => 'Other PDF workspace', 'slug' => 'other-pdf-workspace']);
    config(['service_proposals.owner_workspaces.'.$other->id => 'enterprise']);
    $this->owner->tenants()->attach($other, ['role' => 'owner', 'membership_active' => true]);
    $this->get('/owner-workspaces/'.$other->slug.'/proposals/'.$this->draft->id.'/pdf')->assertNotFound();
    $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    $this->get($this->ownerPdf)->assertForbidden();
});

test('download link is at the top of the private reader and owner preview', function (): void {
    $ownerHtml = $this->actingAs($this->owner)->get(str_replace('/pdf', '/preview', $this->ownerPdf))->assertOk()->getContent();
    expect(strpos($ownerHtml, 'Download PDF'))->toBeLessThan(strpos($ownerHtml, 'A clearer way forward.'));
    expect($ownerHtml)->toContain($this->ownerPdf);
});

test('PDF renders exact saved v9 commercial copy and legacy copy with no markup execution', function (): void {
    $payload = $this->draft->payload;
    $details = json_decode(file_get_contents(base_path('tests/Fixtures/proposals/lees-mcrae-v9-commercial.json')), true, 512, JSON_THROW_ON_ERROR)['client_details'];
    $payload['client_details'] = $details;
    $payload['items'] = array_map(fn ($item) => [...$item, 'terms_months' => [12]], $payload['items']);
    $payload['intro'] = '<img src="https://not-allowed.test/private" onerror="bad()"><script>bad()</script>';
    $html = view('service-proposals.pdf', ['payload' => $payload, 'edition' => 'Synthetic review', 'savedAt' => '2026-10-23T00:00:00Z', 'contentHash' => str_repeat('a', 64), 'wordmark' => '', 'sections' => \App\Proposals\CommercialSections::from($details)])->render();
    foreach ($details['commercial_sections'] as $section) {
        expect($html)->toContain(e($section['heading']));
        foreach ($section['paragraphs'] ?? [] as $paragraph) {
            expect($html)->toContain(e($paragraph));
        }
        foreach ($section['bullets'] ?? [] as $bullet) {
            expect($html)->toContain(e($bullet));
        }
    }
    expect($html)->not->toContain('<script>bad()', '<img src="https://not-allowed.test');
    $bytes = app(PdfDocument::class)->render($payload, 'Synthetic review', '2026-10-23T00:00:00Z', str_repeat('a', 64));
    expect($bytes)->toStartWith('%PDF-')->and(strlen($bytes))->toBeGreaterThan(10000);
    expect(File::directories(storage_path('app/private/proposal-pdf')))->toBe([]);
    if (getenv('PROPOSAL_PDF_CAPTURE')) {
        file_put_contents(getenv('PROPOSAL_PDF_CAPTURE'), app(PdfDocument::class)->render([...$payload, 'intro' => $this->draft->payload['intro']], 'Synthetic review', '2026-10-23T00:00:00Z', str_repeat('a', 64)));
    }
    $legacyBytes = app(PdfDocument::class)->render($this->draft->payload, 'Legacy synthetic review', '2026-10-23T00:00:00Z');
    expect($legacyBytes)->toStartWith('%PDF-');
    Http::assertNothingSent();
});

test('PDF cleanup also runs after rendering fails and oversized snapshots refuse before rendering', function (): void {
    $wordmark = base_path('public/images/proposals/evergrove-software-wordmark.jpg');
    File::partialMock()->shouldReceive('get')->once()->with($wordmark)->andThrow(new RuntimeException('Synthetic artwork read failure'));
    expect(fn () => app(PdfDocument::class)->render($this->draft->payload, 'Draft', '2026-10-23T00:00:00Z'))->toThrow(RuntimeException::class, 'Synthetic artwork read failure');
    expect(File::directories(storage_path('app/private/proposal-pdf')))->toBe([]);
    $payload = $this->draft->payload;
    $payload['intro'] = str_repeat('long ', 50000);
    expect(fn () => app(PdfDocument::class)->render($payload, 'Draft', '2026-10-23T00:00:00Z'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(File::directories(storage_path('app/private/proposal-pdf')))->toBe([]);
});
