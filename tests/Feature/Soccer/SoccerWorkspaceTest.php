<?php

use App\Mail\SoccerSignInCodeMail;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Services\Soccer\SoccerFamilyService;
use App\Services\Soccer\SoccerStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->withoutVite();
    config()->set('logging.default', 'null');
    config()->set('soccer.enabled', true);
    $this->tenant = Tenant::create(['name' => 'Wren Family Soccer', 'slug' => 'wren-family-soccer']);
    TenantAccessProfile::create(['tenant_id' => $this->tenant->id, 'plan_key' => 'starter', 'operating_mode' => 'direct', 'metadata' => ['workspace_focus' => 'soccer']]);
    TenantModuleState::create(['tenant_id' => $this->tenant->id, 'module_key' => 'soccer', 'enabled_override' => true]);
    TenantModuleState::create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'enabled_override' => true]);
    $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $this->admin->tenants()->attach($this->tenant, ['role' => 'admin', 'membership_active' => true]);
    $this->store = app(SoccerStore::class);
    $this->season = $this->store->insert('seasons', $this->tenant->id, ['season_key' => 'spring-2027', 'name' => 'Spring 2027', 'teams' => json_encode(config('soccer.teams'))]);
});
it('renders focused admin tools and blocks unrelated Wren customer routes', function () {
    $this->actingAs($this->admin)->get('/soccer?tenant=wren-family-soccer')->assertOk()->assertSeeText('A new season starts here.')->assertSeeText('Parents')->assertSeeText('Green Bears')->assertDontSee('data-sidebar-key="trajectory"', false)->assertDontSee('data-sidebar-key="branches"', false)->assertDontSee('data-sidebar-key="customers"', false);
    $this->get('/website/products?tenant=wren-family-soccer')->assertRedirect(route('soccer.workspace', ['tenant' => 'wren-family-soccer']));
});
it('restricts coaches to assigned teams and denies website payment and parent administration', function () {
    $coach = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $coach->tenants()->attach($this->tenant, ['role' => 'member', 'membership_active' => true]);
    $this->store->insert('coaches', $this->tenant->id, ['user_id' => $coach->id, 'name' => 'Preview Coach', 'role' => 'assistant', 'team_keys' => json_encode(['green-bears'])]);
    $this->actingAs($coach)->get('/soccer/teams?tenant=wren-family-soccer')->assertOk()->assertSeeText('Green Bears')->assertDontSeeText('Red Falcons')->assertDontSeeText('Season settings');
    foreach (['parents', 'settings', 'website'] as $section) {
        $this->get('/soccer/'.$section.'?tenant=wren-family-soccer')->assertForbidden();
    }
    $this->post('/soccer/actions/refund?tenant=wren-family-soccer', [])->assertForbidden();
});
it('denies strangers even when they know the Wren tenant slug', function () {
    $other = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $this->actingAs($other)->get('/soccer/parents?tenant=wren-family-soccer')->assertRedirect();
});
it('uses verified family sessions without creating backend users or memberships', function () {
    Mail::fake();
    $familyService = app(SoccerFamilyService::class);
    $before = User::count();
    $familyService->code($this->tenant->id, 'parent@example.com');
    expect($this->store->query('challenges', $this->tenant->id)->count())->toBe(1);
    expect(User::count())->toBe($before)->and($this->store->query('families', $this->tenant->id)->count())->toBe(0);
});
it('checks alumni emails before sending codes and keeps new families on their own path', function () {
    Mail::fake();
    $service = app(SoccerFamilyService::class);
    expect(fn () => $service->code($this->tenant->id, 'unknown@example.com', 'existing'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'could not find a previous Wren family');
    Mail::assertNothingSent();
    expect($this->store->query('challenges', $this->tenant->id)->count())->toBe(0);

    $this->store->insert('families', $this->tenant->id, ['email' => 'primary@example.com', 'profile' => $this->store->encrypt(['guardians' => ['second@example.com' => ['role' => 'guardian']]])]);
    $service->code($this->tenant->id, 'second@example.com', 'existing');
    Mail::assertSent(SoccerSignInCodeMail::class, fn ($mail) => $mail->hasTo('second@example.com') && str_contains($mail->render(), 'Wren Family Soccer') && str_contains($mail->render(), '/youth-soccer.jpg') && str_contains($mail->render(), $mail->code));

    $code = '123456';
    $this->store->insert('challenges', $this->tenant->id, ['email' => 'unknown@example.com', 'code_hash' => hash_hmac('sha256', 'unknown@example.com:'.$code, config('app.key')), 'expires_at' => now()->addMinutes(10)]);
    expect(fn () => $service->verify($this->tenant->id, 'unknown@example.com', $code, 'existing'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'could not find a previous Wren family');
    expect($this->store->query('families', $this->tenant->id)->count())->toBe(1);
});
it('rejects family access to another household player and release', function () {
    $a = $this->store->insert('families', $this->tenant->id, ['email' => 'a@example.com', 'profile' => $this->store->encrypt([])]);
    $b = $this->store->insert('families', $this->tenant->id, ['email' => 'b@example.com', 'profile' => $this->store->encrypt([])]);
    $p = $this->store->insert('players', $this->tenant->id, ['family_id' => $b, 'name' => 'Private child', 'birthday' => '2020-01-01', 'profile' => $this->store->encrypt([])]);
    expect(fn () => app(SoccerFamilyService::class)->player($this->tenant->id, $this->store->row('families', $this->tenant->id, $a), ['id' => $p, 'name' => 'Attempt', 'birthday' => '2020-01-01']))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
it('enforces GroupMe role restrictions on the server', function () {
    $coach = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $coach->tenants()->attach($this->tenant, ['role' => 'member', 'membership_active' => true]);
    $this->store->insert('coaches', $this->tenant->id, ['user_id' => $coach->id, 'name' => 'Assistant', 'role' => 'assistant', 'team_keys' => json_encode(['green-bears'])]);
    $this->store->saveSettings($this->tenant->id, ['groupme_groups' => ['soccer' => '1', 'head_coaches' => '2', 'all_coaches' => '3']]);
    Http::preventStrayRequests();
    $this->actingAs($coach)->getJson('/soccer/group-messages?tenant=wren-family-soccer&group_id=2')->assertForbidden();
});
it('rejects unsigned bridge requests and replayed nonces', function () {
    config()->set('soccer.bridge_secret', str_repeat('k', 40));
    $path = '/api/soccer/portal/profile';
    $this->getJson($path)->assertUnauthorized();
    $time = (string) time();
    $nonce = (string) \Illuminate\Support\Str::uuid();
    $sig = hash_hmac('sha256', "GET\n".$path."\n".$time."\n".$nonce."\n".hash('sha256', '[]'), str_repeat('k', 40));
    $headers = ['X-Wren-Time' => $time, 'X-Wren-Nonce' => $nonce, 'X-Wren-Signature' => $sig];
    $this->getJson($path, $headers)->assertUnauthorized();
    $this->getJson($path, $headers)->assertStatus(409);
});
it('blocks registration opening until provider readiness and human smoke evidence both pass', function () {
    $this->store->saveSettings($this->tenant->id, ['launch_verified' => true, 'groupme_groups' => ['soccer' => '1', 'head_coaches' => '2', 'all_coaches' => '3']]);
    $teams = array_map(fn ($t) => ['public' => $t['public'], 'reserved' => $t['reserved']], config('soccer.teams'));
    $this->actingAs($this->admin)->post('/soccer/actions/season?tenant=wren-family-soccer', ['id' => $this->season, 'starts_on' => '2027-03-01', 'offer_hours' => 48, 'registration_open' => true, 'teams' => $teams])->assertRedirect()->assertSessionHasErrors('soccer');
    expect((bool) $this->store->row('seasons', $this->tenant->id, $this->season)->registration_open)->toBeFalse();
});
it('publishes and restores tenant website snapshots while rejecting stale saves', function () {
    \App\Models\TenantModuleEntitlement::create(['tenant_id' => $this->tenant->id, 'module_key' => 'managed_website', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped']);
    config(['managed_website.editor_enabled' => true, 'managed_website.editor_tenant_ids' => [$this->tenant->id], 'managed_website.publishing_enabled' => true, 'managed_website.public_render_enabled' => true]);
    $siteService = app(\App\Services\Soccer\SoccerWebsiteService::class);
    $site = $siteService->initialize($this->tenant, $this->admin);
    $draft = $site->draft_site_version_id;
    $content = $siteService->defaults();
    $content['hero_title'] = 'A fictional new season';
    $siteService->save($this->tenant, $this->admin, array_reverse($content, true), $draft);
    $current = $site->fresh()->draft_site_version_id;
    expect(fn () => $siteService->save($this->tenant, $this->admin, $content, $draft))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $siteService->save($this->tenant, $this->admin, [], $current, true);
    expect($siteService->publicContent(null)['content']['hero_title'])->toBe('A fictional new season');
    $siteService->save($this->tenant, $this->admin, [], $current, false, $draft);
    expect($siteService->content($site->fresh(), $site->fresh()->draft_site_version_id)['hero_title'])->toBe('Joy in the game. Rooted in faith.');
    config()->set('managed_website.public_render_enabled', false);
    expect(fn () => $siteService->publicContent(null))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
it('keeps Wren dashboard and branch catalog free of unrelated modules', function () {
    $r = \Illuminate\Http\Request::create('/dashboard');
    $r->setUserResolver(fn () => $this->admin);
    $r->attributes->set('current_tenant', $this->tenant);
    $result = app(\App\Services\Dashboard\UnifiedDashboardService::class)->forRequest($r, $this->admin);
    expect($result['hero']['label'])->toBe('Spring 2027')->and($result['channel_pulse'])->toBeNull()->and($result['pinned_modules'])->toBe([]);
    expect(app(\App\Services\Tenancy\TenantModuleCatalogService::class)->tenantStorePayload($this->tenant->id)['modules'])->toBe([]);
});
it('requires actual GroupMe membership even when a group API returns a successful response', function () {
    Http::fake(['api.groupme.com/v3/users/me' => Http::response(['response' => ['id' => 'admin-groupme']]), 'api.groupme.com/v3/groups/*' => Http::response(['response' => ['id' => '1', 'members' => [['user_id' => 'another-user']]]])]);
    $svc = app(\App\Services\Soccer\SoccerGroupMeService::class);
    $svc->connect($this->tenant->id, $this->admin, 'fictional-token');
    expect(fn () => $svc->group($this->tenant->id, $this->admin, '1'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
it('rechecks withdrawn family SMS consent before sending an already queued reminder', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-20 12:00:00', 'America/New_York'));
    config()->set('soccer.sms_enabled', true);
    $family = $this->store->insert('families', $this->tenant->id, ['email' => 'parent@example.com', 'profile' => $this->store->encrypt(['phone' => '8645550100']), 'sms_consent_at' => now()]);
    app(\App\Services\Soccer\SoccerEvents::class)->record($this->tenant->id, null, $family, 'payment_reminder', [], familyText: 'Fictional reminder');
    $this->store->query('families', $this->tenant->id)->where('id', $family)->update(['sms_consent_at' => null]);
    Http::preventStrayRequests();
    app(\App\Services\Soccer\SoccerDeliveryService::class)->run($this->tenant->id);
    expect($this->store->query('deliveries', $this->tenant->id)->value('status'))->toBe('suppressed');
});
it('opens once at the scheduled Eastern instant and respects a later admin closure', function () {
    config()->set('soccer.sms_enabled', false);
    $this->store->query('seasons', $this->tenant->id)->where('id', $this->season)->update(['starts_on' => '2027-03-15', 'age_as_of' => '2027-03-15', 'registration_opens_at' => '2026-10-19 04:00:00']);
    $this->mock(\App\Services\Soccer\SoccerLaunchReadiness::class, fn ($mock) => $mock->shouldReceive('blockers')->andReturn([]));
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-19 03:59:00', 'UTC'));
    $this->artisan('soccer:process')->assertSuccessful();
    expect((bool) $this->store->row('seasons', $this->tenant->id, $this->season)->registration_open)->toBeFalse();
    $this->travel(1)->minutes();
    $this->artisan('soccer:process')->assertSuccessful();
    expect((bool) $this->store->row('seasons', $this->tenant->id, $this->season)->registration_open)->toBeTrue();
    $this->store->query('seasons', $this->tenant->id)->where('id', $this->season)->update(['registration_open' => false]);
    $this->artisan('soccer:process')->assertSuccessful();
    expect((bool) $this->store->row('seasons', $this->tenant->id, $this->season)->registration_open)->toBeFalse();
});
it('links an admin-verified additional guardian without granting backend membership', function () {
    $family = $this->store->insert('families', $this->tenant->id, ['email' => 'primary@example.com', 'profile' => $this->store->encrypt([])]);
    $svc = app(SoccerFamilyService::class);
    $svc->addGuardian($this->tenant->id, $family, 'second@example.com', 'Verified against original household contacts', $this->admin->id);
    $code = '123456';
    $this->store->insert('challenges', $this->tenant->id, ['email' => 'second@example.com', 'code_hash' => hash_hmac('sha256', 'second@example.com:'.$code, config('app.key')), 'expires_at' => now()->addMinutes(10)]);
    $before = User::count();
    $token = $svc->verify($this->tenant->id, 'second@example.com', $code);
    expect($svc->family($this->tenant->id, $token)->id)->toBe($family)->and(User::count())->toBe($before);
});
it('renders populated family, refund, import-review, and coaching screens', function () {
    $family = $this->store->insert('families', $this->tenant->id, ['email' => 'fictional@example.com', 'profile' => $this->store->encrypt(['parentName' => 'Example Parent', 'coaching_by_season' => ['spring-2027' => ['volunteer' => true, 'jersey_size' => 'Adult L']]])]);
    $player = $this->store->insert('players', $this->tenant->id, ['family_id' => $family, 'name' => 'Example Child', 'birthday' => '2020-01-01', 'profile' => $this->store->encrypt(['allergies' => 'No'])]);
    $release = $this->store->insert('releases', $this->tenant->id, ['family_id' => $family, 'player_id' => $player, 'season_key' => 'spring-2027', 'source_key' => 'test', 'form_version' => 'test', 'answers' => $this->store->encrypt([]), 'sha256' => str_repeat('a', 64), 'signed_at' => now()]);
    $this->store->insert('registrations', $this->tenant->id, ['season_id' => $this->season, 'family_id' => $family, 'player_id' => $player, 'release_id' => $release, 'team_key' => 'blue-dragons', 'status' => 'registered', 'seat_kind' => 'public', 'needs_jersey' => false, 'amount_cents' => 3000, 'payment_status' => 'paid', 'queued_at' => now()]);
    $invoice = $this->store->insert('invoices', $this->tenant->id, ['season_id' => $this->season, 'family_id' => $family, 'offer_group' => \Illuminate\Support\Str::uuid(), 'registration_ids' => json_encode([$player]), 'amount_cents' => 3000, 'status' => 'paid', 'provider' => 'venmo', 'expires_at' => now()->addHours(48)]);
    $this->store->insert('payments', $this->tenant->id, ['invoice_id' => $invoice, 'provider' => 'venmo', 'provider_key' => 'test', 'amount_cents' => 3000, 'evidence' => $this->store->encrypt([])]);
    $this->store->insert('import_rows', $this->tenant->id, ['source' => 'spring-registration-csv', 'season_key' => 'spring-2026', 'row_number' => 2, 'fingerprint' => str_repeat('b', 64), 'status' => 'review', 'family_id' => $family, 'player_id' => $player, 'evidence' => $this->store->encrypt(['row' => ['Child Name' => 'Example Child']])]);
    foreach (['parents', 'players', 'coaches', 'settings', 'messages'] as $section) {
        $this->actingAs($this->admin)->get('/soccer/'.$section.'?tenant=wren-family-soccer')->assertOk();
    }
});
it('focuses an explicit Wren login without capturing unrelated multi-workspace home redirects', function () {
    expect(\App\Support\Auth\HomeRedirect::pathFor($this->admin, $this->tenant))->toContain('/soccer');
    $other = Tenant::create(['name' => 'Other business', 'slug' => 'other-business']);
    $this->admin->tenants()->attach($other, ['role' => 'admin', 'membership_active' => true]);
    expect(\App\Support\Auth\HomeRedirect::pathFor($this->admin))->not->toContain('/soccer');
});
it('combines Sarah and family texts when their recipient phone is the same', function () {
    $family = $this->store->insert('families', $this->tenant->id, ['email' => 'sarah-fixture@example.com', 'profile' => $this->store->encrypt(['phone' => config('soccer.sms_recipient')]), 'sms_consent_at' => now()]);
    app(\App\Services\Soccer\SoccerEvents::class)->record($this->tenant->id, $this->season, $family, 'waitlist_offer', ['summary' => 'One child offered.'], waitlist: true, familyText: 'Your family offer is ready.');
    expect($this->store->query('deliveries', $this->tenant->id)->count())->toBe(1);
    $payload = $this->store->decrypt($this->store->query('deliveries', $this->tenant->id)->first()->payload);
    expect($payload['body'])->toContain('One child offered.')->toContain('Your family offer is ready.');
});
it('searches only assigned children for coaches and exposes family search to admins', function () {
    $coach = User::factory()->create(['role' => 'member', 'is_active' => true]);
    $coach->tenants()->attach($this->tenant, ['role' => 'member', 'membership_active' => true]);
    $this->store->insert('coaches', $this->tenant->id, ['user_id' => $coach->id, 'name' => 'Coach', 'team_keys' => json_encode(['green-bears'])]);
    $family = $this->store->insert('families', $this->tenant->id, ['email' => 'parent@example.com', 'profile' => $this->store->encrypt(['parentName' => 'Example Parent'])]);
    $private = $this->store->insert('players', $this->tenant->id, ['family_id' => $family, 'name' => 'Private Falcon', 'birthday' => '2018-01-01', 'profile' => $this->store->encrypt([])]);
    $this->store->insert('registrations', $this->tenant->id, ['season_id' => $this->season, 'family_id' => $family, 'player_id' => $private, 'team_key' => 'red-falcons', 'status' => 'registered', 'seat_kind' => 'public', 'needs_jersey' => false, 'amount_cents' => 3000, 'queued_at' => now()]);
    $svc = app(\App\Services\Soccer\SoccerWorkspaceService::class);
    expect($svc->search('Private', ['tenant_id' => $this->tenant->id, 'user' => $coach])['total'])->toBe(0)->and($svc->search('Example Parent', ['tenant_id' => $this->tenant->id, 'user' => $this->admin])['total'])->toBe(1);
});

it('exports signed releases through the canonical backend regardless of the workspace host', function () {
    $family = $this->store->insert('families', $this->tenant->id, ['email' => 'pdf@example.com', 'profile' => $this->store->encrypt([])]);
    $release = $this->store->insert('releases', $this->tenant->id, ['family_id' => $family, 'season_key' => 'fall-2026', 'source_key' => 'pdf-fixture', 'form_version' => 'test', 'answers' => $this->store->encrypt(['child1Name' => 'Fictional Child']), 'sha256' => str_repeat('a', 64), 'signed_at' => now()]);
    $response = $this->actingAs($this->admin)->get('https://wren-family-soccer.theeverbranch.com/soccer/release/'.$release.'?tenant=wren-family-soccer')->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect(parse_url($query['source'], PHP_URL_HOST))->toBe('app.theeverbranch.com');
    $this->getJson($query['source'])->assertOk()->assertJsonPath('playerName', 'Fictional Child');
    $this->getJson(str_replace('actor='.$this->admin->id, 'actor=999999', $query['source']))->assertForbidden();
});

it('prepares SMS only for approved tenants while leaving the sender unverified', function () {
    config(['features.tenant_messaging_provisioning' => false, 'features.tenant_sms_provisioning_tenant_ids' => [$this->tenant->id], 'services.twilio.account_sid' => 'AC'.str_repeat('1', 32), 'services.twilio.auth_token' => 'fictional-provider-token']);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'AC'.str_repeat('2', 32), 'auth_token' => 'fictional-subaccount-token'], 201)]);
    $this->actingAs($this->admin)->post('/soccer/actions/sms-prepare?tenant=wren-family-soccer')->assertRedirect();
    $account = \App\Models\TenantMessagingAccount::query()->forAllTenants()->where('tenant_id', $this->tenant->id)->where('channel', 'sms')->sole();
    expect($account->status)->toBe('awaiting_business_profile')->and($account->isReady())->toBeFalse();
    $other = Tenant::create(['name' => 'Other fixture', 'slug' => 'other-sms-fixture']);
    $provisioner = app(\App\Services\Marketing\Messaging\TenantMessagingProvisioningService::class);
    expect(fn () => $provisioner->provisionSms($other))->toThrow(RuntimeException::class);
    expect(fn () => $provisioner->provisionEmail($this->tenant, 'example.com'))->toThrow(RuntimeException::class);
    $this->post('/soccer/actions/sms-prepare?tenant=wren-family-soccer')->assertRedirect();
    Http::assertSentCount(1);
});

it('stores sender details encrypted without rendering or flashing tax identifiers or GroupMe tokens', function () {
    $input = ['operator_type' => 'registered', 'legal_name' => 'Fictional Organization', 'contact_name' => 'Fictional Contact', 'contact_email' => 'fixture@example.com', 'contact_phone' => '+12025550100', 'address' => '123 Fictional Street', 'city' => 'Example', 'state' => 'SC', 'postal_code' => '29642', 'ein' => '12-3456789'];
    $this->actingAs($this->admin)->post('/soccer/actions/sms-profile?tenant=wren-family-soccer', $input)->assertRedirect();
    expect($this->store->settings($this->tenant->id)['sms_business_profile']['ein'])->toBe('123456789');
    expect((string) $this->store->query('settings', $this->tenant->id)->value('data'))->not->toContain('123456789');
    $this->get('/soccer/settings?tenant=wren-family-soccer')->assertOk()->assertSeeText('Texting sender setup')->assertDontSee('123456789')->assertDontSee('12-3456789');
    $this->post('/soccer/actions/sms-profile?tenant=wren-family-soccer', [...$input, 'operator_type' => 'invalid', 'token' => 'fictional-sensitive-token'])->assertRedirect()->assertSessionHasErrors('operator_type')->assertSessionMissing('_old_input.ein')->assertSessionMissing('_old_input.token');
    $coach = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $coach->tenants()->attach($this->tenant, ['role' => 'member', 'membership_active' => true]);
    $this->store->insert('coaches', $this->tenant->id, ['user_id' => $coach->id, 'name' => 'Fixture coach', 'role' => 'assistant', 'team_keys' => json_encode(['green-bears'])]);
    $this->actingAs($coach)->post('/soccer/actions/sms-profile?tenant=wren-family-soccer', $input)->assertForbidden();
});
