<?php

use App\Models\Tenant;
use App\Models\User;
use App\Proposals\Activity;
use App\Proposals\CampusReviewManifest;
use App\Proposals\ProposalService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    $this->originalStorage = app()->storagePath();
    $this->activityStorage = sys_get_temp_dir().'/proposal-activity-'.Str::uuid();
    app()->useStoragePath($this->activityStorage);
    $this->tenant = Tenant::create(['name' => 'Synthetic activity owner', 'slug' => 'activity-owner']);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    config(['service_proposals.enabled' => true, 'service_proposals.writes_enabled' => true, 'service_proposals.publish_enabled' => true,
        'service_proposals.owner_workspaces' => [$this->tenant->id => 'enterprise'], 'service_proposals.recipient_hosts' => ['evergrove.test'],
        'evergrove.hosts' => ['evergrove.test'], 'enterprise.reviewer_demo_enabled' => true,
        'service_proposals.campus_review' => ['enabled' => true, 'build_id' => 'synthetic-tracked-campus']]);
    app()->instance(CampusReviewManifest::class, new class extends CampusReviewManifest
    {
        protected function sourceHash(): string
        {
            return str_repeat('a', 64);
        }
    });
    $service = app(ProposalService::class);
    $draft = $service->leesMcRaeDraft($this->tenant, $this->owner->id);
    ['version' => $this->version, 'token' => $token] = $service->publish($this->tenant, $this->owner->id, $draft->id, 1, 'Synthetic review password', 7, (string) Str::uuid(), 'reviewer');
    $this->url = 'https://evergrove.test/service-proposals/'.$token;
    $this->ownerUrl = 'https://evergrove.test/owner-workspaces/'.$this->tenant->slug.'/proposals/versions/'.$this->version->id.'/activity';
    $this->body = ['event' => 'proposal_section', 'page' => 'scope', 'scene' => null, 'event_id' => (string) Str::uuid(), 'version_hash' => $this->version->content_hash];
});

afterEach(function (): void {
    app()->useStoragePath($this->originalStorage);
    File::deleteDirectory($this->activityStorage);
});

test('opens failed passwords and actual unlocks remain distinct and encrypted', function (): void {
    $this->get($this->url)->assertOk()->assertSee('Review activity');
    $this->post($this->url.'/unlock', ['password' => 'wrong'])->assertRedirect();
    $events = app(Activity::class)->read($this->version)['events'];
    expect(array_column($events, 'event'))->toBe(['link_opened']);
    $this->post($this->url.'/unlock', ['password' => 'Synthetic review password'])->assertRedirect();
    $this->post($this->url.'/reviewer-name', ['reviewer_name' => 'Ross'])->assertRedirect();
    $response = $this->get($this->url)->assertOk()->assertSee('data-review-activity', false);
    $events = app(Activity::class)->read($this->version)['events'];
    expect(array_column($events, 'event'))->toBe(['link_opened', 'unlocked', 'name_selected', 'proposal_view'])
        ->and(array_unique(array_column($events, 'visit')))->toHaveCount(1)
        ->and(end($events)['name'])->toBe('Ross');
    $raw = file_get_contents(glob($this->activityStorage.'/app/private/proposal-activity/*/*.jsonl')[0]);
    expect($raw)->not->toContain('Ross', 'Synthetic review password', 'link_opened', $this->version->content_hash);
    expect($response->headers->get('Content-Security-Policy'))->toContain("script-src 'sha256-", "connect-src 'self'", "script-src-attr 'none'")
        ->not->toContain("script-src 'unsafe-inline'", 'https://external.test');
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('browser reports need current unlock exact publication and fixed data only', function (): void {
    $this->postJson($this->url.'/activity', $this->body)->assertForbidden();
    $this->post($this->url.'/unlock', ['password' => 'Synthetic review password']);
    $this->postJson($this->url.'/activity', [...$this->body, 'version_hash' => str_repeat('b', 64)])->assertConflict();
    $this->postJson($this->url.'/activity', [...$this->body, 'body' => 'Do not collect this'])->assertUnprocessable();
    $this->postJson($this->url.'/activity', [...$this->body, 'page' => 'private-tenant-record'])->assertUnprocessable();
    $this->postJson($this->url.'/activity', $this->body)->assertOk()->assertJsonPath('recorded', true);
    $this->postJson($this->url.'/activity', $this->body)->assertOk();
    expect(collect(app(Activity::class)->read($this->version)['events'])->where('event', 'proposal_section'))->toHaveCount(1);
    $tour = [...$this->body, 'event' => 'tour_completed', 'page' => null, 'scene' => 7, 'event_id' => (string) Str::uuid()];
    $this->postJson($this->url.'/activity', [...$tour, 'scene' => 2])->assertUnprocessable();
    $this->postJson($this->url.'/activity', $tour)->assertOk();
    $this->version->update(['revoked_at' => now()]);
    $this->postJson($this->url.'/activity', [...$this->body, 'event_id' => (string) Str::uuid()])->assertGone();
});

test('activity access is owner scoped and owner tests are hidden by default', function (): void {
    $this->get($this->ownerUrl)->assertRedirect();
    $this->actingAs($this->owner);
    $this->post($this->url.'/unlock', ['password' => 'Synthetic review password']);
    $this->post($this->url.'/reviewer-name', ['reviewer_name' => 'Ross']);
    $this->get($this->url);
    $this->get($this->ownerUrl)->assertOk()->assertSee('1 owner test hidden')->assertDontSee('Password unlocked');
    $this->get($this->ownerUrl.'?include_tests=1')->assertOk()->assertSee('Password unlocked')->assertSee('Owner test')->assertSee('Ross');
    $other = Tenant::create(['name' => 'Other owner', 'slug' => 'other-activity-owner']);
    $this->owner->tenants()->attach($other, ['role' => 'owner', 'membership_active' => true]);
    config(['service_proposals.owner_workspaces.'.$other->id => 'enterprise']);
    $this->get(str_replace('/activity-owner/', '/other-activity-owner/', $this->ownerUrl))->assertNotFound();
    $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    $this->get($this->ownerUrl)->assertForbidden();
});

test('a stale campus manifest cannot submit page or tour progress and paths are bounded', function (): void {
    $this->post($this->url.'/unlock', ['password' => 'Synthetic review password']);
    $this->postJson($this->url.'/activity', [...$this->body, 'event' => 'campus_page', 'page' => 'projects'])->assertOk();
    config(['service_proposals.campus_review.build_id' => 'another-build']);
    $this->postJson($this->url.'/activity', [...$this->body, 'event' => 'tour_scene', 'page' => null, 'scene' => 1])->assertConflict();
    $files = glob($this->activityStorage.'/app/private/proposal-activity/*/*.jsonl');
    expect($files)->toHaveCount(1)->and(fileperms($files[0]) & 0777)->toBe(0600);
    file_put_contents($files[0], str_repeat('x', Activity::MAX_BYTES));
    $this->postJson($this->url.'/activity', $this->body)->assertServiceUnavailable();
    expect(app(Activity::class)->read($this->version)['limited'])->toBeTrue();
});

test('activity has a separate request budget and respects the proposal write switch', function (): void {
    $this->post($this->url.'/unlock', ['password' => 'Synthetic review password']);
    for ($index = 0; $index < 35; $index++) {
        $this->postJson($this->url.'/activity', [...$this->body, 'event_id' => (string) Str::uuid()])->assertOk();
    }
    $this->get($this->url)->assertOk();
    $before = count(app(Activity::class)->read($this->version)['events']);
    config(['service_proposals.writes_enabled' => false]);
    $this->postJson($this->url.'/activity', [...$this->body, 'event_id' => (string) Str::uuid()])->assertForbidden();
    $this->get($this->url)->assertOk();
    expect(app(Activity::class)->read($this->version)['events'])->toHaveCount($before);
});

test('journal links and modified encrypted records fail without following the link or exposing content', function (): void {
    $this->post($this->url.'/unlock', ['password' => 'Synthetic review password']);
    $file = glob($this->activityStorage.'/app/private/proposal-activity/*/*.jsonl')[0];
    $target = $this->activityStorage.'/untouched.txt';
    file_put_contents($target, 'unchanged');
    unlink($file);
    symlink($target, $file);
    $this->postJson($this->url.'/activity', $this->body)->assertServiceUnavailable();
    expect(file_get_contents($target))->toBe('unchanged')->and(app(Activity::class)->read($this->version)['unavailable'])->toBeTrue();
    unlink($file);
    file_put_contents($file, json_encode(['id' => (string) Str::uuid(), 'value' => 'modified ciphertext'])."\n");
    expect(app(Activity::class)->read($this->version)['unavailable'])->toBeTrue()
        ->and(app(Activity::class)->read($this->version)['events'])->toBeEmpty();
});
