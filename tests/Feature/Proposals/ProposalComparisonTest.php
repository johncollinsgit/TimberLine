<?php

use App\Models\Tenant;
use App\Models\User;
use App\Proposals\CommercialSnapshot;
use App\Proposals\ProposalComparison;
use App\Proposals\ProposalService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    $this->comparisonTenant = Tenant::create(['name' => 'Synthetic comparison owner', 'slug' => 'synthetic-comparison-owner']);
    $this->comparisonOwner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->comparisonOwner->tenants()->attach($this->comparisonTenant, ['role' => 'owner', 'membership_active' => true]);
    config(['app.url' => 'https://evergrove.test', 'enterprise_runtime.base_path' => '',
        'service_proposals.enabled' => true, 'service_proposals.writes_enabled' => true, 'service_proposals.publish_enabled' => true,
        'service_proposals.owner_workspaces' => [$this->comparisonTenant->id => 'enterprise'],
        'service_proposals.recipient_hosts' => ['evergrove.test'], 'evergrove.hosts' => ['evergrove.test']]);
    $this->comparisonService = app(ProposalService::class);
    $this->comparisonDraft = $this->comparisonService->leesMcRaeDraft($this->comparisonTenant, $this->comparisonOwner->id);
    $payload = $this->comparisonDraft->payload;
    $copy = json_decode(file_get_contents(base_path('tests/Fixtures/proposals/lees-mcrae-v9-commercial.json')), true, flags: JSON_THROW_ON_ERROR);
    $payload['client_details'] = $copy['client_details'];
    $payload['unresolved'] = $copy['unresolved'];
    $origins = $overrides = [];
    foreach ($payload['items'] as &$item) {
        $asset = $this->comparisonService->asset($this->comparisonTenant, $item['id'], 'catalog');
        $origins[$item['id']] = ['revision' => $asset->revision, 'payload_sha256' => hash('sha256', json_encode($asset->payload, JSON_THROW_ON_ERROR)), 'terms_months' => $asset->payload['terms_months']];
        $overrides[$item['id']] = ['terms_months' => [12]];
        $item['terms_months'] = [12];
    }
    unset($item);
    $payload['commercial_snapshot'] = ['schema_version' => 1, 'source_reference' => 'Synthetic reviewed comparison source',
        'source_copy_sha256' => hash_file('sha256', base_path('tests/Fixtures/proposals/lees-mcrae-v9-commercial.json')),
        'catalog_origins' => $origins, 'item_overrides' => $overrides];
    $payload['commercial_snapshot']['protected_sha256'] = CommercialSnapshot::hash($payload);
    $this->comparisonDraft->update(['payload' => $payload]);
    $this->comparisonDefinition = json_decode(file_get_contents(resource_path('data/proposals/lees-mcrae-comparison-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    $this->comparisonDefinition['commercial_source'] = array_intersect_key($payload['commercial_snapshot'], array_flip(['source_reference', 'source_copy_sha256', 'protected_sha256']));
    comparisonBindSyntheticDefinition($this->comparisonDefinition);
    $this->comparisonPath = 'https://evergrove.test/owner-workspaces/'.$this->comparisonTenant->slug.'/proposals/'.$this->comparisonDraft->id;
});

afterEach(function (): void {
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

function comparisonBindSyntheticDefinition(array $definition): void
{
    app()->instance(ProposalComparison::class, new class($definition) extends ProposalComparison
    {
        public function __construct(private array $syntheticDefinition) {}

        protected function definition(): array
        {
            return $this->syntheticDefinition;
        }
    });
}

function comparisonSaveInput($test, bool $include = true): array
{
    $test->comparisonDraft->refresh();
    $payload = $test->comparisonDraft->payload;

    return ['client_id' => $test->comparisonDraft->client_id, 'title' => $payload['title'], 'intro' => $payload['intro'],
        'catalog_ids' => array_column($payload['items'], 'id'), 'template_id' => $payload['template']['id'] ?? null,
        'scope_notes' => $payload['scope_notes'], 'usage_plan' => $payload['usage_plan'], 'approach_key' => $payload['approach_key'],
        'reviewer_names' => $payload['reviewer_names'], 'include_personal_letter' => 1, 'include_comparison' => $include ? 1 : 0,
        'revision' => $test->comparisonDraft->revision];
}

function comparisonDom(string $html): DOMXPath
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html);

    return new DOMXPath($dom);
}

test('explicit owner opt in creates the first selected comparison tab while preserving terms letter and section queries', function (): void {
    $original = $this->comparisonDraft->payload;
    $before = $this->actingAs($this->comparisonOwner)->get($this->comparisonPath.'/preview')->assertOk();
    expect(comparisonDom($before->getContent())->query('//input[@data-proposal-section="compare"]')->length)->toBe(0);
    $this->put($this->comparisonPath, [...comparisonSaveInput($this), 'comparison' => ['heading' => 'UNTRUSTED RAW OVERRIDE']])->assertRedirect();
    $saved = $this->comparisonDraft->fresh()->payload;
    expect($saved['comparison'])->toBe(app(ProposalComparison::class)->snapshot($saved))
        ->and(CommercialSnapshot::hash($saved))->toBe(CommercialSnapshot::hash($original))
        ->and($saved['intro'])->toBe($original['intro']);
    $response = $this->get($this->comparisonPath.'/preview')->assertOk()->assertSee('Compare options')->assertDontSee('UNTRUSTED RAW OVERRIDE');
    $dom = comparisonDom($response->getContent());
    expect($dom->query('//input[@data-proposal-section]')->item(0)->getAttribute('data-proposal-section'))->toBe('compare')
        ->and($dom->query('//input[@data-proposal-section="compare"][@checked]')->length)->toBe(1)
        ->and($dom->query('//article[@class="proposal-personal-letter"]')->length)->toBe(1)
        ->and($dom->query('//*[@class="lmc-comparison-table"]/tbody/tr')->length)->toBe(9)
        ->and($dom->query('//*[contains(@class,"lmc-comparison")]//form')->length)->toBe(0);
    foreach (['scope', 'letter', 'overview', 'approach', 'terms'] as $section) {
        $html = $this->get($this->comparisonPath.'/preview?section='.$section)->assertOk()->getContent();
        expect(comparisonDom($html)->query('//input[@data-proposal-section="'.$section.'"][@checked]')->length)->toBe(1);
    }
    $errors = (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['financial' => 'Synthetic financial validation error']));
    $errorPage = $this->withSession(['errors' => $errors])->get($this->comparisonPath.'/preview?section=compare')->assertOk();
    expect(comparisonDom($errorPage->getContent())->query('//input[@data-proposal-section="scope"][@checked]')->length)->toBe(1);
    $this->get($this->comparisonPath.'/edit')->assertOk()->assertSee('name="include_comparison" value="1" checked', false);
    @mkdir(base_path('.tmp/comparison-preview'), 0777, true);
    file_put_contents(base_path('.tmp/comparison-preview/owner.html'), $response->getContent());
    file_put_contents(base_path('.tmp/comparison-preview/headers.json'), json_encode($response->headers->all(), JSON_THROW_ON_ERROR));
});

test('new comparison publication preserves old encrypted edition and its original password boundary', function (): void {
    ['version' => $old, 'token' => $oldToken] = $this->comparisonService->publish($this->comparisonTenant, $this->comparisonOwner->id,
        $this->comparisonDraft->id, 1, 'Synthetic older comparison password', 7, Str::uuid()->toString(), 'reviewer');
    $oldCipher = $old->getRawOriginal('payload');
    $oldHash = $old->content_hash;
    $input = comparisonSaveInput($this);
    $newDraft = $this->comparisonService->saveDraft($this->comparisonTenant, $this->comparisonOwner->id, $input, $this->comparisonDraft->id);
    ['version' => $new, 'token' => $newToken] = $this->comparisonService->publish($this->comparisonTenant, $this->comparisonOwner->id,
        $newDraft->id, $newDraft->revision, 'Synthetic new comparison password', 7, Str::uuid()->toString(), 'reviewer');
    $url = 'https://evergrove.test/service-proposals/'.$newToken;
    $this->get($url)->assertOk()->assertDontSee('lmc-comparison', false)->assertDontSee('Compare options');
    $this->post($url.'/unlock', ['password' => 'Synthetic new comparison password'])->assertRedirect();
    $page = $this->get($url)->assertOk();
    expect(comparisonDom($page->getContent())->query('//input[@data-proposal-section="compare"][@checked]')->length)->toBe(1);
    $this->post('https://evergrove.test/service-proposals/'.$oldToken.'/unlock', ['password' => 'Synthetic older comparison password'])->assertRedirect();
    $oldPage = $this->get('https://evergrove.test/service-proposals/'.$oldToken)->assertOk();
    expect(comparisonDom($oldPage->getContent())->query('//input[@data-proposal-section="compare"]')->length)->toBe(0)
        ->and($old->fresh()->getRawOriginal('payload'))->toBe($oldCipher)
        ->and($old->fresh()->content_hash)->toBe($oldHash)
        ->and($new->content_hash)->not->toBe($oldHash)
        ->and(CommercialSnapshot::hash($new->payload))->toBe(CommercialSnapshot::hash($old->payload));
    $review = $this->get($url.'?section=review')->assertOk();
    expect(comparisonDom($review->getContent())->query('//input[@data-proposal-section="review"][@checked]')->length)->toBe(1);
});

test('absent current commercial pins refuse opt in without changing draft or silently changing older views', function (): void {
    $definition = $this->comparisonDefinition;
    $definition['commercial_source'] = ['source_reference' => null, 'source_copy_sha256' => null, 'protected_sha256' => null];
    comparisonBindSyntheticDefinition($definition);
    $before = $this->comparisonDraft->getRawOriginal('payload');
    $this->actingAs($this->comparisonOwner)->putJson($this->comparisonPath, comparisonSaveInput($this))->assertUnprocessable();
    expect($this->comparisonDraft->fresh()->getRawOriginal('payload'))->toBe($before)
        ->and($this->comparisonDraft->fresh()->revision)->toBe(1);
    $page = $this->get($this->comparisonPath.'/preview')->assertOk();
    expect(comparisonDom($page->getContent())->query('//section[@class="lmc-comparison"]')->length)->toBe(0);
});

test('unknown comparison copy or changed commercial source refuses instead of showing stale comparison values', function (string $change): void {
    $draft = $this->comparisonService->saveDraft($this->comparisonTenant, $this->comparisonOwner->id, comparisonSaveInput($this), $this->comparisonDraft->id);
    $payload = $draft->payload;
    if ($change === 'copy') {
        $payload['comparison']['content']['heading'] = 'Unexpected replacement';
    } else {
        $payload['items'][0]['charges'][0]['amount_cents']++;
    }
    $draft->update(['payload' => $payload]);
    $this->actingAs($this->comparisonOwner)->get($this->comparisonPath.'/preview')->assertStatus(503);
})->with(['copy', 'price']);

test('new source pins alone cannot approve stale comparison prices or a different usage choice', function (string $change): void {
    $payload = $this->comparisonDraft->payload;
    if ($change === 'price') {
        $payload['items'][0]['charges'][0]['amount_cents']++;
    } else {
        $payload['usage_plan'] = 'separately_billed';
    }
    $payload['commercial_snapshot']['protected_sha256'] = CommercialSnapshot::hash($payload);
    $definition = $this->comparisonDefinition;
    $definition['commercial_source']['protected_sha256'] = $payload['commercial_snapshot']['protected_sha256'];
    comparisonBindSyntheticDefinition($definition);
    expect(fn () => app(ProposalComparison::class)->snapshot($payload))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
})->with(['price', 'usage']);

test('the comparison opt in rejects malformed booleans and non Lees McRae client scope', function (): void {
    $this->actingAs($this->comparisonOwner)->putJson($this->comparisonPath, [...comparisonSaveInput($this), 'include_comparison' => ['yes']])->assertUnprocessable();
    $client = $this->comparisonService->asset($this->comparisonTenant, $this->comparisonDraft->client_id, 'client');
    $client->update(['payload' => [...$client->payload, 'reference' => 'another-client']]);
    $this->putJson($this->comparisonPath, comparisonSaveInput($this))->assertUnprocessable();
    expect($this->comparisonDraft->fresh()->revision)->toBe(1);
});

test('only a valid comparison edition can record the new section within existing access and activity limits', function (): void {
    $originalStorage = app()->storagePath();
    $temporary = sys_get_temp_dir().'/comparison-activity-'.Str::uuid();
    app()->useStoragePath($temporary);
    try {
        config(['enterprise.reviewer_demo_enabled' => true,
            'service_proposals.campus_review' => ['enabled' => true, 'build_id' => 'synthetic-comparison-activity']]);
        app()->instance(\App\Proposals\CampusReviewManifest::class, new class extends \App\Proposals\CampusReviewManifest
        {
            protected function sourceHash(): string
            {
                return str_repeat('c', 64);
            }
        });
        ['version' => $old, 'token' => $oldToken] = $this->comparisonService->publish($this->comparisonTenant, $this->comparisonOwner->id,
            $this->comparisonDraft->id, 1, 'Synthetic old activity password', 7, Str::uuid()->toString(), 'reviewer');
        $saved = $this->comparisonService->saveDraft($this->comparisonTenant, $this->comparisonOwner->id, comparisonSaveInput($this), $this->comparisonDraft->id);
        ['version' => $new, 'token' => $newToken] = $this->comparisonService->publish($this->comparisonTenant, $this->comparisonOwner->id,
            $saved->id, $saved->revision, 'Synthetic new activity password', 7, Str::uuid()->toString(), 'reviewer');
        $activity = app(\App\Proposals\Activity::class);
        expect(\App\Proposals\Activity::SECTIONS)->not->toContain('compare');
        expect($activity->sections($old))->toBe(\App\Proposals\Activity::SECTIONS)
            ->and($activity->sections($new))->toBe(['compare', ...\App\Proposals\Activity::SECTIONS]);
        $oldUrl = 'https://evergrove.test/service-proposals/'.$oldToken;
        $url = 'https://evergrove.test/service-proposals/'.$newToken;
        $body = ['event' => 'proposal_section', 'page' => 'compare', 'scene' => null,
            'event_id' => Str::uuid()->toString(), 'version_hash' => $new->content_hash];
        $this->postJson($url.'/activity', $body)->assertForbidden();
        $this->post($oldUrl.'/unlock', ['password' => 'Synthetic old activity password'])->assertRedirect();
        $this->postJson($oldUrl.'/activity', [...$body, 'version_hash' => $old->content_hash])->assertUnprocessable();
        $this->post($url.'/unlock', ['password' => 'Synthetic new activity password'])->assertRedirect();
        $this->get($url)->assertOk();
        expect(collect($activity->read($new)['events'])->where('event', 'proposal_view')->last()['page'])->toBe('compare');
        $this->postJson($url.'/activity', $body)->assertOk()->assertJsonPath('recorded', true);
        $this->postJson($url.'/activity', $body)->assertOk();
        expect(collect($activity->read($new)['events'])->where('event', 'proposal_section'))->toHaveCount(1);
        $this->postJson($url.'/activity', [...$body, 'scene' => 1])->assertUnprocessable();
        $this->postJson($url.'/activity', [...$body, 'version_hash' => $old->content_hash])->assertConflict();
        $this->postJson($url.'/activity', [...$body, 'comparison' => ['heading' => 'RAW']])->assertUnprocessable();
        $malformed = clone $new;
        $payload = $malformed->payload;
        $payload['comparison']['id'] = 'unknown-comparison';
        $malformed->setRawAttributes([...$malformed->getAttributes(), 'payload' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))]);
        expect(fn () => $activity->sections($malformed))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        config(['service_proposals.writes_enabled' => false]);
        $this->postJson($url.'/activity', [...$body, 'event_id' => Str::uuid()->toString()])->assertForbidden();
        config(['service_proposals.writes_enabled' => true]);
        $new->update(['revoked_at' => now()]);
        $this->postJson($url.'/activity', [...$body, 'event_id' => Str::uuid()->toString()])->assertGone();
    } finally {
        app()->useStoragePath($originalStorage);
        \Illuminate\Support\Facades\File::deleteDirectory($temporary);
    }
});
