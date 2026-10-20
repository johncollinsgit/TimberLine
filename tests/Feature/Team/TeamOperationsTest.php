<?php

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\TeamProviderConnection;
use App\Models\TenantModuleEntitlement;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Team\Operations\OperationsActions;
use App\Services\Team\Operations\OperationsProviders;
use App\Services\Team\Operations\OperationsStatistics;
use App\Services\Team\Operations\OperationsSync;
use App\Services\Team\Operations\SheetMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
    Http::preventStrayRequests();
});

function opsArea($tenant, string $profile = 'events'): TeamArea
{
    return TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => ucfirst($profile), 'slug' => $profile, 'profile' => $profile, 'settings' => ['stages' => config('team_operations.profiles.'.$profile.'.stages')]]);
}
function opsRecord($tenant, $area, array $extra = []): TeamOperationRecord
{
    return TeamOperationRecord::create(array_replace(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event', 'title' => 'Fictional autumn festival', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'draft', 'data' => []], $extra));
}
function opsConnection($tenant, $owner, $area, string $provider = 'asana'): TeamProviderConnection
{
    return TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'user_id' => $owner->id, 'area_id' => $area->id, 'provider' => $provider, 'account_key' => hash('sha256', Str::uuid()), 'account_name' => 'fixture@example.invalid', 'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional-access', 'refresh_token' => 'fictional-refresh'], 'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']], 'sync_state' => ['status' => 'current', 'complete' => true]]);
}

test('operations is default gated and area membership cannot cross tenants', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $member = teamMember($t, 'member');
    [$other, $foreign] = teamWorkspace();
    $this->actingAs($member)->getJson('/team/areas?tenant='.$t->slug)->assertOk()->assertJsonCount(0, 'items');
    $this->actingAs($member)->getJson('/team/events?tenant='.$t->slug.'&area='.$a->id)->assertForbidden();
    $this->actingAs($foreign)->getJson('/team/events?tenant='.$other->slug.'&area='.$a->id)->assertNotFound();
    config(['team_operations.enabled' => false]);
    $this->actingAs($owner)->getJson('/team/areas?tenant='.$t->slug)->assertNotFound();
});

test('native operation saves replay unchanged requests and reject conflicting versions', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $input = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'kind' => 'event', 'title' => 'Fictional fair', 'status' => 'research', 'data' => ['venue' => 'Fictional hall', 'untrusted_secret' => 'discard']];
    $url = '/team/operations/records?tenant='.$t->slug.'&area='.$a->id;
    $this->actingAs($owner)->postJson($url, $input)->assertOk()->assertJsonPath('version', 1)->assertJsonMissing(['untrusted_secret' => 'discard']);
    $this->postJson($url, $input)->assertOk()->assertJsonPath('version', 1);
    $this->postJson($url, [...$input, 'title' => 'Different'])->assertConflict();
    $this->postJson($url, [...$input, 'request_id' => (string) Str::uuid(), 'title' => 'Stale'])->assertConflict();
    expect(TeamOperationRecord::count())->toBe(1)->and(DB::table('team_operation_revisions')->count())->toBe(1);
    expect(DB::table('team_operation_records')->value('data'))->not->toContain('Fictional hall');
});

test('area members cannot view financials or mutate source work', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $member = teamMember($t, 'member');
    DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $t->id, 'area_id' => $a->id, 'user_id' => $member->id, 'role' => 'editor']);
    $finance = opsRecord($t, $a, ['kind' => 'financial', 'visibility' => 'admin']);
    $this->actingAs($member)->getJson('/team/operations/records/'.$finance->id.'?tenant='.$t->slug)->assertForbidden();
    $this->getJson('/team/statistics?tenant='.$t->slug.'&area='.$a->id)->assertForbidden();
    $c = opsConnection($t, $owner, $a);
    $source = opsRecord($t, $a, ['connection_id' => $c->id, 'data' => ['_generation' => 1, 'source_id' => '300', 'source_project' => '200', 'source_workspace' => '100']]);
    Http::fake(['*tasks/300*' => Http::response(['data' => ['gid' => '300', 'workspace' => ['gid' => '100'], 'memberships' => [['project' => ['gid' => '200']]]]])]);
    expect(fn () => app(OperationsAccess::class)->record($t, $owner, $source, true))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('wholesale is an entitled tenant profile rather than a Modern Forestry hardcode', function () {
    [$t, $owner] = teamWorkspace();
    $this->actingAs($owner)->postJson('/team/areas?tenant='.$t->slug, ['name' => 'Wholesale', 'profile' => 'wholesale'])->assertForbidden();
    TenantModuleEntitlement::create(['tenant_id' => $t->id, 'module_key' => 'wholesale_operations', 'enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
    \App\Models\TenantAccessProfile::where('tenant_id', $t->id)->update(['plan_key' => 'starter', 'operating_mode' => 'shopify']);
    \App\Models\TenantModuleState::create(['tenant_id' => $t->id, 'module_key' => 'wholesale_operations', 'enabled_override' => true, 'setup_status' => 'configured']);
    \App\Models\ShopifyStore::create(['tenant_id' => $t->id, 'store_key' => 'fixture-wholesale', 'store_role' => 'wholesale', 'shop_domain' => 'fixture-wholesale.myshopify.com', 'access_token' => 'fictional', 'installed_at' => now()]);
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    $this->postJson('/team/areas?tenant='.$t->slug, ['name' => 'Wholesale', 'profile' => 'wholesale'])->assertOk()->assertJsonPath('profile', 'wholesale')->assertJsonPath('settings.preset', []);
});

test('source tokens are encrypted and selection changes reject stale records', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $c = opsConnection($t, $owner, $a);
    expect(DB::table('team_provider_connections')->value('credentials'))->not->toContain('fictional-access');
    $record = app(OperationsSync::class)->upsert($c, 'event', '300', 'Fixture', 'source', ['source_id' => '300', 'source_project' => '200', 'source_workspace' => '100']);
    $c->update(['generation' => 2]);
    expect(fn () => app(OperationsAccess::class)->record($t, $owner, $record))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $updated = app(OperationsSync::class)->upsert($c, 'event', '300', 'Fixture', 'source', ['source_id' => '300', 'source_project' => '200', 'source_workspace' => '100']);
    expect($updated->data['_generation'])->toBe(2)->and($updated->version)->toBe(2);
});

test('another account requires its own source access even inside the same area', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $member = teamMember($t, 'admin');
    $c = opsConnection($t, $owner, $a);
    $record = opsRecord($t, $a, ['connection_id' => $c->id, 'data' => ['_generation' => 1, 'source_id' => '300', 'source_project' => '200', 'source_workspace' => '100']]);
    expect(fn () => app(OperationsAccess::class)->record($t, $member, $record))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('provider backoff and late response fences do not expose data', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $c = opsConnection($t, $owner, $a);
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '120'])]);
    expect(fn () => app(OperationsProviders::class)->asana($c, 'projects/200'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($c->fresh()->retry_at->isFuture())->toBeTrue();
    $c->update(['retry_at' => null]);
    Http::fake(function () use ($c) {
        TeamProviderConnection::whereKey($c->id)->update(['generation' => 2]);

        return Http::response(['data' => ['secret' => 'discard']]);
    });
    expect(fn () => app(OperationsProviders::class)->asana($c, 'projects/200'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('source cursors are tied to connection and generation', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $c = opsConnection($t, $owner, $a);
    $other = opsConnection($t, $owner, $a);
    Http::fake(['*projects/200?*' => Http::response(['data' => ['gid' => '200', 'workspace' => ['gid' => '100']]]), '*tasks?*' => Http::response(['data' => [['gid' => '300']], 'next_page' => ['offset' => 'opaque-next']])]);
    $page = app(OperationsProviders::class)->read($c, 'records');
    expect($page['complete'])->toBeFalse()->and($page['next_cursor'])->toBeString();
    expect(fn () => app(OperationsProviders::class)->read($other, 'records', $page['next_cursor']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $c->update(['generation' => 2]);
    expect(fn () => app(OperationsProviders::class)->read($c, 'records', $page['next_cursor']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('sheet normalization retains zero missing values historical costs and copied ID errors', function () {
    $mapper = app(SheetMapper::class);
    expect($mapper->cents('0'))->toBe(0)->and($mapper->cents(''))->toBeNull()->and($mapper->cents('#REF!'))->toBeNull()->and($mapper->cents('($1,201.05)'))->toBe(-120105);
    expect($mapper->date('01.17.26'))->toBe('2026-01-17')->and($mapper->date('02.30.26'))->toBeNull()->and($mapper->date(46039))->toBeString();
    $rows = [['Name', 'Event', 'Start Date', 'Ending Date', 'Net Sales', 'Total Expense', 'COGS $', '_teams_row_id'], ['A', 'Fair', '2026-01-17', '2026-01-18', '100', '75', '33', 'one'], ['B', 'Fair', '2026-01-17', '2026-01-18', '100', '75', '33', 'two'], ['C', 'Fair', '2026-01-17', '2026-01-18', '100', '75', '33', 'one']];
    $mapped = $mapper->rows('2026', $rows);
    expect($mapped['items'])->toHaveCount(2)->and($mapped['items'][0]['needs_allocation_review'])->toBeTrue()->and($mapped['items'][0]['costs']['cogs'])->toBe(3300)->and($mapped['errors'])->toHaveCount(1);
});

test('prefill excludes previous acceptance payment dates and manual-entry hazards', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    opsRecord($t, $a, ['data' => ['venue' => 'Fictional hall', 'acceptance_status' => 'yes', 'payment_status' => 'paid', 'notes' => 'Never auto copy'], 'starts_on' => '2025-01-01']);
    $this->actingAs($owner)->getJson('/team/events/prefill?tenant='.$t->slug.'&area='.$a->id.'&q=autumn')->assertOk()->assertJsonPath('items.0.prefill.venue', 'Fictional hall')->assertJsonMissingPath('items.0.prefill.acceptance_status')->assertJsonMissingPath('items.0.prefill.payment_status')->assertJsonMissingPath('items.0.prefill.notes');
});

test('document matching uses each filename rather than a multi-event subject', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $c = opsConnection($t, $owner, $a, 'gmail');
    $one = opsRecord($t, $a, ['title' => 'Fictional autumn festival', 'starts_on' => '2026-11-01']);
    opsRecord($t, $a, ['title' => 'Fictional winter festival', 'starts_on' => '2026-12-01']);
    $match = app(OperationsSync::class)->matchDocument($c, 'Fictional-autumn-festival-2026-COI.pdf', 'organizer@example.invalid', 'Three certificates for different events');
    expect($match['target_id'])->toBe($one->id)->and($match['automatic'])->toBeTrue();
    $unknown = app(OperationsSync::class)->matchDocument($c, 'certificate.pdf', 'organizer@example.invalid', 'Fictional autumn festival 2026');
    expect($unknown['target_id'])->toBeNull();
    opsRecord($t, $a, ['title' => 'Fictional autumn festival', 'starts_on' => '2026-10-01']);
    expect(app(OperationsSync::class)->matchDocument($c, 'Fictional-autumn-festival-2026-COI.pdf', '', '')['target_id'])->toBeNull();
});

test('linked documents inherit destination access and redact mailbox details', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $c = opsConnection($t, $owner, $a, 'gmail');
    $member = teamMember($t, 'member');
    DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $t->id, 'area_id' => $a->id, 'user_id' => $member->id, 'role' => 'viewer']);
    $event = opsRecord($t, $a, ['visibility' => 'assigned', 'assigned_user_id' => $owner->id]);
    $doc = opsRecord($t, $a, ['kind' => 'document', 'connection_id' => $c->id, 'status' => 'linked', 'data' => ['_generation' => 1, 'message_id' => 'fictional-message', 'scan' => 'clean', 'target_id' => $event->id, 'storage_key' => 'private/key', 'source_subject' => 'Unrelated private correspondence']]);
    Http::fake(['*' => Http::response(['id' => 'fictional-message'])]);
    $this->actingAs($member)->getJson('/team/operations/records/'.$doc->id.'?tenant='.$t->slug)->assertForbidden();
    $this->actingAs($owner)->getJson('/team/operations/records/'.$doc->id.'?tenant='.$t->slug)->assertOk()->assertJsonMissingPath('data.storage_key')->assertJsonMissingPath('data.source_subject');
});

test('prepared creation is idempotent and disabled dispatch never calls Asana', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $record = opsRecord($t, $a);
    $c = opsConnection($t, $owner, $a);
    config(['team_operations.asana_create_enabled' => false]);
    $url = '/team/communications?tenant='.$t->slug.'&area='.$a->id;
    $input = ['request_id' => (string) Str::uuid(), 'kind' => 'asana.create', 'record_id' => $record->id, 'connection_id' => $c->id, 'reviewed' => true];
    $id = $this->actingAs($owner)->postJson($url, $input)->assertOk()->assertJsonPath('status', 'prepared')->json('id');
    $this->postJson($url, $input)->assertOk()->assertJsonPath('id', $id);
    $this->postJson('/team/communications/'.$id.'/dispatch?tenant='.$t->slug.'&area='.$a->id, ['reviewed' => true])->assertConflict();
    Http::assertNothingSent();
});

test('formatted email sanitization removes executable elements and unsafe links', function () {
    $html = app(OperationsActions::class)->safeHtml('<p onclick="steal()">Safe <strong>update</strong></p><script>secret()</script><a href="javascript:alert(1)" style="position:fixed">x</a><a href="https://example.invalid">Details</a>');
    expect($html)->toContain('<strong>update</strong>')->not->toContain('onclick', '<script', 'javascript:', 'style=')->toContain('https://example.invalid');
});

test('weather never invents a daily forecast beyond its horizon', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    config(['team_operations.weather.enabled' => true, 'team_operations.weather.commercial' => true, 'team_operations.weather.api_key' => 'fictional']);
    $record = opsRecord($t, $a, ['starts_on' => now()->addMonths(4)->toDateString(), 'data' => ['latitude' => 35, 'longitude' => -82]]);
    expect(app(\App\Services\Team\Operations\OperationsWeather::class)->event($record)['available'])->toBeFalse();
    Http::assertNothingSent();
});

test('financial reporting uses every authorized row and never double counts shared event totals', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    for ($i = 1; $i <= 35; $i++) {
        opsRecord($t, $a, ['kind' => 'financial', 'title' => 'Fictional occurrence '.$i, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-02', 'status' => 'accepted', 'data' => ['event_id' => 'occurrence-'.$i, 'needs_allocation_review' => false, 'net_sales_cents' => 10000, 'total_expense_cents' => 7500, 'selling_days' => 2, 'costs' => ['cogs' => 3000, 'lodging' => null]]]);
    }
    foreach (['A', 'B'] as $name) {
        opsRecord($t, $a, ['kind' => 'financial', 'title' => 'Shared event', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-02', 'status' => 'accepted', 'data' => ['event_id' => 'shared', 'needs_allocation_review' => false, 'net_sales_cents' => 9900000, 'total_expense_cents' => 7500, 'costs' => []]]);
    }
    $result = app(OperationsStatistics::class)->events($t, $owner, $a, 2026);
    expect($result['net_sales_cents'])->toBe(350000)->and($result['profit_cents'])->toBe(87500)->and($result['margin_percent'])->toBe(25.0)->and($result['events'])->toHaveCount(35)->and($result['excluded_rows'])->toBe(2)->and($result['complete'])->toBeFalse()->and($result['missing_cost_categories']['lodging'])->toBe(35);
});

test('a failed spreadsheet read retains prior records and never withdraws them as an empty workbook', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $c = opsConnection($t, $owner, $a, 'sheets');
    $c->update(['settings' => ['source' => ['spreadsheet_id' => 'fictional_workbook_id', 'tabs' => ['2026']]]]);
    $record = app(OperationsSync::class)->upsert($c, 'financial', 'row-1', 'Fictional event', 'needs_review', ['net_sales_cents' => 10000]);
    Http::fake(['*' => Http::response([], 503)]);
    expect(fn () => app(OperationsSync::class)->refresh($c))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($record->fresh()->status)->toBe('needs_review')->and($c->fresh()->sync_state['complete'])->toBeFalse()->and($c->fresh()->sync_state['status'])->toBe('unavailable');
});

test('identical Asana task memberships preserve one external identity inside an area', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $c = opsConnection($t, $owner, $a);
    $other = opsConnection($t, $owner, $a);
    $first = app(OperationsSync::class)->upsert($c, 'event', '300', 'Fixture', 'source', []);
    $next = app(OperationsSync::class)->upsert($other, 'event', '300', 'Fixture', 'source', []);
    expect($first->id)->toBe($next->id)->and(TeamOperationRecord::count())->toBe(1);
});

test('internal reminders deduplicate and recheck cancellation and recipient access', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $member = teamMember($t, 'member');
    DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $t->id, 'area_id' => $a->id, 'user_id' => $member->id, 'role' => 'viewer']);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-20T12:05:00Z'));
    $event = opsRecord($t, $a, ['starts_on' => '2026-10-21', 'ends_on' => '2026-10-22']);
    opsRecord($t, $a, ['kind' => 'rule', 'visibility' => 'admin', 'data' => ['_created_by' => $owner->id, 'enabled' => true, 'reviewed' => true, 'external' => false, 'trigger' => 'day_before', 'audience_ids' => [$member->id], 'timezone' => 'America/New_York', 'local_time' => '08:00']]);
    $service = app(\App\Services\Team\Operations\OperationsReminders::class);
    $service->run();
    $service->run();
    expect($service->inbox($t, $member))->toHaveCount(1)->and(\App\Models\TeamOperationAttempt::where('kind', 'reminder')->count())->toBe(1);
    $event->update(['status' => 'cancelled']);
    // Cancellation prevents subsequent schedules; old reminders require current work access.
    $service->run();
    expect(\App\Models\TeamOperationAttempt::where('kind', 'reminder')->count())->toBe(1);
    DB::table('team_area_members')->where('user_id', $member->id)->delete();
    expect($service->inbox($t, $member))->toBe([]);
    $this->travelBack();
});

test('Teams text usage shares the approved tenant ledger and retains stable reservations', function () {
    [$t, $owner] = teamWorkspace();
    config(['bud.ai_enabled' => true, 'bud.provider_configured' => true]);
    \App\Models\TenantBudSetting::create(['tenant_id' => $t->id, 'status' => 'approved', 'ai_status' => 'approved', 'ai_monthly_budget_cents' => 10]);
    $ledger = app(\App\Services\Billing\TenantAiUsageService::class);
    $uuid = (string) Str::uuid();
    $hash = hash('sha256', 'approved source evidence');
    $voice = $ledger->reserveVoice($t, $owner, (string) Str::uuid(), 'field', 'fixture-model', 10);
    $text = $ledger->reserveText($t, $owner, $uuid, 'teams_briefing', $hash, 'fixture-model', [1000000, 1000000]);
    expect($ledger->reserveText($t, $owner, $uuid, 'teams_briefing', $hash, 'fixture-model', [1000000, 1000000])->id)->toBe($text->id);
    $ledger->settleText($text, 100, 40, 'fictional-provider-request');
    expect($text->fresh()->status)->toBe('settled')->and($text->fresh()->buyer_charge_micros)->toBe(140)->and($voice->fresh()->status)->toBe('reserved');
    \App\Models\TenantBudSetting::where('tenant_id', $t->id)->update(['ai_monthly_budget_cents' => 1]);
    expect(fn () => $ledger->reserveText($t, $owner, (string) Str::uuid(), 'teams_briefing', $hash, 'fixture-model', [1000000, 1000000]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

test('deterministic briefings do not require paid AI or expose source notes and compensation', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    config(['team_operations.ai_enabled' => false]);
    $event = opsRecord($t, $a, ['data' => ['next_action' => 'Confirm setup time', 'notes' => 'Private pay discussion', 'payroll_cents' => 99999]]);
    $this->actingAs($owner)->postJson('/team/communications/briefing?tenant='.$t->slug.'&area='.$a->id, ['record_ids' => [$event->id], 'request_id' => (string) Str::uuid(), 'ai' => true])->assertOk()->assertJsonPath('mode', 'deterministic')->assertJsonPath('sources.0', $event->id)->assertJsonMissing(['Private pay discussion', 99999]);
    expect(\App\Models\TenantAiUsageEvent::count())->toBe(0);
    Http::assertNothingSent();
});

test('unsigned provider push notifications cannot schedule source reads', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $c = opsConnection($t, $owner, $a, 'gmail');
    \Illuminate\Support\Facades\Queue::fake();
    $this->postJson('/api/team/operations/push/'.$c->id, ['message' => ['data' => base64_encode('{}')]])->assertForbidden();
    \Illuminate\Support\Facades\Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('template publishing is tenant scoped and restricted while published wording is reusable', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $member = teamMember($t, 'member');
    DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $t->id, 'area_id' => $a->id, 'user_id' => $member->id, 'role' => 'editor']);
    $input = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'kind' => 'email_template', 'title' => 'Staff briefing', 'status' => 'draft', 'data' => ['subject' => '{{record_title}}', 'text' => 'Hi {{recipient_name}}, {{venue}}']];
    $url = '/team/operations/records?tenant='.$t->slug.'&area='.$a->id;
    $this->actingAs($member)->postJson($url, $input)->assertForbidden();
    $this->actingAs($owner)->postJson($url, $input)->assertOk();
    $this->actingAs($member)->getJson('/team/communications/templates?tenant='.$t->slug.'&area='.$a->id)->assertOk()->assertJsonCount(0, 'items');
    $this->actingAs($owner)->postJson($url, [...$input, 'request_id' => (string) Str::uuid(), 'expected_version' => 1, 'status' => 'published'])->assertOk();
    $this->actingAs($member)->getJson('/team/communications/templates?tenant='.$t->slug.'&area='.$a->id)->assertOk()->assertJsonCount(1, 'items');
    $this->postJson($url, [...$input, 'request_id' => (string) Str::uuid(), 'expected_version' => 2, 'status' => 'published'])->assertForbidden();
    Http::assertNothingSent();
});

test('formatted templates escape content report missing details and never interpolate private notes', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $event = opsRecord($t, $a, ['data' => ['venue' => '<script>bad()</script>', 'notes' => 'Private pay', 'payroll_cents' => 99999]]);
    $service = app(\App\Services\Team\Operations\OperationsTemplates::class);
    $message = $service->render($t, $owner, $a, $event, $owner, ['subject' => '{{record_title}}', 'text' => '{{venue}} / {{setup_time}}']);
    expect($message['missing_fields'])->toBe(['setup_time'])->and($message['html'])->toContain('&lt;script&gt;')->not->toContain('<script>')->not->toContain('Private pay');
    expect(fn () => $service->validate(['subject' => 'Hi', 'text' => '{{payroll_cents}}']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $event->update(['title' => "Injected\r\nBcc: bad@example.invalid"]);
    $message = $service->render($t, $owner, $a, $event, $owner, ['subject' => '{{record_title}}', 'text' => 'Hello']);
    expect($message['subject'])->not->toContain("\r")->not->toContain("\n");
    Http::assertNothingSent();
});

test('template previews enforce record recipient permissions before exposing content', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $outsider = teamMember($t, 'member');
    $event = opsRecord($t, $a);
    $this->actingAs($owner)->postJson('/team/communications/templates/preview?tenant='.$t->slug.'&area='.$a->id, ['record_id' => $event->id, 'recipient_user_id' => $outsider->id, 'subject' => 'Hi', 'text' => '{{record_title}}'])->assertForbidden();
    Http::assertNothingSent();
});

test('reminder previews use area recipients and quiet hours and cancellation hides prior reminders', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $this->travelTo(now()->setTimezone('America/New_York')->setTime(8, 10));
    $event = opsRecord($t, $a, ['starts_on' => now()->addDay()->toDateString()]);
    $data = ['trigger' => 'day_before', 'audience_ids' => [$owner->id], 'timezone' => 'America/New_York', 'local_time' => '08:00', 'quiet_start' => '21:00', 'quiet_end' => '07:00', 'channel' => 'inbox', 'enabled' => true, 'reviewed' => true, '_created_by' => $owner->id];
    $this->actingAs($owner)->postJson('/team/communications/automations/preview?tenant='.$t->slug.'&area='.$a->id, ['data' => $data])->assertOk()->assertJsonPath('items.0.count', 1);
    expect(\App\Models\TeamOperationAttempt::count())->toBe(0);
    $this->postJson('/team/communications/automations/preview?tenant='.$t->slug.'&area='.$a->id, ['data' => [...$data, 'local_time' => '22:00']])->assertUnprocessable();
    opsRecord($t, $a, ['kind' => 'rule', 'status' => 'active', 'visibility' => 'admin', 'data' => $data]);
    $engine = app(\App\Services\Team\Operations\OperationsReminders::class);
    $engine->run();
    $engine->run();
    expect($engine->inbox($t, $owner))->toHaveCount(1);
    $event->update(['status' => 'cancelled']);
    expect($engine->inbox($t, $owner))->toBe([]);
    $this->travelBack();
    Http::assertNothingSent();
});

test('area cursor pagination has no gaps across dated and undated records and rejects selection changes', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    for ($i = 0; $i < 65; $i++) {
        opsRecord($t, $a, ['title' => sprintf('Event %03d', $i), 'starts_on' => $i < 35 ? '2026-11-01' : null]);
    }
    $url = '/team/operations/records?tenant='.$t->slug.'&area='.$a->id.'&kind=event';
    $first = $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonCount(30, 'items')->json();
    $second = $this->getJson($url.'&cursor='.urlencode($first['next_cursor']))->assertOk()->assertJsonCount(30, 'items')->json();
    $third = $this->getJson($url.'&cursor='.urlencode($second['next_cursor']))->assertOk()->assertJsonCount(5, 'items')->assertJsonPath('has_more', false)->json();
    expect(collect([...$first['items'], ...$second['items'], ...$third['items']])->pluck('id')->unique())->toHaveCount(65);
    opsConnection($t, $owner, $a);
    $this->getJson($url.'&cursor='.urlencode($first['next_cursor']))->assertConflict();
});

test('resumable multi-project imports retain task identity and withdraw only after the final page', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $c = opsConnection($t, $owner, $a);
    $c->update(['settings' => ['source' => ['asana_workspace' => '100', 'asana_projects' => ['200', '201']]]]);
    config(['team_operations.sync_pages_per_run' => 1]);
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/tasks?')) {
            return Http::response(['data' => [['gid' => '300', 'name' => 'Multi-project event', 'modified_at' => '2026-10-01T00:00:00Z', 'memberships' => [['project' => ['gid' => '200']], ['project' => ['gid' => '201']]]]]]);
        }
        $id = str_contains($request->url(), '/projects/201') ? '201' : '200';

        return Http::response(['data' => ['gid' => $id, 'workspace' => ['gid' => '100'], 'name' => 'Project '.$id]]);
    });
    $old = app(OperationsSync::class)->upsert($c, 'event', '999', 'Removed upstream', 'source', []);
    $first = app(OperationsSync::class)->refresh($c);
    expect($first['complete'])->toBeFalse()->and($old->fresh()->status)->toBe('source');
    $second = app(OperationsSync::class)->refresh($c->fresh());
    expect($second['complete'])->toBeTrue()->and($old->fresh()->status)->toBe('withdrawn');
    expect(TeamOperationRecord::where('title', 'Multi-project event')->count())->toBe(1);
    $source = TeamOperationRecord::where('title', 'Multi-project event')->first();
    expect($source->data['source_projects'])->toBe(['201', '200']);
});

test('token service preserves temporary failures and commits revoked grants and backoff', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $c = opsConnection($t, $owner, $a);
    $c->update(['expires_at' => now()->subMinute()]);
    Http::fake(['*' => Http::sequence()->push(['error' => 'temporary'], 503)->push([], 429, ['Retry-After' => '120'])->push(['error' => 'invalid_grant'], 400)]);
    expect(fn () => app(OperationsProviders::class)->token($c))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($c->fresh()->status)->toBe('connected');
    expect(fn () => app(OperationsProviders::class)->token($c))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($c->fresh()->retry_at->isFuture())->toBeTrue();
    expect(fn () => app(OperationsProviders::class)->token($c))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($c->fresh()->status)->toBe('reconnect');
});

test('reviewed formatted email preparation is idempotent and refuses stale dispatch without contacting Gmail', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $event = opsRecord($t, $a);
    $c = opsConnection($t, $owner, $a, 'gmail');
    foreach (['customers', 'messaging'] as $module) {
        TenantModuleEntitlement::create(['tenant_id' => $t->id, 'module_key' => $module, 'enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
        \App\Models\TenantModuleState::create(['tenant_id' => $t->id, 'module_key' => $module, 'enabled_override' => true, 'setup_status' => 'configured']);
    }
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    $data = ['request_id' => (string) Str::uuid(), 'kind' => 'gmail.send', 'record_id' => $event->id, 'expected_record_version' => 1, 'connection_id' => $c->id, 'recipient_user_id' => $owner->id, 'subject' => '{{record_title}}', 'text' => 'Hello {{recipient_name}}', 'formatted' => true, 'reviewed' => true];
    $url = '/team/communications?tenant='.$t->slug.'&area='.$a->id;
    $first = $this->actingAs($owner)->postJson($url, $data)->assertOk()->assertJsonPath('status', 'prepared')->json();
    $this->postJson($url, $data)->assertOk()->assertJsonPath('id', $first['id']);
    expect($first['preview']['html'])->toContain('<!doctype html>')->and($first['preview']['text'])->toBe('Hello '.$owner->name);
    expect(\App\Models\TeamOperationAttempt::where('kind', 'gmail.send')->count())->toBe(1);
    $event->update(['version' => 2, 'title' => 'Changed event']);
    config(['team_operations.send_enabled' => true]);
    $this->postJson('/team/communications/'.$first['id'].'/dispatch?tenant='.$t->slug.'&area='.$a->id, ['reviewed' => true])->assertConflict();
    expect(\App\Models\TeamOperationAttempt::find($first['id'])->status)->toBe('prepared');
    Http::assertNothingSent();
});

test('external campaign plans cannot become an enabled sequence through a record save', function () {
    [$t, $owner] = teamWorkspace();
    $a = opsArea($t);
    $input = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'kind' => 'campaign', 'title' => 'Fictional outreach plan', 'status' => 'draft', 'data' => ['reviewed' => true, 'external' => true, 'enabled' => true, 'subject' => 'Hello', 'text' => 'Review this wording']];
    $url = '/team/operations/records?tenant='.$t->slug.'&area='.$a->id;
    $this->actingAs($owner)->postJson($url, $input)->assertConflict();
    $this->postJson($url, [...$input, 'data' => [...$input['data'], 'enabled' => false]])->assertOk();
    expect(\App\Models\TeamOperationAttempt::whereIn('kind', ['gmail.send', 'sms.send'])->count())->toBe(0);
    Http::assertNothingSent();
});

test('Square reconciliation isolates tenants and keeps unknown refunds separate from recorded zero', function () {
    [$t, $owner] = teamWorkspace();
    [$other] = teamWorkspace();
    $a = opsArea($t);
    $event = opsRecord($t, $a, ['starts_on' => '2026-09-01', 'ends_on' => '2026-09-02']);
    foreach ([[$t, 'own'], [$other, 'foreign']] as [$tenant, $id]) {
        \App\Models\SquareOrder::create(['tenant_id' => $tenant->id, 'square_order_id' => $id, 'state' => 'COMPLETED', 'location_id' => 'fixture-location', 'total_money_amount' => 10000, 'total_money_currency' => 'USD', 'closed_at' => '2026-09-01T12:00:00Z']);
    }
    \App\Models\SquarePayment::create(['tenant_id' => $t->id, 'square_payment_id' => 'pay-zero', 'square_order_id' => 'own', 'amount_money' => 5000, 'currency' => 'USD', 'raw_payload' => ['refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
    $service = app(\App\Services\Team\Operations\OperationsSquare::class);
    $result = $service->event($t, $owner, $event);
    expect($result['items'])->toHaveCount(1)->and($result['items'][0]['id'])->toBe('own')->and($result['items'][0]['recorded_refunds_cents'])->toBe(0)->and($result['complete'])->toBeFalse();
    \App\Models\SquarePayment::create(['tenant_id' => $t->id, 'square_payment_id' => 'pay-unknown', 'square_order_id' => 'own', 'amount_money' => 5000, 'currency' => 'USD', 'raw_payload' => []]);
    expect($service->event($t, $owner, $event)['items'][0]['recorded_refunds_cents'])->toBeNull();
    Http::assertNothingSent();
});
