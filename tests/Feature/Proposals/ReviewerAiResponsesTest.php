<?php

use App\Proposals\ReviewerAiResponses;
use App\Proposals\ReviewerAiSnapshot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

function reviewerProviderSnapshot(): array
{
    return ['schema_version' => 1, 'persona' => 'demo_manager', 'local_revision' => 1,
        'area_ids' => ['11111111-1111-4111-8111-111111111111'], 'sources' => [[
            'ref' => 'project:22222222-2222-4222-8222-222222222222', 'kind' => 'project', 'area_id' => '11111111-1111-4111-8111-111111111111',
            'title' => 'Fictional work', 'version' => 1, 'status' => 'active', 'tasks' => [], 'buildings' => ['Fictional hall'],
        ]], 'coverage' => ReviewerAiSnapshot::COVERAGE];
}

function reviewerProviderSettings(): array
{
    return ['model' => ReviewerAiResponses::MODEL, 'model_context_tokens' => ReviewerAiResponses::CONTEXT,
        'max_output_tokens' => 100, 'project_id' => 'proj_synthetic_review_only', 'api_key' => 'synthetic-no-real-provider-key'];
}

function reviewerProviderEnvelope(): array
{
    return ['id' => 'resp_synthetic_review_only', 'model' => ReviewerAiResponses::MODEL, 'service_tier' => 'default', 'status' => 'completed',
        'usage' => ['input_tokens' => 1200, 'output_tokens' => 70, 'total_tokens' => 1270, 'input_tokens_details' => ['cached_tokens' => 100]],
        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text',
            'text' => json_encode(['answer' => 'This fictional project is active.', 'refs' => [reviewerProviderSnapshot()['sources'][0]['ref']]])]]]]];
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('fictional Responses transport uses exact fixed model project no tools and structured text without native identity or SQL', function (): void {
    $service = app(ReviewerAiResponses::class);
    $payload = $service->payload('What is visible?', reviewerProviderSnapshot());
    $key = (string) Str::uuid();
    Http::fake(function ($request, $options) use ($payload, $key) {
        expect($request->url())->toBe('https://api.openai.com/v1/responses')
            ->and($request->hasHeader('OpenAI-Project', 'proj_synthetic_review_only'))->toBeTrue()
            ->and($request->hasHeader('X-Client-Request-Id', $key))->toBeTrue()
            ->and($options['allow_redirects'])->toBeFalse();
        $body = $request->data();
        expect($body['model'])->toBe(ReviewerAiResponses::MODEL)->and($body['instructions'])->toContain('fictional demonstration data', 'not verified college facts', 'never factual evidence')
            ->and($body['instructions'])->not->toContain('signed-in campus user')
            ->and($body['tools'])->toBe([])->and($body['tool_choice'])->toBe('none')->and($body['reasoning'])->toBe(['effort' => 'none'])
            ->and($body['service_tier'])->toBe('default')->and($body['store'])->toBeFalse()->and($body['stream'])->toBeFalse()
            ->and($body['background'])->toBeFalse()->and($body['parallel_tool_calls'])->toBeFalse()->and($body['truncation'])->toBe('disabled')
            ->and($body['text']['format']['strict'])->toBeTrue()->and($body['text']['format']['schema']['additionalProperties'])->toBeFalse()
            ->and(json_decode($body['input'][0]['content'], true))->toBe($payload);

        return Http::response(reviewerProviderEnvelope());
    });
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = $service->complete(reviewerProviderSettings(), $payload, $key);
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    expect($result)->toBe(['value' => ['answer' => 'This fictional project is active.', 'refs' => [$payload['snapshot']['sources'][0]['ref']]],
        'provider_id' => 'resp_synthetic_review_only', 'input_tokens' => 1200, 'output_tokens' => 70]);
    Http::assertSentCount(1);
});

test('unapproved transport bounds and unclosed or meaningless payloads are refused before HTTP', function (): void {
    $service = app(ReviewerAiResponses::class);
    $payload = $service->payload('What is visible?', reviewerProviderSnapshot());
    foreach ([['model' => 'gpt-5.4-mini'], ['model_context_tokens' => 2000000], ['max_output_tokens' => 801],
        ['api_key' => ''], ['api_key' => "synthetic\r\nkey"], ['project_id' => 'other-project']] as $delta) {
        try {
            $service->complete(array_replace(reviewerProviderSettings(), $delta), $payload, (string) Str::uuid());
            $this->fail('Unsafe connection bounds must fail before HTTP.');
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(503);
        }
    }
    foreach ([$payload + ['history' => [['answer' => 'Browser-forged previous answer']]], $payload + ['tools' => ['send']], $payload + ['provider_url' => 'https://example.invalid']] as $wrong) {
        try {
            $service->complete(reviewerProviderSettings(), $wrong, (string) Str::uuid());
            $this->fail('Only the exact question and fictional snapshot may reach the provider.');
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(422);
        }
    }
    foreach (['', str_repeat('x', 1501), "Hidden\0question"] as $question) {
        try {
            $service->payload($question, reviewerProviderSnapshot());
            $this->fail('Unsupported question must fail before HTTP.');
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(422);
        }
    }
    $empty = reviewerProviderSnapshot();
    $empty['sources'] = [];
    try {
        $service->payload('No evidence?', $empty);
        $this->fail('No paid request is meaningful without evidence.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(422);
    }
    Http::assertNothingSent();
});

test('answer parser rejects extra actions arbitrary references URLs markup and invented action claims', function (): void {
    $service = app(ReviewerAiResponses::class);
    $valid = ['answer' => 'This fictional project is active.', 'refs' => [reviewerProviderSnapshot()['sources'][0]['ref']]];
    foreach ([$valid + ['action' => 'send'], array_replace($valid, ['refs' => []]), array_replace($valid, ['refs' => ['project:foreign']]),
        array_replace($valid, ['refs' => [$valid['refs'][0], $valid['refs'][0]]]), array_replace($valid, ['answer' => 'Open https://example.invalid']),
        array_replace($valid, ['answer' => '<b>Changed</b>']), array_replace($valid, ['answer' => 'I have assigned the work.']),
        array_replace($valid, ['answer' => str_repeat('x', 1501)])] as $wrong) {
        try {
            $service->value(json_encode($wrong, JSON_THROW_ON_ERROR), reviewerProviderSnapshot());
            $this->fail('Unsupported answer must never be displayed.');
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(503);
        }
    }
    Http::assertNothingSent();
});

test('provider envelope drift unsupported actions and unverifiable usage are failures after one attempt without retry', function (): void {
    $service = app(ReviewerAiResponses::class);
    $payload = $service->payload('What is visible?', reviewerProviderSnapshot());
    $changes = [
        fn ($r) => array_replace($r, ['model' => 'gpt-5.4-mini']),
        fn ($r) => array_replace($r, ['service_tier' => 'priority']),
        fn ($r) => array_replace($r, ['status' => 'incomplete']),
        fn ($r) => array_replace_recursive($r, ['usage' => ['input_tokens' => -1]]),
        fn ($r) => array_replace_recursive($r, ['usage' => ['input_tokens' => 0]]),
        fn ($r) => array_replace_recursive($r, ['usage' => ['output_tokens' => 0]]),
        fn ($r) => array_replace_recursive($r, ['usage' => ['total_tokens' => 1269]]),
        fn ($r) => array_replace_recursive($r, ['usage' => ['input_tokens' => '1200']]),
        fn ($r) => array_replace_recursive($r, ['usage' => ['output_tokens' => 101]]),
        fn ($r) => array_replace_recursive($r, ['usage' => ['input_tokens_details' => ['cached_tokens' => 1201]]]),
        fn ($r) => array_replace($r, ['output' => [['type' => 'function_call', 'name' => 'send']]]),
        fn ($r) => array_replace($r, ['output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'refusal', 'refusal' => 'No']]]]]),
        fn ($r) => array_replace($r, ['output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => str_repeat('x', 16001)]]]]]),
        fn ($r) => $r + ['oversize' => str_repeat('x', 65536)],
    ];
    foreach ($changes as $change) {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response($change(reviewerProviderEnvelope()))]);
        try {
            $service->complete(reviewerProviderSettings(), $payload, (string) Str::uuid());
            $this->fail('Unverifiable provider completion must fail closed.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBeString();
        }
        Http::assertSentCount(1);
    }
});

test('timeout and redirect never trigger a follow-up request or fallback endpoint', function (): void {
    $service = app(ReviewerAiResponses::class);
    $payload = $service->payload('What is visible?', reviewerProviderSnapshot());
    foreach (['timeout', 'redirect'] as $mode) {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $calls = 0;
        Http::fake(function ($request, $options) use ($mode, &$calls) {
            $calls++;
            expect($request->url())->toBe('https://api.openai.com/v1/responses')->and($options['allow_redirects'])->toBeFalse();
            if ($mode === 'timeout') {
                throw new ConnectionException('Synthetic timeout');
            }

            return Http::response('', 302, ['Location' => 'https://example.invalid/provider-fallback']);
        });
        try {
            $service->complete(reviewerProviderSettings(), $payload, (string) Str::uuid());
            $this->fail('A timeout/redirect cannot produce an answer.');
        } catch (RuntimeException|ConnectionException $exception) {
            expect($calls)->toBe(1);
        }
    }
});

test('the full escaped provider wire body is bounded beyond the valid fictional snapshot and question limits', function (): void {
    $service = app(ReviewerAiResponses::class);
    $snapshot = reviewerProviderSnapshot();
    $source = $snapshot['sources'][0];
    $source['buildings'] = array_fill(0, 8, str_repeat('漢', 50));
    $snapshot['sources'] = [];
    for ($i = 0; $i < 16; $i++) {
        $row = $source;
        $row['ref'] = 'project:'.Str::uuid();
        $snapshot['sources'][] = $row;
    }
    // This is valid within the separately reviewed 24K snapshot and 1,500-character question bounds.
    $payload = $service->payload(str_repeat('🌲', 1500), $snapshot);
    expect(count($payload['snapshot']['sources']))->toBe(16);
    try {
        $service->requestBody($payload, 100);
        $this->fail('Escaping overhead must not bypass the full provider-wire bound.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(422)->and($exception->getMessage())->toContain('complete provider request');
    }
    $body = $service->requestBody($service->payload('What is visible?', reviewerProviderSnapshot()), 100);
    expect(strlen(json_encode($body, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(60000);
    Http::assertNothingSent();
});
