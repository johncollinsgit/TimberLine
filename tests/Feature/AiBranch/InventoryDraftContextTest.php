<?php

use App\Models\AiBranchAccount;
use App\Models\AiBranchMemory;
use App\Models\FieldServiceJob;
use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantAiUsageEvent;
use App\Models\TenantBillingSubscription;
use App\Models\User;
use App\Services\AiBranch\AiBranchChat;
use App\Services\AiBranch\AiBranchProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

function inventoryAiFixture(bool $enhanced = true): array
{
    $tenant = Tenant::create(['name' => 'Fictional inventory workspace', 'slug' => 'inventory-ai-'.Str::lower(Str::random(8))]);
    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantAccessAddon::create(['tenant_id' => $tenant->id, 'addon_key' => 'ai_branch_growth', 'enabled' => true, 'source' => 'stripe_fulfillment']);
    TenantBillingSubscription::create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_subscription_reference' => 'sub_'.$tenant->slug,
        'purchase_key' => 'addon.ai_branch_growth', 'status' => 'active', 'current_period_ends_at' => now()->addMonths(2)]);
    AiBranchAccount::create(['tenant_id' => $tenant->id, 'enabled' => true, 'budget_cents' => 1500]);
    config(['ai_branch.enabled' => true, 'ai_branch.tenant_ids' => [$tenant->id], 'ai_branch.api_key' => 'fictional-test-key',
        'ai_branch.model' => 'test-model', 'ai_branch.input_micros_per_million' => 1000000, 'ai_branch.output_micros_per_million' => 4000000,
        'ai_branch.workspace_context_enabled' => $enhanced, 'ai_branch.workspace_context_tenant_ids' => [$tenant->id]]);

    return [$tenant, $user, app(AiBranchChat::class)];
}

function inventoryAiResponse(): array
{
    return ['id' => 'resp_inventory_test', 'status' => 'completed',
        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text',
            'text' => '{"rows":[{"label":"FFI","catalog_index":0,"quantity":12,"uncertain":false}]}']]]],
        'usage' => ['input_tokens' => 100, 'output_tokens' => 30]];
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('dispatches only the current inventory draft even with saved workspace evidence memory and reused history', function (bool $enhanced): void {
    [$tenant, $user, $chat] = inventoryAiFixture($enhanced);
    FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'SQUARE_SOURCE_JOB_SENTINEL', 'status' => 'open']);
    AiBranchMemory::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'body' => 'SQUARE_MEMORY_SENTINEL', 'expires_at' => now()->addDay()]);
    $conversation = $chat->startInventoryDraft($tenant, $user);
    $conversation->update(['messages' => [['role' => 'assistant', 'uuid' => (string) Str::uuid(), 'text' => 'SQUARE_HISTORY_SENTINEL', 'sources' => []]]]);
    $question = "CATALOG:\n0=Fictional Fir 8oz\nOCR:\nFFI 12";
    Http::fake(['api.openai.com/*' => Http::response(inventoryAiResponse())]);
    $uuid = (string) Str::uuid();
    $result = $chat->inventoryDraft($tenant, $user, $conversation->id, $question, $uuid);
    expect($chat->inventoryDraft($tenant, $user, $conversation->id, $question, $uuid))->toBe($result);
    $chat->inventoryDraft($tenant, $user, $conversation->id, $question."\nReviewed", (string) Str::uuid());
    Http::assertSentCount(2);
    foreach (Http::recorded() as [$request]) {
        expect($request->url())->toBe('https://api.openai.com/v1/responses')
            ->and($request['input'])->toHaveCount(1)
            ->and($request['input'][0]['role'])->toBe('user')
            ->and($request['input'][0]['content'])->toBeIn([$question, $question."\nReviewed"])
            ->and($request['instructions'])->toBe(AiBranchProvider::INVENTORY_DRAFT_INSTRUCTIONS)
            ->and($request['store'])->toBeFalse()
            ->and($request->data())->not->toHaveKeys(['tools', 'previous_response_id'])
            ->and(json_encode($request->data()))->not->toContain('SQUARE_SOURCE_JOB_SENTINEL', 'SQUARE_MEMORY_SENTINEL', 'SQUARE_HISTORY_SENTINEL', 'untrusted_workspace_context', 'untrusted_workspace_evidence', 'untrusted_personal_memory', 'Cite exact source refs');
    }
    $event = TenantAiUsageEvent::where('client_uuid', $uuid)->sole();
    expect($result['sources'])->toBe([])->and($result)->not->toHaveKey('workspace_context')
        ->and($result['purpose'])->toBe(AiBranchChat::INVENTORY_DRAFT_PURPOSE)
        ->and($event->context)->toBe(AiBranchChat::INVENTORY_DRAFT_PURPOSE)
        ->and(data_get($event->metadata, 'purpose'))->toBe(AiBranchChat::INVENTORY_DRAFT_PURPOSE)
        ->and(data_get($event->metadata, 'adapter_counts'))->toBe([])
        ->and(data_get($event->metadata, 'request_hash'))->toBe(hash('sha256', json_encode([$user->id, $conversation->id, $question, AiBranchChat::INVENTORY_DRAFT_PURPOSE], JSON_THROW_ON_ERROR)))
        ->and($event->status)->toBe('settled')->and($event->provider_cost_micros)->toBe(220)
        ->and($chat->usage($tenant)['responses'])->toBe(2);
})->with([true, false]);

it('separates inventory conversations and request fingerprints from ordinary chat and other identities', function (): void {
    [$tenant, $user, $chat] = inventoryAiFixture();
    $ordinary = $chat->start($tenant, $user);
    $inventory = $chat->startInventoryDraft($tenant, $user);
    Http::fake(['api.openai.com/*' => Http::response(inventoryAiResponse())]);
    $uuid = (string) Str::uuid();
    $chat->inventoryDraft($tenant, $user, $inventory->id, 'CATALOG: 0=Fir; OCR: F 12', $uuid);
    expect(fn () => $chat->inventoryDraft($tenant, $user, $inventory->id, 'Changed', $uuid))->toThrow(HttpException::class)
        ->and(fn () => $chat->ask($tenant, $user, $inventory->id, 'CATALOG: 0=Fir; OCR: F 12', $uuid))->toThrow(HttpException::class)
        ->and(fn () => $chat->inventoryDraft($tenant, $user, $ordinary->id, 'CATALOG: 0=Fir; OCR: F 12', (string) Str::uuid()))->toThrow(HttpException::class)
        ->and(fn () => $chat->ask($tenant, $user, $inventory->id, 'Hello', (string) Str::uuid()))->toThrow(HttpException::class);
    $other = User::factory()->tenantAdmin()->create();
    $other->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    expect(fn () => $chat->inventoryDraft($tenant, $other, $inventory->id, 'CATALOG: 0=Fir; OCR: F 12', $uuid))->toThrow(HttpException::class);
    Http::assertSentCount(1);
    expect(TenantAiUsageEvent::count())->toBe(1);
});

it('keeps inventory history and its quota separate from ordinary web and native chat lists', function (): void {
    $this->withoutVite();
    [$tenant, $user, $chat] = inventoryAiFixture(false);
    $inventory = $chat->startInventoryDraft($tenant, $user);
    Http::fake(['api.openai.com/*' => Http::response(inventoryAiResponse())]);
    $uuid = (string) Str::uuid();
    $question = 'CATALOG: 0=Fir; OCR: F 12';
    $draft = $chat->inventoryDraft($tenant, $user, $inventory->id, $question, $uuid);
    $ordinaryIds = [];
    for ($index = 0; $index < 30; $index++) {
        $ordinaryIds[] = $chat->start($tenant, $user)->id;
    }
    expect(fn () => $chat->start($tenant, $user))->toThrow(HttpException::class);
    // Newer inventory records must be filtered in SQL before the list's limit.
    for ($index = 1; $index < 30; $index++) {
        $chat->startInventoryDraft($tenant, $user);
    }
    expect(fn () => $chat->startInventoryDraft($tenant, $user))->toThrow(HttpException::class);
    $this->actingAs($user)->get(route('ai-branch.index', ['tenant' => $tenant->slug]))->assertOk()
        ->assertViewHas('conversations', fn ($rows) => $rows->pluck('id')->all() === array_reverse($ordinaryIds));
    config(['ai_branch.assistant_enabled' => true, 'ai_branch.assistant_tenant_ids' => [$tenant->id]]);
    \Laravel\Sanctum\Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);
    $native = $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/ai-assistant')->assertOk()->json('conversations');
    expect(array_column($native, 'id'))->toBe(array_reverse($ordinaryIds))
        ->and($chat->inventoryDraft($tenant, $user, $inventory->id, $question, $uuid))->toBe($draft)
        ->and($chat->usage($tenant)['responses'])->toBe(1);
    Http::assertSentCount(1);
});

it('retains the existing approved allowance gate before any inventory provider dispatch', function (): void {
    [$tenant, $user, $chat] = inventoryAiFixture();
    AiBranchAccount::where('tenant_id', $tenant->id)->update(['budget_cents' => 1]);
    $conversation = $chat->startInventoryDraft($tenant, $user);
    expect(fn () => $chat->inventoryDraft($tenant, $user, $conversation->id, 'CATALOG: 0=Fir; OCR: F 12', (string) Str::uuid()))->toThrow(HttpException::class);
    expect(TenantAiUsageEvent::count())->toBe(0)->and($conversation->fresh()->pending_uuid)->toBeNull();
    Http::assertNothingSent();
});

it('preserves the existing ordinary chat replay fingerprint', function (bool $enhanced): void {
    [$tenant, $user, $chat] = inventoryAiFixture($enhanced);
    $conversation = $chat->start($tenant, $user);
    Http::fake(['api.openai.com/*' => Http::response(inventoryAiResponse())]);
    $uuid = (string) Str::uuid();
    $question = 'Summarize the available workspace sample.';
    $response = $chat->ask($tenant, $user, $conversation->id, $question, $uuid);
    $originalInput = $enhanced ? [$user->id, $conversation->id, $question, 'chat', ['kind' => 'workspace']]
        : [$user->id, $conversation->id, $question];
    expect(data_get(TenantAiUsageEvent::sole()->metadata, 'request_hash'))->toBe(hash('sha256', json_encode($originalInput, JSON_THROW_ON_ERROR)))
        ->and($chat->ask($tenant, $user, $conversation->id, $question, $uuid))->toBe($response);
    Http::assertSentCount(1);
})->with([true, false]);

it('keeps ambiguous inventory provider usage reserved and never dispatches the same request again', function (): void {
    [$tenant, $user, $chat] = inventoryAiFixture();
    $conversation = $chat->startInventoryDraft($tenant, $user);
    Http::fake(fn () => throw new ConnectionException('Fictional interrupted response'));
    $uuid = (string) Str::uuid();
    expect(fn () => $chat->inventoryDraft($tenant, $user, $conversation->id, 'CATALOG: 0=Fir; OCR: F 12', $uuid))->toThrow(HttpException::class);
    $event = TenantAiUsageEvent::sole();
    expect($event->status)->toBe('uncertain')->and($event->provider_cost_micros)->toBeGreaterThan(0)
        ->and($conversation->fresh()->pending_uuid)->toBe($uuid);
    Http::fake(fn () => throw new LogicException('A pending inventory draft must not redispatch'));
    expect(fn () => $chat->inventoryDraft($tenant, $user, $conversation->id, 'CATALOG: 0=Fir; OCR: F 12', $uuid))->toThrow(HttpException::class);
    expect(TenantAiUsageEvent::count())->toBe(1);
    Http::assertNothingSent();
});

it('settles actual inventory usage but withholds the response after current authorization is revoked', function (string $gate): void {
    [$tenant, $user, $chat] = inventoryAiFixture();
    $conversation = $chat->startInventoryDraft($tenant, $user);
    Http::fake(function () use ($tenant, $user, $gate) {
        if ($gate === 'opt-in') {
            AiBranchAccount::where('tenant_id', $tenant->id)->update(['enabled' => false]);
        } else {
            $user->tenants()->updateExistingPivot($tenant->id, ['membership_active' => false]);
        }

        return Http::response(inventoryAiResponse());
    });
    $uuid = (string) Str::uuid();
    expect(fn () => $chat->inventoryDraft($tenant, $user, $conversation->id, 'CATALOG: 0=Fir; OCR: F 12', $uuid))->toThrow(HttpException::class);
    expect(TenantAiUsageEvent::sole()->status)->toBe('settled')->and(TenantAiUsageEvent::sole()->provider_cost_micros)->toBe(220);
    expect(fn () => $chat->inventoryDraft($tenant, $user, $conversation->id, 'CATALOG: 0=Fir; OCR: F 12', $uuid))->toThrow(HttpException::class);
    Http::assertSentCount(1);
})->with(['opt-in', 'membership']);
