<?php

use App\Jobs\RenderMarketplaceVideo;
use App\Models\Marketplace\Identity;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\MakerVideo;
use App\Models\Marketplace\Membership;
use App\Models\Marketplace\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketplace\MakerVideoCaptions;
use App\Services\Marketplace\MakerVideoCatalog;
use App\Services\Marketplace\MakerVideoRenderer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['marketplace.enabled' => true, 'marketplace.checkout_provider' => 'everbranch', 'marketplace.host' => 'marketsandmakers.test', 'app.url' => 'https://app.theeverbranch.test']);
    Route::middleware('web')->group(base_path('routes/marketplace.php'));
    $sorted = new Illuminate\Routing\RouteCollection;
    foreach (collect(Route::getRoutes()->getRoutes())->sortBy(fn ($route) => str_starts_with((string) $route->getName(), 'marketplace.public.') ? 0 : 1) as $route) {
        $sorted->add($route);
    }
    Route::setRoutes($sorted);
    $this->withoutVite();
    Http::preventStrayRequests();
    Storage::fake('marketplace_video');
    Queue::fake();
    $tenant = Tenant::create(['name' => 'Markets and Makers', 'slug' => 'markets-and-makers']);
    Store::create(['tenant_id' => $tenant->id, 'name' => $tenant->name, 'host' => 'marketsandmakers.test', 'verified_at' => now()]);
    $this->operator = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->operator->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    $this->maker = Maker::create(['name' => 'Pilot maker', 'slug' => 'pilot-maker', 'city' => 'Easley', 'story' => 'An independent maker.', 'status' => 'approved']);
    $this->identity = Identity::create(['email' => 'pilot@example.test', 'active' => true]);
    Membership::create(['maker_id' => $this->maker->id, 'identity_id' => $this->identity->id, 'active' => true, 'role' => 'owner']);
    $this->published = 'https://marketsandmakers.test';
    $this->studio = $this->published.'/maker/'.$this->maker->id.'/studio';
    $this->reviews = 'https://app.theeverbranch.test/marketplace/operations/videos';
    $this->draft = [
        'title' => 'Throwing a cup', 'description' => 'Shaping the rim by hand', 'format' => 'short', 'action' => 'submit',
        'revision' => 0, 'trim_start' => 3, 'trim_end' => 15, 'captions' => "WEBVTT\n\n00:00.000 --> 00:02.000\nThe rim.",
    ];
});

function mmEnableVideoMaker(Maker $maker): void
{
    DB::table('marketplace_video_invitations')->insert(['maker_id' => $maker->id, 'created_at' => now(), 'updated_at' => now()]);
}

it('invites a maker, processes only the submitted revision, schedules review and protects private footage', function () {
    $this->withSession(['marketplace_identity' => $this->identity->id])->get($this->studio.'/new')->assertForbidden();
    $this->actingAs($this->operator)->post($this->reviews.'/invitations', ['maker_id' => $this->maker->id, 'action' => 'invite'])->assertRedirect();
    $this->get($this->studio.'/new')->assertOk()->assertSee('Upload a video');
    $this->post($this->studio, [...$this->draft, 'source' => UploadedFile::fake()->create('source.mp4', 20, 'video/mp4')])->assertSessionHasNoErrors()->assertRedirect();
    $video = MakerVideo::sole();
    $this->get($this->studio.'/'.$video->id)->assertOk()->assertSee('Processing');
    $this->get($this->published.'/watch/'.$video->id.'/film')->assertNotFound();
    $this->get($this->published.'/watch/'.$video->id.'/captions.vtt')->assertNotFound();
    Queue::assertPushed(RenderMarketplaceVideo::class, fn ($job) => $job->revision === 1 && $job->videoId === $video->id);
    $disk = Storage::disk('marketplace_video');
    $disk->assertExists($video->source_path);
    $renderer = Mockery::mock(MakerVideoRenderer::class);
    $renderer->shouldReceive('render')->once()->andReturnUsing(function ($video, $path) use ($disk) {
        $disk->put($path, 'approved trim');
        $disk->put($path.'.jpg', 'poster');

        return 12;
    });
    (new RenderMarketplaceVideo($video->id, 1))->handle($renderer);
    (new RenderMarketplaceVideo($video->id, 1))->handle($renderer);
    $this->get($this->reviews.'/'.$video->id)->assertOk()->assertSee('Approve publication');
    $data = ['revision' => 1, 'action' => 'approve', 'publish_at' => now()->addDay()->setTimezone(config('marketplace.timezone'))->format('Y-m-d\TH:i')];
    $this->post($this->reviews.'/'.$video->id, $data)->assertSessionHasNoErrors()->assertRedirect();
    $this->get($this->published.'/watch/'.$video->id)->assertNotFound();
    $this->travel(2)->days();
    $this->get($this->published.'/watch')->assertOk()->assertSee($video->title);
    $this->get($this->published.'/watch/'.$video->id)->assertOk()->assertSee($video->title)->assertDontSee($video->source_path);
    $this->get($this->published.'/watch/'.$video->id.'/film')->assertOk()->assertHeader('Content-Type', 'video/mp4');
    $this->get($this->published.'/watch/'.$video->id.'/captions.vtt')->assertOk()->assertSee('The rim.');
    $this->withCookie(config('session.cookie'), session()->getId());
    $this->post($this->published.'/watch/'.$video->id.'/play')->assertNoContent();
    $this->post($this->published.'/watch/'.$video->id.'/play')->assertNoContent();
    expect($video->fresh()->views)->toBe(1);
    $this->post($this->studio.'/'.$video->id, [...$this->draft, 'revision' => 1, 'action' => 'draft'])->assertSessionHasNoErrors()->assertRedirect();
    $this->get($this->published.'/watch/'.$video->id.'/film')->assertNotFound();
    $this->post($this->reviews.'/'.$video->id, $data)->assertConflict();
    $this->post($this->studio.'/'.$video->id, [...$this->draft, 'revision' => 1])->assertConflict();
});

it('rejects other makers products and drafts and prevents mismatched, disconnected and unavailable card redirects', function () {
    mmEnableVideoMaker($this->maker);
    $other = Maker::create(['name' => 'Neighbor', 'slug' => 'neighbor', 'city' => 'Easley', 'story' => 'Another maker.', 'status' => 'approved']);
    $foreign = Listing::create(['maker_id' => $other->id, 'slug' => 'other-cup', 'title' => 'Other cup', 'category' => 'Ceramics', 'description' => 'Cup', 'price_cents' => 2000, 'images' => [], 'status' => 'approved']);
    $this->withSession(['marketplace_identity' => $this->identity->id])->post($this->studio, [...$this->draft, 'source' => UploadedFile::fake()->create('source.mp4', 20, 'video/mp4'), 'tags' => [['key' => 'marketplace:'.$foreign->id]]])->assertSessionHasErrors('tags');
    $create = fn ($maker) => MakerVideo::create(['maker_id' => $maker->id, 'title' => 'Cup demo', 'format' => 'short', 'source_path' => 'private/raw.mp4', 'source_type' => 'video/mp4', 'render_path' => 'render.mp4', 'status' => 'approved', 'publish_at' => now()]);
    $video = $create($this->maker);
    $another = $create($other);
    $this->get($this->studio.'/'.$another->id)->assertNotFound();
    $this->get($this->studio.'/'.$another->id.'/source')->assertNotFound();
    $this->get($this->published.'/maker/'.$other->id.'/studio')->assertForbidden();
    $tag = $video->tags()->create(['product_key' => 'shopify:1:123', 'label' => 'Cup']);
    $url = $this->published.'/watch/'.$video->id.'/products/'.$tag->id;
    $this->post($this->published.'/watch/'.$another->id.'/products/'.$tag->id)->assertNotFound();
    $this->post($url)->assertRedirect($this->published.'/watch/'.$video->id);
    $this->get($this->published.'/watch/'.$video->id)->assertOk()->assertSee('Currently unavailable');
    $catalog = Mockery::mock(MakerVideoCatalog::class);
    $this->app->instance(MakerVideoCatalog::class, $catalog);
    $catalog->shouldReceive('resolve')->once()->andReturn(['available' => true, 'href' => 'https://maker.example/products/cup?variant=123', 'lane' => 'maker_store']);
    $this->post($url)->assertStatus(303)->assertRedirect('https://maker.example/products/cup?variant=123')->assertSessionMissing('marketplace.video_tags');
    $catalog->shouldReceive('resolve')->once()->andReturn(['available' => true, 'href' => $this->published.'/products/cup', 'lane' => 'marketplace', 'listing_id' => 15]);
    $this->post($url)->assertStatus(303)->assertSessionHas('marketplace.video_tags.15', $tag->id);
    expect($tag->fresh()->clicks)->toBe(2);
    $this->maker->update(['status' => 'suspended']);
    $this->post($url)->assertNotFound();
    $this->get($this->published.'/watch/'.$video->id)->assertNotFound();
});

it('validates trim windows and outputs text-only cues with clip-relative timestamps', function () {
    $validator = new MakerVideoCaptions;
    expect($validator->normalize("WEBVTT\r\n\r\n1\r\n00:00:00.000 --> 00:00:02.500\r\n<c>hello</c>", 3))->toContain('&lt;c&gt;hello&lt;/c&gt;')
        ->and(fn () => $validator->normalize("WEBVTT\n\n00:00.000 --> 01:00.000\nToo late", 10))->toThrow(Illuminate\Validation\ValidationException::class);
    mmEnableVideoMaker($this->maker);
    $this->withSession(['marketplace_identity' => $this->identity->id])->post($this->studio, [...$this->draft, 'source' => UploadedFile::fake()->create('source.mp4', 20, 'video/mp4'), 'trim_end' => 2])->assertSessionHasErrors('trim_end');
    expect(MakerVideo::count())->toBe(0);
});

it('renders only the selected seconds as H.264 with audio, a poster and no private title metadata', function () {
    $ffmpeg = config('marketplace.video_ffmpeg');
    $ffprobe = config('marketplace.video_ffprobe');
    $finder = new Symfony\Component\Process\ExecutableFinder;
    if (! $finder->find($ffmpeg) && ! is_file($ffmpeg) || ! $finder->find($ffprobe) && ! is_file($ffprobe)) {
        $this->markTestSkipped('This encoder integration requires FFmpeg and FFprobe.');
    }
    $disk = Storage::disk('marketplace_video');
    $source = 'source.mp4';
    (new Symfony\Component\Process\Process([$ffmpeg, '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=96x64:rate=24',
        '-f', 'lavfi', '-i', 'sine=frequency=440', '-t', '4', '-c:v', 'libx264', '-c:a', 'aac', '-metadata', 'title=Private recording title', $disk->path($source)]))->mustRun();
    $video = MakerVideo::create(['maker_id' => $this->maker->id, 'title' => 'Rendered cup demo', 'format' => 'short', 'source_path' => $source, 'source_type' => 'video/mp4', 'trim_start' => 1, 'trim_end' => 3, 'status' => 'processing', 'revision' => 1]);
    (new RenderMarketplaceVideo($video->id, 1))->handle(app(MakerVideoRenderer::class));
    $video->refresh();
    expect($video->status)->toBe('review')->and($video->duration)->toBe(2.0);
    $disk->assertExists([$video->render_path, $video->render_path.'.jpg']);
    $probe = new Symfony\Component\Process\Process([$ffprobe, '-v', 'error', '-show_entries', 'format=duration:format_tags=title:stream=codec_name', '-of', 'json', $disk->path($video->render_path)]);
    $info = json_decode($probe->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect((float) $info['format']['duration'])->toBeGreaterThanOrEqual(1.95)->toBeLessThanOrEqual(2.10)
        ->and(array_column($info['streams'], 'codec_name'))->toBe(['h264', 'aac'])
        ->and($info['format']['tags']['title'] ?? null)->toBeNull();
});
