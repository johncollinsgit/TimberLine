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
