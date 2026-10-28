<?php

use App\Models\Tenant;
use App\Models\User;
use App\Proposals\CampusReviewManifest;
use App\Proposals\Comment;
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
    $this->tenant = Tenant::create(['name' => 'Synthetic proposal owner', 'slug' => 'campus-review-owner']);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    config(['service_proposals.enabled' => true, 'service_proposals.writes_enabled' => true, 'service_proposals.publish_enabled' => true,
        'service_proposals.owner_workspaces' => [$this->tenant->id => 'enterprise'], 'service_proposals.recipient_hosts' => ['evergrove.test'],
        'evergrove.hosts' => ['evergrove.test'], 'enterprise.reviewer_demo_enabled' => true,
        'service_proposals.campus_review' => ['enabled' => true, 'build_id' => 'synthetic-campus-build-A']]);
    // Keep HTTP contracts deterministic while other workers edit the actual renderer.
    $this->manifest = new class extends CampusReviewManifest
    {
        public string $hash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        protected function sourceHash(): string
        {
            return $this->hash;
        }
    };
    app()->instance(CampusReviewManifest::class, $this->manifest);
    $this->service = app(ProposalService::class);
    $this->draft = $this->service->leesMcRaeDraft($this->tenant, $this->owner->id);
});

function campusPublish($test, string $audience = 'reviewer'): array
{
    return $test->service->publish($test->tenant, $test->owner->id, $test->draft->id, 1,
        'Synthetic campus password', 7, (string) Str::uuid(), $audience);
}

function campusUrl(string $token, string $suffix = ''): string
{
    return 'https://evergrove.test/service-proposals/'.$token.$suffix;
}

function campusBinding(Version $version): array
{
    return ['version_hash' => $version->content_hash, 'build_id' => $version->payload['campus_review']['build_id'],
        'source_hash' => $version->payload['campus_review']['source_hash']];
}

function campusUnlock($test, string $token): void
{
    $test->post(campusUrl($token, '/unlock'), ['password' => 'Synthetic campus password'])->assertRedirect();
}

function campusName($test, Version $version, string $token, string $name = 'Ross', string $page = 'home')
{
    return $test->postJson(campusUrl($token, '/campus/'.$page.'/reviewer-name'), campusBinding($version) + ['reviewer_name' => $name]);
}

test('campus publication binds approved client build source and pages without changing legacy proposals', function (): void {
    ['version' => $version, 'token' => $token] = campusPublish($this);
    expect($version->payload['campus_review'])->toBe($this->manifest->current());
    expect($version->payload['campus_review']['client_reference'])->toBe('lees-mcrae-college');
    campusUnlock($this, $token);
    $this->get(campusUrl($token))->assertOk()->assertSee('Open campus review')
        ->assertSee(route('service-proposals.recipient.campus', [$token, 'home']), false);
    config(['service_proposals.campus_review.enabled' => false]);
    ['version' => $legacy, 'token' => $legacyToken] = campusPublish($this);
    expect($legacy->payload)->not->toHaveKey('campus_review');
    config(['service_proposals.campus_review.enabled' => true]);
    campusUnlock($this, $legacyToken);
    $this->get(campusUrl($legacyToken))->assertOk()->assertDontSee('Open campus review');
    $this->getJson(campusUrl($legacyToken, '/campus/home/review'))->assertNotFound();
    expect(fn () => $version->update(['payload' => []]))->toThrow(LogicException::class);
});

test('the real source manifest is deterministic bounded and rejects an absent trusted build', function (): void {
    $real = new CampusReviewManifest;
    $first = $real->current();
    expect($first['source_hash'])->toMatch('/^[a-f0-9]{64}$/D')->and($real->current())->toBe($first);
    config(['service_proposals.campus_review.build_id' => '']);
    expect(fn () => $real->current())->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('campus HTML reuses the fictional renderer only after password access with a narrow review CSP', function (): void {
    ['version' => $version, 'token' => $token] = campusPublish($this);
    $this->get(campusUrl($token, '/campus/home'))->assertRedirect(campusUrl($token));
    $this->getJson(campusUrl($token, '/campus/home/review'))->assertForbidden();
    campusUnlock($this, $token);
    $response = $this->get(campusUrl($token, '/campus/projects'))->assertOk()->assertSee('Review this campus page')
        ->assertSee('id="review-dialog"', false)->assertSee('name="expected_reviewer_name"', false)->assertSee('Fictional');
    expect($response->headers->get('Content-Security-Policy'))->toContain("connect-src 'self'", "form-action 'self'", "script-src 'nonce-")
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    $context = $this->getJson(campusUrl($token, '/campus/projects/review'))->assertOk()->assertJsonPath('review.page', 'projects')
        ->assertJsonPath('review.reviewer_name', null)->json('review');
    expect($context['urls']['proposal'])->toBe(campusUrl($token))->and($context['csrf_token'])->toBeString()->not->toBeEmpty();
    $this->getJson(campusUrl($token, '/campus/unknown/review'))->assertNotFound();
    $this->getJson(campusUrl($token, '/campus/home/review?tenant=other'))->assertBadRequest();
    $this->getJson('https://wrong.test/service-proposals/'.$token.'/campus/home/review')->assertNotFound();
});

test('review names persist per version without authentication and comments are shared only on the exact page', function (): void {
    ['version' => $version, 'token' => $token] = campusPublish($this);
    campusUnlock($this, $token);
    $before = [User::count(), DB::table('tenant_user')->count(), DB::table('team_area_members')->count(), Review::count(), DB::table('tenant_billing_orders')->count()];
    campusName($this, $version, $token)->assertOk()->assertJsonPath('review.reviewer_name', 'Ross');
    $body = campusBinding($version) + ['expected_reviewer_name' => 'Ross', 'body' => '<script>hostile feedback</script>', 'comment_key' => (string) Str::uuid()];
    $this->postJson(campusUrl($token, '/campus/home/comments'), $body)->assertOk()->assertJsonCount(1, 'review.comments')
        ->assertJsonPath('review.comments.0.name', 'Ross');
    $this->postJson(campusUrl($token, '/campus/home/comments'), $body)->assertOk();
    expect(Comment::count())->toBe(1);
    $comment = Comment::firstOrFail();
    expect($comment->payload['identity_status'])->toBe('self_selected_display_name')
        ->and($comment->payload['campus_review'])->toBe(['page' => 'home', 'client_reference' => 'lees-mcrae-college',
            'build_id' => $body['build_id'], 'source_hash' => $body['source_hash']]);
    expect(DB::table('service_proposal_comments')->value('payload'))->not->toContain('hostile feedback', 'Ross');
    expect(fn () => $comment->update(['payload' => []]))->toThrow(LogicException::class);
    campusName($this, $version, $token, 'Sarah')->assertOk()->assertJsonPath('review.reviewer_name', 'Sarah');
    $this->getJson(campusUrl($token, '/campus/home/review'))->assertOk()->assertJsonPath('review.comments.0.name', 'Ross');
    $this->getJson(campusUrl($token, '/campus/projects/review'))->assertOk()->assertJsonCount(0, 'review.comments');
    $this->get(campusUrl($token, '/campus/home'))->assertOk()->assertSee('&lt;script&gt;hostile feedback&lt;/script&gt;', false)
        ->assertDontSee('<script>hostile feedback</script>', false);
    // A fresh browser can see shared comments after its own password unlock, without inheriting a display name.
    session()->forget('service_proposal_name.'.$version->id);
    session()->forget('service_proposal_access.'.$version->id);
    campusUnlock($this, $token);
    $this->getJson(campusUrl($token, '/campus/home/review'))->assertOk()->assertJsonPath('review.reviewer_name', null)
        ->assertJsonCount(1, 'review.comments');
    expect([User::count(), DB::table('tenant_user')->count(), DB::table('team_area_members')->count(), Review::count(), DB::table('tenant_billing_orders')->count()])->toBe($before);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('old feedback cannot be replayed under a changed name page body or proposal version', function (): void {
    ['version' => $version, 'token' => $token] = campusPublish($this);
    campusUnlock($this, $token);
    campusName($this, $version, $token)->assertOk();
    $body = campusBinding($version) + ['expected_reviewer_name' => 'Ross', 'body' => 'Retained original feedback', 'comment_key' => (string) Str::uuid()];
    $this->postJson(campusUrl($token, '/campus/home/comments'), $body)->assertOk();
    $this->postJson(campusUrl($token, '/campus/home/comments'), array_replace($body, ['body' => 'Changed']))->assertConflict();
    $this->postJson(campusUrl($token, '/campus/projects/comments'), $body)->assertConflict();
    campusName($this, $version, $token, 'Paul')->assertOk();
    $this->postJson(campusUrl($token, '/campus/home/comments'), $body)->assertConflict();
    ['version' => $second, 'token' => $secondToken] = campusPublish($this);
    $this->getJson(campusUrl($secondToken, '/campus/home/review'))->assertForbidden();
    campusUnlock($this, $secondToken);
    $this->getJson(campusUrl($secondToken, '/campus/home/review'))->assertOk()->assertJsonPath('review.reviewer_name', null)
        ->assertJsonCount(0, 'review.comments');
    expect(Comment::count())->toBe(1);
});

test('changed source under the same build and changed build reject old review access and commands', function (): void {
    ['version' => $version, 'token' => $token] = campusPublish($this);
    campusUnlock($this, $token);
    campusName($this, $version, $token)->assertOk();
    $body = campusBinding($version) + ['expected_reviewer_name' => 'Ross', 'body' => 'Old snapshot feedback', 'comment_key' => (string) Str::uuid()];
    $this->manifest->hash = str_repeat('b', 64);
    $this->getJson(campusUrl($token, '/campus/home/review'))->assertConflict();
    $this->postJson(campusUrl($token, '/campus/home/comments'), $body)->assertConflict();
    $this->get(campusUrl($token))->assertOk()->assertDontSee('Open campus review');
    $this->manifest->hash = str_repeat('a', 64);
    config(['service_proposals.campus_review.build_id' => 'synthetic-campus-build-B']);
    $this->getJson(campusUrl($token, '/campus/home/review'))->assertConflict();
    expect(Comment::count())->toBe(0);
});

test('wrong client and regular owner workspaces never receive campus review authority', function (): void {
    $otherClient = $this->service->saveAsset($this->tenant, $this->owner->id, 'client', ['name' => 'Lees-McRae College', 'reference' => 'different-client']);
    $catalog = $this->service->saveAsset($this->tenant, $this->owner->id, 'catalog', ['name' => 'Generic synthetic option', 'terms_months' => [],
        'charges' => [['label' => 'Synthetic price', 'amount_cents' => 1, 'cadence' => 'month']]]);
    $draft = $this->service->saveDraft($this->tenant, $this->owner->id, ['client_id' => $otherClient->id, 'title' => 'Similar display name', 'catalog_ids' => [$catalog->id]]);
    $other = $this->service->publish($this->tenant, $this->owner->id, $draft->id, 1, 'Synthetic campus password', 7, (string) Str::uuid(), 'reviewer');
    expect($other['version']->payload)->not->toHaveKey('campus_review');
    campusUnlock($this, $other['token']);
    $this->getJson(campusUrl($other['token'], '/campus/home/review'))->assertNotFound();
    ['version' => $version, 'token' => $token] = campusPublish($this);
    campusUnlock($this, $token);
    config(['service_proposals.owner_workspaces.'.$this->tenant->id => 'regular']);
    $this->getJson(campusUrl($token, '/campus/home/review'))->assertNotFound();
    expect(Comment::count())->toBe(0);
});

test('forged identity bindings unknown fields and no chosen name cannot create campus comments', function (): void {
    ['version' => $version, 'token' => $token] = campusPublish($this);
    campusUnlock($this, $token);
    $body = campusBinding($version) + ['expected_reviewer_name' => 'Ross', 'body' => 'Feedback', 'comment_key' => (string) Str::uuid()];
    $this->postJson(campusUrl($token, '/campus/home/comments'), $body)->assertForbidden();
    campusName($this, $version, $token, 'Invented reviewer')->assertUnprocessable();
    campusName($this, $version, $token)->assertOk();
    foreach (['actor_id', 'tenant_id', 'reviewer_id', 'send', 'page'] as $field) {
        $this->postJson(campusUrl($token, '/campus/home/comments'), $body + [$field => 'forged'])->assertUnprocessable();
    }
    foreach (['version_hash' => str_repeat('c', 64), 'source_hash' => str_repeat('d', 64), 'build_id' => 'unreviewed-build'] as $field => $value) {
        $this->postJson(campusUrl($token, '/campus/home/comments'), array_replace($body, [$field => $value]))->assertConflict();
    }
    expect(Comment::count())->toBe(0);
});

test('revocation expiry unlock expiry freezes and reviewer agreement boundaries are rechecked', function (): void {
    ['version' => $version, 'token' => $token] = campusPublish($this);
    campusUnlock($this, $token);
    campusName($this, $version, $token)->assertOk();
    $body = campusBinding($version) + ['expected_reviewer_name' => 'Ross', 'body' => 'Feedback', 'comment_key' => (string) Str::uuid()];
    config(['service_proposals.writes_enabled' => false]);
    $this->getJson(campusUrl($token, '/campus/home/review'))->assertOk()->assertJsonPath('review.writes_enabled', false);
    $this->postJson(campusUrl($token, '/campus/home/comments'), $body)->assertForbidden();
    config(['service_proposals.writes_enabled' => true]);
    $this->postJson(campusUrl($token, '/selection'), [])->assertForbidden();
    $this->postJson(campusUrl($token, '/request-agreement'), [])->assertForbidden();
    $this->travel(31)->minutes();
    $this->getJson(campusUrl($token, '/campus/home/review'))->assertForbidden();
    $this->postJson(campusUrl($token, '/campus/home/comments'), $body)->assertForbidden();
    campusUnlock($this, $token);
    $version->update(['revoked_at' => now()]);
    $this->getJson(campusUrl($token, '/campus/home/review'))->assertGone();
    $this->postJson(campusUrl($token, '/campus/home/comments'), $body)->assertGone();
    ['token' => $secondToken] = campusPublish($this);
    campusUnlock($this, $secondToken);
    $this->travel(8)->days();
    $this->getJson(campusUrl($secondToken, '/campus/home/review'))->assertGone();
    expect(Comment::count())->toBe(0)->and(Review::count())->toBe(0);
});

test('GET feedback reads change no proposal records and disabled bridge cannot expose comments', function (): void {
    ['version' => $version, 'token' => $token] = campusPublish($this);
    campusUnlock($this, $token);
    $before = [DB::table('service_proposal_versions')->get()->toJson(), DB::table('service_proposal_comments')->get()->toJson(),
        DB::table('tenant_user')->get()->toJson()];
    $this->getJson(campusUrl($token, '/campus/home/review'))->assertOk();
    $this->getJson(campusUrl($token, '/campus/projects/review'))->assertOk();
    expect([DB::table('service_proposal_versions')->get()->toJson(), DB::table('service_proposal_comments')->get()->toJson(),
        DB::table('tenant_user')->get()->toJson()])->toBe($before);
    config(['service_proposals.campus_review.enabled' => false]);
    $this->getJson(campusUrl($token, '/campus/home/review'))->assertNotFound();
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('campus review commands require the actual session CSRF token', function (): void {
    ['version' => $version, 'token' => $token] = campusPublish($this);
    campusUnlock($this, $token);
    $csrf = $this->getJson(campusUrl($token, '/campus/home/review'))->assertOk()->json('review.csrf_token');
    app()->instance(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        new class(app(), app('encrypter')) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    $name = campusBinding($version) + ['reviewer_name' => 'Ross'];
    $this->postJson(campusUrl($token, '/campus/home/reviewer-name'), $name)->assertStatus(419);
    $this->withHeader('X-CSRF-TOKEN', $csrf)->postJson(campusUrl($token, '/campus/home/reviewer-name'), $name)
        ->assertOk()->assertJsonPath('review.reviewer_name', 'Ross');
    $body = campusBinding($version) + ['expected_reviewer_name' => 'Ross', 'body' => 'Explicit protected feedback', 'comment_key' => (string) Str::uuid()];
    $this->withHeader('X-CSRF-TOKEN', 'invalid')->postJson(campusUrl($token, '/campus/home/comments'), $body)->assertStatus(419);
    $this->withHeader('X-CSRF-TOKEN', $csrf)->postJson(campusUrl($token, '/campus/home/comments'), $body)->assertOk();
    expect(Comment::count())->toBe(1);
    Http::assertNothingSent();
});

test('private current browser review exposes simulated controls and voice assets without paid approval or new authority', function (): void {
    ['version' => $version, 'token' => $token] = campusPublish($this);
    campusUnlock($this, $token);
    $before = [User::count(), DB::table('tenant_user')->count(), Version::count(), Comment::count()];
    $response = $this->get(campusUrl($token, '/campus/ask'))->assertOk()
        ->assertSee('id="reviewer-simulation"', false)->assertSee('Simulate send')
        ->assertSee('"reviewerSimulation":true', false)->assertSee('EverbranchVoiceJobs', false)
        ->assertSee('Drafts and demo outbox last only on this page');
    expect($response->headers->get('Content-Security-Policy'))->toContain("connect-src 'self'", "script-src 'nonce-");
    expect($version->fresh()->payload)->not->toHaveKey('review_workspace')->not->toHaveKey('reviewer_ai');
    expect([User::count(), DB::table('tenant_user')->count(), Version::count(), Comment::count()])->toBe($before);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('ordinary public sample retains its inactive private conversation and provider gates', function (): void {
    config(['tenancy.domains.canonical.landlord_host' => 'evergrove.test']);
    $this->get('https://evergrove.test/platform/demos/lees-mcrae')->assertOk()
        ->assertDontSee('id="reviewer-simulation"', false)->assertDontSee('"reviewerSimulation":true', false)
        ->assertSee('Coming soon. Continue exploring projects, tickets and team updates in this demo.');
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('trusted live reviewer AI presentation keeps paid controls and assets exclusive from simulation', function (): void {
    ['version' => $version, 'token' => $token] = campusPublish($this);
    campusUnlock($this, $token);
    $context = $this->getJson(campusUrl($token, '/campus/ask/review'))->assertOk()->json('review');
    // Presentation-only fixture. No connection approval, key, budget or provider call is created.
    $context['reviewer_ai'] = ['enabled' => true];
    $response = app(\App\Enterprise\ReviewerPresentation::class)->respond(\Illuminate\Http\Request::create(campusUrl($token, '/campus/ask')), false, $context);
    expect($response->getContent())->toContain('id="proposal-ai-controls"', 'EverbranchReviewerVoice')
        ->not->toContain('id="reviewer-simulation"', '"reviewerSimulation":true');
    Http::assertNothingSent();
    Mail::assertNothingSent();
});
