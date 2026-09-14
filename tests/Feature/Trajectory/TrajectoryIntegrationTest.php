<?php

use App\Models\Trajectory\Account;
use App\Models\Trajectory\Connection;
use App\Models\Trajectory\Notification;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use App\Services\Trajectory\LedgerService;
use App\Services\Trajectory\PilotService;
use App\Services\Trajectory\PlaidService;
use App\Services\Trajectory\ProjectionService;
use App\Services\Trajectory\SmsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->withoutVite();
    config(['trajectory.enabled' => true]);
    $this->user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    [$this->space] = app(PilotService::class)->prepare($this->user, null, true);
    $this->account = Account::create(['space_id' => $this->space->id, 'source_key' => 'integration:cash', 'name' => 'Cash', 'kind' => 'cash', 'balance_cents' => 100000, 'observed_at' => now()]);
});

it('renders the application shell with accessible finance controls', function (): void {
    $this->actingAs($this->user)->get('/trajectory')->assertOk()->assertSee('Trajectory')->assertSee('Finance space')->assertSee('Bills &amp; goals', false);
});

it('requires preview confirmation and imports exact signed cents without duplicate rows', function (): void {
    $csv = "id,date,merchant,amount,category\none,".now()->toDateString().",Market,-19.99,groceries\n";
    $preview = $this->actingAs($this->user)->postJson('/trajectory/spaces/'.$this->space->id.'/imports/preview', ['account_id' => $this->account->id, 'kind' => 'transactions', 'file' => UploadedFile::fake()->createWithContent('bank.csv', $csv)])->assertOk();
    expect(Transaction::count())->toBe(0);
    $token = $preview->json('token');
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/imports/confirm', ['token' => $token])->assertOk();
    expect(Transaction::first()->amount_cents)->toBe(-1999);
    $this->postJson('/trajectory/spaces/'.$this->space->id.'/imports/confirm', ['token' => $token])->assertStatus(410);
});

it('rejects imports into accounts outside the authorized space', function (): void {
    $other = User::factory()->create(['is_active' => true]);
    [$otherSpace] = app(PilotService::class)->prepare($other, null, true);
    $this->actingAs($this->user)->postJson('/trajectory/spaces/'.$otherSpace->id.'/imports/preview', ['kind' => 'transactions'])->assertForbidden();
});

it('requires household invitations to match the verified signed-in email', function (): void {
    $partner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $invite = $this->actingAs($this->user)->postJson('/trajectory/spaces/'.$this->space->id.'/invites', ['email' => $partner->email])->assertOk();
    parse_str(parse_url($invite->json('invite_url'), PHP_URL_QUERY), $query);
    $this->postJson('/trajectory/invites/accept', ['token' => $query['invite']])->assertForbidden();
    $this->actingAs($partner)->postJson('/trajectory/invites/accept', ['token' => $query['invite']])->assertOk();
    $this->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard')->assertOk();
    $this->actingAs($this->user)->deleteJson('/trajectory/spaces/'.$this->space->id.'/members/'.$partner->id)->assertOk();
    $this->actingAs($partner)->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard')->assertForbidden();
});

it('rejects stale classification edits and can undo the latest review', function (): void {
    app(LedgerService::class)->ingest($this->account, [['id' => 'one', 'date' => now()->toDateString(), 'merchant' => 'Store', 'amount_cents' => -100]]);
    $tx = Transaction::first();
    $body = ['version' => $tx->version, 'category' => 'shopping', 'flow' => 'expense', 'face_punched' => true, 'bullshit_spending' => false];
    $url = '/trajectory/spaces/'.$this->space->id.'/transactions/'.$tx->id;
    $this->actingAs($this->user)->patchJson($url, $body)->assertOk();
    $this->patchJson($url, $body)->assertConflict();
    $this->postJson($url.'/undo', ['version' => $tx->fresh()->version])->assertOk();
    expect($tx->fresh()->face_punched)->toBeFalse();
});

it('caps goal reserves at their remaining target and reduces spending without reducing income', function (): void {
    $p = app(ProjectionService::class);
    $today = CarbonImmutable::parse('2026-01-01');
    $goals = [['target_cents' => 10000, 'saved_cents' => 9900, 'monthly_cents' => 3000]];
    $base = $p->forecast(100000, [1 => ['income' => 1000, 'expense' => -500]], [], [], $goals, [], $today, 1);
    $scenario = $p->forecast(100000, [1 => ['income' => 1000, 'expense' => -500]], [], [], $goals, ['spending_reduction_bps' => 2000], $today, 1);
    expect($base['daily'][0]['cash_cents'])->toBe(100500)->and($scenario['daily'][0]['cash_cents'])->toBe(100600)->and($base['daily'][count($base['daily']) - 1]['goal_reserve_cents'])->toBe(10000);
});

it('does not charge credit purchases to cash until the scheduled payment', function (): void {
    $data = app(ProjectionService::class)->forecast(100000, [7 => -1000], [], [['account_id' => 7, 'kind' => 'credit', 'balance_cents' => 0, 'apr_bps' => 0, 'payment_cents' => 10000, 'next_due_on' => '2026-01-03']], [], [], CarbonImmutable::parse('2026-01-01'), 1);
    expect($data['daily'][0]['cash_cents'])->toBe(100000)->and($data['daily'][0]['debt_cents'])->toBe(1000)->and($data['daily'][1]['cash_cents'])->toBe(98000);
});

it('requests two years of transactions and consents to compatible debt and investment data', function (): void {
    config(['trajectory.plaid.client_id' => 'fixture', 'trajectory.plaid.secret' => 'fixture', 'trajectory.plaid.environment' => 'sandbox']);
    Http::fake(['*/link/token/create' => Http::response(['link_token' => 'link-fixture', 'expiration' => now()->addHour()->toIso8601String()])]);

    $link = app(PlaidService::class)->link($this->space);

    expect($link['link_token'])->toBe('link-fixture');
    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return str_ends_with($request->url(), '/link/token/create')
            && $request['products'] === ['transactions']
            && $request['additional_consented_products'] === ['investments', 'liabilities']
            && $request['transactions']['days_requested'] === 730;
    });
});

it('adds supported USD investment accounts to net-worth evidence without assuming holdings', function (): void {
    config(['trajectory.plaid.client_id' => 'fixture', 'trajectory.plaid.secret' => 'fixture', 'trajectory.plaid.environment' => 'sandbox']);
    $connection = Connection::create(['space_id' => $this->space->id, 'external_id' => 'investment-item', 'access_token' => 'fixture-token']);
    Http::fake(['*/investments/holdings/get' => Http::response(['accounts' => [[
        'account_id' => 'brokerage-a', 'name' => 'Brokerage', 'balances' => ['current' => 321.09, 'iso_currency_code' => 'USD'],
    ]]])]);

    app(PlaidService::class)->investments($connection);

    expect(Account::where('source_key', 'plaid:'.$connection->id.':brokerage-a')->firstOrFail())
        ->kind->toBe('investment')
        ->balance_cents->toBe(32109)
        ->and($connection->fresh()->coverage['investments']['account_count'])->toBe(1);
});

it('keeps failed bank pagination atomic and commits the cursor with complete changes', function (): void {
    config(['trajectory.plaid.client_id' => 'fixture', 'trajectory.plaid.secret' => 'fixture', 'trajectory.plaid.environment' => 'sandbox']);
    $connection = Connection::create(['space_id' => $this->space->id, 'external_id' => 'item-fixture', 'access_token' => 'secret-fixture']);
    $account = ['account_id' => 'bank-a', 'name' => 'Bank', 'type' => 'depository', 'balances' => ['current' => 100, 'iso_currency_code' => 'USD']];
    Http::fake(['*/transactions/sync' => Http::sequence()->push(['accounts' => [$account], 'added' => [['account_id' => 'bank-a', 'transaction_id' => 'one', 'date' => '2026-09-01', 'name' => 'Market', 'amount' => 5.25, 'pending' => false, 'iso_currency_code' => 'USD']], 'modified' => [], 'removed' => [], 'next_cursor' => 'page-2', 'has_more' => true])->push(['error_code' => 'TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION'], 400)]);
    try {
        app(PlaidService::class)->sync($connection);
    } catch (\Illuminate\Validation\ValidationException) {
    }
    expect(Transaction::count())->toBe(0)->and($connection->fresh()->cursor)->toBeNull();
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake(['*/transactions/sync' => Http::response(['accounts' => [$account], 'added' => [['account_id' => 'bank-a', 'transaction_id' => 'one', 'date' => '2026-09-01', 'name' => 'Market', 'amount' => 5.25, 'pending' => false, 'iso_currency_code' => 'USD']], 'modified' => [], 'removed' => [], 'next_cursor' => 'done', 'has_more' => false]), '*/liabilities/get' => Http::response(['liabilities' => []])]);
    app(PlaidService::class)->sync($connection);
    expect(Transaction::first()->amount_cents)->toBe(-525)->and($connection->fresh()->cursor)->toBe('done');
    expect(DB::table('trajectory_connections')->where('id', $connection->id)->value('access_token'))->not->toContain('secret-fixture');
});

it('rejects forged bank webhook signatures before enqueuing work', function (): void {
    $this->postJson('/api/trajectory/webhooks/plaid', ['item_id' => 'unknown'], ['Plaid-Verification' => 'invalid'])->assertForbidden();
    expect(app(PlaidService::class)->verifyWebhook('a.b.c', '{}'))->toBeFalse();
});

it('accepts SMS replies only for the verified sender and consumes the reference once', function (): void {
    config(['trajectory.sms_enabled' => true]);
    app(LedgerService::class)->ingest($this->account, [['id' => 'sms', 'date' => now()->toDateString(), 'merchant' => 'Repair', 'amount_cents' => -10000]]);
    $tx = Transaction::first();
    Record::create(['space_id' => $this->space->id, 'kind' => 'sms', 'name' => (string) $this->user->id, 'data' => ['user_id' => $this->user->id, 'phone' => '+15555550101', 'verified_at' => now()->toIso8601String(), 'consented_at' => now()->toIso8601String()]]);
    $n = Notification::create(['space_id' => $this->space->id, 'user_id' => $this->user->id, 'transaction_id' => $tx->id, 'dedupe_key' => 'sms-fixture', 'phone' => '+15555550101', 'body' => 'Fixture', 'reply_hash' => hash('sha256', 'ABCDEFGH1234'), 'status' => 'sent', 'expires_at' => now()->addHour()]);
    $sms = app(SmsService::class);
    $sms->reply('+15555550102', 'ABCDEFGH1234 FACE', 'sid-other');
    expect($tx->fresh()->face_punched)->toBeFalse();
    $sms->reply('+15555550101', 'ABCDEFGH1234 FACE', 'sid-right');
    expect($tx->fresh()->face_punched)->toBeTrue();
    $sms->reply('+15555550101', 'ABCDEFGH1234 BS', 'sid-replay');
    expect($tx->fresh()->bullshit_spending)->toBeFalse();
    $sms->reply('+15555550101', 'STOP', 'sid-stop');
    expect(Record::where('kind', 'sms')->first()->active)->toBeFalse();
});

it('denies household access and invitations to unverified accounts', function (): void {
    $this->user->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($this->user)->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard')->assertForbidden();
});

it('validates a real ES256 bank webhook and rejects body tampering', function (): void {
    config(['trajectory.plaid.client_id' => 'fixture', 'trajectory.plaid.secret' => 'fixture']);
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $ec = openssl_pkey_get_details($key)['ec'];
    $base64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $body = '{"item_id":"fixture"}';
    $parts = [$base64(json_encode(['alg' => 'ES256', 'kid' => 'fixture'])), $base64(json_encode(['iat' => time(), 'request_body_sha256' => hash('sha256', $body)]))];
    openssl_sign(implode('.', $parts), $der, $key, OPENSSL_ALGO_SHA256);
    $offset = 2;
    $offset++;
    $rLength = ord($der[$offset++]);
    $r = substr($der, $offset, $rLength);
    $offset += $rLength;
    $offset++;
    $sLength = ord($der[$offset++]);
    $s = substr($der, $offset, $sLength);
    $raw = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT).str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    Http::fake(['*/webhook_verification_key/get' => Http::response(['key' => ['alg' => 'ES256', 'crv' => 'P-256', 'x' => $base64($ec['x']), 'y' => $base64($ec['y']), 'expired_at' => null]])]);
    $jwt = implode('.', $parts).'.'.$base64($raw);
    expect(app(PlaidService::class)->verifyWebhook($jwt, $body))->toBeTrue()->and(app(PlaidService::class)->verifyWebhook($jwt, '{}'))->toBeFalse();
});

it('reads Square and Shopify as evidence without adding them to QuickBooks income', function (): void {
    $tenant = \App\Models\Tenant::create(['slug' => 'trajectory-revenue', 'name' => 'Revenue fixture']);
    \App\Models\TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->user->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    [, $business] = app(PilotService::class)->prepare($this->user, $tenant, true);
    $connection = \App\Models\IntegrationConnection::create(['tenant_id' => $tenant->id, 'provider' => 'quickbooks', 'external_account_id' => hash('sha256', 'fixture-qbo'), 'status' => 'connected', 'access_token' => 'fixture']);
    \App\Models\QuickBooksReportingSnapshot::create(['tenant_id' => $tenant->id, 'integration_connection_id' => $connection->id, 'range_key' => 'month', 'period_start' => now()->startOfMonth(), 'period_end' => now(), 'observed_at' => now(), 'metrics' => ['total_income' => '1000.00', 'total_expenses' => '400.00', 'accounting_method' => 'Cash']]);
    \App\Models\SquareOrder::create(['tenant_id' => $tenant->id, 'square_order_id' => 'fixture-square', 'state' => 'COMPLETED', 'closed_at' => now(), 'total_money_amount' => 50000, 'total_money_currency' => 'USD']);
    \App\Models\Order::create(['tenant_id' => $tenant->id, 'shopify_order_id' => 'fixture-shopify', 'order_type' => 'retail', 'source' => 'shopify', 'ordered_at' => now(), 'total_price' => 400]);
    $data = app(\App\Services\Trajectory\DashboardService::class)->build($business);
    expect($data['business']['ledger']['income_cents'])->toBe(100000)->and($data['business']['channels']['revenue_cents'])->toBe(90000);
});

it('replaces a linked bill with its debt payment and pays debt goals without double reserving cash', function (): void {
    $today = CarbonImmutable::parse('2026-01-01');
    $data = app(ProjectionService::class)->forecast(100000, [], [['id' => 42, 'amount_cents' => -15000, 'cadence' => 'monthly', 'next_due_on' => '2026-01-02']], [['id' => 7, 'recurring_record_id' => 42, 'balance_cents' => 50000, 'apr_bps' => 0, 'payment_cents' => 10000, 'next_due_on' => '2026-01-02']], [['goal_type' => 'debt_paydown', 'debt_record_id' => 7, 'target_cents' => 10000, 'saved_cents' => 0, 'monthly_cents' => 5000]], [], $today, 1);
    expect($data['daily'][0]['cash_cents'])->toBe(85000)->and($data['daily'][0]['debt_cents'])->toBe(35000)->and($data['daily'][0]['goal_reserve_cents'])->toBe(0);
});

it('uses reviewed seasonal bill amounts and category-specific savings scenarios', function (): void {
    $service = app(ProjectionService::class);
    $result = $service->forecast(100000, [1 => ['income' => 1000, 'expense' => -500, 'categories' => ['shopping' => -200, 'groceries' => -300]]], [['id' => 1, 'amount_cents' => -1000, 'cadence' => 'monthly', 'next_due_on' => '2026-01-02', 'seasonal_amounts_cents' => [1 => -2000]]], [], [], ['reduction_category' => 'shopping', 'spending_reduction_bps' => 5000], CarbonImmutable::parse('2026-01-01'), 1);
    expect($result['daily'][0]['cash_cents'])->toBe(98600);
});

it('matches metal cash movements once and retains sold lot basis', function (): void {
    $ledger = app(LedgerService::class);
    $ledger->ingest($this->account, [['id' => 'metal-buy', 'date' => now()->toDateString(), 'merchant' => 'Bullion', 'amount_cents' => -20000], ['id' => 'metal-sell', 'date' => now()->toDateString(), 'merchant' => 'Bullion sale', 'amount_cents' => 12000]]);
    $buy = Transaction::where('amount_cents', -20000)->first();
    $sell = Transaction::where('amount_cents', 12000)->first();
    $lot = ['metal' => 'silver', 'quantity' => '2', 'weight' => '1', 'unit' => 'troy_ounce', 'purity_bps' => 10000, 'cost_basis_cents' => 20000, 'acquired_on' => now()->toDateString(), 'resale_adjustment_cents' => 0, 'transaction_id' => $buy->id];
    $url = '/trajectory/spaces/'.$this->space->id.'/records';
    $this->actingAs($this->user)->postJson($url, ['kind' => 'metal', 'name' => 'Silver', 'data' => [...$lot, 'cost_basis_cents' => 19000]])->assertStatus(422);
    $created = $this->postJson($url, ['kind' => 'metal', 'name' => 'Silver', 'data' => $lot])->assertOk()->json();
    $this->postJson($url, ['kind' => 'metal', 'name' => 'Duplicate lot', 'data' => $lot])->assertStatus(422);
    app(\App\Services\Trajectory\MetalsService::class)->sell($this->user, Record::findOrFail($created['id']), '1', 12000, 1, $sell->id);
    expect(Record::find($created['id'])->data['cost_basis_cents'])->toBe(10000)
        ->and($buy->fresh()->flow)->toBe('asset_transfer')
        ->and($sell->fresh()->flow)->toBe('asset_transfer');
    $this->patchJson($url.'/'.$created['id'], ['kind' => 'metal', 'name' => 'Reset history', 'data' => $lot, 'version' => 2])->assertStatus(422);
});

it('eliminates linked equity from combined net worth and refuses ambiguous equity totals', function (): void {
    $tenant = \App\Models\Tenant::create(['name' => 'Equity company', 'slug' => 'equity-company', 'status' => 'active']);
    $tenant->accessProfile()->create(['enabled_features' => ['base']]);
    $tenant->users()->attach($this->user->id, ['role' => 'owner', 'membership_active' => true]);
    [, $business] = app(PilotService::class)->prepare($this->user, $tenant, true);
    Account::create(['space_id' => $business->id, 'source_key' => 'company-cash', 'name' => 'Company cash', 'kind' => 'cash', 'balance_cents' => 500000, 'observed_at' => now()]);
    $equity = Record::create(['space_id' => $this->space->id, 'kind' => 'asset', 'name' => 'Company equity', 'data' => ['asset_type' => 'business_equity', 'value_cents' => 500000, 'linked_business_space_id' => $business->id, 'observed_on' => now()->toDateString()]]);
    $combined = app(\App\Services\Trajectory\ReconciliationService::class)->combined($this->user, $this->space->id, $business->id);
    expect($combined['net_worth_cents'])->toBe(600000)->and($combined['excluded_business_equity_cents'])->toBe(500000);
    $data = $equity->data;
    unset($data['linked_business_space_id']);
    $equity->update(['data' => $data]);
    expect(app(\App\Services\Trajectory\ReconciliationService::class)->combined($this->user, $this->space->id, $business->id)['net_worth_cents'])->toBeNull();
});

it('prepares a business-only pilot without creating a household or billing subscription', function (): void {
    $owner = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $tenant = \App\Models\Tenant::create(['name' => 'Business only', 'slug' => 'business-only', 'status' => 'active']);
    $tenant->accessProfile()->create(['enabled_features' => ['base']]);
    $tenant->users()->attach($owner->id, ['role' => 'owner', 'membership_active' => true]);
    $spaces = app(PilotService::class)->prepare($owner, $tenant, true, 'business');
    expect($spaces)->toHaveCount(1)->and($spaces[0]->kind)->toBe('business')
        ->and(\App\Models\Trajectory\Space::where('owner_user_id', $owner->id)->where('kind', 'household')->exists())->toBeFalse()
        ->and(config('trajectory.checkout_enabled'))->toBeFalse();
});

it('uses original cash movements in forecasts while showing allocated spending', function (): void {
    $this->account->update(['history_start' => now()->subDays(90)]);
    app(LedgerService::class)->ingest($this->account, [['id' => 'mixed-purpose', 'date' => now()->subDay()->toDateString(), 'merchant' => 'Mixed purchase', 'amount_cents' => -9000, 'category' => 'shopping']]);
    // Cross-space split authorization is exercised separately; establish its persisted result here.
    \App\Models\Trajectory\Allocation::where('transaction_id', Transaction::first()->id)->update(['amount_cents' => -3000]);
    $view = app(\App\Services\Trajectory\DashboardService::class)->build($this->space);
    expect($view['forecast']['daily'][0]['cash_cents'])->toBe(99900);
});

it('sanitizes provider debt suggestions and keeps mortgage escrow for review', function (): void {
    $connection = Connection::create(['space_id' => $this->space->id, 'external_id' => 'liability-item', 'access_token' => 'private-token', 'status' => 'connected', 'coverage' => ['observed_at' => now()->toIso8601String(), 'liabilities' => ['mortgage' => [['account_id' => 'mortgage-remote', 'account_number' => 'sensitive-number', 'interest_rate' => ['percentage' => 6.25], 'next_monthly_payment' => 2200, 'next_payment_due_date' => '2026-10-01']]]]]);
    Account::create(['space_id' => $this->space->id, 'connection_id' => $connection->id, 'source_key' => 'plaid:'.$connection->id.':mortgage-remote', 'name' => 'Mortgage', 'kind' => 'loan', 'balance_cents' => 25000000]);
    $rows = app(PlaidService::class)->debtSuggestions($this->space);
    expect($rows[0]['apr_bps'])->toBe(625)->and($rows[0]['payment_cents'])->toBeNull()
        ->and(json_encode($rows))->not->toContain('sensitive-number')->not->toContain('private-token');
});

it('denies business staff and household partners company financial routes', function (): void {
    $tenant = \App\Models\Tenant::create(['name' => 'Private company', 'slug' => 'private-company', 'status' => 'active']);
    $tenant->accessProfile()->create(['enabled_features' => ['base']]);
    $tenant->users()->attach($this->user->id, ['role' => 'owner', 'membership_active' => true]);
    [, $business] = app(PilotService::class)->prepare($this->user, $tenant, true);
    $staff = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $tenant->users()->attach($staff->id, ['role' => 'member', 'membership_active' => true]);
    DB::table('trajectory_members')->insert(['space_id' => $this->space->id, 'user_id' => $staff->id]);
    $this->actingAs($staff)->getJson('/trajectory/spaces/'.$this->space->id.'/dashboard')->assertOk();
    $this->getJson('/trajectory/spaces/'.$business->id.'/dashboard')->assertForbidden();
    $this->getJson('/trajectory/spaces/'.$this->space->id.'/combined?business_id='.$business->id)->assertForbidden();
    $this->getJson('/trajectory/spaces/'.$business->id.'/reconciliation')->assertForbidden();
});

it('returns older evidence by authorized allocation and keeps daily income consistent', function (): void {
    app(LedgerService::class)->ingest($this->account, [
        ['id' => 'old-evidence', 'date' => now()->subMonths(2)->toDateString(), 'merchant' => 'Old shopping', 'amount_cents' => -9900, 'category' => 'shopping'],
        ['id' => 'owner-out', 'date' => now()->toDateString(), 'merchant' => 'Owner pay', 'amount_cents' => -10000],
    ]);
    $old = Transaction::where('amount_cents', -9900)->first();
    $out = Transaction::where('amount_cents', -10000)->first();
    $out->update(['flow' => 'owner_wages', 'reviewed' => true]);
    $this->actingAs($this->user)->postJson('/trajectory/spaces/'.$this->space->id.'/evidence', ['ids' => [$old->id]])->assertOk()->assertJsonPath('0.amount_cents', -9900);
    $other = User::factory()->create(['is_active' => true]);
    [$otherSpace] = app(PilotService::class)->prepare($other, null, true);
    $this->actingAs($other)->postJson('/trajectory/spaces/'.$otherSpace->id.'/evidence', ['ids' => [$old->id]])->assertOk()->assertExactJson([]);
    $view = app(\App\Services\Trajectory\DashboardService::class)->build($this->space);
    expect(array_sum(array_column($view['daily_series'], 'income_cents')))->toBe($view['summary']['income_cents']);
});

it('nets refunds before estimating spending reductions and savings opportunities', function (): void {
    $this->account->update(['history_start' => now()->subDays(90)]);
    app(LedgerService::class)->ingest($this->account, [
        ['id' => 'purchased', 'date' => now()->subDays(2)->toDateString(), 'merchant' => 'Shop', 'amount_cents' => -18000, 'category' => 'shopping'],
        ['id' => 'returned', 'date' => now()->subDay()->toDateString(), 'merchant' => 'Shop', 'amount_cents' => 9000, 'category' => 'shopping'],
    ]);
    $scenario = Record::create(['space_id' => $this->space->id, 'kind' => 'scenario', 'name' => 'Half the net shopping', 'data' => ['spending_reduction_bps' => 5000, 'reduction_category' => 'shopping']]);
    $data = app(\App\Services\Trajectory\DashboardService::class)->build($this->space, 'month', $scenario->id);
    expect($data['forecast']['daily'][0]['cash_cents'])->toBe(99900)
        ->and($data['comparison']['daily'][0]['cash_cents'])->toBe(99950)
        ->and($data['recommendations'][0]['monthly_savings_cents'])->toBe(3000);
});
