<?php

use App\Models\Tenant;
use App\Models\User;
use App\Proposals\Draft;
use App\Proposals\ProposalService;
use App\Proposals\Review;
use App\Proposals\Version;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    $this->tenant = Tenant::create(['name' => 'Evergrove Enterprise test', 'slug' => 'proposal-enterprise-test']);
    $this->regular = Tenant::create(['name' => 'Evergrove regular test', 'slug' => 'proposal-regular-test']);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    $this->owner->tenants()->attach($this->regular, ['role' => 'owner', 'membership_active' => true]);
    config(['service_proposals.enabled' => true, 'service_proposals.writes_enabled' => true, 'service_proposals.publish_enabled' => true,
        'service_proposals.owner_workspaces' => [$this->tenant->id => 'enterprise', $this->regular->id => 'regular'],
        'service_proposals.recipient_hosts' => ['evergrove.test'], 'evergrove.hosts' => ['evergrove.test']]);
    $this->service = app(ProposalService::class);
    $this->draft = $this->service->leesMcRaeDraft($this->tenant, $this->owner->id);
    $this->ownerPath = '/owner-workspaces/'.$this->tenant->slug.'/proposals';
});

function proposalPublish($test, string $audience = 'client'): array
{
    return $test->service->publish($test->tenant, $test->owner->id, $test->draft->id, 1, 'Test proposal password', 7, Str::uuid()->toString(), $audience);
}

test('Lees-McRae prices are client scoped totals and unresolved terms stay explicit', function (): void {
    $items = $this->draft->payload['items'];
    expect(array_column($items[0]['charges'], 'amount_cents'))->toBe([300000, 100000])
        ->and(array_column($items[1]['charges'], 'amount_cents'))->toBe([700000, 250000])
        ->and($items[0]['terms_months'])->toBe([])
        ->and($this->draft->payload['intro'])->toContain('printed pieces of paper handed to technicians', 'installable web app')
        ->and($this->draft->payload['legal_status'])->toBe('unreviewed')
        ->and($this->service->assets($this->regular))->toHaveCount(0);
    $selected = $this->service->selection($this->draft->payload, [['id' => $items[1]['id']]]);
    expect($selected['totals_cents'])->toBe(['month' => 250000, 'one_time' => 700000]);
    expect(fn () => $this->service->leesMcRaeDraft($this->regular, $this->owner->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('owners can list create edit preview catalog and draft master templates without provider IO', function (): void {
    $this->actingAs($this->owner)->get($this->ownerPath)->assertOk()->assertSee('Proposals')->assertSee('Service catalog');
    $this->get($this->ownerPath.'/create')->assertOk();
    $this->get($this->ownerPath.'/'.$this->draft->id.'/edit')->assertOk()->assertSee('Shape the proposal.');
    $response = $this->get($this->ownerPath.'/'.$this->draft->id.'/preview')->assertOk()->assertSee('A proposal for Lees-McRae College')->assertSee('Prepared by Evergrove Software')->assertSee('The Evergrove Difference')->assertDontSee('Request an agreement</button>', false);
    if (getenv('PROPOSAL_CAPTURE_HTML')) {
        file_put_contents(base_path('.tmp/proposal-preview.html'), $response->getContent());
    }
    $this->post($this->ownerPath.'/assets/template', ['name' => 'Master draft', 'text' => '<script>bad()</script>', 'legal_status' => 'approved'])->assertRedirect();
    expect($this->service->assets($this->tenant, 'template')->first()->payload['legal_status'])->toBe('unreviewed');
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('no global role bypasses current active owner membership', function (): void {
    foreach (['member', 'viewer', 'guest'] as $role) {
        $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['role' => $role]);
        $this->actingAs($this->owner)->get($this->ownerPath)->assertForbidden();
    }
    $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'owner', 'membership_active' => false]);
    $this->get($this->ownerPath)->assertForbidden();
    $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => true]);
    $this->owner->update(['is_active' => false]);
    $this->get($this->ownerPath)->assertForbidden();
});

test('an unknown or client workspace cannot become an owner workspace by name or URL', function (): void {
    $clientTenant = Tenant::create(['name' => 'Evergrove Enterprise', 'slug' => 'similar-name']);
    $this->owner->tenants()->attach($clientTenant, ['role' => 'owner', 'membership_active' => true]);
    $this->actingAs($this->owner)->get('/owner-workspaces/similar-name/proposals')->assertNotFound();
    $this->get('/owner-workspaces/'.$this->regular->slug.'/proposals')->assertOk()->assertDontSee('Lees-McRae College');
    $this->get('/owner-workspaces/'.$this->regular->slug.'/proposals/'.$this->draft->id.'/edit')->assertNotFound();
});

test('cross workspace assets and client specific commercial offers are refused', function (): void {
    $client = $this->service->saveAsset($this->tenant, $this->owner->id, 'client', ['name' => 'Other client', 'reference' => 'other']);
    $payload = ['client_id' => $client->id, 'title' => 'Another proposal', 'catalog_ids' => [$this->draft->payload['items'][0]['id']]];
    $this->actingAs($this->owner)->post($this->ownerPath, $payload)->assertUnprocessable();
    $payload['client_id'] = $this->draft->client_id;
    $this->post('/owner-workspaces/'.$this->regular->slug.'/proposals', $payload)->assertNotFound();
    expect(Draft::count())->toBe(1);
});

test('draft and catalog optimistic revisions prevent lost edits', function (): void {
    $input = ['client_id' => $this->draft->client_id, 'title' => 'Revised draft', 'catalog_ids' => array_column($this->draft->payload['items'], 'id'), 'revision' => 1, 'source' => 'voice_draft'];
    $updated = $this->service->saveDraft($this->tenant, $this->owner->id, $input, $this->draft->id);
    expect($updated->revision)->toBe(2)->and($updated->payload['source'])->toBe('voice_draft')->and(Version::count())->toBe(0);
    $this->actingAs($this->owner)->put($this->ownerPath.'/'.$this->draft->id, $input)->assertConflict();
    $item = $this->service->assets($this->tenant, 'catalog')->first();
    $this->service->saveAsset($this->tenant, $this->owner->id, 'catalog', [...$item->payload, 'revision' => 1], $item->id);
    expect(fn () => $this->service->saveAsset($this->tenant, $this->owner->id, 'catalog', [...$item->payload, 'revision' => 1], $item->id))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('published snapshots do not change when prices templates clients and drafts change', function (): void {
    ['version' => $version] = proposalPublish($this);
    $hash = $version->content_hash;
    $item = $this->service->assets($this->tenant, 'catalog')->first();
    $payload = $item->payload;
    $payload['charges'][0]['amount_cents'] = 123;
    $this->service->saveAsset($this->tenant, $this->owner->id, 'catalog', [...$payload, 'revision' => 1], $item->id);
    $this->service->saveDraft($this->tenant, $this->owner->id, ['client_id' => $this->draft->client_id, 'title' => 'Changed', 'catalog_ids' => [$item->id], 'revision' => 1], $this->draft->id);
    expect($version->fresh()->payload['items'][0]['charges'][0]['amount_cents'])->toBe(300000)
        ->and($version->fresh()->content_hash)->toBe($hash);
    expect(fn () => $version->update(['content_hash' => str_repeat('a', 64)]))->toThrow(LogicException::class);
    expect(fn () => $version->fresh()->delete())->toThrow(LogicException::class);
});

test('configured one two three year terms stay separate from monthly cadence', function (): void {
    $item = $this->draft->payload['items'][0];
    $item['terms_months'] = [12, 24, 36];
    $payload = [...$this->draft->payload, 'items' => [$item]];
    foreach ([12, 24, 36] as $term) {
        $selection = $this->service->selection($payload, [['id' => $item['id'], 'term_months' => $term]]);
        expect($selection['services'][0]['term_months'])->toBe($term)->and($selection['totals_cents']['month'])->toBe(100000);
    }
    expect(fn () => $this->service->selection($payload, [['id' => $item['id'], 'term_months' => 18]]))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn () => $this->service->selection($this->draft->payload, [['id' => $item['id'], 'term_months' => 12]]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('mutually exclusive bundles unknown services and duplicate selections cannot alter totals', function (): void {
    $ids = array_column($this->draft->payload['items'], 'id');
    foreach ([[['id' => $ids[0]], ['id' => $ids[1]]], [['id' => Str::uuid()->toString()]], [['id' => $ids[0]], ['id' => $ids[0]]]] as $selection) {
        expect(fn () => $this->service->selection($this->draft->payload, $selection))->toThrow(\Illuminate\Validation\ValidationException::class);
    }
});

test('recipient password gate hides client prices and requires the exact configured host', function (): void {
    ['version' => $version, 'token' => $token] = proposalPublish($this);
    $url = 'https://evergrove.test/service-proposals/'.$token;
    $this->get($url)->assertOk()->assertSee('A private proposal.')->assertDontSee('Lees-McRae')->assertDontSee('3,000');
    $this->post($url.'/request-agreement', [])->assertForbidden();
    $this->post($url.'/unlock', ['password' => 'incorrect'])->assertSessionHasErrors();
    $this->get($url)->assertDontSee('Lees-McRae');
    $this->post($url.'/unlock', ['password' => 'Test proposal password'])->assertRedirect();
    $response = $this->get($url)->assertOk()->assertSee('A proposal for Lees-McRae College')->assertDontSee('tour controls');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $this->get('https://other.test/service-proposals/'.$token)->assertNotFound();
    expect(DB::table('service_proposal_versions')->where('id', $version->id)->value('payload'))->not->toContain('Lees-McRae');
});

test('recipient reviews exact selected prices before a replay safe nonbinding agreement request', function (): void {
    ['version' => $version, 'token' => $token] = proposalPublish($this);
    $url = 'https://evergrove.test/service-proposals/'.$token;
    $this->post($url.'/unlock', ['password' => 'Test proposal password']);
    $this->get($url)->assertOk();
    $key = session('service_proposal_request.'.$version->id);
    $data = ['version_hash' => $version->content_hash, 'request_key' => $key, 'selected' => [$version->payload['items'][1]['id']], 'acknowledge' => 1, 'amount_cents' => 1];
    $this->post($url.'/selection', $data)->assertOk()->assertSee('Review your selected scope.')->assertSee('7,000.00')->assertSee('2,500.00');
    expect(Review::count())->toBe(0);
    $this->post($url.'/request-agreement', $data)->assertOk()->assertSee('Your agreement request is recorded.');
    $this->post($url.'/request-agreement', $data)->assertOk();
    expect(Review::count())->toBe(1)->and(Review::first()->payload['selection']['totals_cents']['month'])->toBe(250000)
        ->and(Review::first()->payload['signature_status'])->toBe('not_signed')
        ->and(Review::first()->payload['payment_status'])->toBe('not_started')
        ->and(DB::table('agreement_acceptances')->count())->toBe(0)
        ->and(DB::table('tenant_billing_orders')->count())->toBe(0);
    $data['selected'] = [$version->payload['items'][0]['id']];
    $this->post($url.'/request-agreement', $data)->assertConflict();
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('reviewer access cannot request agreements and revoked or expired links stop existing sessions', function (): void {
    ['version' => $version, 'token' => $token] = proposalPublish($this, 'reviewer');
    $url = 'https://evergrove.test/service-proposals/'.$token;
    $this->post($url.'/unlock', ['password' => 'Test proposal password']);
    $this->get($url)->assertOk()->assertSee('Reviewer access')->assertDontSee('Review selected services</button>', false);
    $this->post($url.'/selection', [])->assertForbidden();
    $this->post($url.'/request-agreement', [])->assertForbidden();
    $version->update(['revoked_at' => now()]);
    $this->get($url)->assertGone();
    ['token' => $clientToken] = proposalPublish($this);
    $this->travel(8)->days();
    $this->get('https://evergrove.test/service-proposals/'.$clientToken)->assertGone();
});

test('independent feature write and publication freezes fail closed', function (): void {
    config(['service_proposals.publish_enabled' => false]);
    expect(fn () => proposalPublish($this))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    config(['service_proposals.writes_enabled' => false]);
    $this->actingAs($this->owner)->post($this->ownerPath.'/lees-mcrae-draft')->assertForbidden();
    $this->get($this->ownerPath)->assertOk();
    config(['service_proposals.enabled' => false]);
    $this->get($this->ownerPath)->assertNotFound();
});

test('catalog UI preserves cents and draft legal text is escaped', function (): void {
    $this->actingAs($this->owner)->post($this->ownerPath.'/assets/catalog', ['name' => '<script>alert(1)</script>', 'setup_amount' => '12.34', 'recurring_amount' => '5.67', 'cadence' => 'month', 'terms_months' => ['12', '24', '36']])->assertRedirect();
    $asset = $this->service->assets($this->tenant, 'catalog')->last();
    expect($asset->payload['charges'][0]['amount_cents'])->toBe(1234)->and($asset->payload['terms_months'])->toBe([12, 24, 36]);
    $this->get($this->ownerPath)->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    $this->post($this->ownerPath.'/assets/catalog', ['name' => 'Invalid', 'setup_amount' => '-1.00', 'cadence' => 'month'])->assertSessionHasErrors('setup_amount');
});

test('lost publication response replays the exact token and mismatched retries stop', function (): void {
    $key = Str::uuid()->toString();
    $first = $this->service->publish($this->tenant, $this->owner->id, $this->draft->id, 1, 'Test proposal password', 7, $key);
    $again = $this->service->publish($this->tenant, $this->owner->id, $this->draft->id, 1, 'Test proposal password', 7, $key);
    expect($again['token'])->toBe($first['token'])->and($again['version']->id)->toBe($first['version']->id)->and(Version::count())->toBe(1);
    expect(fn () => $this->service->publish($this->tenant, $this->owner->id, $this->draft->id, 1, 'Different test password', 7, $key))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(DB::table('service_proposal_versions')->value('access_result'))->not->toContain($first['token']);
});

test('Ross Sarah and Paul are persisted review names with version scoped shared comments and no accounts', function (): void {
    ['version' => $version, 'token' => $token] = proposalPublish($this, 'reviewer');
    $url = 'https://evergrove.test/service-proposals/'.$token;
    $usersBefore = User::count();
    $this->post($url.'/unlock', ['password' => 'Test proposal password']);
    $this->post($url.'/reviewer-name', ['reviewer_name' => 'Ross'])->assertRedirect();
    $this->get($url)->assertOk()->assertSee('Commenting as')->assertSee('Ross');
    $comment = ['page' => 'approach', 'body' => '<script>review</script>', 'comment_key' => Str::uuid()->toString()];
    $this->post($url.'/comments', $comment)->assertRedirect();
    $this->post($url.'/comments', $comment)->assertRedirect();
    expect(\App\Proposals\Comment::count())->toBe(1)->and(User::count())->toBe($usersBefore);
    $this->post($url.'/reviewer-name', ['reviewer_name' => 'Sarah'])->assertRedirect();
    $this->get($url)->assertOk()->assertSee('&lt;script&gt;review&lt;/script&gt;', false)->assertSee('Paul')->assertDontSee('<script>review</script>', false);
    $this->actingAs($this->owner)->get($this->ownerPath)->assertOk()->assertSee('Lees-McRae College')->assertSee('Ross');
    $this->get('/owner-workspaces/'.$this->regular->slug.'/proposals')->assertOk()->assertDontSee('&lt;script&gt;review&lt;/script&gt;', false);
    $version->update(['revoked_at' => now()]);
    $this->post($url.'/comments', [...$comment, 'comment_key' => Str::uuid()->toString()])->assertGone();
});

test('Lees-McRae support travel usage and sequential activation stay explicit and do not change global rates', function (): void {
    $details = $this->draft->payload['client_details'];
    expect($details['support'])->toContain('Monday–Friday, 9 am–5 pm Eastern Time', 'one business day')
        ->and($details['usage'])->toContain('without markup')
        ->and($details['travel'])->toContain('all-in quote approved in advance')
        ->and($details['activation'])->toContain('COO approval', 'only after explicit acceptance')
        ->and($details['rollout'])->toContain('sequentially', 'Phased billing amounts and rollout duration remain to be agreed', 'Subscription billing commencement is governed independently for each workspace')
        ->and($this->draft->payload['reviewer_names'])->toBe(['Ross', 'Sarah', 'Paul'])
        ->and(config('marketing.messaging.platform.buyer_rates_micros.sms'))->toBe(50000);
});

test('optional usage estimator uses only reviewer assumptions with zero markup and writes no billing records', function (): void {
    $estimate = app(\App\Proposals\UsageEstimate::class)->calculate(['departments' => 3, 'low' => '12.34', 'high' => '50']);
    expect($estimate['low_cents'])->toBe(3702)->and($estimate['high_cents'])->toBe(15000)->and($estimate['markup_cents'])->toBe(0);
    ['token' => $token] = proposalPublish($this);
    $url = 'https://evergrove.test/service-proposals/'.$token;
    $this->post($url.'/usage-estimate', ['departments' => 3, 'low' => '12.34', 'high' => '50'])->assertForbidden();
    $this->post($url.'/unlock', ['password' => 'Test proposal password']);
    $this->post($url.'/usage-estimate', ['departments' => 3, 'low' => '12.34', 'high' => '50'])->assertRedirect();
    $this->get($url)->assertOk()->assertSee('37.02')->assertSee('150.00')->assertSee('not measured usage');
    expect(Review::count())->toBe(0)->and(DB::table('tenant_billing_orders')->count())->toBe(0);
});

test('usage planning assumptions never display on another proposal version or an owner preview', function (): void {
    ['token' => $first] = proposalPublish($this);
    ['token' => $second] = proposalPublish($this);
    $one = 'https://evergrove.test/service-proposals/'.$first;
    $two = 'https://evergrove.test/service-proposals/'.$second;
    $this->post($one.'/unlock', ['password' => 'Test proposal password']);
    $this->post($two.'/unlock', ['password' => 'Test proposal password']);
    $this->post($one.'/usage-estimate', ['departments' => 3, 'low' => '12.34', 'high' => '50'])->assertRedirect();
    $this->get($two)->assertOk()->assertDontSee('37.02');
    $this->post($one.'/usage-estimate', ['departments' => 3, 'low' => '12.34', 'high' => '50']);
    $this->actingAs($this->owner)->get($this->ownerPath.'/'.$this->draft->id.'/preview')->assertOk()->assertDontSee('37.02');
});

test('AI and voice intake create only scoped drafts and reject invented commercial fields', function (): void {
    $suggestion = ['client_id' => $this->draft->client_id, 'title' => 'Suggested scope', 'catalog_ids' => [$this->draft->payload['items'][0]['id']]];
    foreach (['ai_draft', 'voice_draft'] as $source) {
        $this->actingAs($this->owner)->postJson($this->ownerPath.'/draft-intake', compact('source', 'suggestion'))->assertCreated()->assertJson(['status' => 'draft', 'published' => false, 'sent' => false]);
    }
    $this->postJson($this->ownerPath.'/draft-intake', ['source' => 'ai_draft', 'suggestion' => [...$suggestion, 'amount_cents' => 1, 'publish' => true]])->assertUnprocessable();
    expect(Version::count())->toBe(0)->and(DB::table('tenant_billing_orders')->count())->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('explicit owner publication requires review and retries return the same private link', function (): void {
    $data = ['revision' => 1, 'password' => 'Test proposal password', 'days' => 7, 'audience' => 'client', 'publish_key' => Str::uuid()->toString(), 'confirm' => 1];
    $this->actingAs($this->owner)->get($this->ownerPath.'/'.$this->draft->id.'/edit')->assertOk()->assertSee('name="publish_key"', false);
    $this->post($this->ownerPath.'/'.$this->draft->id.'/publish', $data)->assertRedirect()->assertSessionHas('private_proposal_path');
    $firstPath = session('private_proposal_path');
    $this->post($this->ownerPath.'/'.$this->draft->id.'/publish', $data)->assertRedirect()->assertSessionHas('private_proposal_path', $firstPath);
    expect(Version::count())->toBe(1);
    $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    $this->post($this->ownerPath.'/'.$this->draft->id.'/publish', $data)->assertForbidden();
});

test('implementation clause belongs to saved draft content and never rewrites an older publication', function (): void {
    $legacy = $this->draft->payload;
    unset($legacy['client_details']['implementation_timeline_heading'], $legacy['client_details']['implementation_timeline']);
    $legacy['client_details']['activation'] = 'Legacy published copy: staff access and billing follow explicit acceptance.';
    $legacy['client_details']['rollout'] = 'Legacy published sequential rollout. Billing start, phased billing and rollout duration remain to be agreed.';
    $this->draft->update(['payload' => $legacy]);
    ['version' => $version, 'token' => $token] = proposalPublish($this);
    $beforePayload = $version->payload;
    $beforeHash = $version->content_hash;

    $revised = $this->draft->payload;
    $revised['client_details'] = \App\Proposals\LeesMcRaeDetails::content();
    $this->draft->update(['payload' => $revised, 'revision' => 2]);
    $this->actingAs($this->owner)->get($this->ownerPath.'/'.$this->draft->id.'/preview')->assertOk()
        ->assertSee(\App\Proposals\LeesMcRaeDetails::IMPLEMENTATION_TIMELINE_HEADING)
        ->assertSee(\App\Proposals\LeesMcRaeDetails::IMPLEMENTATION_TIMELINE);

    $url = 'https://evergrove.test/service-proposals/'.$token;
    $this->post($url.'/unlock', ['password' => 'Test proposal password'])->assertRedirect();
    $this->get($url)->assertOk()->assertSee($legacy['client_details']['activation'])
        ->assertDontSee('<h2>'.\App\Proposals\LeesMcRaeDetails::IMPLEMENTATION_TIMELINE_HEADING.'</h2>', false);
    expect($version->fresh()->payload)->toBe($beforePayload)
        ->and($version->fresh()->content_hash)->toBe($beforeHash)
        ->and(Version::count())->toBe(1)
        ->and(DB::table('tenant_billing_orders')->count())->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('reviewed PDF commercial snapshot survives normal owner saves and catalog drift while old versions remain exact', function (): void {
    $copy = json_decode(file_get_contents(base_path('tests/Fixtures/proposals/lees-mcrae-v9-commercial.json')), true, flags: JSON_THROW_ON_ERROR);
    ['version' => $oldVersion, 'token' => $oldToken] = proposalPublish($this);
    $oldRaw = $oldVersion->getRawOriginal('payload');
    $oldHash = $oldVersion->content_hash;
    $payload = $this->draft->payload;
    $origins = $overrides = [];
    foreach ($payload['items'] as &$item) {
        $asset = $this->service->asset($this->tenant, $item['id'], 'catalog');
        $origins[$item['id']] = ['revision' => $asset->revision, 'payload_sha256' => hash('sha256', json_encode($asset->payload, JSON_THROW_ON_ERROR)), 'terms_months' => $asset->payload['terms_months']];
        $overrides[$item['id']] = ['terms_months' => [12]];
        $item['terms_months'] = [12];
    }
    unset($item);
    $payload['client_details'] = $copy['client_details'];
    $payload['unresolved'] = $copy['unresolved'];
    $payload['commercial_snapshot'] = ['schema_version' => 1, 'source_reference' => 'Synthetic reviewed PDF v9', 'source_copy_sha256' => hash_file('sha256', base_path('tests/Fixtures/proposals/lees-mcrae-v9-commercial.json')),
        'catalog_origins' => $origins, 'item_overrides' => $overrides];
    $payload['commercial_snapshot']['protected_sha256'] = \App\Proposals\CommercialSnapshot::hash($payload);
    // Test-only reconciliation in disposable SQLite; production has a separate reviewed, currently nonexecutable plan.
    $this->draft->fill(['payload' => $payload, 'revision' => 2])->save();
    $draftPath = $this->ownerPath.'/'.$this->draft->id;
    $input = ['revision' => 2, 'client_id' => $this->draft->client_id, 'title' => 'Reviewed commercial terms retained', 'intro' => $payload['intro'], 'scope_notes' => $payload['scope_notes'],
        'catalog_ids' => array_column($payload['items'], 'id'), 'usage_plan' => 'unconfirmed', 'approach_key' => 'evergrove_department_journey', 'reviewer_names_text' => 'Ross, Sarah, Paul'];
    $this->actingAs($this->owner)->put($draftPath, $input)->assertRedirect();
    $this->draft->refresh();
    expect($this->draft->revision)->toBe(3)->and($this->draft->payload['items'])->toBe($payload['items'])
        ->and($this->draft->payload['client_details'])->toBe($payload['client_details'])->and($this->draft->payload['commercial_snapshot'])->toBe($payload['commercial_snapshot']);
    $asset = $this->service->asset($this->tenant, $payload['items'][0]['id'], 'catalog');
    $changed = $asset->payload;
    $changed['charges'][1]['amount_cents'] = 999999;
    $changed['terms_months'] = [36];
    $asset->fill(['payload' => $changed, 'revision' => 2])->save();
    $catalogRaw = $asset->getRawOriginal('payload');
    $this->put($draftPath, [...$input, 'revision' => 3])->assertRedirect();
    $this->draft->refresh();
    expect($this->draft->revision)->toBe(4)->and($this->draft->payload['items'])->toBe($payload['items'])
        ->and($asset->fresh()->getRawOriginal('payload'))->toBe($catalogRaw);
    $this->get($draftPath.'/preview')->assertOk()->assertSee('Contract term and renewal')->assertSee('12-month term')->assertSee('60 days')->assertSee('90 days')
        ->assertSee('No routine subscription-pause entitlement')->assertSee('What setup includes')->assertSee('Staged fees')->assertSee('Support requests are submitted through the ticketing system.')
        ->assertDontSee('phased billing remains to be agreed')->assertDontSee('Contract duration: to be agreed.');
    foreach ([['catalog_ids' => [$payload['items'][0]['id']]], ['usage_plan' => 'workspace_allowance'], ['scope_notes' => 'Changed contractual scope.']] as $change) {
        $this->put($draftPath, [...$input, 'revision' => 4, ...$change])->assertStatus(409);
        expect($this->draft->fresh()->revision)->toBe(4)->and($this->draft->fresh()->payload)->toBe($this->draft->payload);
    }
    ['version' => $newVersion, 'token' => $newToken] = $this->service->publish($this->tenant, $this->owner->id, $this->draft->id, 4, 'Test proposal password', 7, Str::uuid()->toString());
    $newUrl = 'https://evergrove.test/service-proposals/'.$newToken;
    $this->post($newUrl.'/unlock', ['password' => 'Test proposal password']);
    $this->get($newUrl)->assertOk()->assertSee('value="12"', false)->assertSee('Contract term and renewal');
    $selection = $this->service->selection($newVersion->payload, [['id' => $payload['items'][0]['id'], 'term_months' => 12]]);
    expect($selection['totals_cents'])->toBe(['month' => 100000, 'one_time' => 300000])->and($selection['services'][0]['term_months'])->toBe(12)
        ->and(fn () => $this->service->selection($newVersion->payload, [['id' => $payload['items'][0]['id']]]))->toThrow(\Illuminate\Validation\ValidationException::class);
    $oldUrl = 'https://evergrove.test/service-proposals/'.$oldToken;
    $this->post($oldUrl.'/unlock', ['password' => 'Test proposal password']);
    $this->get($oldUrl)->assertOk()->assertSee('Contract duration: to be agreed.')->assertDontSee('Contract term and renewal');
    expect($oldVersion->fresh()->content_hash)->toBe($oldHash)->and($oldVersion->fresh()->getRawOriginal('payload'))->toBe($oldRaw)
        ->and($oldVersion->fresh()->payload['items'][0]['terms_months'])->toBe([])->and(Version::count())->toBe(2)->and(Review::count())->toBe(0)
        ->and(DB::table('tenant_billing_orders')->count())->toBe(0);
    $corrupted = $this->draft->payload;
    $corrupted['commercial_snapshot']['catalog_origins'][$payload['items'][0]['id']]['payload_sha256'] = str_repeat('c', 64);
    $this->draft->fill(['payload' => $corrupted])->save();
    expect(fn () => $this->service->publish($this->tenant, $this->owner->id, $this->draft->id, 4, 'Test proposal password', 7, Str::uuid()->toString()))->toThrow(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class)
        ->and(Version::count())->toBe(2)->and($this->draft->fresh()->revision)->toBe(4);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});
