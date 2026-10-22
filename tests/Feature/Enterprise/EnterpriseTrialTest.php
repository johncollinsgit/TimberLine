<?php

use App\Enterprise\Trial\ReviewerTrial;
use App\Enterprise\Trial\TrialBudget;
use App\Enterprise\Trial\TrialStore;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Services\Team\TeamFileScanner;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['enterprise.runtime' => true, 'app.url' => 'https://trial.example.invalid']);
});

function trialFixture(): array
{
    $tenant = Tenant::query()->create(['name' => 'Synthetic isolated reviewer storage', 'slug' => 'trial-'.Str::lower(Str::random(8))]);
    $area = TeamArea::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Synthetic trial only', 'slug' => 'synthetic-trial',
        'profile' => 'enterprise_department', 'version' => 1, 'settings' => ['synthetic_fixture' => true]]);
    config(['enterprise.trial' => ['enabled' => true, 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'canonical_origin' => 'https://trial.example.invalid',
        'prior_spend_verified' => true, 'prior_spend_microusd' => 0, 'prior_spend_evidence' => 'Synthetic zero-cost test baseline, not real provider evidence',
        'rates_valid_until' => now()->addDay()->toIso8601String(), 'reviewers' => ['one' => [
            'code_hash' => hash('sha256', 'synthetic-reviewer-code-one'), 'expires_at' => now()->addDay()->toIso8601String(),
            'emails' => ['reviewer@example.invalid'], 'sms' => ['+12025550123']], 'two' => [
                'code_hash' => hash('sha256', 'synthetic-reviewer-code-two'), 'expires_at' => now()->addDay()->toIso8601String(),
                'emails' => ['other@example.invalid'], 'sms' => ['+12025550124']]],
        'channels' => ['sms' => ['enabled' => true, 'account_sid' => 'AC'.str_repeat('a', 32), 'auth_token' => 'synthetic-token',
            'from_number' => '+15005550006', 'sender_verified' => true, 'sender_evidence' => 'synthetic evidence', 'max_cost_microusd_per_segment' => 10000],
            'email' => ['enabled' => true, 'api_key' => 'synthetic-no-real-key', 'from_email' => 'demo@example.invalid', 'sender_verified' => true,
                'sender_evidence' => 'synthetic evidence', 'max_cost_microusd' => 10000],
            'ai' => ['enabled' => true, 'api_key' => 'synthetic-no-real-key', 'model' => 'synthetic-approved-model', 'max_input' => 12000, 'max_output' => 800,
                'input_rate_microusd_per_million' => 1000000, 'output_rate_microusd_per_million' => 1000000]]]]);
    $request = trialRequest();
    $state = app(ReviewerTrial::class)->start($request, 'synthetic-reviewer-code-one');

    return [$request, $state, $tenant, $area];
}

function trialRequest(?Store $session = null): Request
{
    if (! $session) {
        $session = new Store('enterprise-trial-test', new ArraySessionHandler(120));
        $session->start();
    }
    $request = Request::create('https://trial.example.invalid/enterprise/trial', 'POST');
    $request->setLaravelSession($session);

    return $request;
}

function trialPreview(Request $request, string $channel = 'sms', array $extra = []): array
{
    return app(ReviewerTrial::class)->preview($request, $extra + ['request_id' => (string) Str::uuid(), 'channel' => $channel,
        'destination' => $channel === 'email' ? 'reviewer@example.invalid' : ($channel === 'sms' ? '+12025550123' : null), 'fields' => ['title', 'status', 'owner', 'next_action']]);
}

function trialFake(array|callable $handlers): void
{
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake($handlers);
}

function trialSms(Request $request): array
{
    trialFake(['api.twilio.com/*' => Http::response(['sid' => 'SM'.str_repeat('b', 32), 'status' => 'queued'], 201)]);
    $preview = trialPreview($request);
    $result = app(ReviewerTrial::class)->confirm($request, $preview['id'], $preview['digest']);

    return [$preview, $result, basename($preview['content']['url'])];
}

test('approved reviewer trial holds synthetic records without employee accounts mailbox access or grants', function (): void {
    [$request, $state] = trialFixture();
    $before = TeamOperationRecord::query()->count();
    expect(app(ReviewerTrial::class)->start($request, 'synthetic-reviewer-code-one')['session_id'])->toBe($state['session_id']);
    expect(TeamOperationRecord::query()->count())->toBe($before)->and($state['sample'])->toBeTrue()->and($state['tasks'])->toHaveCount(3);
    foreach (['users', 'tenant_user', 'team_area_members', 'tenant_mailbox_users'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    expect(DB::table('team_operation_revisions')->whereNotNull('user_id')->count())->toBe(0);
    expect(fn () => app(ReviewerTrial::class)->start(trialRequest(), 'not-an-approved-reviewer-code'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('storage runtime synthetic marker origin reviewer browser and frozen configuration all fail closed', function (): void {
    [$request, $state, $tenant, $area] = trialFixture();
    $other = trialRequest();
    $other->session()->put('enterprise.trial.session', $state['session_id']);
    expect(fn () => app(ReviewerTrial::class)->show($other))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $area->update(['settings' => []]);
    expect(fn () => app(TrialStore::class)->tenant())->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $area->update(['settings' => ['synthetic_fixture' => true]]);
    config(['enterprise.runtime' => false]);
    expect(fn () => app(ReviewerTrial::class)->show($request))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    config(['enterprise.runtime' => true, 'enterprise.trial.canonical_origin' => 'https://elsewhere.example.invalid']);
    expect(fn () => app(ReviewerTrial::class)->show($request))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    config(['enterprise.trial.canonical_origin' => 'https://trial.example.invalid']);
    $request->headers->set('Host', 'elsewhere.example.invalid');
    expect(fn () => app(ReviewerTrial::class)->show($request))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('destinations and selected sample fields require independent approval and preview is replayable without provider IO', function (): void {
    [$request] = trialFixture();
    foreach ([['channel' => 'email', 'destination' => 'other@example.invalid'], ['channel' => 'sms', 'destination' => '+12025550124'],
        ['channel' => 'ai', 'fields' => ['student_private_details']]] as $input) {
        expect(fn () => trialPreview($request, $input['channel'], $input))->toThrow($input['channel'] === 'ai'
            ? \Illuminate\Validation\ValidationException::class : \Symfony\Component\HttpKernel\Exception\HttpException::class);
    }
    $key = (string) Str::uuid();
    $preview = trialPreview($request, 'sms', ['request_id' => $key]);
    $replay = trialPreview($request, 'sms', ['request_id' => $key]);
    expect($preview['id'])->toBe($replay['id'])->and($replay['replayed'])->toBeTrue()->and($preview['quote']['details']['encoding'])->toBe('gsm7')
        ->and($preview['quote']['details']['segments'])->toBeGreaterThan(0);
    expect($preview['content']['url'])->not->toContain('reviewer', '%40', '+12025550123');
    expect(fn () => app(ReviewerTrial::class)->task(basename($preview['content']['url'])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('exact SMS confirmation commits one shared charge and sends ordinary SMS once with independent events', function (): void {
    [$request] = trialFixture();
    trialFake(['api.twilio.com/*' => function ($http) {
        expect(TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->sole()->status)->toBe('dispatching');
        expect($http['SmartEncoded'])->toBe('false')->and(isset($http['MediaUrl']))->toBeFalse()->and(isset($http['ShortenUrls']))->toBeFalse();

        return Http::response(['sid' => 'SM'.str_repeat('b', 32), 'status' => 'queued'], 201);
    }]);
    $preview = trialPreview($request);
    expect(fn () => app(ReviewerTrial::class)->confirm($request, $preview['id'], str_repeat('0', 64)))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $accepted = app(ReviewerTrial::class)->confirm($request, $preview['id'], $preview['digest']);
    $replay = app(ReviewerTrial::class)->confirm($request, $preview['id'], $preview['digest']);
    expect($accepted['events']['acceptance'])->toBe('accepted')->and($accepted['events']['delivery'])->toBe('unverified')
        ->and($accepted['events']['link_opened'])->toBeFalse()->and($accepted['events']['acknowledged'])->toBeFalse()->and($replay['replayed'])->toBeTrue();
    Http::assertSentCount(1);
});

test('email uses a verified service sender exact receiving address responsive sample HTML and no mailbox OAuth', function (): void {
    [$request] = trialFixture();
    trialFake(['api.sendgrid.com/v3/mail/send' => function ($http) {
        expect($http['from']['email'])->toBe('demo@example.invalid')->and($http['personalizations'][0]['to'][0]['email'])->toBe('reviewer@example.invalid');
        expect($http['content'][1]['value'])->toContain('Lees-McRae College', 'Everbranch Enterprise demonstration', 'fictional', 'View sample request', 'max-width:600px');
        expect($http['tracking_settings']['click_tracking']['enable'])->toBeFalse();

        return Http::response([], 202, ['X-Message-Id' => 'synthetic-sendgrid-id']);
    }]);
    $preview = trialPreview($request, 'email');
    expect(app(ReviewerTrial::class)->confirm($request, $preview['id'], $preview['digest'])['status'])->toBe('accepted');
    Http::assertSentCount(1);
    expect(DB::table('tenant_mailbox_users')->count())->toBe(0);
});

test('one lifetime budget covers all reviewers channels prior spend and acceptance rather than resetting by session', function (): void {
    [$request] = trialFixture();
    $budget = app(TrialBudget::class);
    $charge = $budget->reserve('acceptance', 'synthetic-acceptance', 'digest', 4990000);
    $budget->settle($charge['id'], 'accepted', ['status' => 'accepted'], 4990000);
    $second = trialRequest();
    app(ReviewerTrial::class)->start($second, 'synthetic-reviewer-code-two');
    $preview = trialPreview($request);
    expect(fn () => app(ReviewerTrial::class)->confirm($request, $preview['id'], $preview['digest']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($budget->status()['remaining_microusd'])->toBe(10000);
    expect(fn () => $budget->reserve('ai', 'second-reviewer', 'digest', 10001))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    config(['enterprise.trial.prior_spend_microusd' => 1]);
    expect(fn () => $budget->reserve('email', 'cannot-reset-prior', 'digest', 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('unknown prior costs disabled channels stale rates and modified previews refuse paid IO', function (): void {
    [$request] = trialFixture();
    $preview = trialPreview($request);
    config(['enterprise.trial.prior_spend_verified' => false]);
    expect(fn () => app(ReviewerTrial::class)->confirm($request, $preview['id'], $preview['digest']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    config(['enterprise.trial.prior_spend_verified' => true, 'enterprise.trial.channels.sms.enabled' => false]);
    expect(fn () => app(ReviewerTrial::class)->confirm($request, $preview['id'], $preview['digest']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    config(['enterprise.trial.channels.sms.enabled' => true, 'enterprise.trial.rates_valid_until' => now()->subMinute()->toIso8601String()]);
    expect(fn () => app(ReviewerTrial::class)->confirm($request, $preview['id'], $preview['digest']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('provider uncertainty preserves full maximum reservation and repeats never duplicate a paid call', function (): void {
    [$request] = trialFixture();
    $calls = 0;
    trialFake(['api.twilio.com/*' => function () use (&$calls) {
        $calls++;
        throw new \RuntimeException('Synthetic unknown provider outcome');
    }]);
    $preview = trialPreview($request);
    expect(app(ReviewerTrial::class)->confirm($request, $preview['id'], $preview['digest'])['status'])->toBe('uncertain');
    expect(app(ReviewerTrial::class)->confirm($request, $preview['id'], $preview['digest'])['replayed'])->toBeTrue()->and($calls)->toBe(1);
    expect(app(TrialBudget::class)->status()['used_or_reserved_microusd'])->toBe($preview['quote']['maximum_microusd']);
});

test('over-bound actual cost freezes the whole campaign and pending reservations cannot dispatch afterward', function (): void {
    [$request] = trialFixture();
    $budget = app(TrialBudget::class);
    $one = $budget->reserve('sms', 'one', 'one-hash', 100);
    $two = $budget->reserve('email', 'two', 'two-hash', 100);
    expect(fn () => $budget->settle($one['id'], 'accepted', ['status' => 'accepted'], 101))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($budget->status()['paused'])->toBeTrue();
    expect(fn () => $budget->claimDispatch($two['id'], 'two-hash'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $budget->reserve('ai', 'three', 'three', 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('cross-device link opens and explicit acknowledgment are separate from provider delivery and update only synthetic data', function (): void {
    [$request] = trialFixture();
    [$preview, , $token] = trialSms($request);
    $trial = app(ReviewerTrial::class);
    $mobile = $trial->task($token);
    expect($mobile['sample'])->toBeTrue()->and(json_encode($mobile))->not->toContain('reviewer@example.invalid', '+12025550123');
    $key = (string) Str::uuid();
    $input = ['request_id' => $key, 'expected_version' => $mobile['task']['version'], 'action' => 'acknowledge'];
    $ack = $trial->update($token, $input);
    expect($ack['task']['acknowledged_at'])->not->toBeNull()->and($trial->update($token, $input)['replayed'])->toBeTrue();
    $events = $trial->show($request)['actions'][0]['events'];
    expect($events['link_opened'])->toBeTrue()->and($events['acknowledged'])->toBeTrue()->and($events['delivery'])->toBe('unverified');
    $updated = $trial->update($token, ['request_id' => (string) Str::uuid(), 'expected_version' => $ack['task']['version'], 'action' => 'update',
        'status' => 'awaiting_review', 'note' => '<img src=x onerror=alert(1)> literal note']);
    expect($updated['task']['status'])->toBe('awaiting_review');
    $trial->revokeLink($request, $preview['link_id']);
    expect(fn () => $trial->task($token))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(DB::table('team_tasks')->count())->toBe(0);
});

test('wrong reviewer task links expiry and approval revocation never disclose another trial or real records', function (): void {
    [$request] = trialFixture();
    [$preview, , $token] = trialSms($request);
    $other = trialRequest();
    app(ReviewerTrial::class)->start($other, 'synthetic-reviewer-code-two');
    expect(fn () => app(ReviewerTrial::class)->confirm($other, $preview['id'], $preview['digest']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => app(ReviewerTrial::class)->revokeLink($other, $preview['link_id']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->travel(3)->hours();
    expect(fn () => app(ReviewerTrial::class)->task($token))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->travelBack();
    config(['enterprise.trial.reviewers.one.sms' => []]);
    expect(fn () => app(ReviewerTrial::class)->task($token))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('trial uploads validate real bytes remain private quarantined and repeat with stable receipts', function (): void {
    [$request] = trialFixture();
    Storage::fake('team_private');
    config(['team_work.scanner.enabled' => false]);
    [, , $token] = trialSms($request);
    $key = (string) Str::uuid();
    $file = UploadedFile::fake()->createWithContent('sample.pdf', "%PDF-1.7\nSynthetic fixture PDF\n%%EOF");
    $trial = app(ReviewerTrial::class);
    $upload = $trial->upload($token, $key, $file);
    expect($upload['status'])->toBe('quarantined')->and($upload['downloadable'])->toBeFalse();
    expect($trial->upload($token, $key, $file)['replayed'])->toBeTrue();
    expect(fn () => $trial->download($token, $upload['id']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $trial->upload($token, (string) Str::uuid(), UploadedFile::fake()->createWithContent('photo.jpg', '<script>not an image</script>')))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(TeamOperationRecord::query()->where('kind', 'ent_trial_upload')->count())->toBe(1);
});

test('clean files download only through current capability and interrupted same-file upload can resume after its claim expires', function (): void {
    [$request] = trialFixture();
    Storage::fake('team_private');
    app()->instance(TeamFileScanner::class, new class extends TeamFileScanner
    {
        public function scan(string $privateAbsolutePath, string $mimeType, string $sha256): string
        {
            return 'clean';
        }
    });
    [$preview, , $token] = trialSms($request);
    $key = (string) Str::uuid();
    $file = UploadedFile::fake()->image('sample.png', 20, 20);
    $upload = app(ReviewerTrial::class)->upload($token, $key, $file);
    $row = TeamOperationRecord::query()->findOrFail($upload['id']);
    $row->status = 'storing';
    $row->data = array_replace($row->data, ['claim_expires_at' => now()->subMinute()->toIso8601String()]);
    $row->save();
    $repaired = app(ReviewerTrial::class)->upload($token, $key, $file);
    expect($repaired['id'])->toBe($upload['id'])->and($repaired['status'])->toBe('clean');
    $response = app(ReviewerTrial::class)->download($token, $upload['id']);
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    app(ReviewerTrial::class)->revokeLink($request, $preview['link_id']);
    expect(fn () => app(ReviewerTrial::class)->download($token, $upload['id']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('AI sees selected fictional fields only validates refs and uses the same lifetime ledger', function (): void {
    [$request, $state] = trialFixture();
    $ref = 'sample:'.$state['tasks'][0]['id'];
    trialFake(['api.openai.com/v1/responses' => function ($http) use ($ref) {
        expect($http['input'][0]['content'])->not->toContain('reviewer@example.invalid', '+12025550123', 'next_action', 'due_on');
        expect($http['store'])->toBeFalse();

        return Http::response(['status' => 'completed', 'id' => 'synthetic-response', 'usage' => ['input_tokens' => 20, 'output_tokens' => 10],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['refs' => [$ref]])]]]]]);
    }]);
    $preview = trialPreview($request, 'ai', ['fields' => ['title', 'status']]);
    $result = app(ReviewerTrial::class)->confirm($request, $preview['id'], $preview['digest']);
    expect($result['status'])->toBe('accepted')->and($result['outcome']['assessment']['refs'])->toBe([$ref]);
    expect(app(TrialBudget::class)->status()['used_or_reserved_microusd'])->toBe(30);
    Http::assertSentCount(1);
});

test('unverifiable or over-bound AI usage freezes further paid campaign calls and cannot execute record actions', function (): void {
    [$request] = trialFixture();
    trialFake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'id' => 'synthetic-response',
        'usage' => ['input_tokens' => 999999, 'output_tokens' => 10], 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"refs":[]}']]]]])]);
    $preview = trialPreview($request, 'ai');
    expect(app(ReviewerTrial::class)->confirm($request, $preview['id'], $preview['digest'])['status'])->toBe('uncertain');
    expect(app(TrialBudget::class)->status()['paused'])->toBeTrue()->and(DB::table('team_tasks')->count())->toBe(0);
    expect(fn () => app(TrialBudget::class)->reserve('sms', 'after-unknown-ai', 'digest', 1))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertSentCount(1);
});
