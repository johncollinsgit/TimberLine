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
    $this->getJson($this->demoBase.'/api/tickets')->assertOk()->assertJsonCount(9);
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
