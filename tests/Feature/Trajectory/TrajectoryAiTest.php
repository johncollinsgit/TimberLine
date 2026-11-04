<?php

use App\Models\TenantAiUsageEvent;
use App\Models\TenantBudSetting;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Event;
use App\Models\User;
use App\Services\Billing\TenantAiUsageService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\TrajectoryAiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    config([
        'trajectory.enabled' => true, 'trajectory.ai_enabled' => true, 'trajectory.goldapi_key' => null,
        'bud.ai_enabled' => true, 'bud.provider_configured' => true, 'bud.ai_api_key' => 'fictional-provider-credential',
        'marketing_desk.owner_model' => 'example-finance-model',
        'marketing_desk.owner_input_micros_per_million' => 100000,
        'marketing_desk.owner_output_micros_per_million' => 500000,
    ]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $resolver = Mockery::mock(TenantModuleAccessResolver::class);
    $resolver->shouldReceive('canAccess')->andReturn(true);
    app()->instance(TenantModuleAccessResolver::class, $resolver);
    $this->connection = Connection::create(['space_id' => $this->space->id, 'external_id' => 'example-finance-item', 'institution_name' => 'Example Bank', 'status' => 'connected', 'access_token' => 'fictional-bank-secret']);
    $this->account = Account::create(['space_id' => $this->space->id, 'connection_id' => $this->connection->id, 'source_key' => 'plaid:'.$this->connection->id.':example-finance-account', 'name' => 'Example checking', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 50000, 'observed_at' => now()]);
    $this->setting = TenantBudSetting::create(['tenant_id' => $this->space->tenant_id, 'ai_status' => 'approved', 'ai_monthly_budget_cents' => 100]);
    $this->data = app(DashboardService::class)->build($this->space, 'month');
    $this->service = app(TrajectoryAiService::class);
    $this->uuid = (string) Str::uuid();
    Http::preventStrayRequests();
    $this->providerResponse = ['id' => 'resp_example_finance', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Recorded cash is $500.00; review incomplete coverage before planning.']]]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 25]];
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->providerResponse)]);
});

test('finance AI uses the authorized private context and verified transport with durable usage', function (): void {
    $answer = $this->service->respond($this->owner, $this->space, 'Explain current cash.', $this->data, $this->uuid);
    expect($answer['ai']['status'])->toBe('completed')->and($answer['reply'])->toContain('$500.00')
        ->and($answer['proposals'])->toBe([]);
    Http::assertSent(function ($request): bool {
        $body = $request->data();
        return $request->url() === 'https://api.openai.com/v1/responses' && $body['store'] === false
            && $body['model'] === 'example-finance-model' && ! isset($body['tools'])
            && ! str_contains(json_encode($body), 'fictional-bank-secret')
            && ! str_contains(json_encode($body), 'fictional-provider-credential')
            && ! str_contains(json_encode($body), 'private raw payment');
    });
    $usage = TenantAiUsageEvent::forTenantId($this->space->tenant_id)->firstOrFail();
    expect($usage->feature)->toBe('trajectory_finance')->and($usage->status)->toBe('settled')
        ->and($usage->provider_request_id)->toBe('resp_example_finance')
        ->and($usage->metadata['pricing_version'])->toBe('trajectory-finance-text-v1')
        ->and($usage->metadata)->not->toHaveKeys(['space_id', 'question', 'context', 'reply']);
    $audit = Event::where('space_id', $this->space->id)->where('action', 'trajectory_ai_request')->firstOrFail();
    expect($audit->after['state'])->toBe('settled')
        ->and($audit->after)->not->toHaveKeys(['question', 'context', 'reply'])
        ->and($audit->getRawOriginal('after'))->not->toContain($this->uuid);
});

test('unconfigured denied exhausted and missing billing states return honest Core without dispatch', function (string $state): void {
    match ($state) {
        'provider' => config(['bud.provider_configured' => false]),
        'feature' => config(['trajectory.ai_enabled' => false]),
        'approval' => $this->setting->update(['ai_status' => 'disabled']),
        'budget' => $this->setting->update(['ai_monthly_budget_cents' => 0]),
        'tenant' => $this->space->update(['tenant_id' => null]),
    };
    // The missing-billing case still needs permitted finance access; inject only that boundary.
    if ($state === 'tenant') {
        $access = Mockery::mock(\App\Services\Trajectory\FinanceAccess::class);
        $access->shouldReceive('authorize')->andReturnNull();
        app()->instance(\App\Services\Trajectory\FinanceAccess::class, $access);
    }
    $answer = $this->service->respond($this->owner, $this->space, 'Explain current cash.', $this->data, $this->uuid);
    expect($answer['mode'])->toStartWith('Bud Core')->and($answer['notice'])->not->toBeEmpty();
    Http::assertNothingSent();
    expect(TenantAiUsageEvent::count())->toBe(0);
})->with(['provider', 'feature', 'approval', 'budget', 'tenant']);

test('unknown or Square-derived balance provenance cannot reach the provider', function (string $source): void {
    if ($source === 'unknown') {
        $this->account->update(['connection_id' => null, 'source_key' => 'manual:unknown-origin']);
    } else {
        $this->connection->update(['institution_name' => 'Square']);
    }
    $answer = $this->service->respond($this->owner, $this->space, 'Explain current cash.', $this->data, $this->uuid);
    expect($answer['ai']['status'])->toBe('source_provenance_unverified');
    Http::assertNothingSent();
    expect(TenantAiUsageEvent::count())->toBe(0);
})->with(['unknown', 'square']);

test('cross-space data or unauthorized household access is rejected before an AI request', function (): void {
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    expect(fn () => $this->service->respond($other, $this->space, 'Explain cash.', $this->data, $this->uuid))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->data['space']['id'] = $this->space->id + 999;
    expect(fn () => $this->service->respond($this->owner, $this->space, 'Explain cash.', $this->data, $this->uuid))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('timeout keeps the full reservation and cannot retry the same paid request', function (): void {
    Http::fake(function () {
        throw new ConnectionException('private-provider-diagnostic-never-return');
    });
    $answer = $this->service->respond($this->owner, $this->space, 'Explain current cash.', $this->data, $this->uuid);
    expect($answer['ai']['status'])->toBe('outcome_unconfirmed')
        ->and(json_encode($answer))->not->toContain('private-provider-diagnostic-never-return');
    $usage = TenantAiUsageEvent::firstOrFail();
    expect($usage->status)->toBe('reserved')->and($usage->buyer_charge_micros)->toBeGreaterThan(0);
    $again = $this->service->respond($this->owner, $this->space, 'Explain current cash.', $this->data, $this->uuid);
    expect($again['ai']['status'])->toBe('request_not_replayed')->and(TenantAiUsageEvent::count())->toBe(1);
    expect(Event::where('action', 'trajectory_ai_request')->count())->toBe(1);
});

test('validated budget proposals bypass AI and never mutate the budget', function (): void {
    $this->data['category_options'] = ['groceries'];
    $this->data['records'] = [];
    $answer = $this->service->respond($this->owner, $this->space, 'Set groceries budget to $325 monthly', $this->data, $this->uuid);
    expect($answer['ai']['status'])->toBe('review_required')->and($answer['proposals'][0]['data']['monthly_cents'])->toBe(32500);
    Http::assertNothingSent();
    expect(TenantAiUsageEvent::count())->toBe(0);
});

test('secret-bearing or false-action provider text is withheld after legitimate usage settlement', function (string $reply): void {
    $this->providerResponse['output'][0]['content'][0]['text'] = $reply;
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($this->providerResponse)]);
    $answer = $this->service->respond($this->owner, $this->space, 'Explain current cash.', $this->data, $this->uuid);
    expect($answer['ai']['status'])->toBe('answer_requires_review')->and($answer['reply'])->not->toBe($reply)
        ->and(TenantAiUsageEvent::firstOrFail()->status)->toBe('settled');
})->with(['fictional-provider-credential', 'I have transferred money for you.']);

test('text usage extension retains the existing teams feature and rejects arbitrary feature names', function (): void {
    $tenant = \App\Models\Tenant::findOrFail($this->space->tenant_id);
    $ledger = app(TenantAiUsageService::class);
    $event = $ledger->reserveText($tenant, $this->owner, (string) Str::uuid(), 'teams_briefing', str_repeat('a', 64), 'example-model', [100000, 500000]);
    $ledger->settleText($event, 10, 10, 'resp_existing_teams');
    expect($event->fresh()->metadata['pricing_version'])->toBe('teams-text-v1')->and($event->fresh()->status)->toBe('settled');
    expect(fn () => $ledger->reserveText($tenant, $this->owner, (string) Str::uuid(), 'unknown_feature', str_repeat('b', 64), 'example-model', [100000, 500000]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('access revoked before dispatch refunds only the unused reservation', function (): void {
    $access = Mockery::mock(\App\Services\Trajectory\FinanceAccess::class);
    $access->shouldReceive('authorize')->once()->ordered()->andReturnNull();
    $access->shouldReceive('authorize')->once()->ordered()->andThrow(new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException);
    app()->instance(\App\Services\Trajectory\FinanceAccess::class, $access);
    expect(fn () => $this->service->respond($this->owner, $this->space, 'Explain cash.', $this->data, $this->uuid))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
    expect(TenantAiUsageEvent::firstOrFail()->status)->toBe('refunded')
        ->and(TenantAiUsageEvent::firstOrFail()->buyer_charge_micros)->toBe(0);
});

test('partial safe context excludes mixed totals rather than silently sending unknown source figures', function (): void {
    Account::create(['space_id' => $this->space->id, 'source_key' => 'manual:unverified-example', 'name' => 'Unverified example balance', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 999999]);
    $this->data['summary']['cash_cents'] = 1049999;
    $answer = $this->service->respond($this->owner, $this->space, 'Explain cash.', $this->data, $this->uuid);
    expect($answer['ai']['status'])->toBe('completed')->and($answer['ai']['source_coverage']['complete'])->toBeFalse();
    Http::assertSent(function ($request): bool {
        $payload = json_decode($request['input'][0]['content'], true)['untrusted_financial_context'];
        return ! isset($payload['summary'], $payload['planning'], $payload['decision_support'])
            && $payload['verified_bank_balances'][0]['balance_cents'] === 50000
            && ! str_contains(json_encode($payload), '1049999') && ! str_contains(json_encode($payload), '999999');
    });
});

test('account-filtered AI context includes only the selected bank account and no household plan', function (): void {
    Account::create(['space_id' => $this->space->id, 'connection_id' => $this->connection->id, 'source_key' => 'plaid:'.$this->connection->id.':another-example', 'name' => 'Another account', 'kind' => 'cash', 'currency' => 'USD', 'balance_cents' => 83000]);
    $this->data['scope_filter'] = ['account_id' => $this->account->id];
    $answer = $this->service->respond($this->owner, $this->space, 'Explain cash.', $this->data, $this->uuid);
    expect($answer['ai']['status'])->toBe('completed');
    Http::assertSent(function ($request): bool {
        $context = json_decode($request['input'][0]['content'], true)['untrusted_financial_context'];
        return count($context['verified_bank_balances']) === 1
            && $context['verified_bank_balances'][0]['id'] === $this->account->id
            && ! isset($context['planning'], $context['decision_support'], $context['metals'], $context['budgets']);
    });
});

test('independent current bank provenance cannot authorize Square-derived observations or historical snapshot aggregates', function (): void {
    $today = \Carbon\CarbonImmutable::now($this->space->timezone)->startOfDay();
    $priorStart = $today->startOfMonth()->subYearNoOverflow();
    $priorEnd = $today->subYearNoOverflow();
    app(\App\Services\Trajectory\RecordService::class)->save($this->owner, $this->space, 'financial_observation', 'Fictional observation canary', [
        'metric' => 'take_home_income', 'aggregation' => 'period_total', 'source_id' => 'fictional-square-observation',
        'period_start' => $priorStart->toDateString(), 'period_end' => $priorEnd->toDateString(),
        'observed_on' => $today->toDateString(), 'amount_cents' => 87654321,
        'source' => 'Square derived observation private-note canary', 'complete' => false,
    ]);
    $data = app(DashboardService::class)->build($this->space, 'month');
    // Prove the historical dashboard really contains the observation, while
    // current bank checks alone still report independent provenance.
    expect(json_encode($data['period_comparison']))->toContain('Square derived observation private-note canary')
        ->and($this->service->sourceCoverage($this->space)['complete'])->toBeTrue();
    $data['summary']['net_worth_cents'] = 76543219;
    $data['planning']['required_monthly_cents'] = 76543218;
    $data['decision_support']['month']['received_cents'] = 76543217;
    $answer = $this->service->respond($this->owner, $this->space, 'Explain current cash.', $data, $this->uuid);
    expect($answer['ai']['status'])->toBe('completed');
    Http::assertSent(function ($request): bool {
        $context = json_decode($request['input'][0]['content'], true)['untrusted_financial_context'];
        $serialized = json_encode($context);

        return ! isset($context['summary']) && ! isset($context['planning']) && ! isset($context['period_comparison']) && ! isset($context['decision_support'])
            && $context['verified_bank_balances'][0]['balance_cents'] === 50000
            && ! str_contains($serialized, 'Square derived observation private-note canary')
            && ! str_contains($serialized, '87654321') && ! str_contains($serialized, '76543219')
            && ! str_contains($serialized, '76543218') && ! str_contains($serialized, '76543217');
    });
});

test('provider budget context permits only reviewed typed targets without free-text names notes or source references', function (): void {
    foreach ([true, false] as $reviewed) {
        app(\App\Services\Trajectory\RecordService::class)->save($this->owner, $this->space, 'budget', 'Private budget name canary '.(int) $reviewed, [
            'category' => 'groceries', 'monthly_cents' => $reviewed ? 24680 : 13579,
            'purpose' => 'spending', 'effective_on' => now($this->space->timezone)->toDateString(),
            'source' => 'Private budget source-note canary', 'source_id' => 'private-source-reference-'.(int) $reviewed,
            'reviewed' => $reviewed,
        ]);
    }
    $data = app(DashboardService::class)->build($this->space, 'month');
    $this->service->respond($this->owner, $this->space, 'Explain current cash.', $data, $this->uuid);
    Http::assertSent(function ($request): bool {
        $context = json_decode($request['input'][0]['content'], true)['untrusted_financial_context'];
        $serialized = json_encode($context);

        return count($context['budgets']) === 1 && $context['budgets'][0]['data']['monthly_cents'] === 24680
            && ! str_contains($serialized, '13579') && ! str_contains($serialized, 'Private budget name canary')
            && ! str_contains($serialized, 'Private budget source-note canary') && ! str_contains($serialized, 'private-source-reference-');
    });
});
