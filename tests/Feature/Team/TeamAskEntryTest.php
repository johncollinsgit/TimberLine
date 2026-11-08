<?php

use App\Models\AiBranchAccount;
use App\Models\AiBranchConversation;
use App\Models\TenantModuleEntitlement;
use App\Services\AiBranch\AiBranchOffer;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'ai_branch.standard_enabled' => true]);
    Http::preventStrayRequests();
});

test('Teams owner Ask entry opens the real tenant assistant without enabling or spending AI usage', function (): void {
    [$tenant, $owner] = teamWorkspace();
    $this->actingAs($owner)->get(route('team.app', ['tenant' => $tenant->slug]))->assertOk()
        ->assertSee('href="'.route('ai-branch.index', ['tenant' => $tenant->slug]).'">Ask Everbranch', false)
        ->assertDontSeeText('AI Voice Branch');
    $this->getJson(route('ai-branch.assistant.session', ['tenant' => $tenant->slug]))->assertStatus(404);
    expect(AiBranchAccount::count())->toBe(0)->and(AiBranchConversation::count())->toBe(0);
    Http::assertNothingSent();
});

test('Teams membership alone never grants assistant access or exposes another workspace Ask entry', function (): void {
    [$tenant] = teamWorkspace();
    $member = teamMember($tenant, 'member');
    $this->actingAs($member)->get(route('team.app', ['tenant' => $tenant->slug]))->assertOk()->assertDontSeeText('Ask Everbranch');
    $this->get(route('ai-branch.index', ['tenant' => $tenant->slug]))->assertForbidden();
    expect(AiBranchAccount::count())->toBe(0)->and(AiBranchConversation::count())->toBe(0);
    Http::assertNothingSent();
});

test('approved Teams owner receives the existing in-place assistant host with explicit workspace-only scope', function (): void {
    [$tenant, $owner] = teamWorkspace();
    config(['team_work.premium_enabled' => true, 'ai_branch.existing_tenants' => [$tenant->id => $tenant->slug]]);
    TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'ai_branch', 'enabled_status' => 'enabled',
        'availability_status' => 'available', 'billing_status' => 'included', 'entitlement_source' => AiBranchOffer::INCLUDED_SOURCE]);
    $account = AiBranchAccount::create(['tenant_id' => $tenant->id, 'enabled' => true, 'budget_cents' => 1000]);
    $response = $this->actingAs($owner)->get(route('team.app', ['tenant' => $tenant->slug]))->assertOk()
        ->assertSee('data-team-assistant-entry', false)->assertSee('data-ai-assistant', false)
        ->assertSee('data-page-finder-enabled="false"', false)
        ->assertSee('data-base-url="'.route('ai-branch.index', ['tenant' => $tenant->slug]).'"', false)
        ->assertSee('data-scope-notice="Teams event details and Asana notes are not included in this workspace context."', false);
    if ($path = getenv('TEAMS_ASK_BROWSER_HTML')) {
        if (! str_starts_with($path, sys_get_temp_dir().'/codex-teams-ask-')) {
            throw new RuntimeException('Private disposable browser fixture only.');
        }
        file_put_contents($path, $response->getContent());
        chmod($path, 0600);
    }
    expect(AiBranchConversation::count())->toBe(0)->and($account->fresh()->budget_cents)->toBe(1000);
    $this->blade('<x-app-ai-assistant :tenant="$tenant" />', ['tenant' => $tenant])
        ->assertSee('data-page-finder-enabled="true"', false);
    $account->update(['enabled' => false]);
    $settings = $this->get(route('team.app', ['tenant' => $tenant->slug]))->assertOk()->assertDontSee('data-ai-assistant', false)
        ->assertSee('href="'.route('ai-branch.index', ['tenant' => $tenant->slug]).'">Ask Everbranch', false);
    if ($path) {
        file_put_contents($path.'.settings.html', $settings->getContent());
        chmod($path.'.settings.html', 0600);
    }
    Http::assertNothingSent();
});
