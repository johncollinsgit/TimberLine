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
        'title' => 'Throwing a cup', 'description' => 'Shaping the rim by hand', 'theme' => 'behind-the-scenes', 'format' => 'short', 'action' => 'submit',
        'revision' => 0, 'trim_start' => 3, 'trim_end' => 15, 'captions' => "WEBVTT\n\n00:00.000 --> 00:02.000\nThe rim.",
    ];
});

it('exports the maker editor and shoppable player for the isolated browser fixtures', function () {
    if (! getenv('MARKETPLACE_CONSOLE_FIXTURES')) {
        $this->markTestSkipped('Rendered browser fixtures are opt-in.');
    }
    $this->withVite();
    $directory = storage_path('framework/testing/marketplace-console');
    \Illuminate\Support\Facades\File::ensureDirectoryExists($directory);
    mmEnableVideoMaker($this->maker);
    $listing = Listing::create(['maker_id' => $this->maker->id, 'slug' => 'studio-test-cup', 'title' => 'Studio test cup', 'variant_label' => '8 oz', 'category' => 'Ceramics', 'description' => 'Cup', 'price_cents' => 2000, 'images' => [], 'status' => 'approved']);
    $video = MakerVideo::create(['maker_id' => $this->maker->id, 'title' => 'Throwing a cup', 'format' => 'short', 'source_path' => 'private/raw.mp4', 'source_type' => 'video/mp4', 'render_path' => 'render.mp4', 'duration' => 12, 'status' => 'approved', 'publish_at' => now()]);
    $video->tags()->create(['product_key' => 'marketplace:'.$listing->id, 'label' => 'Cup', 'start_seconds' => 2, 'end_seconds' => 4]);
    $editor = $this->withSession(['marketplace_identity' => $this->identity->id])->get($this->studio.'/new')->assertOk();
    $player = $this->get($this->published.'/watch/'.$video->id)->assertOk();
    file_put_contents($directory.'/video-editor.html', $editor->getContent());
    file_put_contents($directory.'/video-player.html', $player->getContent());
});

it('lets customers and organizers discover business ideas and featured makers while only invited approved maker owners may publish', function () {
    $maker = $this->maker;
    $create = fn ($title, $theme, $format) => MakerVideo::create(['maker_id' => $maker->id, 'title' => $title, 'theme' => $theme, 'format' => $format, 'source_path' => 'private/raw.mp4', 'source_type' => 'video/mp4', 'render_path' => 'render.mp4', 'duration' => 12, 'status' => 'approved', 'publish_at' => now()]);
    $start = $create('Starting a pottery studio', 'starting-a-business', 'long');
    $create('Pricing a first collection', 'starting-a-business', 'short');
    $create('Trimming the foot', 'behind-the-scenes', 'short');
    $create('A first collection', 'maker-showcases', 'long');
    $url = $this->published.'/watch';
    $this->get($url.'?theme=starting-a-business&format=long')->assertOk()->assertSee('Starting a pottery studio')->assertDontSee('Pricing a first collection')->assertDontSee('Trimming the foot');
    $this->get($url.'?theme=behind-the-scenes&format=long')->assertOk()->assertSee('No videos match these filters');
    $this->get($url.'?maker='.$maker->slug)->assertOk()->assertSee('Watch '.$maker->name);
    $this->get($url.'/'.$start->id)->assertOk()->assertSee('Explore this maker’s business');
    $this->get($this->published.'/makers/'.$maker->slug)->assertOk()->assertSee('Watch '.$maker->name);
    $customer = Identity::create(['email' => 'customer@market.example.test', 'active' => true]);
    $this->actingAs($this->operator)->withSession(['marketplace_identity' => $customer->id])->get($url)->assertOk();
    mmEnableVideoMaker($maker);
    $this->get($this->studio.'/new')->assertForbidden();
    $this->post($this->studio, $this->draft)->assertForbidden();
    Membership::create(['maker_id' => $maker->id, 'identity_id' => $customer->id, 'active' => true, 'role' => 'organizer']);
    $this->post($this->studio, $this->draft)->assertForbidden();
    $maker->update(['status' => 'suspended']);
    $this->withSession(['marketplace_identity' => $this->identity->id])->get($this->studio.'/new')->assertForbidden();
    $this->post($this->studio, $this->draft)->assertForbidden();
    $this->get($url.'?maker='.$maker->slug)->assertNotFound();
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
    $this->post($this->reviews.'/'.$video->id.'/costs', ['minutes' => 90, 'cost' => '100.00'])->assertRedirect();
    $this->get($this->reviews)->assertOk()->assertSee('1 participating makers')->assertSee('1.5 team production hours')->assertSee('$100.00');
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
    $this->post($this->studio, [...$this->draft, 'source' => UploadedFile::fake()->create('source.mp4', 20, 'video/mp4'), 'source_sha256' => str_repeat('a', 64)])->assertSessionHasErrors('source');
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

    // MediaRecorder WebM blobs can have neither a container duration nor an index.
    $stream = new Symfony\Component\Process\Process([$ffmpeg, '-v', 'error', '-i', $disk->path($source), '-map', '0:v:0', '-c:v', 'libvpx', '-f', 'webm', 'pipe:1']);
    $disk->put('camera.webm', $stream->mustRun()->getOutput());
    $video->update(['source_path' => 'camera.webm', 'source_type' => 'video/webm', 'revision' => 2, 'status' => 'processing']);
    (new RenderMarketplaceVideo($video->id, 2))->handle(app(MakerVideoRenderer::class));
    expect($video->fresh()->status)->toBe('review')->and($video->fresh()->duration)->toBe(2.0);
});

function mmVideoUploadParts(string $bytes): array
{
    $parts = str_split($bytes, \App\Services\Marketplace\MakerVideoUploads::CHUNK_BYTES);
    $hashes = array_map(fn ($part) => hash('sha256', $part), $parts);

    return [$parts, $hashes, ['size' => strlen($bytes), 'fingerprint' => hash('sha256', implode('', $hashes)), 'revision' => 0]];
}

it('saves a real video larger than the PHP file limit through private retryable one MiB requests', function () {
    mmEnableVideoMaker($this->maker);
    $this->withSession(['marketplace_identity' => $this->identity->id]);
    $bytes = file_get_contents(public_path('images/public-site/everbranch-studio-hero-loop.mp4')).pack('N', 1400008).'free'.str_repeat("\0", 1400000);
    [$parts, $hashes, $init] = mmVideoUploadParts($bytes);
    expect(strlen($bytes))->toBeGreaterThan(2 * 1024 ** 2);
    $token = (string) \Illuminate\Support\Str::uuid();
    $url = $this->studio.'/uploads/'.$token;
    $json = ['Accept' => 'application/json'];
    $this->postJson($url, $init)->assertOk()->assertJsonPath('next_index', 0);
    $send = fn ($i, $sum = null) => $this->post($url.'/parts/'.$i, [
        'part' => UploadedFile::fake()->createWithContent('part.bin', $parts[$i]), 'checksum' => $sum ?? $hashes[$i],
    ], $json);
    $send(1)->assertConflict();
    $send(0, str_repeat('0', 64))->assertUnprocessable();
    $send(0)->assertOk()->assertJsonPath('next_index', 1);
    $send(0)->assertOk()->assertJsonPath('next_index', 1);
    $this->postJson($url, $init)->assertOk()->assertJsonPath('next_index', 1);
    $this->postJson($url.'/finish')->assertConflict();
    for ($i = 1; $i < count($parts); $i++) {
        $send($i)->assertOk()->assertJsonPath('next_index', $i + 1);
    }
    $this->postJson($url.'/finish')->assertOk()->assertJsonPath('state', 'ready');
    $this->postJson($url.'/finish')->assertOk()->assertJsonPath('state', 'ready');
    $form = [...$this->draft, 'source_upload_id' => $token, 'source_sha256' => hash('sha256', $bytes)];
    $this->post($this->studio, [...$form, 'title' => ''])->assertSessionHasErrors('title');
    $this->post($this->studio, [...$form, 'source_sha256' => str_repeat('0', 64)])->assertSessionHasErrors('source');
    expect(MakerVideo::count())->toBe(0);
    $this->post($this->studio, $form)->assertSessionHasNoErrors()->assertRedirect();
    $video = MakerVideo::sole();
    expect($video->revision)->toBe(1)->and($video->source_type)->toBe('video/mp4')
        ->and(hash('sha256', Storage::disk('marketplace_video')->get($video->source_path)))->toBe(hash('sha256', $bytes));
    Queue::assertPushed(RenderMarketplaceVideo::class, 1);
    Storage::disk('marketplace_video')->assertMissing('upload-staging/'.$this->maker->id.'/'.$token.'/data');
    $this->post($this->studio, $form)->assertRedirect($this->studio.'/'.$video->id);
    expect(MakerVideo::count())->toBe(1)->and($video->fresh()->revision)->toBe(1);
    Queue::assertPushed(RenderMarketplaceVideo::class, 1);
});

it('restricts staged maker video uploads by membership, invitation, file type, reservation and expiration', function () {
    [$parts, $hashes, $init] = mmVideoUploadParts(str_repeat('plain text ', 100));
    $token = (string) \Illuminate\Support\Str::uuid();
    $url = $this->studio.'/uploads/'.$token;
    $this->withSession(['marketplace_identity' => $this->identity->id])->postJson($url, $init)->assertForbidden();
    mmEnableVideoMaker($this->maker);
    $this->postJson($url, [...$init, 'size' => 3 * 1024 ** 3])->assertUnprocessable();
    $this->postJson($url, $init)->assertOk();
    $this->get($url)->assertNotFound();
    $other = Maker::create(['name' => 'Other', 'slug' => 'other-stage', 'city' => 'Easley', 'story' => 'Other maker.', 'status' => 'approved']);
    $identity = Identity::create(['email' => 'other-stage@example.test', 'active' => true]);
    Membership::create(['maker_id' => $other->id, 'identity_id' => $identity->id, 'active' => true, 'role' => 'owner']);
    mmEnableVideoMaker($other);
    $foreign = $this->published.'/maker/'.$other->id.'/studio/uploads/'.$token;
    $this->withSession(['marketplace_identity' => $identity->id])->postJson($url.'/finish')->assertForbidden();
    $this->postJson($foreign.'/finish')->assertNotFound();
    $this->post($this->published.'/maker/'.$other->id.'/studio', [...$this->draft, 'source_upload_id' => $token])->assertNotFound();
    $this->withSession(['marketplace_identity' => $this->identity->id])->post($url.'/parts/0', [
        'part' => UploadedFile::fake()->createWithContent('maker.mp4', $parts[0]), 'checksum' => $hashes[0],
    ], ['Accept' => 'application/json'])->assertOk();
    $this->postJson($url.'/finish')->assertUnprocessable()->assertJsonValidationErrors('source');
    for ($i = 0; $i < 2; $i++) {
        $this->postJson($this->studio.'/uploads/'.\Illuminate\Support\Str::uuid(), $init)->assertOk();
    }
    $next = $this->studio.'/uploads/'.\Illuminate\Support\Str::uuid();
    $this->postJson($next, $init)->assertStatus(429);
    $this->deleteJson($url)->assertOk();
    $this->postJson($next, $init)->assertOk();
    $this->travel(25)->hours();
    $this->postJson($next.'/finish')->assertGone();
    expect(app(\App\Services\Marketplace\MakerVideoUploads::class)->prune())->toBe(3);
    $this->postJson($next.'/finish')->assertNotFound();
});

it('preserves a draft when assembled bytes fail verification and binds a replacement ticket to its video and revision', function () {
    $bytes = file_get_contents(public_path('images/public-site/everbranch-studio-hero-loop.mp4'));
    [$parts, $hashes, $init] = mmVideoUploadParts($bytes);
    mmEnableVideoMaker($this->maker);
    $this->withSession(['marketplace_identity' => $this->identity->id])->post($this->studio, [
        ...$this->draft, 'action' => 'draft', 'source' => UploadedFile::fake()->create('first.mp4', 3, 'video/mp4'),
    ])->assertSessionHasNoErrors();
    $video = MakerVideo::sole();
    $token = (string) \Illuminate\Support\Str::uuid();
    $url = $this->studio.'/uploads/'.$token;
    $this->postJson($url, [...$init, 'video_id' => $video->id, 'revision' => 1])->assertOk();
    $this->postJson($url, $init)->assertConflict();
    foreach ($parts as $i => $part) {
        $this->post($url.'/parts/'.$i, ['part' => UploadedFile::fake()->createWithContent('part.bin', $part), 'checksum' => $hashes[$i]],
            ['Accept' => 'application/json'])->assertOk();
    }
    $this->postJson($url.'/finish')->assertOk();
    $this->post($this->studio, [...$this->draft, 'source_upload_id' => $token])->assertConflict();
    $path = 'upload-staging/'.$this->maker->id.'/'.$token.'/data';
    Storage::disk('marketplace_video')->put($path, 'broken');
    $this->post($this->studio.'/'.$video->id, [...$this->draft, 'revision' => 1, 'source_upload_id' => $token])->assertUnprocessable();
    expect($video->fresh()->revision)->toBe(1)->and($video->fresh()->source_path)->toBe($video->source_path);
    Storage::disk('marketplace_video')->put($path, $bytes);
    $this->post($this->studio.'/'.$video->id, [...$this->draft, 'action' => 'draft', 'revision' => 1, 'source_upload_id' => $token])->assertSessionHasNoErrors();
    expect($video->fresh()->revision)->toBe(2);
    Storage::disk('marketplace_video')->assertMissing($video->source_path);
});
