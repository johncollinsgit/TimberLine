<?php

use App\Http\Controllers\StudioDistributionController;
use App\Jobs\UploadStudioYouTubeVideo;
use App\Models\IntegrationConnection;
use App\Models\MarketingSocialPost;
use App\Models\MarketingSocialPublication;
use App\Models\StudioEpisodeDelivery;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ManagedWebsite\StudioEpisodeFeed;
use App\Services\ManagedWebsite\StudioYouTubeAccount;
use App\Services\ManagedWebsite\StudioYouTubeUpload;
use App\Services\Marketing\Desk\MarketingDeskAccess;
use App\Services\Marketing\Desk\SocialPostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->tenant = Tenant::create(['name' => 'Podcast fixture', 'slug' => 'podcast-fixture']);
    $this->actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actor->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    $this->partialMock(MarketingDeskAccess::class)->shouldReceive('available')->andReturnTrue();
    $this->feed = 'https://show.example.test/podcast/rss.xml';
    config(['studio_distribution.feeds' => [$this->tenant->id => $this->feed], 'marketing_desk.media_hosts' => ['show.example.test']]);
    $this->episode = (string) Str::uuid();
    $this->revision = (string) Str::uuid();
    $this->digest = hash('sha256', 'video');
    $this->asset = 'https://show.example.test/podcast/media/'.$this->digest.'.mp4';
    $this->rss = '<rss xmlns:studio="https://theeverbranch.com/ns/studio/1"><channel><item><title>Studio episode</title><description>John and Sarah</description><guid>urn:uuid:'.$this->episode.'</guid><studio:revision>'.$this->revision.'</studio:revision><enclosure url="https://show.example.test/podcast/media/'.str_repeat('b', 64).'.mp3"/><studio:asset kind="episode_video" url="'.$this->asset.'"/><studio:asset kind="short" url="https://other.test/video.mp4"/></item></channel></rss>';
    $this->selection = ['episode' => $this->episode, 'revision' => $this->revision, 'asset' => $this->digest];
    $this->studioRequest = function (array $input) {
        $request = Request::create('/website/studio/distribution', 'POST', $input);
        $request->setUserResolver(fn () => $this->actor);
        $request->setLaravelSession(app('session.store'));
        $request->attributes->set('current_tenant_id', $this->tenant->id);

        return $request;
    };
    Http::preventStrayRequests();
    $this->withoutVite();
});

it('turns a current feed asset into exactly one editable Meta draft and leaves scheduling to the operator', function () {
    Http::fake([$this->feed => fn () => Http::response($this->rss)]);
    $feed = app(StudioEpisodeFeed::class);
    expect($feed->episodes($this->tenant->id)[$this->episode]['assets'])->toHaveCount(1)
        ->and(fn () => $feed->url($this->tenant->id + 100))->toThrow(HttpException::class)
        ->and(fn () => $feed->asset($this->tenant->id, $this->episode, (string) Str::uuid(), $this->digest))->toThrow(HttpException::class);
    $request = ($this->studioRequest)($this->selection + ['caption' => 'Watch John and Sarah']);
    $controller = app(StudioDistributionController::class);
    $controller->meta($request, app(SocialPostService::class));
    $controller->meta($request, app(SocialPostService::class));
    $post = MarketingSocialPost::sole();
    expect($post->status)->toBe('draft')->and($post->content['media_urls'])->toBe([$this->asset])
        ->and(MarketingSocialPublication::count())->toBe(0)->and(StudioEpisodeDelivery::count())->toBe(1);
    $this->mock(\App\Services\Onboarding\TenantOnboardingCompletionService::class)->shouldReceive('isComplete')->andReturnTrue();
    $this->actingAs($this->actor)->get(route('studio.distribution.index', ['tenant' => $this->tenant->slug]))->assertOk()
        ->assertSee('John and Sarah')->assertSee('Rumble upload')->assertSee('Spotify for Creators')->assertSee('Apple Podcasts Connect');
});

it('binds creator OAuth to the consenting workspace owner and accepts each callback once', function () {
    config(['studio_distribution.google_id' => 'creator-client', 'studio_distribution.google_secret' => 'test-oauth-secret',
        'studio_distribution.callback' => 'https://app.example.test/integrations/studio/youtube/callback']);
    $controller = app(StudioDistributionController::class);
    $accounts = app(StudioYouTubeAccount::class);
    $request = ($this->studioRequest)([]);
    $url = $controller->connect($request, $accounts)->getTargetUrl();
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect($query['code_challenge_method'])->toBe('S256')->and($query['scope'])->toContain('youtube.upload');
    $callback = ($this->studioRequest)(['state' => 'wrong', 'code' => 'test-code']);
    expect(fn () => $controller->callback($callback, $accounts, app(MarketingDeskAccess::class), app(StudioEpisodeFeed::class)))->toThrow(HttpException::class);
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-access-token', 'refresh_token' => 'test-refresh-token',
        'scope' => $query['scope'], 'expires_in' => 3600]),
        'https://www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['id' => 'UCFixture', 'snippet' => ['title' => 'Channel fixture']]]])]);
    $callback = ($this->studioRequest)(['state' => $query['state'], 'code' => 'test-code']);
    $controller->callback($callback, $accounts, app(MarketingDeskAccess::class), app(StudioEpisodeFeed::class));
    $conn = IntegrationConnection::forTenantId($this->tenant->id)->where('provider', 'youtube_studio')->sole();
    expect($conn->external_account_label)->toBe('Channel fixture')->and($conn->getRawOriginal('refresh_token'))->not->toContain('test-refresh-token')
        ->and(fn () => $controller->callback($callback, $accounts, app(MarketingDeskAccess::class), app(StudioEpisodeFeed::class)))->toThrow(HttpException::class);
    $this->actor->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'member']);
    expect(fn () => $controller->disconnect($request, $conn->id))->toThrow(HttpException::class);
});

it('resumes a lost YouTube completion on the same session without posting the video or creating a second receipt again', function () {
    Queue::fake();
    $channel = IntegrationConnection::create(['tenant_id' => $this->tenant->id, 'provider' => 'youtube_studio', 'status' => 'connected',
        'external_account_id' => 'UCStudio', 'external_account_label' => 'Studio', 'access_token' => 'fixture-access', 'refresh_token' => 'fixture-refresh', 'expires_at' => now()->addHour()]);
    $session = 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=fixture';
    $start = $chunks = $probes = 0;
    Http::fake(function ($request) use ($session, &$start, &$chunks, &$probes) {
        if ($request->url() === $this->feed) {
            return Http::response($this->rss);
        }
        if ($request->url() === $this->asset) {
            expect($request->hasHeader('Authorization'))->toBeFalse();
            if ($request->method() === 'HEAD') {
                return Http::response('', 200, ['Content-Length' => 5]);
            }

            return Http::response('video', 206, ['Content-Range' => 'bytes 0-4/5', 'Content-Length' => 5]);
        }
        expect($request->header('Authorization'))->toBe(['Bearer fixture-access']);
        if ($request->method() === 'POST') {
            $start++;
            expect($request['status']['privacyStatus'])->toBe('private')->and($request['snippet']['title'])->toBe('Launch episode');

            return Http::response([], 200, ['Location' => $session]);
        }
        expect($request->url())->toBe($session);
        if ($request->header('Content-Range') === ['bytes */5']) {
            $probes++;

            return $probes === 1 ? Http::response('', 308) : Http::response(['id' => '123456789ab'], 201);
        }
        $chunks++;
        expect($request->body())->toBe('video');

        return Http::response([], 503);
    });
    $request = ($this->studioRequest)($this->selection + ['title' => 'Launch episode', 'channel' => $channel->id,
        'privacy' => 'private', 'made_for_kids' => '0', 'confirmed' => '1']);
    $controller = app(StudioDistributionController::class);
    $controller->youtube($request);
    $controller->youtube($request);
    $receipt = StudioEpisodeDelivery::sole();
    $job = new UploadStudioYouTubeVideo($this->tenant->id, $receipt->id);
    expect(fn () => $job->handle(app(StudioEpisodeFeed::class), app(StudioYouTubeUpload::class)))->toThrow(RuntimeException::class);
    $job->handle(app(StudioEpisodeFeed::class), app(StudioYouTubeUpload::class));
    $job->handle(app(StudioEpisodeFeed::class), app(StudioYouTubeUpload::class));
    expect([$start, $chunks, $probes])->toBe([1, 1, 2])->and($receipt->fresh()->status)->toBe('uploaded')
        ->and($receipt->fresh()->details['video_id'])->toBe('123456789ab')->and($receipt->fresh()->details)->not->toHaveKey('session');
});
