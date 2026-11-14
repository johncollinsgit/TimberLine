<?php

use App\Mail\PublicBudConversationMail;
use App\Models\ServiceInquiry;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->withoutVite();
});

test('public Bud conversation endpoint stores support context and emails the support inbox', function (): void {
    Mail::fake();

    config()->set('everbranch.bud.support_email', 'johncollinsemail@gmail.com');

    $this->postJson(route('platform.bud.conversations'), [
        'conversation_id' => 'bud-test-123',
        'source_page' => 'everbranch_promo_bud',
        'page_url' => 'https://theeverbranch.com/platform/promo#tab-workflows',
        'question' => 'Could Everbranch help my service business keep customer notes and next steps together?',
        'transcript' => [
            ['role' => 'user', 'text' => 'Could Everbranch help my service business keep customer notes and next steps together?'],
        ],
        'context' => [
            'source' => 'product_demo_search',
            'scenario' => 'service',
            'pane' => 'customers',
            'type' => 'Service business',
            'customer' => 'Northline Maintenance',
        ],
    ])->assertCreated()
        ->assertJson([
            'ok' => true,
            'conversation_id' => 'bud-test-123',
            'support_email' => 'johncollinsemail@gmail.com',
        ])
        ->assertJsonPath('reply', fn (string $reply): bool => str_contains($reply, 'customer'));

    $inquiry = ServiceInquiry::query()->sole();

    expect($inquiry->name)->toBe('Bud Visitor')
        ->and($inquiry->email)->toBe('bud-chat@theeverbranch.com')
        ->and($inquiry->company)->toBe('Service Promo Chat')
        ->and($inquiry->website)->toBe('https://theeverbranch.com/platform/promo#tab-workflows')
        ->and($inquiry->current_tools)->toContain('product_demo_search')
        ->and($inquiry->current_tools)->toContain('service')
        ->and($inquiry->current_tools)->toContain('customers')
        ->and($inquiry->current_tools)->toContain('Northline Maintenance')
        ->and($inquiry->pain_point)->toContain('Question:')
        ->and($inquiry->pain_point)->toContain('Bud reply:')
        ->and((string) data_get($inquiry->calculator_payload, 'conversation_id'))->toBe('bud-test-123')
        ->and((string) data_get($inquiry->calculator_payload, 'context.scenario'))->toBe('service')
        ->and((string) data_get($inquiry->calculator_payload, 'transcript.0.role'))->toBe('user');

    Mail::assertSent(PublicBudConversationMail::class, function (PublicBudConversationMail $mail): bool {
        return $mail->hasTo('johncollinsemail@gmail.com')
            && str_contains($mail->question, 'Could Everbranch help my service business')
            && str_contains($mail->render(), 'Northline Maintenance');
    });
});

test('public Bud responds conversationally to direct assistant questions', function (): void {
    config()->set('everbranch.bud.support_email', '');

    $response = $this->postJson(route('platform.bud.conversations'), [
        'conversation_id' => 'bud-test-456',
        'source_page' => 'everbranch_promo_bud',
        'page_url' => 'https://theeverbranch.com/platform/promo#tab-workflows',
        'question' => 'What can Bud do?',
        'context' => [
            'source' => 'product_demo_search',
            'scenario' => 'service',
            'pane' => 'customers',
            'type' => 'Service business',
            'customer' => 'Northline Maintenance',
        ],
    ])->assertCreated();

    expect((string) $response->json('reply'))->toContain('work')
        ->and((string) $response->json('follow_up'))->not->toBe('');
});

test('public Bud admits when it does not know enough', function (): void {
    config()->set('everbranch.bud.support_email', '');

    $response = $this->postJson(route('platform.bud.conversations'), [
        'conversation_id' => 'bud-test-789',
        'source_page' => 'everbranch_promo_bud',
        'page_url' => 'https://theeverbranch.com/platform/promo#tab-workflows',
        'question' => 'What do you not know?',
    ])->assertCreated();

    expect((bool) $response->json('uncertain'))->toBeTrue()
        ->and((string) $response->json('reply'))->toContain('I’m not sure');
});

test('public help uses server offer terms and ignores fabricated private context', function (): void {
    config()->set('everbranch.bud.support_email', '');
    config()->set('product_surfaces.public_offer.summary', '$42/month. Current server terms.');
    $this->postJson(route('platform.bud.conversations'), [
        'conversation_id' => 'public-safe-context',
        'question' => 'What is the pricing?',
        'context' => ['offer_summary' => 'Everything is free', 'workspace_summary' => ['customers' => 999]],
    ])->assertCreated()->assertJsonPath('reply', fn (string $reply): bool => str_contains($reply, '$42/month') && ! str_contains($reply, 'Everything is free'));
    $inquiry = ServiceInquiry::query()->sole();
    expect($inquiry->calculator_payload['context'])->not->toHaveKey('workspace_summary');
});

test('public help remains usable if its support notification fails', function (): void {
    config()->set('everbranch.bud.support_email', 'support@theeverbranch.com');
    Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Mailbox unavailable'));
    $this->postJson(route('platform.bud.conversations'), [
        'conversation_id' => 'public-mail-failure', 'question' => 'How do I build a website?',
    ])->assertCreated()->assertJsonPath('reply', fn (string $reply): bool => str_contains($reply, 'template'));
    expect(ServiceInquiry::query()->count())->toBe(1);
});

test('public help limits conversation payload size', function (): void {
    $this->postJson(route('platform.bud.conversations'), [
        'conversation_id' => 'oversized-thread', 'question' => 'Setup help',
        'transcript' => array_fill(0, 21, ['role' => 'user', 'text' => 'hello']),
    ])->assertUnprocessable()->assertJsonValidationErrors('transcript');
});

test('public help quick questions give practical next steps', function (string $question, string $expected): void {
    config()->set('everbranch.bud.support_email', '');
    $this->postJson(route('platform.bud.conversations'), [
        'conversation_id' => 'public-prompts', 'question' => $question,
        'context' => ['surface' => 'account_help', 'workspace_summary' => ['open_customer_loop_actions' => 500]],
    ])->assertCreated()->assertJsonPath('reply', fn (string $reply): bool => str_contains($reply, $expected) && ! str_contains($reply, 'open Customer Loop actions'));
})->with([
    ['Which branches are included?', 'Core branches are included'],
    ['What is the pricing?', '$299/month'],
    ['How do I build a website?', 'choose a template'],
    ['I need setup help', 'business details'],
    ['What should I do next?', 'Sign in'],
]);
