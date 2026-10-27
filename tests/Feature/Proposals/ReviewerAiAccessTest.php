<?php

use App\Models\Tenant;
use App\Models\User;
use App\Proposals\CampusReviewManifest;
use App\Proposals\ProposalService;
use App\Proposals\ReviewerAiAccess;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->withoutVite();
    $this->tenant = Tenant::create(['name' => 'Synthetic AI proposal owner', 'slug' => 'synthetic-review-ai']);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    config(['app.url' => 'https://evergrove.test', 'enterprise.runtime' => true, 'enterprise_runtime.base_path' => '',
        'enterprise_runtime.trial_mount_only' => false, 'service_proposals.runtime_purpose' => 'proposal_owner',
        'service_proposals.enabled' => true, 'service_proposals.writes_enabled' => true, 'service_proposals.publish_enabled' => true,
        'service_proposals.owner_workspaces' => [$this->tenant->id => 'enterprise'], 'service_proposals.recipient_hosts' => ['evergrove.test'],
        'evergrove.hosts' => ['evergrove.test'], 'service_proposals.reviewer_ai.ui_enabled' => true,
        'service_proposals.reviewer_ai.enabled' => false, 'service_proposals.campus_review' => ['enabled' => true, 'build_id' => 'ai-synthetic-build']]);
    $this->manifest = new class extends CampusReviewManifest
    {
        public string $hash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        protected function sourceHash(): string
        {
            return $this->hash;
        }
    };
    app()->instance(CampusReviewManifest::class, $this->manifest);
    $service = app(ProposalService::class);
    $draft = $service->leesMcRaeDraft($this->tenant, $this->owner->id);
    $published = $service->publish($this->tenant, $this->owner->id, $draft->id, 1, 'Synthetic private AI password', 7, (string) Str::uuid(), 'reviewer');
    $this->version = $published['version'];
    $this->token = $published['token'];
    $this->url = 'https://evergrove.test/service-proposals/'.$published['token'];
    Route::post('/service-proposals/{token}/campus/ask/access-proof', function (Request $request, string $token) {
        $scope = app(ReviewerAiAccess::class)->scope($request, $token, $request->input('binding'), (bool) $request->input('start'));

        return response()->json(['version_id' => $scope['version']->id, 'source_hash' => $scope['manifest']['source_hash'], 'browser_hash' => $scope['browser_hash']]);
    })->middleware('web');
});

afterEach(function (): void {
    Http::assertNothingSent();
});

function reviewerAiBinding($test): array
{
    return ['version_hash' => $test->version->content_hash, 'build_id' => 'ai-synthetic-build', 'source_hash' => $test->manifest->hash];
}

function reviewerAiUnlock($test): void
{
    $test->post($test->url.'/unlock', ['password' => 'Synthetic private AI password'])->assertRedirect();
    // Feature clients must explicitly carry the synthetic session cookie across requests.
    $test->withCredentials()->withCookie(config('session.cookie'), session()->getId());
}

test('proposal AI access is default-off and requires the exact unlocked current build without employee identity or paid services', function (): void {
    $url = $this->url.'/campus/ask/access-proof';
    $this->postJson($url, ['start' => true])->assertForbidden();
    reviewerAiUnlock($this);
    config(['service_proposals.reviewer_ai.ui_enabled' => false]);
    $this->postJson($url, ['start' => true])->assertNotFound();
    config(['service_proposals.reviewer_ai.ui_enabled' => true]);
    $before = [User::count(), DB::table('tenant_user')->count()];
    DB::enableQueryLog();
    DB::flushQueryLog();
    $first = $this->postJson($url, ['start' => true, 'binding' => reviewerAiBinding($this)])->assertOk()->json();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($first['browser_hash'])->toMatch('/^[a-f0-9]{64}$/D')->and(auth()->check())->toBeFalse()
        ->and([User::count(), DB::table('tenant_user')->count()])->toBe($before);
    foreach ($queries as $query) {
        expect($query['query'])->not->toContain('team_operation_records', 'ent_trial_budget', 'ent_trial_charge');
    }
    $this->postJson($url, ['binding' => reviewerAiBinding($this)])->assertOk()->assertJsonPath('browser_hash', $first['browser_hash']);
    expect(config('service_proposals.reviewer_ai.enabled'))->toBeFalse();
});

test('wrong or omitted explicit binding fields and wrong mount fail before any provider or ledger', function (): void {
    reviewerAiUnlock($this);
    $url = $this->url.'/campus/ask/access-proof';
    $binding = reviewerAiBinding($this);
    $this->postJson($url, ['start' => true, 'binding' => []])->assertUnprocessable();
    $this->postJson($url, ['start' => true, 'binding' => $binding + ['persona' => 'native_owner']])->assertUnprocessable();
    foreach (['version_hash', 'source_hash', 'build_id'] as $field) {
        $wrong = $binding;
        $wrong[$field] = $field === 'build_id' ? 'other-build' : str_repeat('b', 64);
        $this->postJson($url, ['start' => true, 'binding' => $wrong])->assertConflict();
    }
    $this->postJson($url.'?tenant=other', ['start' => true])->assertBadRequest();
    config(['enterprise_runtime.base_path' => '/enterprise-campus']);
    $this->postJson($url, ['start' => true])->assertNotFound();
});

test('revoked expired changed-build and fresh-browser grants cannot reuse previous AI access', function (): void {
    reviewerAiUnlock($this);
    $url = $this->url.'/campus/ask/access-proof';
    $first = $this->postJson($url, ['start' => true])->assertOk()->json('browser_hash');
    // A genuinely independent browser store has the current password grant but no AI nonce.
    $session = new Store('independent-reviewer-ai-browser', new ArraySessionHandler(120));
    $session->start();
    $session->put('service_proposal_access.'.$this->version->id, ['hash' => $this->version->content_hash, 'until' => now()->addMinutes(20)->timestamp]);
    $request = Request::create($url, 'POST');
    $request->setLaravelSession($session);
    try {
        app(ReviewerAiAccess::class)->scope($request, $this->token);
        $this->fail('A password grant cannot reuse another browser AI nonce.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
    $second = app(ReviewerAiAccess::class)->scope($request, $this->token, reviewerAiBinding($this), true)['browser_hash'];
    expect($second)->not->toBe($first);
    expect(app(ReviewerAiAccess::class)->scope($request, $this->token, reviewerAiBinding($this))['browser_hash'])->toBe($second);
    $this->manifest->hash = str_repeat('c', 64);
    $this->postJson($url)->assertConflict();
    $this->manifest->hash = str_repeat('a', 64);
    $this->travelTo($this->version->expires_at->copy()->addSecond());
    $this->postJson($url)->assertStatus(410);
    $this->travelBack();
    $this->version->forceFill(['revoked_at' => now()])->save();
    $this->postJson($url)->assertStatus(410);
});
