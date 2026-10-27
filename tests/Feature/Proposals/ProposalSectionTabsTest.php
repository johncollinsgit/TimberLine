<?php

use App\Models\Tenant;
use App\Models\User;
use App\Proposals\CommercialSections;
use App\Proposals\ProposalService;
use App\Proposals\Review;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    $this->tabTenant = Tenant::create(['name' => 'Synthetic proposal tabs', 'slug' => 'synthetic-proposal-tabs']);
    $this->tabOwner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->tabOwner->tenants()->attach($this->tabTenant, ['role' => 'owner', 'membership_active' => true]);
    config(['app.url' => 'https://evergrove.test', 'enterprise_runtime.base_path' => '',
        'service_proposals.enabled' => true, 'service_proposals.writes_enabled' => true, 'service_proposals.publish_enabled' => true,
        'service_proposals.owner_workspaces' => [$this->tabTenant->id => 'enterprise'], 'service_proposals.recipient_hosts' => ['evergrove.test'],
        'evergrove.hosts' => ['evergrove.test']]);
    $this->tabService = app(ProposalService::class);
    $this->tabDraft = $this->tabService->leesMcRaeDraft($this->tabTenant, $this->tabOwner->id);
});

function proposalTabsPublish($test, string $audience = 'client', bool $legacy = false): array
{
    if (! $legacy) {
        $payload = $test->tabDraft->payload;
        $copy = json_decode(file_get_contents(base_path('tests/Fixtures/proposals/lees-mcrae-v9-commercial.json')), true, flags: JSON_THROW_ON_ERROR);
        $template = $test->tabService->saveAsset($test->tabTenant, $test->tabOwner->id, 'template', ['name' => 'Synthetic exact template', 'text' => 'Synthetic master language retained verbatim for screen and print.']);
        $payload['template'] = ['id' => $template->id, 'revision' => $template->revision, ...$template->payload];
        $payload['client_details'] = $copy['client_details'];
        $origins = $overrides = [];
        foreach ($payload['items'] as &$item) {
            $asset = $test->tabService->asset($test->tabTenant, $item['id'], 'catalog');
            $origins[$item['id']] = ['revision' => $asset->revision, 'payload_sha256' => hash('sha256', json_encode($asset->payload, JSON_THROW_ON_ERROR)), 'terms_months' => $asset->payload['terms_months']];
            $overrides[$item['id']] = ['terms_months' => [12]];
            $item['terms_months'] = [12];
        }
        unset($item);
        $payload['unresolved'] = $copy['unresolved'];
        $payload['journey'][0]['gate'] = 'Agree the discovery sessions and the initial department focus.';
        $payload['commercial_snapshot'] = ['schema_version' => 1, 'source_reference' => 'Synthetic reviewed PDF v9', 'source_copy_sha256' => hash_file('sha256', base_path('tests/Fixtures/proposals/lees-mcrae-v9-commercial.json')), 'catalog_origins' => $origins, 'item_overrides' => $overrides];
        $payload['commercial_snapshot']['protected_sha256'] = \App\Proposals\CommercialSnapshot::hash($payload);
        $test->tabDraft->update(['payload' => $payload]);
    }

    return $test->tabService->publish($test->tabTenant, $test->tabOwner->id, $test->tabDraft->id, 1,
        'Synthetic proposal tabs password', 7, Str::uuid()->toString(), $audience);
}

function proposalTabsDom(string $html): DOMXPath
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html);

    return new DOMXPath($dom);
}

function proposalTabsCapture(string $name, $response): void
{
    $document = proposalTabsDom($response->getContent());
    if ($document->query('//fieldset[contains(@class,"proposal-section-tabs")]')->length > 0) {
        $scripts = $document->query('//script');
        expect($scripts->length)->toBe(1)
            ->and($scripts->item(0)->hasAttribute('data-proposal-navigation'))->toBeTrue()
            ->and($scripts->item(0)->hasAttribute('src'))->toBeFalse()
            ->and($scripts->item(0)->textContent)->toBe(file_get_contents(resource_path('js/service-proposals/mobile-navigation.js')))
            ->and($response->headers->get('Content-Security-Policy'))->toContain("script-src 'sha256-".base64_encode(hash_file('sha256', resource_path('js/service-proposals/mobile-navigation.js'), true))."'", "script-src-attr 'none'");
    }
    if (getenv('PROPOSAL_TABS_CAPTURE') !== '1') {
        return;
    }
    @mkdir(base_path('.tmp/proposal-tabs'), 0777, true);
    file_put_contents(base_path('.tmp/proposal-tabs/'.$name.'.html'), $response->getContent());
    file_put_contents(base_path('.tmp/proposal-tabs/'.$name.'-headers.json'), json_encode($response->headers->all(), JSON_THROW_ON_ERROR));
}

function proposalTabsSavePersonalLetter($test): \App\Proposals\Draft
{
    $test->tabDraft->refresh();
    $payload = $test->tabDraft->payload;

    return $test->tabService->saveDraft($test->tabTenant, $test->tabOwner->id, [
        'client_id' => $test->tabDraft->client_id, 'title' => $payload['title'], 'intro' => $payload['intro'],
        'catalog_ids' => array_column($payload['items'], 'id'), 'template_id' => $payload['template']['id'] ?? null,
        'scope_notes' => $payload['scope_notes'], 'usage_plan' => $payload['usage_plan'],
        'approach_key' => $payload['approach_key'], 'reviewer_names' => $payload['reviewer_names'],
        'include_personal_letter' => 1, 'revision' => $test->tabDraft->revision,
    ], $test->tabDraft->id);
}

test('the approved personal letter is the first selected real tab only after explicit owner draft opt in', function (): void {
    $path = 'https://evergrove.test/owner-workspaces/'.$this->tabTenant->slug.'/proposals/'.$this->tabDraft->id.'/preview';
    $old = $this->actingAs($this->tabOwner)->get($path)->assertOk();
    expect(proposalTabsDom($old->getContent())->query('//input[@data-proposal-section="letter"]')->length)->toBe(0);
    $old->assertHeader('Content-Security-Policy');
    expect($old->headers->get('Content-Security-Policy'))->not->toContain('collins-family', 'everbranch-enterprise-e57d8900');

    $saved = proposalTabsSavePersonalLetter($this);
    $letter = \App\Proposals\PersonalLetter::snapshot();
    expect($saved->payload['personal_letter'])->toBe($letter);
    $response = $this->get($path)->assertOk();
    $xpath = proposalTabsDom($response->getContent());
    expect($xpath->query('//input[@data-proposal-section]')->item(0)->getAttribute('data-proposal-section'))->toBe('letter')
        ->and($xpath->query('//input[@data-proposal-section="letter"][@checked]')->length)->toBe(1)
        ->and($xpath->query('//input[@data-proposal-section="overview"][@checked]')->length)->toBe(0)
        ->and($xpath->query('//article[@class="proposal-personal-letter"]')->length)->toBe(1)
        ->and($xpath->query('//article[@class="proposal-personal-letter"]//img')->length)->toBe(2);
    foreach ([$letter['salutation'], ...$letter['paragraphs'], $letter['signer'], $letter['role'], $letter['caption']] as $copy) {
        $response->assertSee(e($copy), false);
    }
    expect($response->headers->get('Content-Security-Policy'))->toContain('https://evergrove.test'.$letter['logo_asset'], 'https://evergrove.test'.$letter['family_asset'])
        ->not->toContain("img-src 'self'", 'https://external.test');
    proposalTabsCapture('personal-letter', $response);
    $selected = $this->get($path.'?section=scope')->assertOk();
    expect(proposalTabsDom($selected->getContent())->query('//input[@data-proposal-section="scope"][@checked]')->length)->toBe(1);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('adding the letter creates a new immutable review without rewriting the old edition or bypassing its password', function (): void {
    ['version' => $old, 'token' => $oldToken] = proposalTabsPublish($this, 'reviewer');
    $oldCipher = $old->getRawOriginal('payload');
    $oldHash = $old->content_hash;
    $this->tabDraft->refresh();
    $saved = proposalTabsSavePersonalLetter($this);
    ['version' => $version, 'token' => $token] = $this->tabService->publish($this->tabTenant, $this->tabOwner->id, $saved->id,
        $saved->revision, 'Synthetic personal letter password', 7, Str::uuid()->toString(), 'reviewer');
    $url = 'https://evergrove.test/service-proposals/'.$token;
    $locked = $this->get($url)->assertOk();
    $locked->assertDontSee('Dear Andrew and Blaine,')->assertDontSee('proposal-personal-letter', false);
    expect($locked->headers->get('Content-Security-Policy'))->not->toContain('collins-family');
    $this->post($url.'/unlock', ['password' => 'Synthetic personal letter password'])->assertRedirect();
    $newPage = $this->get($url)->assertOk();
    expect(proposalTabsDom($newPage->getContent())->query('//input[@data-proposal-section="letter"][@checked]')->length)->toBe(1);
    $this->post('https://evergrove.test/service-proposals/'.$oldToken.'/unlock', ['password' => 'Synthetic proposal tabs password'])->assertRedirect();
    $oldPage = $this->get('https://evergrove.test/service-proposals/'.$oldToken)->assertOk();
    expect(proposalTabsDom($oldPage->getContent())->query('//input[@data-proposal-section="letter"]')->length)->toBe(0)
        ->and($old->fresh()->getRawOriginal('payload'))->toBe($oldCipher)
        ->and($old->fresh()->content_hash)->toBe($oldHash)
        ->and($version->content_hash)->not->toBe($oldHash)
        ->and(\App\Proposals\CommercialSnapshot::hash($version->payload))->toBe(\App\Proposals\CommercialSnapshot::hash($old->payload));
});

test('personal letter content and images cannot be substituted through raw draft input or another client', function (): void {
    $payload = $this->tabDraft->payload;
    $input = ['client_id' => $this->tabDraft->client_id, 'title' => $payload['title'], 'catalog_ids' => array_column($payload['items'], 'id'),
        'revision' => 1, 'include_personal_letter' => 1, 'personal_letter' => ['family_asset' => 'https://external.test/private-photo']];
    $saved = $this->tabService->saveDraft($this->tabTenant, $this->tabOwner->id, $input, $this->tabDraft->id);
    expect($saved->payload['personal_letter'])->toBe(\App\Proposals\PersonalLetter::snapshot());
    $client = $this->tabService->asset($this->tabTenant, $this->tabDraft->client_id, 'client');
    $client->update(['payload' => [...$client->payload, 'reference' => 'another-client']]);
    expect(fn () => $this->tabService->saveDraft($this->tabTenant, $this->tabOwner->id, [...$input, 'revision' => 2], $this->tabDraft->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($saved->fresh()->revision)->toBe(2);
});

test('an unknown personal letter snapshot refuses rather than injecting an external image or changing the approved copy', function (): void {
    $saved = proposalTabsSavePersonalLetter($this);
    $payload = $saved->payload;
    $payload['personal_letter']['family_asset'] = 'https://external.test/private-photo';
    $saved->update(['payload' => $payload]);
    $this->actingAs($this->tabOwner)->get('https://evergrove.test/owner-workspaces/'.$this->tabTenant->slug.'/proposals/'.$saved->id.'/preview')->assertStatus(503);
    Http::assertNothingSent();
});

test('private password gate shows only the exact college graphic without exposing the saved proposal', function (bool $college, bool $debug): void {
    config(['app.debug' => $debug]);
    $privateIntro = 'private-intro-'.Str::uuid();
    $privateItem = 'private-service-'.Str::uuid();
    $payload = $this->tabDraft->payload;
    $payload['intro'] .= "\n".$privateIntro;
    $payload['items'][0]['description'] .= "\n".$privateItem;
    if (! $college) {
        $payload['client']['reference'] = 'another-client';
    }
    $this->tabDraft->update(['payload' => $payload]);
    ['version' => $version, 'token' => $token] = proposalTabsPublish($this, 'reviewer');
    $url = 'https://evergrove.test/service-proposals/'.$token;
    app('livewire')->forceAssetInjection();
    $response = $this->get($url)->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $xpath = proposalTabsDom($response->getContent());
    expect($xpath->query('//div[@role="img"][@aria-label="Lees-McRae College"]')->length)->toBe($college ? 1 : 0)
        ->and($xpath->query('//form[@action="'.$url.'/unlock"]//input[@type="password"][@name="password"][@autocomplete="current-password"]')->length)->toBe(1)
        ->and($xpath->query('//script | //video | //iframe | //input[@data-proposal-section]')->length)->toBe(0);
    $response->assertDontSee(e($version->payload['intro']), false)
        ->assertDontSee(e($version->payload['items'][0]['description']), false)
        ->assertDontSee('name="reviewer_name"', false)
        ->assertDontSee('name="scenario[name]"', false);
    if ($college) {
        $response->assertSee('lmc-login-settle 2800ms', false)->assertSee('prefers-reduced-motion:no-preference', false);
        proposalTabsCapture('password-gate', $response);
    }
    $foreign = $this->get(str_replace('evergrove.test', 'foreign.test', $url))->assertNotFound();
    $version->update(['revoked_at' => now()]);
    $revoked = $this->get($url)->assertGone();
    foreach ([$foreign, $revoked] as $rejected) {
        // Debug pages quote test source; assert rendered elements rather than source substrings.
        $rejectedDom = proposalTabsDom($rejected->getContent());
        expect($rejectedDom->query('//*[contains(concat(" ", normalize-space(@class), " "), " lmc-login__mark ")] | //*[@role="img"][@aria-label="Lees-McRae College"]')->length)->toBe(0)
            ->and($rejectedDom->query('//input[@type="password"] | //input[@data-proposal-section] | //input[@name="reviewer_name"] | //input[@name="scenario[name]"]')->length)->toBe(0);
        $rejected->assertDontSee($privateIntro, false)->assertDontSee($privateItem, false);
    }
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with(['college non-debug' => [true, false], 'other non-debug' => [false, false], 'college debug' => [true, true], 'other debug' => [false, true]]);

test('owner preview renders native section controls with the exact approved graphic only for the college', function (): void {
    $path = 'https://evergrove.test/owner-workspaces/'.$this->tabTenant->slug.'/proposals/'.$this->tabDraft->id.'/preview';
    app('livewire')->forceAssetInjection();
    $response = $this->actingAs($this->tabOwner)->get($path)->assertOk();
    $xpath = proposalTabsDom($response->getContent());
    expect($xpath->query('//fieldset[contains(@class,"proposal-section-tabs")]')->length)->toBe(1)
        ->and($xpath->query('//input[@data-proposal-section]')->length)->toBe(4)
        ->and($xpath->query('//input[@data-proposal-section="overview"][@checked]')->length)->toBe(1)
        ->and($xpath->query('//input[@data-proposal-section][@aria-selected]')->length)->toBe(0)
        ->and($xpath->query('//div[@role="img"][@aria-label="Lees-McRae College"]')->length)->toBe(1)
        ->and($xpath->query('//script[not(@data-proposal-navigation)] | //video | //iframe')->length)->toBe(0);
    proposalTabsCapture('owner-preview', $response);
    $payload = $this->tabDraft->payload;
    $payload['client']['reference'] = 'another-client';
    $this->tabDraft->update(['payload' => $payload]);
    expect(proposalTabsDom($this->get($path)->assertOk()->getContent())->query('//div[@role="img"][@aria-label="Lees-McRae College"]')->length)->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('proposal documents clear retained Livewire assets without disabling a later Livewire page', function (): void {
    Route::get('/fixture-proposal-livewire-next', fn () => response('<html><head></head><body>Fictional next page</body></html>'));
    try {
        app('livewire')->forceAssetInjection();
        $preview = $this->actingAs($this->tabOwner)->get('https://evergrove.test/owner-workspaces/'.$this->tabTenant->slug.'/proposals/'.$this->tabDraft->id.'/preview')->assertOk();
        expect(proposalTabsDom($preview->getContent())->query('//script[not(@data-proposal-navigation)] | //video | //iframe')->length)->toBe(0)
            ->and($preview->headers->get('Content-Security-Policy'))->toContain("default-src 'none'", "form-action 'self'");
        proposalTabsCapture('owner-preview', $preview);
        app('livewire')->forceAssetInjection();
        $next = $this->get('https://evergrove.test/fixture-proposal-livewire-next')->assertOk();
        expect(proposalTabsDom($next->getContent())->query('//script[@src][@data-update-uri]')->length)->toBe(1);
        $next->assertSee('Livewire Styles', false);
    } finally {
        app('livewire')->flushState();
    }
});

test('client panels retain every exact stored commercial paragraph and original native form identities', function (): void {
    ['version' => $version, 'token' => $token] = proposalTabsPublish($this);
    $url = 'https://evergrove.test/service-proposals/'.$token;
    $raw = $version->getRawOriginal('payload');
    $this->post($url.'/unlock', ['password' => 'Synthetic proposal tabs password'])->assertRedirect();
    $this->post($url.'/reviewer-name', ['reviewer_name' => 'Ross'])->assertRedirect($url.'?section=review');
    $response = $this->get($url.'?section=scope')->assertOk();
    $xpath = proposalTabsDom($response->getContent());
    expect($xpath->query('//input[@data-proposal-section="scope"][@checked]')->length)->toBe(1)
        ->and($xpath->query('//input[@data-proposal-section]')->length)->toBe(5)
        ->and($xpath->query('//form//input[@data-proposal-section]')->length)->toBe(0)
        ->and($xpath->query('//form[@action="'.$url.'/selection"]//input[@name="selected[]"]')->length)->toBe(2)
        ->and($xpath->query('//form[@action="'.$url.'/selection"]//input[@name="version_hash"]')->item(0)->getAttribute('value'))->toBe($version->content_hash)
        ->and($xpath->query('//form[@action="'.$url.'/comments"]//textarea[@name="body"]')->length)->toBe(1)
        ->and($xpath->query('//section[@data-proposal-panel="scope"]//*[@id="usage-estimator"]')->length)->toBe(1)
        ->and($xpath->query('//section[@data-proposal-panel="terms"]//*[@id="usage-estimator"]')->length)->toBe(0);
    foreach (CommercialSections::from($version->payload['client_details']) as $section) {
        $response->assertSee(e($section['heading']), false);
        foreach (array_merge($section['paragraphs'] ?? [], $section['bullets'] ?? []) as $text) {
            $response->assertSee(e($text), false);
        }
        foreach ($section['tables'] ?? [] as $table) {
            foreach (array_merge($table['columns'], ...$table['rows']) as $text) {
                $response->assertSee(e($text), false);
            }
        }
    }
    expect($version->fresh()->getRawOriginal('payload'))->toBe($raw)->and($version->fresh()->content_hash)->toBe($version->content_hash)
        ->and(Review::count())->toBe(0);
    proposalTabsCapture('client', $response);
});

test('reviewer and legacy publications use real available panels without creating selections or changing ciphertext', function (): void {
    ['version' => $version, 'token' => $token] = proposalTabsPublish($this, 'reviewer', true);
    $url = 'https://evergrove.test/service-proposals/'.$token;
    $raw = $version->getRawOriginal('payload');
    $this->post($url.'/unlock', ['password' => 'Synthetic proposal tabs password']);
    $response = $this->get($url.'?section=review')->assertOk()->assertSee('Reviewer access')->assertDontSee('Review selected services</button>', false);
    expect(proposalTabsDom($response->getContent())->query('//input[@data-proposal-section="review"][@checked]')->length)->toBe(1);
    foreach (['section=unknown', 'section[]=review', 'section=%22%3E%3Cscript%3E'] as $query) {
        $response = $this->get($url.'?'.$query)->assertOk();
        expect(proposalTabsDom($response->getContent())->query('//input[@data-proposal-section="overview"][@checked]')->length)->toBe(1);
    }
    $this->get('https://not-allowed.invalid/service-proposals/'.$token.'?section=review')->assertNotFound();
    expect($version->fresh()->getRawOriginal('payload'))->toBe($raw)->and(Review::count())->toBe(0);
    proposalTabsCapture('reviewer-legacy', $this->get($url.'?section=review')->assertOk());
});

test('native selection and agreement request summaries share applicable panels and preserve exact chosen prices and terms', function (): void {
    ['version' => $version, 'token' => $token] = proposalTabsPublish($this);
    $url = 'https://evergrove.test/service-proposals/'.$token;
    $this->post($url.'/unlock', ['password' => 'Synthetic proposal tabs password']);
    $this->get($url);
    $item = $version->payload['items'][1]['id'];
    $data = ['version_hash' => $version->content_hash, 'request_key' => session('service_proposal_request.'.$version->id), 'selected' => [$item], 'terms' => [$item => 12]];
    $response = $this->post($url.'/selection', $data)->assertOk()->assertSee('2,500.00')->assertSee('7,000.00')->assertSee('1 year(s)');
    expect(proposalTabsDom($response->getContent())->query('//a[@href="'.$url.'?section=scope"]')->length)->toBe(1);
    $xpath = proposalTabsDom($response->getContent());
    expect($xpath->query('//input[@data-proposal-section]')->length)->toBe(3)
        ->and($xpath->query('//input[@data-proposal-section="scope"][@checked]')->length)->toBe(1)
        ->and($xpath->query('//form//input[@name="selected[]"]')->item(0)->getAttribute('value'))->toBe($item);
    proposalTabsCapture('selection-review', $response);
    $requested = $this->post($url.'/request-agreement', $data + ['acknowledge' => 1])->assertOk()->assertSee('Your agreement request is recorded.');
    expect(proposalTabsDom($requested->getContent())->query('//input[@data-proposal-section]')->length)->toBe(3)
        ->and(Review::count())->toBe(1)->and(Review::sole()->payload['selection']['totals_cents'])->toBe(['month' => 250000, 'one_time' => 700000]);
    proposalTabsCapture('requested', $requested);
});

test('multiple native manager summaries have independent accessible panel IDs and radio groups', function (): void {
    for ($index = 0; $index < 2; $index++) {
        ['version' => $version, 'token' => $token] = proposalTabsPublish($this);
        $url = 'https://evergrove.test/service-proposals/'.$token;
        $this->post($url.'/unlock', ['password' => 'Synthetic proposal tabs password']);
        $this->get($url);
        $item = $version->payload['items'][0]['id'];
        $this->post($url.'/request-agreement', ['version_hash' => $version->content_hash,
            'request_key' => session('service_proposal_request.'.$version->id), 'selected' => [$item],
            'terms' => [$item => 12], 'acknowledge' => 1])->assertOk();
    }
    $response = $this->actingAs($this->tabOwner)->get('https://evergrove.test/owner-workspaces/'.$this->tabTenant->slug.'/proposals')->assertOk();
    $xpath = proposalTabsDom($response->getContent());
    $groups = $xpath->query('//fieldset[contains(@class,"proposal-section-tabs")]');
    expect($groups->length)->toBe(2);
    $names = $ids = [];
    foreach ($groups as $group) {
        expect($xpath->query('.//input[@data-proposal-section][@checked]', $group)->length)->toBe(1);
        $names[] = $xpath->query('.//input[@data-proposal-section]', $group)->item(0)->getAttribute('name');
        foreach ($xpath->query('.//*[starts-with(@id,"proposal-tabs-")]', $group) as $node) {
            $ids[] = $node->getAttribute('id');
        }
    }
    expect(array_unique($names))->toHaveCount(2)->and(array_unique($ids))->toHaveCount(count($ids));
    proposalTabsCapture('manager-multiple', $response);
});

test('form validation and existing saved result redirects reopen the relevant panel', function (): void {
    ['version' => $version, 'token' => $token] = proposalTabsPublish($this);
    $url = 'https://evergrove.test/service-proposals/'.$token;
    $this->post($url.'/unlock', ['password' => 'Synthetic proposal tabs password']);
    $this->get($url);
    $this->from($url.'?section=terms')->post($url.'/selection', ['version_hash' => $version->content_hash,
        'request_key' => session('service_proposal_request.'.$version->id), 'selected' => []])->assertSessionHasErrors('selected');
    $response = $this->get($url.'?section=terms')->assertOk();
    expect(proposalTabsDom($response->getContent())->query('//input[@data-proposal-section="scope"][@checked]')->length)->toBe(1);
    $this->post($url.'/reviewer-name', ['reviewer_name' => 'Ross'])->assertRedirect($url.'?section=review');
    $this->post($url.'/usage-estimate', ['departments' => 1, 'low' => '1.00', 'high' => '2.00'])->assertRedirect($url.'?section=scope#usage-estimator');
    $this->post($url.'/comments', ['page' => 'pricing', 'body' => 'Synthetic comment stays in the reviewed version.', 'comment_key' => Str::uuid()->toString()])->assertRedirect($url.'?section=review#review-comments');
    expect(Review::count())->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('copied official graphic paths and motion sources are exact and cannot load active or external content', function (): void {
    $root = resource_path('views/service-proposals/branding');
    expect(hash_file('sha256', resource_path('images/proposals/lees-mcrae-official-2026.svg')))->toBe('27ec9c96ef32992d0816b2a7fd30a5cd9e1ff557e839f10f7bca2803d7d02efd');
    foreach (['lees-mcrae-login-styles.blade.php', 'lees-mcrae-crest.blade.php', 'lees-mcrae-letters.blade.php'] as $path) {
        $content = file_get_contents($root.'/'.$path);
        expect($content)->not->toMatch('/<(script|foreignObject|iframe|video)\b|\bon\w+\s*=|(?:href|src)\s*=\s*[\"\'](?:https?:|\/\/)|url\(\s*[\"\']?https?:/i');
    }
});
