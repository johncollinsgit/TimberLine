<?php

use App\Models\AiBranchAccount;
use App\Models\Tenant;
use App\Models\TenantAiUsageEvent;
use App\Models\TenantModuleEntitlement;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\AiBranch\AiBranchOffer;
use App\Services\Marketing\Messaging\TenantMessagingUsageService;
use App\Services\Trajectory\FinanceAiService;
use App\Services\Trajectory\FinanceAuditService;
use App\Services\Trajectory\GeraldAudioProvider;
use App\Services\Trajectory\GeraldService;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['trajectory.enabled' => true, 'ai_branch.standard_enabled' => true, 'ai_branch.api_key' => 'fictional-test-key', 'ai_branch.rates_valid_until' => now()->addMonth()->toIso8601String(), 'trajectory.audio_rates.valid_until' => now()->addMonth()->toIso8601String()]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->owner, null, true);
    $tenant = Tenant::findOrFail($this->space->tenant_id);
    config(['ai_branch.existing_tenants' => [$tenant->id => $tenant->slug]]);
    TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'ai_branch', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included', 'entitlement_source' => AiBranchOffer::INCLUDED_SOURCE]);
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    app(FinanceAiService::class)->configure($this->owner, $this->space, ['enabled' => true, 'confirmed' => true, 'model' => 'gpt-6-luna', 'budget_cents' => 500, 'audio_approved' => true]);
    $this->space->refresh();
    app(TenantMessagingUsageService::class)->fund($tenant->id, 5000000, 'fixture-gerald-credit');
    $this->account = Account::create(['space_id' => $this->space->id, 'source_key' => 'gerald:cash', 'name' => 'Checking', 'kind' => 'cash', 'balance_cents' => 1000000, 'observed_at' => now(), 'history_start' => now()->subDays(90)]);
    $this->url = "/trajectory/spaces/{$this->space->id}/gerald";
    $this->fakeReply = function (array $answer) {
        Http::fake(['api.openai.com/v1/responses' => Http::response(['id' => 'resp_gerald', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($answer)]]]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 100, 'input_token_details' => ['cached_tokens' => 0]]])]);
    };
});

test('household Gerald uses private finance access without creating generic business membership', function () {
    expect($this->owner->tenants()->count())->toBe(0);
    ($this->fakeReply)(['reply' => 'Your plan needs {{income_needed}} per month. The paperwork is, regrettably, quite clear.', 'source_ids' => [], 'category_options' => [], 'plan_change' => null]);
    $request = ['question' => 'What income do we need?', 'request_id' => (string) Str::uuid()];
    $answer = $this->actingAs($this->owner)->postJson($this->url.'/messages', $request)->assertOk();
    expect($answer->json('reply'))->toContain($answer->json('facts.income_needed'));
    $event = TenantAiUsageEvent::sole();
    expect($event->buyer_charge_micros)->toBe($event->provider_cost_micros * 2)->and($event->context)->toBe('trajectory');
    $this->postJson($this->url.'/messages', [...$request, 'conversation_id' => $answer->json('conversation_id')])->assertOk();
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => str_contains($r['instructions'], 'weary, witty') && $r['store'] === false);
    $dashboard = $this->getJson("/trajectory/spaces/{$this->space->id}/dashboard")->assertOk();
    expect(collect($dashboard->json('records'))->whereIn('kind', ['gerald_conversation', 'gerald_request']))->toBeEmpty();
    $private = Record::where('kind', 'gerald_conversation')->sole();
    $this->deleteJson("/trajectory/spaces/{$this->space->id}/records/{$private->id}", ['version' => $private->version])->assertNotFound();
    $other = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($other)->getJson($this->url)->assertForbidden();
});

test('Gerald never substitutes invented currency for server calculations and offers supported category alternatives', function () {
    app(LedgerService::class)->ingest($this->account, [['id' => 'purchase', 'date' => now()->subDay()->toDateString(), 'merchant' => 'Amazon', 'amount_cents' => -5000]]);
    $tx = Transaction::sole();
    ($this->fakeReply)(['reply' => 'You only need $99999.', 'category_options' => [['id' => $tx->id, 'categories' => ['materials', 'education'], 'reason' => 'What was purchased and who used it?'], ['id' => 99999, 'categories' => ['materials'], 'reason' => 'Foreign transaction']], 'plan_change' => null]);
    $answer = $this->actingAs($this->owner)->postJson($this->url.'/messages', ['question' => 'What other categories fit?', 'request_id' => (string) Str::uuid(), 'focus_ids' => [$tx->id]])->assertOk();
    expect($answer->json('reply'))->not->toContain('99999')->and($answer->json('category_options'))->toHaveCount(1)->and($tx->fresh()->reviewed)->toBeFalse();
    $this->postJson($this->url.'/messages', ['question' => 'Foreign records?', 'request_id' => (string) Str::uuid(), 'focus_ids' => [99999]])->assertNotFound();
});

test('combined Gerald figures agree with the dashboard and a removed business link revokes the conversation', function () {
    $tenant = Tenant::create(['slug' => 'gerald-company', 'name' => 'Gerald Company']);
    \App\Models\TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $business] = app(PilotService::class)->prepare($this->owner, $tenant, true);
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    $business->update(['settings' => [...$business->settings, 'flexible_monthly_cents' => 200000, 'income_expectations' => ['income' => 250000]]]);
    $this->space->update(['settings' => [...$this->space->fresh()->settings, 'flexible_monthly_cents' => 300000, 'income_expectations' => ['income' => 350000]]]);
    ($this->fakeReply)(['reply' => 'Together you need {{income_needed}} with a gap of {{income_gap}}.', 'plan_change' => null]);
    $this->actingAs($this->owner);
    $plan = $this->getJson("/trajectory/spaces/{$this->space->id}/combined?business_id={$business->id}")->assertOk()->json('debt_progress');
    $answer = $this->postJson($this->url.'/messages', ['question' => 'What do we need together?', 'request_id' => (string) Str::uuid(), 'business_id' => $business->id])->assertOk();
    foreach (['income_needed' => 'required_monthly_cents', 'income_gap' => 'income_gap_cents', 'debt_payment' => 'payment_monthly_cents', 'expected_income' => 'expected_monthly_cents'] as $fact => $field) {
        expect($answer->json('facts.'.$fact))->toBe('$'.number_format($plan[$field] / 100, 2));
    }
    \Illuminate\Support\Facades\DB::table('trajectory_links')->where('household_id', $this->space->id)->where('business_id', $business->id)->delete();
    $this->postJson($this->url.'/messages', ['question' => 'Still together?', 'request_id' => (string) Str::uuid(), 'conversation_id' => $answer->json('conversation_id'), 'business_id' => $business->id])->assertForbidden();
    Http::assertSentCount(1);
});

test('plan changes require a fresh single-use exact confirmation and preserve an audited save', function () {
    $service = app(GeraldService::class);
    $proposal = $service->preview($this->owner, $this->space, ['kind' => 'budget', 'values' => ['flexible_monthly_cents' => 250000]]);
    expect($this->space->fresh()->settings['flexible_monthly_cents'] ?? null)->toBeNull();
    $this->actingAs($this->owner)->postJson($this->url.'/proposals/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertOk()->assertJsonPath('saved', true);
    expect($this->space->fresh()->settings['flexible_monthly_cents'])->toBe(250000);
    $this->postJson($this->url.'/proposals/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertConflict();
    $proposal = $service->preview($this->owner, $this->space->fresh(), ['kind' => 'budget', 'values' => ['flexible_monthly_cents' => 200000]]);
    $this->travel(3)->minutes();
    $this->postJson($this->url.'/proposals/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertConflict();
    $proposal = $service->preview($this->owner, $this->space->fresh(), ['kind' => 'budget', 'values' => ['flexible_monthly_cents' => 200000]]);
    $this->space->refresh()->update(['settings' => [...$this->space->settings, 'flexible_monthly_cents' => 175000]]);
    $this->postJson($this->url.'/proposals/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertConflict();
});

test('a separate recorded confirmation is bound to its proposal and cannot be fabricated or reused', function () {
    $proposal = app(GeraldService::class)->preview($this->owner, $this->space, ['kind' => 'budget', 'values' => ['flexible_monthly_cents' => 250000]]);
    $wav = app(GeraldAudioProvider::class)->wave(str_repeat("\0", 48000));
    Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Gerald, save this plan.', 'usage' => ['seconds' => 1]], 200, ['x-request-id' => 'req_voice'])]);
    $uuid = (string) Str::uuid();
    $this->actingAs($this->owner)->post($this->url.'/transcription', ['audio' => UploadedFile::fake()->createWithContent('voice.wav', $wav), 'request_id' => $uuid, 'proposal_id' => $proposal['id']], ['Accept' => 'application/json'])->assertOk();
    $this->postJson($this->url.'/proposals/'.$proposal['id'].'/confirm', ['transcription_receipt' => $uuid])->assertOk();
    $this->postJson($this->url.'/proposals/'.$proposal['id'].'/confirm', ['transcription_receipt' => $uuid])->assertConflict();
    $proposal = app(GeraldService::class)->preview($this->owner, $this->space->fresh(), ['kind' => 'budget', 'values' => ['flexible_monthly_cents' => 150000]]);
    $this->postJson($this->url.'/proposals/'.$proposal['id'].'/confirm', ['transcription_receipt' => $uuid])->assertUnprocessable();
});

test('exhausted caps and unapproved voice rates stop before provider dispatch', function () {
    AiBranchAccount::where('tenant_id', $this->space->tenant_id)->update(['budget_cents' => 0]);
    $this->actingAs($this->owner)->postJson($this->url.'/messages', ['question' => 'Budget?', 'request_id' => (string) Str::uuid()])->assertForbidden();
    expect(TenantAiUsageEvent::count())->toBe(0);
    AiBranchAccount::where('tenant_id', $this->space->tenant_id)->update(['budget_cents' => 500]);
    $this->space->update(['settings' => [...$this->space->settings, 'gerald_audio_terms' => null]]);
    $wav = app(GeraldAudioProvider::class)->wave(str_repeat("\0", 48000));
    $this->post($this->url.'/transcription', ['audio' => UploadedFile::fake()->createWithContent('voice.wav', $wav), 'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertForbidden();
    Http::assertNothingSent();
});

test('full-history review scans beyond the dashboard queue and reuses unchanged checks', function () {
    Queue::fake();
    $rows = [];
    foreach (range(1, 270) as $i) {
        $rows[] = ['id' => 'history-'.$i, 'date' => now()->subDays(300)->toDateString(), 'merchant' => 'Purchase '.$i, 'amount_cents' => -1000];
    }
    app(LedgerService::class)->ingest($this->account, $rows);
    ($this->fakeReply)(['findings' => []]);
    $service = app(FinanceAuditService::class);
    $audit = $service->start($this->owner, $this->space);
    while ($service->work($audit->fresh())) {
    }
    expect($audit->fresh()->data['scanned'])->toBe(270)->and($audit->fresh()->data['status'])->toBe('complete');
    $second = $service->start($this->owner, $this->space);
    while ($service->work($second->fresh())) {
    }
    expect($second->fresh()->data['scanned'])->toBe(270)->and($second->fresh()->data['reused'])->toBe(270);
    Http::assertSentCount(27);
});

test('approved automatic categories preserve prior reviews, avoid broad merchants and retain undo', function () {
    Queue::fake();
    $rows = [];
    foreach (range(1, 8) as $i) {
        $rows[] = ['id' => 'groceries-'.$i, 'date' => now()->subDays($i)->toDateString(), 'merchant' => 'Publix', 'amount_cents' => -$i * 1000];
    }
    $rows[] = ['id' => 'broad', 'date' => now()->subDay()->toDateString(), 'merchant' => 'Amazon', 'amount_cents' => -8000];
    app(LedgerService::class)->ingest($this->account, $rows);
    $txs = Transaction::orderBy('id')->get();
    foreach ($txs->take(3) as $tx) {
        $tx->update(['category' => 'groceries', 'reviewed' => true]);
    }
    $this->space->update(['settings' => [...$this->space->settings, 'gerald_review' => ['auto_categorize' => true, 'after_sync' => false, 'actor_id' => $this->owner->id]]]);
    $findings = $txs->map(fn ($tx) => ['id' => $tx->id, 'category' => 'groceries', 'alternatives' => [], 'kind' => 'category', 'reason' => 'Matches your grocery treatment.'])->all();
    ($this->fakeReply)(['findings' => $findings]);
    $audit = app(FinanceAuditService::class)->start($this->owner, $this->space);
    while (app(FinanceAuditService::class)->work($audit->fresh())) {
    }
    expect($audit->fresh()->data['automatic_count'])->toBe(5)->and($txs->last()->fresh()->reviewed)->toBeFalse();
    $automatic = $txs[3]->fresh();
    expect($automatic->category)->toBe('groceries')->and($automatic->reviewed)->toBeTrue()->and($automatic->explanation)->toBe(LedgerService::AUTOMATIC_REVIEW);
    $this->actingAs($this->owner)->postJson("/trajectory/spaces/{$this->space->id}/transactions/{$automatic->id}/undo", ['version' => $automatic->version])->assertOk();
    expect($automatic->fresh()->reviewed)->toBeFalse();
});

test('automatic decisions cannot reinforce themselves as human category evidence', function () {
    Queue::fake();
    $rows = [];
    foreach (range(1, 5) as $i) {
        $rows[] = ['id' => 'auto-history-'.$i, 'date' => now()->subDays($i)->toDateString(), 'merchant' => 'Corner Market', 'amount_cents' => -$i * 1000];
    }
    app(LedgerService::class)->ingest($this->account, $rows);
    $txs = Transaction::orderBy('id')->get();
    foreach ($txs->take(3) as $tx) {
        $tx->update(['category' => 'groceries', 'reviewed' => true, 'explanation' => LedgerService::AUTOMATIC_REVIEW]);
    }
    $this->space->update(['settings' => [...$this->space->settings, 'gerald_review' => ['auto_categorize' => true, 'actor_id' => $this->owner->id]]]);
    ($this->fakeReply)(['findings' => $txs->map(fn ($tx) => ['id' => $tx->id, 'category' => 'groceries', 'kind' => 'category', 'reason' => 'A possible grocery purchase.'])->all()]);
    $service = app(FinanceAuditService::class);
    $audit = $service->start($this->owner, $this->space);
    while ($service->work($audit->fresh())) {
    }
    expect($audit->fresh()->data['automatic_count'])->toBe(0)->and($txs->last()->fresh()->reviewed)->toBeFalse();
    Http::assertSent(fn ($request) => ($context = json_decode($request['input'][0]['content'], true)) && empty($context['reviewed_patterns']));
});

test('a human category decision refreshes merchant evidence while unrelated checks remain reusable', function () {
    Queue::fake();
    app(LedgerService::class)->ingest($this->account, [
        ['id' => 'learn-1', 'date' => now()->subDays(3)->toDateString(), 'merchant' => 'Corner Market', 'amount_cents' => -1000],
        ['id' => 'learn-2', 'date' => now()->subDays(2)->toDateString(), 'merchant' => 'Corner Market', 'amount_cents' => -2000],
        ['id' => 'other', 'date' => now()->subDay()->toDateString(), 'merchant' => 'Other Shop', 'amount_cents' => -3000],
    ]);
    ($this->fakeReply)(['findings' => []]);
    $service = app(FinanceAuditService::class);
    $audit = $service->start($this->owner, $this->space);
    while ($service->work($audit->fresh())) {
    }
    $tx = Transaction::orderBy('id')->first();
    app(LedgerService::class)->classify($this->owner, $this->space, $tx, ['version' => $tx->version, 'category' => 'groceries', 'flow' => 'expense', 'face_punched' => false, 'bullshit_spending' => false]);
    $second = $service->start($this->owner, $this->space);
    while ($service->work($second->fresh())) {
    }
    expect($second->fresh()->data['reused'])->toBe(1);
    Http::assertSentCount(2);
});

test('balance updates invalidate old spoken or on-screen budget readbacks', function () {
    $proposal = app(GeraldService::class)->preview($this->owner, $this->space, ['kind' => 'budget', 'values' => ['flexible_monthly_cents' => 250000]]);
    $this->account->update(['balance_cents' => 900000]);
    $this->actingAs($this->owner)->postJson($this->url.'/proposals/'.$proposal['id'].'/confirm', ['confirmed' => true])->assertConflict();
});

test('disabling a household user during inference prevents disclosure after measured settlement', function () {
    Http::fake(function () {
        User::whereKey($this->owner->id)->update(['is_active' => false]);

        return Http::response(['id' => 'resp_revoked', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['reply' => 'Your plan is {{income_needed}}.', 'plan_change' => null])]]]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 100, 'input_token_details' => ['cached_tokens' => 0]]]);
    });
    $this->actingAs($this->owner)->postJson($this->url.'/messages', ['question' => 'Budget?', 'request_id' => (string) Str::uuid()])->assertForbidden();
    expect(TenantAiUsageEvent::sole()->status)->toBe('settled');
});

test('audio reservations cannot be reconciled with a text model rate card', function () {
    $uuid = (string) Str::uuid();
    expect(fn () => app(FinanceAiService::class)->run($this->owner, $this->space, 'speech', $uuid, ['text' => 'Fixture'], fn () => throw new RuntimeException('Unknown provider outcome'), ['model' => GeraldAudioProvider::SPEECH_MODEL, 'reserve_micros' => 1000]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $event = TenantAiUsageEvent::sole();
    $event->update(['created_at' => now()->subMinutes(3)]);
    $operator = User::factory()->create(['role' => 'platform_admin', 'is_active' => true]);
    $args = ['event' => $event->id, '--actor' => $operator->id, '--evidence' => 'verified-fixture-provider-evidence', '--apply' => true];
    $this->artisan('ai-branch:reconcile-usage', [...$args, '--input-tokens' => 10, '--output-tokens' => 10])->assertFailed();
    $this->artisan('ai-branch:reconcile-usage', [...$args, '--verified-provider-micros' => 150])->assertSuccessful();
    expect($event->fresh()->status)->toBe('settled')->and($event->fresh()->buyer_charge_micros)->toBe(300);
});
