<?php

use App\Models\AiBranchAccount;
use App\Models\AiBranchConversation;
use App\Models\AiBranchMemory;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantAiUsageEvent;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\AiBranch\AiBranchAccess;
use App\Services\AiBranch\AiBranchActions;
use App\Services\AiBranch\AiBranchChat;
use App\Services\AiBranch\AiBranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

function aiBranchFixture(string $slug = 'ai-pilot'): array
{
    $tenant = Tenant::query()->create(['name' => 'AI Pilot', 'slug' => $slug]);
    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantAccessAddon::query()->create(['tenant_id' => $tenant->id, 'addon_key' => 'ai_branch_growth', 'enabled' => true, 'source' => 'stripe_fulfillment']);
    \App\Models\TenantBillingSubscription::query()->create([
        'tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_subscription_reference' => 'sub_'.$slug,
        'purchase_key' => 'addon.ai_branch_growth', 'status' => 'active', 'current_period_ends_at' => now()->addMonths(2),
    ]);
    AiBranchAccount::query()->create(['tenant_id' => $tenant->id, 'enabled' => true, 'budget_cents' => 1500]);
    config()->set('ai_branch.tenant_ids', [...config('ai_branch.tenant_ids', []), $tenant->id]);

    return [$tenant, $user];
}

function aiBranchResponse(string $reply = 'The recent job is scheduled [job:1].'): array
{
    return ['id' => 'resp_test', 'status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $reply]]]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 30]];
}

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set(['ai_branch.enabled' => true, 'ai_branch.actions_enabled' => true, 'ai_branch.api_key' => 'test-dedicated-key',
        'ai_branch.model' => 'test-model', 'ai_branch.input_micros_per_million' => 1000000, 'ai_branch.output_micros_per_million' => 4000000]);
});

test('chat retrieves only allowed tenant fields and records encrypted history and included usage', function (): void {
    [$tenant, $user] = aiBranchFixture();
    [$other] = aiBranchFixture('other');
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Install switch', 'status' => 'scheduled', 'customer_email' => 'private@example.test', 'lock_box_code' => 'SECRET-CODE']);
    FieldServiceJob::query()->create(['tenant_id' => $other->id, 'title' => 'OTHER TENANT SECRET', 'status' => 'open']);
    Http::fake(['api.openai.com/*' => Http::response(aiBranchResponse())]);
    $chat = app(AiBranchChat::class);
    $conversation = $chat->start($tenant, $user);
    $result = $chat->ask($tenant, $user, $conversation->id, 'Summarize recent jobs', (string) Str::uuid());
    expect($result['sources'])->toHaveCount(1)->and($result['sources'][0]['id'])->toBe($job->id);
    Http::assertSent(function ($request): bool {
        $body = json_encode($request->data());

        return $request->url() === 'https://api.openai.com/v1/responses' && $request['store'] === false && $request['service_tier'] === 'default'
            && ! isset($request['tools']) && str_contains($body, 'Install switch')
            && ! str_contains($body, 'OTHER TENANT SECRET') && ! str_contains($body, 'SECRET-CODE') && ! str_contains($body, 'private@example.test');
    });
    $event = TenantAiUsageEvent::query()->sole();
    expect($event->status)->toBe('settled')->and($event->buyer_charge_micros)->toBe(0)->and($event->provider_cost_micros)->toBe(220)
        ->and(data_get($event->metadata, 'input_tokens'))->toBe(100)
        ->and(DB::table('ai_branch_conversations')->value('messages'))->not->toContain('Summarize recent jobs');
});

test('the native assistant reuses private chat and memory without dispatching on view', function (): void {
    [$tenant, $user] = aiBranchFixture('fictional-voice');
    config()->set(['ai_branch.assistant_enabled' => true, 'ai_branch.assistant_tenant_ids' => [$tenant->id]]);
    \Laravel\Sanctum\Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);
    $base = '/api/mobile/v1/workspaces/'.$tenant->slug.'/ai-assistant';
    $this->getJson($base)->assertOk()->assertJsonPath('version', 1)->assertJsonPath('conversation', null);
    Http::assertNothingSent();
    $conversation = $this->postJson($base.'/conversations')->assertCreated()->json('conversation_id');
    Http::fake(['api.openai.com/*' => Http::response(aiBranchResponse('Fictional answer.'))]);
    $uuid = (string) Str::uuid();
    $this->postJson($base.'/conversations/'.$conversation.'/messages', ['question' => 'Hello', 'request_id' => $uuid])->assertOk()->assertJsonPath('text', 'Fictional answer.');
    $this->postJson($base.'/conversations/'.$conversation.'/messages', ['question' => 'Hello', 'request_id' => $uuid])->assertOk();
    Http::assertSentCount(1);
    $this->postJson($base.'/memories', ['body' => 'Use short summaries.', 'confirmed' => true])->assertCreated()->assertJsonPath('restart_required', true);
    $memory = AiBranchMemory::query()->where('tenant_id', $tenant->id)->sole();
    $this->getJson($base)->assertOk()->assertJsonPath('memories.0.body', 'Use short summaries.');
    $this->deleteJson($base.'/memories/'.$memory->id)->assertOk();
    expect(AiBranchMemory::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('the native assistant gate and current membership protect every request', function (): void {
    [$tenant, $user] = aiBranchFixture('fictional-voice');
    \Laravel\Sanctum\Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);
    $base = '/api/mobile/v1/workspaces/'.$tenant->slug.'/ai-assistant';
    $this->getJson($base)->assertNotFound();
    config()->set(['ai_branch.assistant_enabled' => true, 'ai_branch.assistant_tenant_ids' => [$tenant->id]]);
    $user->tenants()->updateExistingPivot($tenant->id, ['role' => 'member']);
    $this->getJson($base)->assertForbidden();
    $this->postJson($base.'/conversations')->assertForbidden();
    Http::assertNothingSent();
});

test('native assistant rollout revocation during dispatch suppresses the answer and retains usage', function (): void {
    [$tenant, $user] = aiBranchFixture('fictional-revocation');
    config()->set(['ai_branch.assistant_enabled' => true, 'ai_branch.assistant_tenant_ids' => [$tenant->id]]);
    \Laravel\Sanctum\Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);
    $base = '/api/mobile/v1/workspaces/'.$tenant->slug.'/ai-assistant';
    $conversation = $this->postJson($base.'/conversations')->assertCreated()->json('conversation_id');
    Http::fake(function () {
        config()->set('ai_branch.assistant_tenant_ids', []);

        return Http::response(aiBranchResponse('PRIVATE ANSWER'));
    });
    $this->postJson($base.'/conversations/'.$conversation.'/messages', ['question' => 'Hello', 'request_id' => (string) Str::uuid()])->assertNotFound()->assertDontSee('PRIVATE ANSWER');
    expect(TenantAiUsageEvent::query()->sole()->status)->toBe('settled');
    Http::assertSentCount(1);
});

test('revoked email verification blocks a refreshed assistant authorization', function (): void {
    [$tenant, $user] = aiBranchFixture('fictional-verification');
    config()->set(['ai_branch.assistant_enabled' => true, 'ai_branch.assistant_tenant_ids' => [$tenant->id]]);
    User::whereKey($user->id)->update(['email_verified_at' => null]);
    expect($user->hasVerifiedEmail())->toBeTrue();
    expect(fn () => app(\App\Services\AiBranch\AiAssistantAccess::class)->authorize($tenant, $user))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

test('native note preview approval and undo keep one receipt and reject another tenant job', function (): void {
    [$tenant, $user] = aiBranchFixture('fictional-note');
    [$other] = aiBranchFixture('fictional-other-note');
    config()->set(['ai_branch.assistant_enabled' => true, 'ai_branch.assistant_tenant_ids' => [$tenant->id]]);
    \Laravel\Sanctum\Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Fictional work', 'status' => 'open']);
    $foreign = FieldServiceJob::create(['tenant_id' => $other->id, 'title' => 'FOREIGN JOB', 'status' => 'open']);
    $base = '/api/mobile/v1/workspaces/'.$tenant->slug.'/ai-assistant';
    $this->getJson($base)->assertOk()->assertJsonPath('actions_available', true)->assertJsonPath('note_jobs.0.id', $job->id)->assertDontSee('FOREIGN JOB');
    $this->postJson($base.'/actions', ['job_id' => $foreign->id, 'body' => 'Forbidden', 'request_id' => (string) Str::uuid()])->assertNotFound();
    $preview = $this->postJson($base.'/actions', ['job_id' => $job->id, 'body' => 'Fictional internal note', 'request_id' => (string) Str::uuid()])->assertCreated()->json();
    expect(FieldServiceJobNote::count())->toBe(0);
    $payload = ['digest' => $preview['digest'], 'decision' => 'approve', 'confirmed' => true];
    $this->postJson($base.'/actions/'.$preview['action_id'].'/decision', $payload)->assertOk();
    $this->postJson($base.'/actions/'.$preview['action_id'].'/decision', $payload)->assertOk();
    expect(FieldServiceJobNote::count())->toBe(1);
    $undo = ['digest' => $preview['digest'], 'request_id' => (string) Str::uuid(), 'confirmed' => true];
    $this->postJson($base.'/actions/'.$preview['action_id'].'/undo', $undo)->assertOk()->assertJsonPath('status', 'executed');
    $this->postJson($base.'/actions/'.$preview['action_id'].'/undo', $undo)->assertOk();
    expect(FieldServiceJobNote::count())->toBe(0)->and(DB::table('field_service_job_notes')->count())->toBe(1);
    Http::assertNothingSent();
});

test('assistant financial summaries expose only saved authoritative totals and stop on connector revocation', function (): void {
    [$tenant, $user] = aiBranchFixture('fictional-finance');
    config()->set(['ai_branch.assistant_enabled' => true, 'ai_branch.assistant_tenant_ids' => [$tenant->id], 'ai_branch.assistant_finance_enabled' => true]);
    foreach (['integrations', 'accounting_command_center', 'quickbooks'] as $module) {
        TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => $module, 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included_in_plan', 'entitlement_source' => 'test']);
    }
    $connection = \App\Models\IntegrationConnection::query()->create(['tenant_id' => $tenant->id, 'provider' => 'quickbooks', 'status' => 'connected', 'access_token' => 'fictional-secret']);
    $range = app(\App\Services\Accounting\AccountingDateRangeService::class)->resolve('current_month');
    \App\Models\QuickBooksReportingSnapshot::query()->create([
        'tenant_id' => $tenant->id, 'integration_connection_id' => $connection->id, 'range_key' => 'current_month',
        'period_start' => $range['starts_at'], 'period_end' => $range['ends_at'], 'observed_at' => now()->subDays(2),
        'metrics' => ['total_income' => '1000.01', 'total_expenses' => '200.02', 'net_income' => '799.99', 'accounting_method' => 'Cash', 'account_lines' => [['label' => 'PRIVATE LINE']]],
    ]);
    $summary = app(\App\Services\AiBranch\AiFinancialSummary::class)->capture($tenant, $user);
    expect($summary['available'])->toBeTrue()->and($summary['amounts_cents']['total_income'])->toBe(100001)
        ->and($summary['currency'])->toBeNull()->and($summary['basis'])->toBe('cash')->and($summary['stale'])->toBeTrue()
        ->and(json_encode($summary))->not->toContain('PRIVATE LINE', 'fictional-secret');
    $connection->update(['status' => 'disconnected']);
    $unavailable = app(\App\Services\AiBranch\AiFinancialSummary::class)->capture($tenant, $user);
    expect($unavailable['available'])->toBeFalse()->and($unavailable)->not->toHaveKey('amounts_cents');
    Http::assertNothingSent();
});

test('chat request replay is idempotent and changed inputs conflict', function (): void {
    [$tenant, $user] = aiBranchFixture();
    Http::fake(['api.openai.com/*' => Http::response(aiBranchResponse())]);
    $chat = app(AiBranchChat::class);
    $conversation = $chat->start($tenant, $user);
    $uuid = (string) Str::uuid();
    $first = $chat->ask($tenant, $user, $conversation->id, 'Hello', $uuid);
    expect($chat->ask($tenant, $user, $conversation->id, 'Hello', $uuid))->toBe($first);
    Http::assertSentCount(1);
    expect(fn () => $chat->ask($tenant, $user, $conversation->id, 'Changed', $uuid))->toThrow(HttpException::class);
    expect(TenantAiUsageEvent::query()->count())->toBe(1);
});

test('tenant and user cannot read or replay another private conversation', function (): void {
    [$tenant, $user] = aiBranchFixture();
    [$other, $otherUser] = aiBranchFixture('other');
    $conversation = app(AiBranchChat::class)->start($other, $otherUser);
    $this->actingAs($user)->getJson(route('ai-branch.index', ['tenant' => $tenant->slug, 'conversation' => $conversation->id]))->assertNotFound();
    $sameTenantUser = User::factory()->tenantAdmin()->create();
    $sameTenantUser->tenants()->attach($other->id, ['role' => 'owner', 'membership_active' => true]);
    $this->actingAs($sameTenantUser)->getJson(route('ai-branch.index', ['tenant' => $other->slug, 'conversation' => $conversation->id]))->assertNotFound();
    Http::assertNothingSent();
});

test('flags allowlist opt-in billing and membership independently fail closed', function (string $gate): void {
    [$tenant, $user] = aiBranchFixture();
    match ($gate) {
        'global' => config()->set('ai_branch.enabled', false),
        'allowlist' => config()->set('ai_branch.tenant_ids', []),
        'opt-in' => AiBranchAccount::query()->where('tenant_id', $tenant->id)->update(['enabled' => false]),
        'billing' => TenantAccessAddon::query()->where('tenant_id', $tenant->id)->update(['enabled' => false]),
        'source' => TenantAccessAddon::query()->where('tenant_id', $tenant->id)->update(['source' => 'client_request']),
        'expired' => TenantAccessAddon::query()->where('tenant_id', $tenant->id)->update(['ends_at' => now()->subSecond()]),
        'member' => $user->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]),
        'role' => $user->tenants()->updateExistingPivot($tenant->id, ['role' => 'member']),
        'inactive' => $user->forceFill(['is_active' => false])->save(),
    };
    expect(fn () => app(AiBranchChat::class)->start($tenant, $user))->toThrow(HttpException::class);
    Http::assertNothingSent();
})->with(['global', 'allowlist', 'opt-in', 'billing', 'source', 'expired', 'member', 'role', 'inactive']);

test('duplicate paid tiers fail closed instead of silently selecting a price', function (): void {
    [$tenant, $user] = aiBranchFixture();
    TenantAccessAddon::query()->create(['tenant_id' => $tenant->id, 'addon_key' => 'ai_branch_pro', 'enabled' => true, 'source' => 'stripe_fulfillment']);
    expect(fn () => app(AiBranchAccess::class)->authorize($tenant, $user))->toThrow(HttpException::class);
});

test('quota reserves worst-case cost and blocks a second call before external spending', function (): void {
    [$tenant, $user] = aiBranchFixture();
    AiBranchAccount::query()->where('tenant_id', $tenant->id)->update(['budget_cents' => 1]);
    $chat = app(AiBranchChat::class);
    $conversation = $chat->start($tenant, $user);
    $this->actingAs($user)->postJson(route('ai-branch.ask', ['tenant' => $tenant->slug, 'conversation' => $conversation->id]), ['question' => 'Hello', 'request_id' => (string) Str::uuid()])->assertStatus(429);
    expect(TenantAiUsageEvent::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('monthly response ceilings and UTC month rollover use committed usage', function (): void {
    [$tenant, $user] = aiBranchFixture();
    config()->set('module_catalog.addons.ai_branch_growth.ai_limits.responses', 1);
    $this->travelTo(now('UTC')->startOfMonth()->addDays(5));
    Http::fake(['api.openai.com/*' => Http::response(aiBranchResponse())]);
    $chat = app(AiBranchChat::class);
    $conversation = $chat->start($tenant, $user);
    $chat->ask($tenant, $user, $conversation->id, 'Hello', (string) Str::uuid());
    expect(fn () => $chat->ask($tenant, $user, $conversation->id, 'Again', (string) Str::uuid()))->toThrow(HttpException::class);
    $this->travelTo(now('UTC')->addMonth()->startOfMonth()->addDay());
    $conversation = $chat->start($tenant, $user);
    $chat->ask($tenant, $user, $conversation->id, 'New month', (string) Str::uuid());
    Http::assertSentCount(2);
});

test('ambiguous provider failures retain a reservation and cannot be retried', function (string $failure): void {
    [$tenant, $user] = aiBranchFixture();
    $reply = aiBranchResponse();
    if ($failure === 'usage') {
        unset($reply['usage']);
    }
    if ($failure === 'incomplete') {
        $reply['status'] = 'incomplete';
    }
    if ($failure === 'overflow') {
        $reply['usage']['output_tokens'] = 999999;
    }
    Http::fake(['api.openai.com/*' => $failure === 'timeout' ? Http::failedConnection() : Http::response($reply, $failure === 'http' ? 500 : 200)]);
    $chat = app(AiBranchChat::class);
    $conversation = $chat->start($tenant, $user);
    $uuid = (string) Str::uuid();
    expect(fn () => $chat->ask($tenant, $user, $conversation->id, 'Hello', $uuid))->toThrow(HttpException::class);
    $event = TenantAiUsageEvent::query()->sole();
    expect($event->status)->toBe('uncertain')->and($event->provider_cost_micros)->toBeGreaterThan(0)->and($conversation->fresh()->pending_uuid)->toBe($uuid);
    expect(fn () => $chat->ask($tenant, $user, $conversation->id, 'Hello', $uuid))->toThrow(HttpException::class);
    expect(TenantAiUsageEvent::query()->count())->toBe(1);
})->with(['http', 'timeout', 'usage', 'incomplete', 'overflow']);

test('an in-flight reservation is durable and serializes concurrent chat attempts', function (): void {
    [$tenant, $user] = aiBranchFixture();
    $chat = app(AiBranchChat::class);
    $conversation = $chat->start($tenant, $user);
    Http::fake(function () use ($chat, $tenant, $user, $conversation) {
        expect(TenantAiUsageEvent::query()->sole()->status)->toBe('reserved')->and(DB::transactionLevel())->toBe(1);
        // RefreshDatabase wraps the test in an outer transaction; the request's
        // reservation transaction has finished before dispatch.
        expect(fn () => $chat->ask($tenant, $user, $conversation->id, 'Concurrent', (string) Str::uuid()))->toThrow(HttpException::class);

        return Http::response(aiBranchResponse());
    });
    $chat->ask($tenant, $user, $conversation->id, 'First', (string) Str::uuid());
    Http::assertSentCount(1);
});

test('module revocation removes retrieval and invalidates previous chat context', function (): void {
    [$tenant, $user] = aiBranchFixture();
    $chat = app(AiBranchChat::class);
    $conversation = $chat->start($tenant, $user);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'enabled_status' => 'disabled']);
    expect(app(AiBranchContext::class)->sources($tenant, $user))->toBe([]);
    expect(fn () => app(AiBranchChat::class)->conversation($tenant, $user, $conversation->id))->toThrow(HttpException::class);
});

test('explicit personal memory is encrypted deletable and unavailable to other users', function (): void {
    [$tenant, $user] = aiBranchFixture();
    [$other, $otherUser] = aiBranchFixture('other');
    $conversation = app(AiBranchChat::class)->start($tenant, $user);
    $this->actingAs($user)->post(route('ai-branch.memory', ['tenant' => $tenant->slug]), ['body' => 'Use concise language', 'confirmed' => '1'])->assertRedirect();
    $memory = AiBranchMemory::query()->sole();
    expect(DB::table('ai_branch_memories')->value('body'))->not->toContain('Use concise language');
    $this->actingAs($otherUser)->deleteJson(route('ai-branch.memory.delete', ['tenant' => $other->slug, 'memory' => $memory->id]))->assertNotFound();
    expect(fn () => app(AiBranchChat::class)->conversation($tenant, $user, $conversation->id))->toThrow(HttpException::class);
    $this->actingAs($user)->delete(route('ai-branch.memory.delete', ['tenant' => $tenant->slug, 'memory' => $memory->id]))->assertRedirect();
    expect(AiBranchMemory::query()->count())->toBe(0);
});

test('note approval requires immutable preview and executes only once without delivery', function (): void {
    [$tenant, $user] = aiBranchFixture();
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Panel work', 'status' => 'scheduled']);
    $actions = app(AiBranchActions::class);
    $action = $actions->propose($tenant, $user, $job->id, 'Customer requested a callback draft', (string) Str::uuid());
    expect(FieldServiceJobNote::query()->count())->toBe(0)->and(DB::table('ai_branch_actions')->value('payload'))->not->toContain('callback');
    expect(fn () => $actions->decide($tenant, $user, $action->id, str_repeat('0', 64), true))->toThrow(HttpException::class);
    $actions->decide($tenant, $user, $action->id, $action->digest, true);
    $actions->decide($tenant, $user, $action->id, $action->digest, true);
    expect(FieldServiceJobNote::query()->count())->toBe(1)->and($job->fresh()->status)->toBe('scheduled')
        ->and(FieldServiceJobNote::query()->sole()->metadata['visibility'])->toBe('owner');
    Http::assertNothingSent();
});

test('an unchanged AI internal note has one audited compensating undo', function (): void {
    [$tenant, $user] = aiBranchFixture();
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Fictional panel work', 'status' => 'scheduled']);
    $actions = app(AiBranchActions::class);
    $action = $actions->propose($tenant, $user, $job->id, 'Reviewed fictional note', (string) Str::uuid());
    $actions->decide($tenant, $user, $action->id, $action->digest, true);
    $uuid = (string) Str::uuid();
    $receipt = $actions->undo($tenant, $user, $action->id, $action->digest, $uuid);
    expect($actions->undo($tenant, $user, $action->id, $action->digest, $uuid)->id)->toBe($receipt->id)
        ->and(FieldServiceJobNote::query()->count())->toBe(0)->and(DB::table('field_service_job_notes')->count())->toBe(1)->and($action->fresh()->status)->toBe('reversed')
        ->and(\App\Models\LandlordOperatorAction::query()->where('action_type', 'ai_branch.note_undo')->count())->toBe(1);
    Http::assertNothingSent();
});

test('legacy review previews and replay receipts honor revoked job access', function (): void {
    [$tenant, $user] = aiBranchFixture();
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Fictional job', 'status' => 'open']);
    $actions = app(AiBranchActions::class);
    $pending = $actions->propose($tenant, $user, $job->id, 'PRIVATE PENDING PREVIEW', (string) Str::uuid());
    $executed = $actions->propose($tenant, $user, $job->id, 'Reviewed internal note', (string) Str::uuid());
    $actions->decide($tenant, $user, $executed->id, $executed->digest, true);
    TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'enabled_status' => 'disabled']);
    $this->actingAs($user)->get(route('ai-branch.index', ['tenant' => $tenant->slug]))->assertOk()->assertDontSee('PRIVATE PENDING PREVIEW');
    expect(fn () => $actions->decide($tenant, $user, $executed->id, $executed->digest, true))->toThrow(HttpException::class);
    expect($pending->fresh()->status)->toBe('pending')->and(FieldServiceJobNote::count())->toBe(1);
});

test('intervening note edits prevent undo even if the text is subsequently restored', function (): void {
    [$tenant, $user] = aiBranchFixture();
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Fictional panel work', 'status' => 'scheduled']);
    $actions = app(AiBranchActions::class);
    $action = $actions->propose($tenant, $user, $job->id, 'Original', (string) Str::uuid());
    $actions->decide($tenant, $user, $action->id, $action->digest, true);
    $note = FieldServiceJobNote::query()->sole();
    $note->update(['body' => 'A teammate edited this']);
    $note->update(['body' => 'Original']);
    expect(fn () => $actions->undo($tenant, $user, $action->id, $action->digest, (string) Str::uuid()))->toThrow(HttpException::class);
    expect(FieldServiceJobNote::query()->count())->toBe(1)->and($action->fresh()->status)->toBe('executed');
    Http::assertNothingSent();
});

test('stale expired revoked and cross-tenant action approvals fail closed', function (string $failure): void {
    [$tenant, $user] = aiBranchFixture();
    [$other, $otherUser] = aiBranchFixture('other');
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Panel work', 'status' => 'open']);
    $actions = app(AiBranchActions::class);
    $action = $actions->propose($tenant, $user, $job->id, 'Reviewed note', (string) Str::uuid());
    match ($failure) {
        'stale' => $job->forceFill(['title' => 'Changed job'])->save(),
        'expired' => $action->forceFill(['expires_at' => now()->subMinute()])->save(),
        'revoked' => $user->tenants()->updateExistingPivot($tenant->id, ['role' => 'member']),
        'billing' => TenantAccessAddon::query()->where('tenant_id', $tenant->id)->update(['enabled' => false]),
        'cross-tenant' => null,
    };
    if ($failure === 'cross-tenant') {
        $this->actingAs($otherUser)->postJson(route('ai-branch.decide', ['tenant' => $other->slug, 'action' => $action->id]), ['digest' => $action->digest, 'decision' => 'approve', 'confirmed' => '1'])->assertNotFound();
    } else {
        expect(fn () => $actions->decide($tenant, $user, $action->id, $action->digest, true))->toThrow(HttpException::class);
    }
    expect(FieldServiceJobNote::query()->count())->toBe(0);
})->with(['stale', 'expired', 'revoked', 'billing', 'cross-tenant']);

test('workspace escapes generated HTML and exposes explicit settings and review controls', function (): void {
    [$tenant, $user] = aiBranchFixture();
    Http::fake(['api.openai.com/*' => Http::response(aiBranchResponse('<script>alert(1)</script>'))]);
    $chat = app(AiBranchChat::class);
    $conversation = $chat->start($tenant, $user);
    $chat->ask($tenant, $user, $conversation->id, 'Draft text', (string) Str::uuid());
    $this->actingAs($user)->get(route('ai-branch.index', ['tenant' => $tenant->slug, 'conversation' => $conversation->id]))
        ->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
        ->assertSeeText('Your saved memory')->assertSeeText('Review an internal job note');
});

test('catalog preserves prices and blocks sales before commercial approval', function (): void {
    foreach (['starter' => 4900, 'growth' => 9900, 'pro' => 19900] as $tier => $price) {
        expect(config('module_catalog.addons.ai_branch_'.$tier.'.pricing.recurring_price_cents'))->toBe($price)
            ->and(\App\Support\Tenancy\CatalogPurchasePolicy::addonEnabled('ai_branch_'.$tier))->toBeFalse();
    }
    expect(config('module_catalog.modules.ai_branch.default_enabled'))->toBeFalse()
        ->and(config('module_catalog.modules.ai_branch.visibility.public_site'))->toBeFalse()
        ->and(config('bud.core_enabled'))->toBeTrue();
});

test('failed payment subscription expiry and duplicate subscriptions block usage immediately', function (string $failure): void {
    [$tenant, $user] = aiBranchFixture();
    $row = \App\Models\TenantBillingSubscription::query()->sole();
    if ($failure === 'duplicate') {
        $copy = $row->replicate();
        $copy->provider_subscription_reference = 'sub_duplicate';
        $copy->save();
    } elseif ($failure === 'expired') {
        $row->forceFill(['current_period_ends_at' => now()->subSecond()])->save();
    } else {
        $row->forceFill(['status' => $failure])->save();
    }
    expect(fn () => app(AiBranchAccess::class)->authorize($tenant, $user))->toThrow(HttpException::class);
    Http::assertNothingSent();
})->with(['past_due', 'canceled', 'unpaid', 'expired', 'duplicate']);

test('verified Stripe events grant AI once and cancellation revokes it without changing another tenant', function (): void {
    [$tenant, $user] = aiBranchFixture();
    [$other, $otherUser] = aiBranchFixture('other');
    TenantAccessAddon::query()->where('tenant_id', $tenant->id)->delete();
    \App\Models\TenantBillingSubscription::query()->where('tenant_id', $tenant->id)->delete();
    config()->set(['services.stripe.webhook_secret' => 'whsec_ai_test', 'commercial.billing_readiness.lifecycle_mutations_enabled' => true]);
    $service = app(\App\Services\Billing\StripeWebhookIngestService::class);
    $send = function (array $event) use ($service): array {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_ai_test');

        return $service->ingest($payload, $signature);
    };
    $event = ['id' => 'evt_ai_paid', 'type' => 'checkout.session.completed', 'created' => time(), 'livemode' => false, 'data' => ['object' => [
        'id' => 'cs_ai_paid', 'object' => 'checkout.session', 'payment_status' => 'paid', 'customer' => 'cus_ai', 'subscription' => 'sub_ai',
        'metadata' => ['tenant_id' => (string) $tenant->id, 'tenant_slug' => $tenant->slug, 'checkout_plan_key' => 'base', 'addons_interest' => 'ai_branch_growth'],
    ]]];
    expect($send($event)['ok'])->toBeTrue();
    expect($send($event)['ok'])->toBeTrue();
    expect(TenantAccessAddon::query()->where('tenant_id', $tenant->id)->where('addon_key', 'ai_branch_growth')->where('source', 'stripe_fulfillment')->count())->toBe(1);
    expect(app(AiBranchAccess::class)->authorize($tenant, $user)['purchase_key'])->toBe('addon.ai_branch_growth');
    $event['id'] = 'evt_ai_cancel';
    $event['type'] = 'customer.subscription.deleted';
    $event['data']['object'] = ['id' => 'sub_ai', 'object' => 'subscription', 'customer' => 'cus_ai', 'status' => 'canceled', 'metadata' => ['tenant_id' => (string) $tenant->id, 'checkout_plan_key' => 'base', 'addons_interest' => 'ai_branch_growth']];
    expect($send($event)['ok'])->toBeTrue();
    expect(fn () => app(AiBranchAccess::class)->authorize($tenant, $user))->toThrow(HttpException::class);
    expect(app(AiBranchAccess::class)->authorize($other, $otherUser)['purchase_key'])->toBe('addon.ai_branch_growth');
    Http::assertNothingSent();
});

test('starter supports chat but cannot execute notes', function (): void {
    [$tenant, $user] = aiBranchFixture();
    TenantAccessAddon::query()->where('tenant_id', $tenant->id)->update(['addon_key' => 'ai_branch_starter']);
    \App\Models\TenantBillingSubscription::query()->where('tenant_id', $tenant->id)->update(['purchase_key' => 'addon.ai_branch_starter']);
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Small job', 'status' => 'open']);
    expect(app(AiBranchAccess::class)->authorize($tenant, $user)['pricing']['recurring_price_cents'])->toBe(4900);
    expect(fn () => app(AiBranchActions::class)->propose($tenant, $user, $job->id, 'Note', (string) Str::uuid()))->toThrow(HttpException::class);
});

test('settings require explicit approval and audit before and after budget with no subscription mutation', function (): void {
    [$tenant, $user] = aiBranchFixture();
    $this->actingAs($user)->postJson(route('ai-branch.settings', ['tenant' => $tenant->slug]), ['enabled' => true, 'budget_cents' => 1000])->assertUnprocessable();
    $this->actingAs($user)->post(route('ai-branch.settings', ['tenant' => $tenant->slug]), ['enabled' => false, 'budget_cents' => 1000, 'confirmed' => '1'])->assertRedirect();
    expect(AiBranchAccount::query()->sole()->enabled)->toBeFalse()->and(\App\Models\LandlordOperatorAction::query()->where('action_type', 'ai_branch.opt_in')->count())->toBe(1);
    Http::assertNothingSent();
});

test('retention deletes expired private content while retaining usage and redacting approval evidence', function (): void {
    [$tenant, $user] = aiBranchFixture();
    $conversation = app(AiBranchChat::class)->start($tenant, $user);
    $conversation->forceFill(['expires_at' => now()->subDay()])->save();
    AiBranchMemory::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'body' => 'Expired preference', 'expires_at' => now()->subDay()]);
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Small job', 'status' => 'open']);
    $action = app(AiBranchActions::class)->propose($tenant, $user, $job->id, 'Private draft', (string) Str::uuid());
    $action->forceFill(['expires_at' => now()->subDays(31)])->save();
    $this->artisan('ai-branch:prune')->assertSuccessful();
    expect(AiBranchConversation::query()->count())->toBe(1);
    $this->artisan('ai-branch:prune', ['--apply' => true])->assertSuccessful();
    expect(AiBranchConversation::query()->count())->toBe(0)->and(AiBranchMemory::query()->count())->toBe(0)
        ->and($action->fresh()->payload)->toBe(['redacted' => true])->and($action->fresh()->digest)->toBe($action->digest);
});

test('uncertain usage reconciliation previews before applying and never makes a provider call', function (): void {
    [$tenant, $user] = aiBranchFixture();
    Http::fake(['api.openai.com/*' => Http::response([], 500)]);
    $chat = app(AiBranchChat::class);
    $conversation = $chat->start($tenant, $user);
    expect(fn () => $chat->ask($tenant, $user, $conversation->id, 'Hello', (string) Str::uuid()))->toThrow(HttpException::class);
    $event = TenantAiUsageEvent::query()->sole();
    $event->forceFill(['created_at' => now()->subMinutes(3)])->save();
    $operator = User::factory()->create(['role' => 'platform_admin', 'is_active' => true]);
    $arguments = ['event' => $event->id, '--actor' => $operator->id, '--evidence' => 'provider-case-123', '--no-charge' => true];
    $this->artisan('ai-branch:reconcile-usage', $arguments)->assertSuccessful();
    expect($event->fresh()->status)->toBe('uncertain');
    $this->artisan('ai-branch:reconcile-usage', [...$arguments, '--apply' => true])->assertSuccessful();
    expect($event->fresh()->status)->toBe('refunded')->and($event->fresh()->provider_cost_micros)->toBe(0)->and($conversation->fresh()->pending_uuid)->toBeNull();
    Http::assertSentCount(1);
});

test('data access revoked during a provider call prevents returning the answer but retains cost accounting', function (): void {
    [$tenant, $user] = aiBranchFixture();
    $chat = app(AiBranchChat::class);
    $conversation = $chat->start($tenant, $user);
    Http::fake(function () use ($tenant) {
        TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'enabled_status' => 'disabled']);

        return Http::response(aiBranchResponse());
    });
    expect(fn () => $chat->ask($tenant, $user, $conversation->id, 'Summarize', (string) Str::uuid()))->toThrow(HttpException::class);
    expect(TenantAiUsageEvent::query()->sole()->status)->toBe('settled');
});

test('AI internal notes do not enter the existing metered voice invoice lane', function (): void {
    [$tenant, $user] = aiBranchFixture();
    Http::fake(['api.openai.com/*' => Http::response(aiBranchResponse())]);
    $chat = app(AiBranchChat::class);
    $conversation = $chat->start($tenant, $user);
    $chat->ask($tenant, $user, $conversation->id, 'Hello', (string) Str::uuid());
    TenantAiUsageEvent::query()->update(['occurred_at' => now()->subMonths(2)]);
    $result = app(\App\Services\Billing\TenantAiUsageInvoiceService::class)->invoiceClosedMonths($tenant->slug, false);
    expect($result['groups'])->toBe(0)->and($result['drafts_created'])->toBe(0);
    Http::assertSentCount(1);
});

test('workspace spending caps accept dollars with exact cents and reject ambiguous amounts', function (): void {
    [$tenant, $user] = aiBranchFixture();
    $url = route('ai-branch.settings', ['tenant' => $tenant->slug]);
    $this->actingAs($user)->post($url, ['enabled' => true, 'budget_dollars' => '12.34', 'confirmed' => '1'])->assertRedirect();
    expect(AiBranchAccount::query()->sole()->budget_cents)->toBe(1234);
    $this->postJson($url, ['enabled' => true, 'budget_dollars' => '12.345', 'confirmed' => '1'])->assertUnprocessable();
    $this->postJson($url, ['enabled' => true, 'budget_dollars' => '1', 'budget_cents' => 200, 'confirmed' => '1'])->assertUnprocessable();
});
