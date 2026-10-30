<?php

use App\Services\CampusFacilities\CampusReference;
use App\Services\CampusFacilities\DemoStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    $this->demoDirectory = sys_get_temp_dir().'/lmc-facilities-'.Str::uuid();
    config()->set(['campus_facilities.enabled' => true, 'campus_facilities.storage_path' => $this->demoDirectory,
        'enterprise.reviewer_demo_enabled' => false, 'tenancy.domains.canonical.landlord_host' => 'app.theeverbranch.com',
        'tenancy.landlord.primary_host' => 'app.theeverbranch.com', 'tenancy.landlord.hosts' => ['app.theeverbranch.com'],
        'tenancy.auth.flagship_hosts' => ['app.theeverbranch.com', 'theeverbranch.com']]);
    $this->demoBase = 'https://app.theeverbranch.com/platform/demos/lees-mcrae';
    $this->withHeader('Origin', 'https://app.theeverbranch.com');
    Http::preventStrayRequests();
});
afterEach(function (): void {
    File::deleteDirectory($this->demoDirectory);
    // Http::preventStrayRequests prevents live network calls throughout this suite.
});

function campusDemoDraft(): array
{
    return ['requester' => 'Alex Morgan', 'requesterId' => 'alex', 'department' => 'Academics', 'contact' => 'alex@example.com', 'location' => 'Bowman Building', 'room' => '203', 'issue' => 'Fictional classroom light flickers.', 'onset' => 'This morning', 'impact' => 'Classroom users', 'hazards' => 'No exposed wiring reported', 'access' => 'After 3 PM', 'category' => 'Maintenance'];
}

it('serves the standalone branded mobile shell and share card at the existing public URL', function (): void {
    $response = $this->get($this->demoBase)->assertOk()->assertSee('lmc-api-base', false)
        ->assertSee('/campus-facilities/assets/', false)->assertSee('property="og:image"', false)
        ->assertDontSee('Start 8-minute walkthrough');
    expect($response->headers->getCookies())->toBe([]);
    $this->get($this->demoBase.'?role=technician')->assertOk();
    $this->get($this->demoBase.'?tenant_id=16')->assertNotFound();
    $this->get($this->demoBase.'/manifest.webmanifest')->assertOk()->assertJsonPath('start_url', '/platform/demos/lees-mcrae/');
    $this->get($this->demoBase.'/sw.js')->assertOk()->assertSee("u.pathname.includes('/api/')", false);
    $this->get('https://untrusted.example.test/platform/demos/lees-mcrae/api/tickets')->assertNotFound();
});

it('persists one shared ticket across clients and requires head approval before technician work', function (): void {
    $input = ['role' => 'staff', 'actor' => 'alex', 'action' => 'create', 'requestKey' => (string) Str::uuid(), 'fields' => campusDemoDraft(), 'photos' => []];
    $ticket = $this->postJson($this->demoBase.'/api/tickets', $input)->assertOk()->assertJsonPath('status', 'Pending approval')->json();
    $this->postJson($this->demoBase.'/api/tickets', $input)->assertOk()->assertJsonPath('id', $ticket['id']);
    $this->postJson($this->demoBase.'/api/tickets', [...$input, 'fields' => [...$input['fields'], 'issue' => 'A different report.']])->assertConflict();
    $this->getJson($this->demoBase.'/api/tickets')->assertOk()->assertJsonCount(45);
    $command = ['id' => $ticket['id'], 'revision' => 1, 'role' => 'admin', 'actor' => 'admin', 'action' => 'assign', 'data' => ['assignee' => 'sam']];
    $this->postJson($this->demoBase.'/api/tickets', $command)->assertUnprocessable();
    $this->postJson($this->demoBase.'/api/tickets', [...$command, 'action' => 'start'])->assertUnprocessable();
    $approved = $this->postJson($this->demoBase.'/api/tickets', [...$command, 'role' => 'head', 'actor' => 'morgan', 'action' => 'approve'])->assertOk()->assertJsonPath('status', 'Approved')->json();
    $this->postJson($this->demoBase.'/api/tickets', $command)->assertConflict();
    $assigned = $this->postJson($this->demoBase.'/api/tickets', [...$command, 'revision' => $approved['revision']])->assertOk()->assertJsonPath('assignee', 'sam')->json();
    $tech = ['id' => $ticket['id'], 'revision' => $assigned['revision'], 'role' => 'technician', 'actor' => 'sam', 'action' => 'note', 'data' => ['message' => 'Fictional inspection complete.']];
    $noted = $this->postJson($this->demoBase.'/api/tickets', $tech)->assertOk()->json();
    expect(end($noted['events'])['visibility'])->toBe('internal');
    $this->postJson($this->demoBase.'/api/tickets', [...$tech, 'actor' => 'casey', 'revision' => $noted['revision']])->assertForbidden();
    $this->postJson($this->demoBase.'/api/tickets', [...$tech, 'revision' => $noted['revision'], 'action' => 'complete', 'data' => ['work' => []]])->assertUnprocessable();
    $work = ['summary' => 'Replaced and tested fictional lamp.', 'minutes' => 25, 'materials' => 'Example lamp', 'outcome' => 'Test passed', 'remaining' => 'None'];
    $completed = $this->postJson($this->demoBase.'/api/tickets', [...$tech, 'revision' => $noted['revision'], 'action' => 'complete', 'data' => ['work' => $work]])->assertOk()->assertJsonPath('status', 'Completed')->json();
    $fresh = app(DemoStore::class)->access(fn (&$state) => $state['tickets'][$ticket['id']], false);
    expect($fresh)->toBe($completed)->and($fresh['workLogs'][0]['minutes'])->toBe(25);
});

it('validates intake and returns details or rejects without exposing native authority', function (): void {
    $input = ['role' => 'staff', 'actor' => 'alex', 'action' => 'create', 'requestKey' => (string) Str::uuid(), 'fields' => campusDemoDraft()];
    $this->postJson($this->demoBase.'/api/tickets', [...$input, 'fields' => []])->assertUnprocessable();
    $this->withHeader('Origin', 'https://evil.example')->postJson($this->demoBase.'/api/tickets', $input)->assertForbidden();
    $this->withHeader('Origin', 'https://app.theeverbranch.com');
    $ticket = $this->postJson($this->demoBase.'/api/tickets', $input)->assertOk()->json();
    $command = ['id' => $ticket['id'], 'revision' => 1, 'role' => 'head', 'actor' => 'avery', 'action' => 'details', 'data' => ['message' => 'Which light is affected?']];
    $this->postJson($this->demoBase.'/api/tickets', $command)->assertForbidden();
    $details = $this->postJson($this->demoBase.'/api/tickets', [...$command, 'actor' => 'morgan'])->assertOk()->assertJsonPath('status', 'Needs details')->json();
    $reply = $this->postJson($this->demoBase.'/api/tickets', [...$command, 'role' => 'staff', 'actor' => 'alex', 'revision' => $details['revision'], 'action' => 'reply', 'data' => ['message' => 'The lamp above the desk.']])->assertOk()->assertJsonPath('status', 'Pending approval')->json();
    $this->postJson($this->demoBase.'/api/tickets', [...$command, 'actor' => 'morgan', 'revision' => $reply['revision'], 'action' => 'reject', 'data' => ['message' => 'Fictional duplicate example.']])->assertOk()->assertJsonPath('status', 'Rejected');
    $this->getJson('https://app.theeverbranch.com/team/data')->assertUnauthorized();
});

it('stores decoded photos on the existing server and rejects disguised files', function (): void {
    $photo = $this->post($this->demoBase.'/api/photos', ['file' => UploadedFile::fake()->image('campus.jpg', 100, 100)])->assertOk()->json();
    $this->get($this->demoBase.'/api/photos/'.$photo['id'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->post($this->demoBase.'/api/photos', ['file' => UploadedFile::fake()->createWithContent('bad.png', '<script>bad</script>')])->assertUnprocessable();
    $input = ['role' => 'staff', 'actor' => 'alex', 'action' => 'create', 'requestKey' => (string) Str::uuid(), 'fields' => campusDemoDraft(), 'photos' => [$photo]];
    $this->postJson($this->demoBase.'/api/tickets', $input)->assertOk()->assertJsonPath('photos.0.id', $photo['id']);
    expect(is_file($this->demoDirectory.'/'.$photo['id'].'.jpg'))->toBeTrue();
    $this->get($this->demoBase.'/api/photos/'.Str::uuid())->assertNotFound();
});

it('retains last successful campus references when a live source fails', function (): void {
    Http::fake(['www.lmc.edu/*' => Http::response('', 503)]);
    $data = app(CampusReference::class)->read(true);
    expect($data['stale'])->toBeTrue()->and(count($data['buildings']))->toBeGreaterThan(20);
    Http::assertSentCount(1);
    Http::fake(); // afterEach only checks requests for tests that do not explicitly exercise campus refresh.
});

it('serializes competing updates in independent PHP processes without losing history', function (): void {
    $input = ['role' => 'staff', 'actor' => 'alex', 'action' => 'create', 'requestKey' => (string) Str::uuid(), 'fields' => campusDemoDraft()];
    $ticket = $this->postJson($this->demoBase.'/api/tickets', $input)->assertOk()->json();
    $command = ['id' => $ticket['id'], 'revision' => 1, 'role' => 'head', 'actor' => 'morgan', 'action' => 'approve', 'data' => []];
    $processes = [];
    foreach (['approve', 'details'] as $action) {
        $data = [...$command, 'action' => $action, 'data' => ['message' => 'Which lamp is affected?']];
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, base_path('tests/Support/campus-facilities-cas-worker.php'), $this->demoDirectory, base64_encode(json_encode($data, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing']);
        $process->setTimeout(30)->start();
        $processes[] = $process;
    }
    touch($this->demoDirectory.'/go');
    $statuses = [];
    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $statuses[] = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR)['status'];
    }
    sort($statuses);
    $saved = app(DemoStore::class)->access(fn (&$state) => $state['tickets'][$ticket['id']], false);
    expect($statuses)->toBe([200, 409])->and($saved['revision'])->toBe(2)->and(count($saved['events']))->toBe(2);
});

it('records comments with their author and photos once even after an uncertain retry', function (): void {
    $photo = $this->post($this->demoBase.'/api/photos', ['file' => UploadedFile::fake()->image('inspection.jpg')])->assertOk()->json();
    $input = ['id' => 'FM-1048', 'role' => 'staff', 'actor' => 'alex', 'action' => 'comment', 'data' => ['id' => (string) Str::uuid(), 'text' => 'Fictional inspection photo attached.', 'photos' => [$photo], 'mentions' => [], 'notify' => true]];
    $saved = $this->postJson($this->demoBase.'/api/tickets', $input)->assertOk()->assertJsonPath('comments.0.author.name', 'Alex Morgan')->assertJsonPath('comments.0.photos.0.id', $photo['id'])->json();
    $this->postJson($this->demoBase.'/api/tickets', $input)->assertOk()->assertJsonCount(1, 'comments')->assertJsonPath('revision', $saved['revision']);
    $this->postJson($this->demoBase.'/api/tickets', [...$input, 'data' => [...$input['data'], 'text' => 'Changed same receipt']])->assertConflict();
    $this->postJson($this->demoBase.'/api/tickets', [...$input, 'data' => [...$input['data'], 'id' => (string) Str::uuid(), 'text' => '']])->assertOk()->assertJsonCount(2, 'comments');
    $this->postJson($this->demoBase.'/api/tickets', [...$input, 'actor' => 'jordan'])->assertForbidden();
    Http::assertNothingSent();
});

it('confirms a self-entered address before mentions and sends one branded email per comment receipt', function (): void {
    config()->set(['services.sendgrid.api_key' => 'SG.synthetic-test-only', 'campus_facilities.mail_enabled' => true, 'campus_facilities.mail_from' => 'notifications@theeverbranch.com']);
    $messages = [];
    Http::fake(['api.sendgrid.com/v3/mail/send' => function ($request) use (&$messages) {
        $messages[] = $request->data();

        return Http::response('', 202);
    }]);
    $cookie = (string) Str::uuid().'.'.str_repeat('a', 64);
    // Start with a known synthetic browser capability to exercise updates and privacy.
    $memberId = explode('.', $cookie)[0];
    app(DemoStore::class)->access(function (&$state) use ($memberId) {
        $state['participants'][$memberId] = ['id' => $memberId, 'name' => 'Demo recipient', 'email' => \Illuminate\Support\Facades\Crypt::encryptString('old@example.test'), 'capability' => hash('sha256', str_repeat('a', 64)), 'enabled' => true, 'verified' => false, 'verification' => '', 'expires' => 0];
    });
    $this->withCredentials()->withCookie('lmc_facilities', $cookie);
    $this->postJson($this->demoBase.'/api/participants', ['name' => 'Demo recipient', 'email' => 'recipient@example.test', 'enabled' => true])->assertOk()->assertJsonPath('me.verified', false);
    $directory = $this->getJson($this->demoBase.'/api/participants')->assertOk()->json();
    expect($directory['me']['id'])->toBe($memberId)->and($directory['people'])->toBe([])->and(json_encode($directory))->not->toContain('recipient@example.test');
    expect(count($messages))->toBe(1)->and($messages[0]['from']['email'])->toBe('notifications@theeverbranch.com');
    preg_match('~/verify/([a-f0-9-]{36})/([a-f0-9]{64})~', $messages[0]['content'][0]['value'], $verification);
    $url = $this->demoBase.'/verify/'.$verification[1].'/'.$verification[2];
    $this->get($url)->assertOk();
    $this->getJson($this->demoBase.'/api/participants')->assertJsonPath('me.verified', false);
    $this->post($url)->assertOk();
    $this->getJson($this->demoBase.'/api/participants')->assertJsonPath('people.0.verified', true);
    $comment = ['id' => 'FM-1048', 'role' => 'staff', 'actor' => 'alex', 'action' => 'comment', 'data' => ['id' => (string) Str::uuid(), 'text' => 'Please review this fictional update <script>unsafe</script>.', 'photos' => [], 'mentions' => [$memberId], 'notify' => true]];
    $this->postJson($this->demoBase.'/api/tickets', $comment)->assertOk()->assertJsonPath('comments.0.author.name', 'Demo recipient')->assertJsonPath('comments.0.notification', 'accepted');
    $this->postJson($this->demoBase.'/api/tickets', $comment)->assertOk();
    expect(count($messages))->toBe(2)->and($messages[1]['personalizations'][0]['to'][0]['email'])->toBe('recipient@example.test')
        ->and($messages[1]['content'][1]['value'])->toContain('Everbranch', 'FM-1048', '?ticket=FM-1048', '&lt;script&gt;unsafe&lt;/script&gt;')->not->toContain('<script>unsafe</script>');
    $this->postJson($this->demoBase.'/api/participants', ['name' => 'Demo recipient', 'email' => 'recipient@example.test', 'enabled' => false])->assertOk()->assertJsonPath('me.enabled', false);
    $this->getJson($this->demoBase.'/api/participants')->assertJsonCount(0, 'people');
    expect(count($messages))->toBe(2);
});

it('adds building samples once while preserving edited shared tickets and attachments', function (): void {
    $store = app(DemoStore::class);
    $state = $store->seed();
    expect(count($state['tickets']))->toBe(44);
    foreach (['Shelton Learning Commons', 'Virginia Hall', 'May Wildlife Rehabilitation Center'] as $building) {
        $teams = array_values(array_unique(array_map(fn ($t) => $t['fields']['category'], array_filter($state['tickets'], fn ($t) => $t['fields']['location'] === $building))));
        sort($teams);
        expect($teams)->toBe(['Grounds', 'Housekeeping', 'Maintenance']);
    }
    unset($state['building_samples']);
    $state['tickets']['FM-1048']['revision'] = 7;
    $state['tickets']['FM-1048']['title'] = 'Previously edited shared ticket';
    $state['tickets']['FM-B13-1']['title'] = 'Previously edited building job';
    $directory = $store->directory();
    file_put_contents($directory.'/workspace.json', json_encode($state, JSON_THROW_ON_ERROR));
    $read = $store->access(fn (&$s) => $s, false);
    expect(count($read['tickets']))->toBe(44)
        ->and($read['tickets']['FM-1048']['revision'])->toBe(7)
        ->and($read['tickets']['FM-1048']['title'])->toBe('Previously edited shared ticket')
        ->and($read['tickets']['FM-B13-1']['title'])->toBe('Previously edited building job');
    expect($store->access(fn (&$s) => count($s['tickets']), false))->toBe(44);
});

it('uses a server-only Realtime key with replay protection and bounded session cleanup', function (): void {
    config(['campus_facilities.openai_api_key' => 'fictional-test-key', 'campus_facilities.voice_enabled' => true]);
    \Illuminate\Support\Facades\Cache::put('scheduler:last_heartbeat', now()->toIso8601String());
    Http::fake([
        'api.openai.com/v1/realtime/calls' => Http::response("v=0\r\nanswer", 201, ['Location' => '/v1/realtime/calls/rtc_fixture']),
        'api.openai.com/v1/realtime/calls/*/hangup' => Http::response('', 200),
    ]);
    $input = ['sessionId' => (string) Str::uuid(), 'closeToken' => (string) Str::uuid(), 'mode' => 'request', 'fields' => campusDemoDraft(), 'sdp' => "v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n"];
    $this->postJson($this->demoBase.'/api/voice', $input)->assertOk()->assertJsonPath('sdp', "v=0\r\nanswer")->assertDontSee('fictional-test-key');
    $this->postJson($this->demoBase.'/api/voice', $input)->assertOk();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->body(), "m=audio 9 UDP/TLS/RTP/SAVPF 111\r\n\r\n--"));
    $this->postJson($this->demoBase.'/api/voice', [...$input, 'sessionId' => (string) Str::uuid()])->assertTooManyRequests();
    $this->postJson($this->demoBase.'/api/voice/close', [...$input, 'closeToken' => (string) Str::uuid()])->assertForbidden();
    $this->postJson($this->demoBase.'/api/voice/close', $input)->assertOk();
    Http::assertSentCount(2);
    $this->postJson($this->demoBase.'/api/voice/close', $input)->assertOk();
    Http::assertSentCount(2);
    $this->postJson($this->demoBase.'/api/voice', $input)->assertConflict();
    $new = [...$input, 'sessionId' => (string) Str::uuid()];
    $this->postJson($this->demoBase.'/api/voice', $new)->assertOk();
    app(DemoStore::class)->access(function (&$state) use ($new) {
        $state['voice_sessions'][$new['sessionId']]['expires'] = time() - 1;
    });
    expect(app(\App\Services\CampusFacilities\LiveVoice::class)->expire(app(DemoStore::class)))->toBe(1);
    Http::assertSentCount(4);
    $third = [...$input, 'sessionId' => (string) Str::uuid()];
    $this->postJson($this->demoBase.'/api/voice', $third)->assertOk();
    $this->postJson($this->demoBase.'/api/voice/close', $third)->assertOk();
    $this->postJson($this->demoBase.'/api/voice', [...$input, 'sessionId' => (string) Str::uuid()])->assertTooManyRequests();
});

it('fails voice closed with a stale scheduler and holds unknown creation outcomes', function (): void {
    config(['campus_facilities.openai_api_key' => 'fictional-test-key', 'campus_facilities.voice_enabled' => true]);
    $input = ['sessionId' => (string) Str::uuid(), 'closeToken' => (string) Str::uuid(), 'mode' => 'request', 'fields' => campusDemoDraft(), 'sdp' => "v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n"];
    \Illuminate\Support\Facades\Cache::put('scheduler:last_heartbeat', now()->subMinutes(4)->toIso8601String());
    $this->postJson($this->demoBase.'/api/voice', $input)->assertServiceUnavailable();
    Http::assertNothingSent();
    \Illuminate\Support\Facades\Cache::put('scheduler:last_heartbeat', now()->toIso8601String());
    Http::fake(['api.openai.com/*' => Http::response('', 503)]);
    $this->postJson($this->demoBase.'/api/voice', $input)->assertServiceUnavailable();
    $this->postJson($this->demoBase.'/api/voice', [...$input, 'sessionId' => (string) Str::uuid()])->assertTooManyRequests();
    Http::assertSentCount(1);
});

it('validates extracted voice fields without saving or changing the requester identity', function (): void {
    $input = ['mode' => 'request', 'fields' => [...campusDemoDraft(), 'room' => ''], 'update' => ['room' => '203']];
    $this->postJson($this->demoBase.'/api/voice/draft', $input)->assertOk()->assertJsonPath('draft.room', '203')->assertJsonPath('saved', false);
    $this->postJson($this->demoBase.'/api/voice/draft', [...$input, 'update' => ['requesterId' => 'admin']])->assertUnprocessable();
    $this->postJson($this->demoBase.'/api/voice/draft', [...$input, 'update' => ['status' => 'Completed']])->assertUnprocessable();
    $this->postJson($this->demoBase.'/api/voice/draft', ['mode' => 'work', 'fields' => [], 'update' => ['minutes' => -1]])->assertUnprocessable();
    expect(count(app(DemoStore::class)->access(fn (&$s) => $s['tickets'], false)))->toBe(44);
    Http::assertNothingSent();
});

it('answers campus questions from the selected current job projection without changing work', function (): void {
    config(['campus_facilities.openai_api_key' => 'fictional-test-key', 'campus_facilities.voice_enabled' => true]);
    $campus = json_decode(file_get_contents(resource_path('data/campus-facilities/campus.json')), true);
    $this->mock(CampusReference::class, fn ($mock) => $mock->shouldReceive('read')->andReturn($campus));
    Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['answer' => 'FM-1045 is in progress at Bowman Building.', 'ticketIds' => ['FM-1045', 'FM-1048', 'INVENTED']])]]]]])]);
    $input = ['role' => 'technician', 'actor' => 'sam', 'question' => 'What work is happening?', 'history' => [], 'requestId' => (string) Str::uuid()];
    $before = app(DemoStore::class)->access(fn (&$s) => $s['tickets'], false);
    $this->postJson($this->demoBase.'/api/assistant', $input)->assertOk()->assertJsonPath('ticketIds', ['FM-1045']);
    $this->postJson($this->demoBase.'/api/assistant', $input)->assertOk();
    Http::assertSentCount(1);
    Http::assertSent(function ($request) {
        $context = json_decode($request['input'], true)['currentContext'];
        $ids = array_column($context['jobs'], 'id');

        return $request['store'] === false && in_array('FM-1045', $ids) && ! in_array('FM-1048', $ids) && ! str_contains($request['input'], 'alex@example.com') && count(array_unique(array_column($context['jobs'], 'assignedTo'))) === 1;
    });
    expect(app(DemoStore::class)->access(fn (&$s) => $s['tickets'], false))->toBe($before);
    $this->postJson($this->demoBase.'/api/assistant', [...$input, 'question' => 'Different question'])->assertConflict();
    $this->postJson($this->demoBase.'/api/voice/draft', ['mode' => 'ask', 'fields' => [], 'update' => ['status' => 'Completed']])->assertUnprocessable();
});

it('holds uncertain chat attempts and enforces the shared daily question allowance', function (): void {
    config(['campus_facilities.openai_api_key' => 'fictional-test-key', 'campus_facilities.voice_enabled' => true]);
    $campus = json_decode(file_get_contents(resource_path('data/campus-facilities/campus.json')), true);
    $this->mock(CampusReference::class, fn ($mock) => $mock->shouldReceive('read')->andReturn($campus));
    Http::fake(['api.openai.com/v1/responses' => Http::response('', 503)]);
    $input = ['role' => 'admin', 'actor' => 'admin', 'question' => 'Which jobs need approval?', 'history' => [], 'requestId' => (string) Str::uuid()];
    $this->postJson($this->demoBase.'/api/assistant', $input)->assertServiceUnavailable();
    $this->postJson($this->demoBase.'/api/assistant', $input)->assertConflict();
    Http::assertSentCount(1);
    app(DemoStore::class)->access(function (&$s) {
        $s['assistant_usage'][now()->format('Y-m-d')] = 50;
    });
    $this->postJson($this->demoBase.'/api/assistant', [...$input, 'requestId' => (string) Str::uuid()])->assertTooManyRequests();
    Http::assertSentCount(1);
});
