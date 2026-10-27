<?php

use App\Proposals\ReviewerAiSnapshot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function reviewerSnapshotFixture(): array
{
    return ['schema_version' => 1, 'persona' => 'demo_manager', 'local_revision' => 7,
        'area_ids' => ['11111111-1111-4111-8111-111111111111'],
        'sources' => [['ref' => 'project:22222222-2222-4222-8222-222222222222', 'kind' => 'project',
            'area_id' => '11111111-1111-4111-8111-111111111111', 'title' => 'Fictional roof inspection', 'version' => 3, 'status' => 'active',
            'tasks' => [['id' => '33333333-3333-4333-8333-333333333333', 'title' => 'Inspect flashing', 'version' => 2,
                'status' => 'in_progress', 'assigned_person' => 'Fictional worker', 'priority' => 'high', 'due_on' => '2026-10-24']],
            'buildings' => ['Fictional building']]], 'coverage' => ReviewerAiSnapshot::COVERAGE];
}

function assertReviewerSnapshotRejected(array $input): void
{
    try {
        app(ReviewerAiSnapshot::class)->normalize($input);
        test()->fail('Unreviewed snapshot fields must be refused.');
    } catch (ValidationException|HttpException $exception) {
        expect($exception instanceof ValidationException ? 422 : $exception->getStatusCode())->toBe(422);
    }
}

test('fictional snapshot normalization preserves the exact closed fields without a provider or native record lookup', function (): void {
    Http::preventStrayRequests();
    $input = reviewerSnapshotFixture();
    expect(app(ReviewerAiSnapshot::class)->normalize($input))->toBe($input);
    $reordered = array_reverse($input, true);
    $reordered['sources'][0] = array_reverse($input['sources'][0], true);
    $reordered['sources'][0]['tasks'][0] = array_reverse($input['sources'][0]['tasks'][0], true);
    expect(app(ReviewerAiSnapshot::class)->normalize($reordered))->toBe($input);
    $input['sources'] = [['ref' => 'request:44444444-4444-4444-8444-444444444444', 'kind' => 'request',
        'area_id' => $input['area_ids'][0], 'title' => 'Fictional incoming ticket', 'version' => 1, 'status' => 'submitted',
        'priority' => null, 'due_on' => null, 'building' => '']];
    $input['persona'] = 'requester';
    expect(app(ReviewerAiSnapshot::class)->normalize($input))->toBe($input);
    Http::assertNothingSent();
});

test('closed schema rejects hidden fields arbitrary links native authority and mismatched or duplicate references', function (): void {
    foreach ([['tenant_id', 99], ['provider_url', 'https://example.invalid'], ['native_authorized', true]] as [$key, $value]) {
        $input = reviewerSnapshotFixture();
        $input[$key] = $value;
        assertReviewerSnapshotRejected($input);
    }
    foreach (['description', 'notes', 'uploads', 'review_comments', 'url', 'contact_email'] as $key) {
        $input = reviewerSnapshotFixture();
        $input['sources'][0][$key] = 'Not shared';
        assertReviewerSnapshotRejected($input);
    }
    $input = reviewerSnapshotFixture();
    $input['sources'][0]['tasks'][0]['mailbox'] = 'Never shared';
    assertReviewerSnapshotRejected($input);
    $input = reviewerSnapshotFixture();
    $input['sources'][0]['area_id'] = '55555555-5555-4555-8555-555555555555';
    assertReviewerSnapshotRejected($input);
    $input = reviewerSnapshotFixture();
    $input['sources'][] = $input['sources'][0];
    assertReviewerSnapshotRejected($input);
    $input = reviewerSnapshotFixture();
    $input['sources'][0]['ref'] = 'request:22222222-2222-4222-8222-222222222222';
    assertReviewerSnapshotRejected($input);
    $input = reviewerSnapshotFixture();
    $input['persona'] = 'native_owner';
    assertReviewerSnapshotRejected($input);
    $input['persona'] = 'requester';
    assertReviewerSnapshotRejected($input);
});

test('snapshot limits enforce exact types valid dates statuses text and bounded total size', function (): void {
    foreach ([['schema_version', '1'], ['local_revision', '7'], ['coverage', 'Verified campus facts']] as [$key, $value]) {
        $input = reviewerSnapshotFixture();
        $input[$key] = $value;
        assertReviewerSnapshotRejected($input);
    }
    foreach ([['version', '2'], ['title', str_repeat('x', 101)], ['title', "Hidden\0field"], ['status', 'paid']] as [$key, $value]) {
        $input = reviewerSnapshotFixture();
        $input['sources'][0][$key] = $value;
        assertReviewerSnapshotRejected($input);
    }
    foreach ([['version', '2'], ['due_on', '2026-02-30'], ['priority', 'execute_now']] as [$key, $value]) {
        $input = reviewerSnapshotFixture();
        $input['sources'][0]['tasks'][0][$key] = $value;
        assertReviewerSnapshotRejected($input);
    }
    $input = reviewerSnapshotFixture();
    $input['sources'][0]['tasks'] = array_fill(0, 17, $input['sources'][0]['tasks'][0]);
    assertReviewerSnapshotRejected($input);
    $input = reviewerSnapshotFixture();
    $input['area_ids'] = array_fill(0, 9, $input['area_ids'][0]);
    assertReviewerSnapshotRejected($input);
    $input = reviewerSnapshotFixture();
    $input['sources'] = array_fill(0, 33, $input['sources'][0]);
    assertReviewerSnapshotRejected($input);
    $input = reviewerSnapshotFixture();
    $input['sources'][0]['title'] = str_repeat('x', 24001);
    assertReviewerSnapshotRejected($input);
});

test('the total task bound applies across projects rather than restarting for each source', function (): void {
    $input = reviewerSnapshotFixture();
    $project = $input['sources'][0];
    $input['sources'] = [];
    for ($i = 0; $i < 4; $i++) {
        $source = $project;
        $source['ref'] = 'project:'.Str::uuid();
        $source['tasks'] = [];
        for ($j = 0; $j < 16; $j++) {
            $task = $project['tasks'][0];
            $task['id'] = (string) Str::uuid();
            $source['tasks'][] = $task;
        }
        $input['sources'][] = $source;
    }
    expect(array_sum(array_map(fn ($source) => count($source['tasks']), app(ReviewerAiSnapshot::class)->normalize($input)['sources'])))->toBe(64);
    $project['ref'] = 'project:'.Str::uuid();
    $input['sources'][] = $project;
    assertReviewerSnapshotRejected($input);
});

function reviewerSnapshotV2Fixture(): array
{
    $input = reviewerSnapshotFixture();
    $input['schema_version'] = 2;
    $input['coverage'] = ReviewerAiSnapshot::COVERAGE_V2;
    $source = &$input['sources'][0];
    $source['buildings'] = [['id' => '66666666-6666-4666-8666-666666666666', 'title' => 'Fictional building', 'version' => 1]];
    $source['tasks'][0] += ['assigned_person_id' => 'worker_2', 'building_id' => $source['buildings'][0]['id'],
        'request' => ['id' => '77777777-7777-4777-8777-777777777777', 'version' => 2, 'status' => 'assigned']];
    $source['contractor_plans'] = [['id' => '88888888-8888-4888-8888-888888888888', 'version' => 1,
        'contractor_id' => '99999999-9999-4999-8999-999999999999', 'contractor_version' => 2,
        'contractor_title' => 'Fictional roofing crew', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-03']];
    $source['costs'] = [
        ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'version' => 1, 'amount_minor' => 0, 'currency' => 'USD', 'basis' => 'estimate'],
        ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'version' => 2, 'amount_minor' => 250, 'currency' => 'USD', 'basis' => 'recorded_cost'],
        ['id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'version' => 1, 'amount_minor' => 90, 'currency' => 'EUR', 'basis' => 'recorded_cost'],
    ];
    $source['comparisons'] = \App\Proposals\ReviewerAiEvidence::comparisons($source['costs']);

    return $input;
}

test('schema2 closed fictional graph preserves exact IDs plans and server checked selected cost math without native authority', function (): void {
    Http::preventStrayRequests();
    $input = reviewerSnapshotV2Fixture();
    $result = app(ReviewerAiSnapshot::class)->normalize($input);
    expect($result)->toBe($input);
    expect($result['sources'][0]['comparisons'])->toBe([
        ['currency' => 'EUR', 'estimate_minor' => null, 'recorded_cost_minor' => 90, 'difference_minor' => null,
            'estimate_ids' => [], 'recorded_cost_ids' => ['cccccccc-cccc-4ccc-8ccc-cccccccccccc']],
        ['currency' => 'USD', 'estimate_minor' => 0, 'recorded_cost_minor' => 250, 'difference_minor' => 250,
            'estimate_ids' => ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'], 'recorded_cost_ids' => ['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']],
    ]);
    $reordered = array_reverse($input, true);
    $reordered['sources'][0] = array_reverse($input['sources'][0], true);
    $reordered['sources'][0]['tasks'][0] = array_reverse($input['sources'][0]['tasks'][0], true);
    expect(app(ReviewerAiSnapshot::class)->normalize($reordered))->toBe($input);
    Http::assertNothingSent();
});

test('schema2 refuses unpreviewed arithmetic hidden nested fields invalid IDs dates types or unsupported financial perspectives', function (): void {
    foreach (['url', 'notes', 'contact_email', 'commercial_price', 'paid_invoice'] as $field) {
        foreach (['buildings', 'contractor_plans', 'costs', 'tasks'] as $path) {
            $input = reviewerSnapshotV2Fixture();
            $input['sources'][0][$path][0][$field] = 'Never shared';
            assertReviewerSnapshotRejected($input);
        }
    }
    foreach ([['amount_minor', '250'], ['amount_minor', -1], ['amount_minor', 1000000001], ['currency', 'usd'], ['basis', 'approved_budget'], ['id', 'not-an-id']] as [$field, $value]) {
        $input = reviewerSnapshotV2Fixture();
        $input['sources'][0]['costs'][0][$field] = $value;
        assertReviewerSnapshotRejected($input);
    }
    $input = reviewerSnapshotV2Fixture();
    $input['sources'][0]['comparisons'][1]['difference_minor'] = 999;
    assertReviewerSnapshotRejected($input);
    $input = reviewerSnapshotV2Fixture();
    $input['sources'][0]['comparisons'][1]['extra_provider_instruction'] = 'Never';
    assertReviewerSnapshotRejected($input);
    $input = reviewerSnapshotV2Fixture();
    $input['sources'][0]['contractor_plans'][0]['ends_on'] = '2026-10-01';
    assertReviewerSnapshotRejected($input);
    $input = reviewerSnapshotV2Fixture();
    $input['sources'][0]['tasks'][0]['building_id'] = (string) Str::uuid();
    assertReviewerSnapshotRejected($input);
    $input = reviewerSnapshotV2Fixture();
    $input['sources'][0]['tasks'][0]['assigned_person_id'] = null;
    assertReviewerSnapshotRejected($input);
    $input = reviewerSnapshotV2Fixture();
    $input['persona'] = 'worker';
    assertReviewerSnapshotRejected($input);
    $input['sources'][0]['costs'] = [];
    $input['sources'][0]['comparisons'] = [];
    expect(app(ReviewerAiSnapshot::class)->normalize($input)['persona'])->toBe('worker');
});

test('schema2 nested rows keep global no duplicate costs plans and strict bounded sizes', function (): void {
    foreach (['costs', 'contractor_plans'] as $path) {
        $input = reviewerSnapshotV2Fixture();
        $copy = $input['sources'][0];
        $copy['ref'] = 'project:'.Str::uuid();
        $copy['tasks'] = [];
        $copy['buildings'] = [];
        if ($path === 'costs') {
            $copy['contractor_plans'] = [];
        } else {
            $copy['costs'] = [];
            $copy['comparisons'] = [];
        }
        $input['sources'][] = $copy;
        assertReviewerSnapshotRejected($input);
    }
    foreach ([['buildings', 9], ['contractor_plans', 9], ['costs', 17]] as [$path, $number]) {
        $input = reviewerSnapshotV2Fixture();
        $input['sources'][0][$path] = array_fill(0, $number, $input['sources'][0][$path][0]);
        assertReviewerSnapshotRejected($input);
    }
    $input = reviewerSnapshotV2Fixture();
    $input['sources'][0]['contractor_plans'][0]['contractor_title'] = str_repeat('x', 24001);
    assertReviewerSnapshotRejected($input);
});

test('schema2 requester ticket links cannot reference hidden projects or broaden source kinds', function (): void {
    $input = reviewerSnapshotFixture();
    $input['schema_version'] = 2;
    $input['persona'] = 'requester';
    $input['coverage'] = ReviewerAiSnapshot::COVERAGE_V2;
    $input['sources'] = [['ref' => 'request:44444444-4444-4444-8444-444444444444', 'kind' => 'request',
        'area_id' => $input['area_ids'][0], 'title' => 'Fictional personal ticket', 'version' => 1, 'status' => 'submitted',
        'priority' => null, 'due_on' => null, 'building' => 'Fictional hall', 'building_id' => '66666666-6666-4666-8666-666666666666',
        'building_version' => 1, 'project_id' => null, 'task_id' => null]];
    expect(app(ReviewerAiSnapshot::class)->normalize($input))->toBe($input);
    $input['sources'][0]['project_id'] = (string) Str::uuid();
    assertReviewerSnapshotRejected($input);
});

test('schema1 existing shared task identities remain accepted while schema2 rejects ambiguous duplicates', function (): void {
    $legacy = reviewerSnapshotFixture();
    $copy = $legacy['sources'][0];
    $copy['ref'] = 'project:'.Str::uuid();
    $legacy['sources'][] = $copy;
    expect(app(ReviewerAiSnapshot::class)->normalize($legacy)['sources'])->toHaveCount(2);
    $input = reviewerSnapshotV2Fixture();
    $copy = $input['sources'][0];
    $copy['ref'] = 'project:'.Str::uuid();
    $copy['contractor_plans'] = [];
    $copy['costs'] = [];
    $copy['comparisons'] = [];
    $input['sources'][] = $copy;
    assertReviewerSnapshotRejected($input);
});

test('schema2 missing closed identity fields return validation refusal rather than a partially normalized graph', function (): void {
    foreach (['ref', 'area_id'] as $field) {
        $input = reviewerSnapshotV2Fixture();
        unset($input['sources'][0][$field]);
        assertReviewerSnapshotRejected($input);
    }
    $input = reviewerSnapshotV2Fixture();
    unset($input['sources'][0]['tasks'][0]['assigned_person']);
    assertReviewerSnapshotRejected($input);
});

test('schema2 enforces sixty four total cost inputs and thirty two total plans across selected projects', function (): void {
    foreach ([['costs', 16, 64], ['contractor_plans', 8, 32]] as [$field, $perProject, $total]) {
        $input = reviewerSnapshotV2Fixture();
        $template = $input['sources'][0];
        $input['sources'] = [];
        for ($i = 0; $i < 4; $i++) {
            $source = $template;
            $source['ref'] = 'project:'.Str::uuid();
            $source['tasks'] = $source['buildings'] = $source['contractor_plans'] = $source['costs'] = $source['comparisons'] = [];
            for ($j = 0; $j < $perProject; $j++) {
                $row = $template[$field][0];
                $row['id'] = (string) Str::uuid();
                $source[$field][] = $row;
            }
            $source['comparisons'] = \App\Proposals\ReviewerAiEvidence::comparisons($source['costs']);
            $input['sources'][] = $source;
        }
        expect(array_sum(array_map(fn ($source) => count($source[$field]), app(ReviewerAiSnapshot::class)->normalize($input)['sources'])))->toBe($total);
        $extra = $input['sources'][0];
        $extra['ref'] = 'project:'.Str::uuid();
        $extra[$field] = [$template[$field][0]];
        $extra[$field][0]['id'] = (string) Str::uuid();
        $extra['comparisons'] = \App\Proposals\ReviewerAiEvidence::comparisons($extra['costs']);
        $input['sources'][] = $extra;
        assertReviewerSnapshotRejected($input);
    }
});

test('schema2 nullable request accepts null and refuses empty or incomplete nested objects as422 before indexing', function (): void {
    foreach ([[], json_decode('{}', true), ['version' => 1], ['id' => '77777777-7777-4777-8777-777777777777'], ['status' => 'assigned']] as $request) {
        $input = reviewerSnapshotV2Fixture();
        $input['sources'][0]['tasks'][0]['request'] = $request;
        assertReviewerSnapshotRejected($input);
    }
    foreach (['buildings', 'contractor_plans', 'costs', 'tasks'] as $field) {
        $input = reviewerSnapshotV2Fixture();
        $input['sources'][0][$field] = [[]];
        assertReviewerSnapshotRejected($input);
    }
    $input = reviewerSnapshotV2Fixture();
    $input['sources'][0]['tasks'][0]['request'] = null;
    expect(app(ReviewerAiSnapshot::class)->normalize($input)['sources'][0]['tasks'][0]['request'])->toBeNull();
});
