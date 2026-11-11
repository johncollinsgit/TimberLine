<?php

use App\Models\AiBranchAccount;
use App\Models\AiBranchConversation;
use App\Models\CustomerLoopAction;
use App\Models\CustomerLoopActivity;
use App\Models\FieldServiceJob;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantAiUsageEvent;
use App\Models\TenantBillingSubscription;
use App\Models\User;
use App\Services\AiBranch\AiCustomerEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function squareAiBoundaryTenant(): array
{
    $uuid = (string) Str::uuid();
    $tenant = Tenant::create(['name' => 'Fictional AI boundary', 'slug' => 'ai-square-boundary-'.$uuid]);
    $owner = User::factory()->tenantAdmin()->create(['email' => 'ai-square-boundary-'.$uuid.'@example.invalid']);
    $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test',
        'metadata' => ['tenant_blueprint' => ['business_template' => 'field_service'], 'ai_branch' => ['timezone' => 'America/New_York']]]);
    TenantAccessAddon::create(['tenant_id' => $tenant->id, 'addon_key' => 'ai_branch_growth', 'enabled' => true, 'source' => 'stripe_fulfillment']);
    TenantBillingSubscription::create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_subscription_reference' => 'sub_'.$uuid,
        'purchase_key' => 'addon.ai_branch_growth', 'status' => 'active', 'current_period_ends_at' => now()->addMonths(2)]);
    AiBranchAccount::create(['tenant_id' => $tenant->id, 'enabled' => true, 'budget_cents' => 1500]);
    config(['ai_branch.tenant_ids' => [...config('ai_branch.tenant_ids', []), $tenant->id],
        'ai_branch.workspace_context_tenant_ids' => [...config('ai_branch.workspace_context_tenant_ids', []), $tenant->id]]);

    return [$tenant, $owner];
}

function squareAiBoundaryResponse(): array
{
    return ['id' => 'fictional_square_boundary_response', 'status' => 'completed',
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Review permitted local work.']]]],
        'usage' => ['input_tokens' => 100, 'output_tokens' => 30]];
}

function squareAiBoundarySource(Tenant $tenant, string $tag, string $provenance = 'manual'): array
{
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id,
        'first_name' => $provenance === 'manual' ? 'ALLOWED-MANUAL-CUSTOMER-'.$tag : 'BLOCKED-CUSTOMER-'.$tag,
        'last_name' => 'Fixture', 'source_channels' => match ($provenance) {
            'channel' => ['Square'], 'legacy_channel' => ['legacy_square_marketing'], default => ['manual'],
        }]);
    if (in_array($provenance, ['linked', 'legacy_link'], true)) {
        MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id,
            'source_type' => $provenance === 'legacy_link' ? 'square_marketing_contact' : 'square_customer', 'source_id' => 'fictional-'.Str::uuid()]);
    } elseif ($provenance === 'unowned') {
        // Preserve an original legacy source row with no assigned tenant; do not claim or rewrite it.
        DB::table('marketing_profile_links')->insert(['tenant_id' => null, 'marketing_profile_id' => $profile->id,
            'source_type' => 'square_payment', 'source_id' => 'fictional-'.Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
    } elseif (in_array($provenance, ['alias', 'legacy_alias'], true)) {
        $alias = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'BLOCKED-RETAINED-ALIAS-'.$tag,
            'source_channels' => ['manual'], 'merged_into_profile_id' => $profile->id, 'merged_at' => now(), 'archived_at' => now()]);
        MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $alias->id,
            'source_type' => $provenance === 'legacy_alias' ? 'square_marketing_contact' : 'square_order', 'source_id' => 'fictional-'.Str::uuid()]);
    }
    FieldServiceJob::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id,
        'title' => 'Independent locally entered work', 'status' => 'blocked']);
    $action = CustomerLoopAction::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id,
        'title' => ($provenance === 'manual' ? 'ALLOWED-MANUAL-FOLLOWUP-' : 'BLOCKED-FOLLOWUP-').$tag,
        'action_type' => 'follow_up', 'reason' => 'Fictional boundary fixture', 'status' => 'suggested',
        'due_at' => $provenance === 'manual' ? now()->subDays(2) : now()->subDay()]);
    $activity = CustomerLoopActivity::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id,
        'source_type' => 'manual', 'event_key' => 'fictional-boundary-'.Str::uuid(), 'title' => 'Private local activity', 'occurred_at' => now()]);

    return compact('profile', 'action', 'activity');
}

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['ai_branch.enabled' => true, 'ai_branch.workspace_context_enabled' => true, 'ai_branch.api_key' => 'fictional-key',
        'ai_branch.model' => 'test-model', 'ai_branch.input_micros_per_million' => 1000000, 'ai_branch.output_micros_per_million' => 4000000]);
    // Retain the real guard instance across HTTP requests to prove fresh provenance admission.
    app()->instance(AiCustomerEvidence::class, app(AiCustomerEvidence::class));
});

it('keeps owned retained channel and alias Square customer evidence out of ordinary chat and briefing transport', function (array $modes): void {
    [$tenant, $owner] = squareAiBoundaryTenant();
    $manual = squareAiBoundarySource($tenant, 'CONTROL');
    $manual['profile']->update(['source_channels' => ['manual', 'squarespace', 'Squarebox']]);
    $forbidden = [];
    foreach ($modes as $mode) {
        $source = squareAiBoundarySource($tenant, strtoupper($mode), $mode);
        $forbidden[] = $source['profile']->first_name;
        $forbidden[] = $source['action']->title;
    }
    [$foreign] = squareAiBoundaryTenant();
    $foreignSource = squareAiBoundarySource($foreign, 'FOREIGN', 'linked');
    $forbidden[] = $foreignSource['profile']->first_name;
    $forbidden[] = $foreignSource['action']->title;
    $forbidden[] = 'BLOCKED-RETAINED-ALIAS-ALIAS';
    Http::fake(['api.openai.com/*' => Http::response(squareAiBoundaryResponse())]);
    $conversation = $this->actingAs($owner)->postJson('/ai-branch/conversations?tenant='.$tenant->slug)->assertCreated()->json('conversation_id');
    $chat = $this->postJson('/ai-branch/conversations/'.$conversation.'/messages?tenant='.$tenant->slug,
        ['question' => 'What needs attention?', 'request_id' => (string) Str::uuid()])->assertOk();
    $briefing = $this->postJson(route('ai-branch.briefings', ['tenant' => $tenant->slug]), ['request_id' => (string) Str::uuid()])->assertOk();
    foreach ([$chat->json(), $briefing->json()] as $response) {
        expect(json_encode($response))->toContain($manual['profile']->first_name);
        foreach ($forbidden as $value) {
            expect(json_encode($response))->not->toContain($value);
        }
    }
    Http::assertSentCount(2);
    foreach (Http::recorded() as [$request]) {
        $payload = json_encode($request->data(), JSON_THROW_ON_ERROR);
        expect($payload)->toContain($manual['profile']->first_name, $manual['action']->title)
            ->and($request['store'])->toBeFalse();
        foreach ($forbidden as $value) {
            expect($payload)->not->toContain($value);
        }
    }
})->with(['original sources' => [['linked', 'unowned', 'channel', 'alias']],
    'legacy sources independently marked' => [['legacy_channel', 'legacy_link', 'legacy_alias']]]);

it('denies Square customer and followup page origins before any model dispatch', function (string $mode, string $kind): void {
    [$tenant, $owner] = squareAiBoundaryTenant();
    $source = squareAiBoundarySource($tenant, 'PAGE', $mode);
    $id = $kind === 'customer' ? $source['profile']->id : $source['action']->id;
    $conversation = $this->actingAs($owner)->postJson('/ai-branch/conversations?tenant='.$tenant->slug)->assertCreated()->json('conversation_id');
    $this->postJson('/ai-branch/conversations/'.$conversation.'/messages?tenant='.$tenant->slug,
        ['question' => 'Describe this page', 'request_id' => (string) Str::uuid(), 'origin' => ['kind' => $kind, 'id' => $id]])->assertForbidden();
    $this->postJson(route('ai-branch.briefings', ['tenant' => $tenant->slug]),
        ['request_id' => (string) Str::uuid(), 'origin' => ['kind' => $kind, 'id' => $id]])->assertForbidden();
    expect(TenantAiUsageEvent::where('tenant_id', $tenant->id)->count())->toBe(0);
    Http::assertNothingSent();
})->with([['linked', 'customer'], ['unowned', 'customer'], ['channel', 'customer'], ['alias', 'customer'],
    ['linked', 'followup'], ['unowned', 'followup'], ['channel', 'followup'], ['alias', 'followup'],
    ['legacy_channel', 'customer'], ['legacy_link', 'customer'], ['legacy_alias', 'customer'],
    ['legacy_channel', 'followup'], ['legacy_link', 'followup'], ['legacy_alias', 'followup']]);

it('revokes saved customer followup and activity history immediately when a new Square source arrives', function (string $type, string $provenance): void {
    [$tenant, $owner] = squareAiBoundaryTenant();
    $source = squareAiBoundarySource($tenant, 'NEW-LINK');
    Http::fake(['api.openai.com/*' => Http::response(squareAiBoundaryResponse())]);
    $conversation = $this->actingAs($owner)->postJson('/ai-branch/conversations?tenant='.$tenant->slug)->assertCreated()->json('conversation_id');
    $url = '/ai-branch/conversations/'.$conversation.'/messages?tenant='.$tenant->slug;
    $input = ['question' => 'Review permitted work', 'request_id' => (string) Str::uuid()];
    $first = $this->postJson($url, $input)->assertOk()->json();
    $selected = collect($first['sources'])->where('type', $type)->values()->all();
    expect($selected)->toHaveCount(1);
    $saved = AiBranchConversation::findOrFail($conversation);
    // Isolate each original source type so another customer source cannot mask a history gap.
    $saved->forceFill(['messages' => array_map(fn ($message) => [...$message, 'sources' => $selected], $saved->messages)])->save();
    if ($provenance === 'legacy_channel') {
        $source['profile']->update(['source_channels' => ['legacy_square_marketing']]);
    } else {
        $profile = $source['profile'];
        if ($provenance === 'legacy_alias') {
            $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Retained original alias',
                'source_channels' => ['manual'], 'merged_into_profile_id' => $profile->id, 'merged_at' => now(), 'archived_at' => now()]);
        }
        MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id,
            'source_type' => $provenance === 'linked' ? 'square_customer' : 'square_marketing_contact', 'source_id' => 'new-original-'.Str::uuid()]);
    }
    $this->postJson($url, $input)->assertConflict();
    $this->postJson($url, [...$input, 'request_id' => (string) Str::uuid()])->assertConflict();
    $this->get('/ai-branch?tenant='.$tenant->slug.'&conversation='.$conversation)->assertOk()->assertDontSee($source['profile']->first_name);
    expect(TenantAiUsageEvent::where('tenant_id', $tenant->id)->count())->toBe(1);
    Http::assertSentCount(1);
})->with([['customer', 'linked'], ['followup', 'linked'], ['customer_activity', 'linked'],
    ['customer', 'legacy_channel'], ['followup', 'legacy_channel'], ['customer_activity', 'legacy_channel'],
    ['customer', 'legacy_link'], ['followup', 'legacy_link'], ['customer_activity', 'legacy_link'],
    ['customer', 'legacy_alias'], ['followup', 'legacy_alias'], ['customer_activity', 'legacy_alias']]);

it('retains manual page evidence while denying foreign origins and ambiguous cross tenant aliases', function (): void {
    [$tenant, $owner] = squareAiBoundaryTenant();
    [$foreign] = squareAiBoundaryTenant();
    $manual = squareAiBoundarySource($tenant, 'PAGE-CONTROL');
    $other = squareAiBoundarySource($foreign, 'FOREIGN-PAGE', 'linked');
    Http::fake(['api.openai.com/*' => Http::response(squareAiBoundaryResponse())]);
    $conversation = $this->actingAs($owner)->postJson('/ai-branch/conversations?tenant='.$tenant->slug)->assertCreated()->json('conversation_id');
    $url = '/ai-branch/conversations/'.$conversation.'/messages?tenant='.$tenant->slug;
    $this->postJson($url, ['question' => 'Review local customer', 'request_id' => (string) Str::uuid(),
        'origin' => ['kind' => 'customer', 'id' => $manual['profile']->id]])->assertOk()->assertJsonPath('workspace_context.page.title', $manual['profile']->first_name.' Fixture');
    $fresh = $this->postJson('/ai-branch/conversations?tenant='.$tenant->slug)->assertCreated()->json('conversation_id');
    $freshUrl = '/ai-branch/conversations/'.$fresh.'/messages?tenant='.$tenant->slug;
    foreach (['customer' => $other['profile']->id, 'followup' => $other['action']->id] as $kind => $id) {
        $this->postJson($freshUrl, ['question' => 'Review local work', 'request_id' => (string) Str::uuid(), 'origin' => ['kind' => $kind, 'id' => $id]])->assertNotFound();
    }
    MarketingProfile::create(['tenant_id' => $foreign->id, 'first_name' => 'FOREIGN-ALIAS-MUST-NOT-LEAK',
        'source_channels' => ['square'], 'merged_into_profile_id' => $manual['profile']->id, 'merged_at' => now()]);
    $this->postJson($freshUrl, ['question' => 'Review local work', 'request_id' => (string) Str::uuid(),
        'origin' => ['kind' => 'customer', 'id' => $manual['profile']->id]])->assertForbidden();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => ! str_contains(json_encode($request->data()), $other['profile']->first_name));
});
