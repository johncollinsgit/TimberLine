<?php

use App\Models\Tenant;
use App\Models\User;
use App\Proposals\FinancialScenario;
use App\Proposals\ProposalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    $this->tenant = Tenant::create(['name' => 'Fictional financial fixture', 'slug' => 'financial-owner']);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    config(['service_proposals.enabled' => true, 'service_proposals.writes_enabled' => true, 'service_proposals.publish_enabled' => true,
        'service_proposals.owner_workspaces' => [$this->tenant->id => 'enterprise'],
        'service_proposals.recipient_hosts' => ['evergrove.test'], 'evergrove.hosts' => ['evergrove.test']]);
    $this->service = app(ProposalService::class);
    $this->draft = $this->service->leesMcRaeDraft($this->tenant, $this->owner->id);
    $this->calc = app(FinancialScenario::class);
    $this->previewUrl = '/owner-workspaces/'.$this->tenant->slug.'/proposals/'.$this->draft->id.'/preview';
    $this->cartUrl = '/owner-workspaces/'.$this->tenant->slug.'/proposals/'.$this->draft->id.'/financial-planning';
});

function financialInput($test, int $count = 1, int $offer = 0): array
{
    return $test->calc->prepare($test->draft->payload, ['name' => 'Fictional Light '.$count, 'service_id' => $test->draft->payload['items'][$offer]['id'],
        'department_count' => $count, 'profile' => 'light']);
}

function financialRows(): array
{
    $out = [];
    foreach (['service_proposal_assets', 'service_proposal_drafts', 'service_proposal_versions', 'service_proposal_reviews', 'service_proposal_comments', 'tenant_user'] as $table) {
        $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }

    return $out;
}

function financialPrepare($test, string $getUrl, string $postUrl, array $override = []): array
{
    $binding = $test->get($getUrl)->assertOk()->viewData('financial')['binding'];
    $test->post($postUrl, ['planning_hash' => $binding, 'action' => 'prepare', 'name' => 'PRIVATE PLANNING SENTINEL',
        'service_id' => $test->draft->payload['items'][0]['id'], 'department_count' => 1, 'profile' => 'light', ...$override])
        ->assertRedirectContains('section=scope#usage-estimator');

    return $test->get($getUrl)->assertOk()->viewData('financial');
}

test('unit arithmetic matches the pinned dated provider receipt without floating currency rounding', function (): void {
    $receipt = json_decode(file_get_contents(base_path('docs/operations/evidence/proposal-financial-rates-2026-10-23.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($receipt['verified_on'])->toBe('2026-10-23')->and($receipt['sources'][0]['facts']['gpt-6-luna'])->toBe(['input' => 0.10, 'output' => 0.50]);
    expect(hash_file('sha256', base_path('docs/operations/evidence/proposal-financial-rates-2026-10-23.json')))->toBe(FinancialScenario::RATE_RECEIPT_SHA256);
    foreach (FinancialScenario::MODELS as $model => $rates) {
        expect($rates)->toBe([(int) round($receipt['sources'][0]['facts'][$model]['input'] * 1000), (int) round($receipt['sources'][0]['facts'][$model]['output'] * 1000)]);
        $modelInput = financialInput($this);
        $modelInput['departments'][0]['model'] = $model;
        $modelResult = $this->calc->calculate($this->draft->payload, $modelInput);
        expect($modelResult['departments'][0]['lines'][0]['low_nano'])->toBe(100 * (2000 * $rates[0] + 500 * $rates[1]));
    }
    expect($receipt['sources'][1]['facts'])->toMatchArray(['base' => .0083, 'carrier_low' => .0035, 'carrier_high' => .005, 'number_monthly' => 1.15])
        ->and($receipt['sources'][2]['facts'])->toMatchArray(['per_1000_emails' => .10, 'per_gb_attachment_data' => .12]);
    $input = financialInput($this);
    $result = $this->calc->calculate($this->draft->payload, $input);
    // 100*(2000*.10+500*.50)/1M=.045; 30*.05=1.50; SMS1.18–1.33; email .10+.012=.112.
    expect(array_column($result['departments'][0]['lines'], 'low_nano'))->toBe([45000000, 1500000000, 1180000000, 112000000])
        ->and($result['usage_low_nano'])->toBe(2837000000)->and($result['usage_high_nano'])->toBe(2987000000)
        ->and($result['combined_low_nano'])->toBe(1002837000000)->and($result['number_rental_nano'])->toBeNull()
        ->and($result['all_in_total'])->toBeNull()->and($result['markup_nano'])->toBe(0)
        ->and(implode(' ', $result['pending']))->toContain('rental quantity', 'unverified', 'unpriced')
        ->and(FinancialScenario::money(45000000))->toBe('$0.05')->and(FinancialScenario::money(1))->toBe('<$0.01');
    $input['phone_numbers'] = 2;
    $withNumbers = $this->calc->calculate($this->draft->payload, $input);
    expect($withNumbers['number_rental_nano'])->toBe(2300000000)->and($withNumbers['usage_low_nano'])->toBe(5137000000);
});

test('only exact immutable native offers gain a full-scope combined subtotal and two departments never gain a fabricated price', function (): void {
    expect($this->calc->capacity($this->draft->payload, $this->draft->payload['items'][0]))->toBe(1)
        ->and($this->calc->capacity($this->draft->payload, $this->draft->payload['items'][1]))->toBe(3);
    $two = $this->calc->calculate($this->draft->payload, financialInput($this, 2, 1));
    $three = $this->calc->calculate($this->draft->payload, financialInput($this, 3, 1));
    expect($two['combined_low_nano'])->toBeNull()->and($two['service']['totals_cents']['month'])->toBe(250000)
        ->and($three['combined_low_nano'])->toBe(2508511000000);
    foreach (['reference', 'client_id', 'exclusive_group', 'name', 'charge', 'extra_charge', 'extra_charge_key'] as $change) {
        $payload = $this->draft->payload;
        match ($change) {
            'reference' => $payload['client']['reference'] = 'another-college',
            'client_id' => $payload['items'][0]['client_id'] = (string) Str::uuid(),
            'exclusive_group' => $payload['items'][0]['exclusive_group'] = 'other',
            'name' => $payload['items'][0]['name'] = 'One workspace altered',
            'charge' => $payload['items'][0]['charges'][1]['amount_cents'] = 100001,
            'extra_charge' => $payload['items'][0]['charges'][] = ['label' => 'Extra', 'amount_cents' => 1, 'cadence' => 'month'],
            'extra_charge_key' => $payload['items'][0]['charges'][0]['discount'] = 1,
        };
        expect($this->calc->calculate($payload, financialInput($this))['combined_low_nano'])->toBeNull();
    }
    expect(fn () => $this->calc->prepare($this->draft->payload, ['name' => 'Too many', 'service_id' => $this->draft->payload['items'][0]['id'], 'department_count' => 2, 'profile' => 'light']))->toThrow(ValidationException::class);
});

test('department-specific volumes and separate nullable payment preferences never discount pooled usage', function (): void {
    $input = financialInput($this, 3, 1);
    expect($input['management'])->toBeNull()->and($input['method'])->toBeNull()->and($input['departments'][0]['method'])->toBeNull();
    $input['departments'][1] = [...$this->calc->profile('busy'), 'label' => 'Fictional busy department'];
    $input['departments'][2] = [...$this->calc->profile('moderate'), 'label' => 'Fictional moderate department'];
    $input['management'] = 'university';
    $input['method'] = 'prepaid';
    $pool = $this->calc->calculate($this->draft->payload, $input);
    $input['management'] = 'department';
    $input['departments'][1]['method'] = 'actual';
    $separate = $this->calc->calculate($this->draft->payload, $input);
    expect($pool['usage_low_nano'])->toBe($separate['usage_low_nano'])->and($pool['usage_high_nano'])->toBe($separate['usage_high_nano'])
        ->and($separate['input']['method'])->toBeNull()->and($separate['input']['departments'][0]['method'])->toBeNull()
        ->and($separate['input']['departments'][1]['method'])->toBe('actual')
        ->and($pool['departments'][0]['low_nano'])->not->toBe($pool['departments'][1]['low_nano']);
});

test('bounded validation rejects forged models units terms rows and foreign snapshot items', function (): void {
    foreach (['model', 'negative', 'decimal_count', 'attachment', 'term', 'foreign', 'rowkey', 'rowextra', 'huge'] as $case) {
        $input = financialInput($this);
        match ($case) {
            'model' => $input['departments'][0]['model'] = 'unapproved-model',
            'negative' => $input['departments'][0]['voice_minutes'] = -1,
            'decimal_count' => $input['departments'][0]['sms_segments'] = '1.5',
            'attachment' => $input['departments'][0]['attachment_gb'] = '1e9',
            'term' => $input['term_months'] = 18,
            'foreign' => $input['service_id'] = (string) Str::uuid(),
            'rowkey' => $input['departments'] = ['forged' => $input['departments'][0]],
            'rowextra' => $input['departments'][0]['rate'] = '0',
            'huge' => $input['departments'][0]['questions'] = 1000001,
        };
        expect(fn () => $this->calc->calculate($this->draft->payload, $input))->toThrow(ValidationException::class);
    }
    $input = financialInput($this, 3, 1);
    foreach ($input['departments'] as &$row) {
        $row = [...$row, 'model' => 'gpt-6-astra', 'questions' => 1000000, 'input_tokens' => 100000, 'output_tokens' => 100000,
            'voice_minutes' => 1000000, 'sms_segments' => 100000000, 'emails' => 1000000000, 'attachment_gb' => '999999.999'];
    }
    unset($row);
    expect($this->calc->calculate($this->draft->payload, $input)['usage_high_nano'])->toBeInt();
});

test('owner planning adds compares removes clears and exactly retries without changing any commercial or access row', function (): void {
    $before = financialRows();
    $this->actingAs($this->owner);
    config(['service_proposals.writes_enabled' => false, 'service_proposals.publish_enabled' => false]);
    $state = financialPrepare($this, $this->previewUrl, $this->cartUrl);
    $save = ['action' => 'save', 'planning_hash' => $state['binding'], 'scenario_id' => $state['draft']['id'], 'scenario' => $state['draft']['input']];
    $save['scenario']['departments'][0]['label'] = '<script>FICTIONAL</script>';
    $this->post($this->cartUrl, $save)->assertRedirectContains('section=scope');
    $this->post($this->cartUrl, $save)->assertRedirect();
    $reordered = $save;
    $reordered['scenario']['departments'][0] = array_reverse($reordered['scenario']['departments'][0], true);
    $this->post($this->cartUrl, $reordered)->assertRedirect();
    $response = $this->get($this->previewUrl.'?section=scope')->assertOk()->assertSee('&lt;script&gt;FICTIONAL&lt;/script&gt;', false)->assertDontSee('<script>FICTIONAL</script>', false);
    expect($response->viewData('financial')['scenarios'])->toHaveCount(1);
    $changed = $save;
    $changed['scenario']['departments'][0]['questions']++;
    $this->post($this->cartUrl, $changed)->assertConflict();
    $this->post($this->cartUrl, ['action' => 'remove', 'planning_hash' => $state['binding'], 'scenario_id' => (string) Str::uuid()])->assertNotFound();
    $this->post($this->cartUrl, ['action' => 'remove', 'planning_hash' => $state['binding'], 'scenario_id' => $save['scenario_id']])->assertRedirect();
    expect($this->get($this->previewUrl)->viewData('financial')['scenarios'])->toBe([]);
    $this->post($this->cartUrl, ['action' => 'clear', 'planning_hash' => $state['binding']])->assertRedirect();
    expect(financialRows())->toBe($before);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('cart is bounded to three comparisons and profiles only replace the chosen department quantities', function (): void {
    $this->actingAs($this->owner);
    for ($i = 0; $i < 3; $i++) {
        $state = financialPrepare($this, $this->previewUrl, $this->cartUrl, ['service_id' => $this->draft->payload['items'][1]['id'], 'department_count' => 3]);
        $input = $state['draft']['input'];
        $input['departments'][1]['profile'] = 'busy';
        $this->post($this->cartUrl, ['action' => 'profile:1', 'planning_hash' => $state['binding'], 'scenario_id' => $state['draft']['id'], 'scenario' => $input])->assertRedirect();
        $state = $this->get($this->previewUrl)->viewData('financial');
        expect($state['draft']['input']['departments'][0]['questions'])->toBe(100)->and($state['draft']['input']['departments'][1]['questions'])->toBe(2000);
        $this->post($this->cartUrl, ['action' => 'save', 'planning_hash' => $state['binding'], 'scenario_id' => $state['draft']['id'], 'scenario' => $state['draft']['input']])->assertRedirect();
    }
    $state = $this->get($this->previewUrl)->viewData('financial');
    expect($state['scenarios'])->toHaveCount(3);
    $this->post($this->cartUrl, ['action' => 'prepare', 'planning_hash' => $state['binding']])->assertSessionHasErrors('financial');
});

test('planning is bound to current owner identity draft revision and exact immutable recipient version', function (): void {
    $this->actingAs($this->owner);
    $state = financialPrepare($this, $this->previewUrl, $this->cartUrl);
    $this->draft->update(['revision' => 2]);
    expect((int) $this->draft->fresh()->revision)->toBe(2);
    $newHash = $this->get($this->previewUrl)->viewData('financial')['binding'];
    expect($newHash)->not->toBe($state['binding']);
    $this->post($this->cartUrl, ['action' => 'clear', 'planning_hash' => $state['binding']])->assertConflict();
    expect($this->get($this->previewUrl)->viewData('financial')['draft'])->toBeNull();
    $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    $this->post($this->cartUrl, ['action' => 'clear', 'planning_hash' => $state['binding']])->assertForbidden();
    $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => true]);
    $this->draft->revision = 1;
    $this->draft->save();
    ['version' => $version, 'token' => $token] = $this->service->publish($this->tenant, $this->owner->id, $this->draft->id, 1, 'Fictional proposal password', 7, (string) Str::uuid(), 'reviewer');
    $url = 'https://evergrove.test/service-proposals/'.$token;
    $this->post($url.'/financial-planning', ['action' => 'clear', 'planning_hash' => $state['binding']])->assertForbidden();
    $this->post($url.'/unlock', ['password' => 'Fictional proposal password'])->assertRedirect();
    $state = financialPrepare($this, $url, $url.'/financial-planning');
    $before = financialRows();
    config(['service_proposals.writes_enabled' => false]);
    $this->post($url.'/financial-planning', ['action' => 'save', 'planning_hash' => $state['binding'], 'scenario_id' => $state['draft']['id'], 'scenario' => $state['draft']['input']])->assertRedirect();
    $this->get($url.'?section=scope')->assertSee('PRIVATE PLANNING SENTINEL')->assertSee('Reviewer access');
    $this->post($url.'/request-agreement', [])->assertForbidden();
    $other = $this->service->publish($this->tenant, $this->owner->id, $this->draft->id, 1, 'Fictional proposal password', 7, (string) Str::uuid(), 'reviewer');
    $otherUrl = 'https://evergrove.test/service-proposals/'.$other['token'];
    $this->post($otherUrl.'/unlock', ['password' => 'Fictional proposal password']);
    expect($this->get($otherUrl)->viewData('financial')['scenarios'])->toBe([]);
    $this->post('https://wrong.test/service-proposals/'.$token.'/financial-planning', ['action' => 'clear', 'planning_hash' => $state['binding']])->assertNotFound();
    $version->forceFill(['revoked_at' => now()])->save();
    $this->post($url.'/financial-planning', ['action' => 'clear', 'planning_hash' => $state['binding']])->assertGone();
    // Revocation and second publication above are explicit test operations, not cart effects.
    expect(DB::table('service_proposal_reviews')->count())->toBe(0)->and(DB::table('service_proposal_comments')->count())->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('validation stays in Financial and only bounded planning fields are flashed', function (): void {
    $this->actingAs($this->owner);
    $hash = $this->get($this->previewUrl)->viewData('financial')['binding'];
    $response = $this->post($this->cartUrl, ['action' => 'prepare', 'planning_hash' => $hash, 'name' => str_repeat('x', 100), 'profile' => 'invented',
        'password' => 'NEVER FLASH THIS', 'access_token' => 'NEVER FLASH THIS']);
    $response->assertRedirectContains('section=scope#usage-estimator')->assertSessionHasErrors('financial');
    expect(session()->getOldInput('password'))->toBeNull()->and(session()->getOldInput('access_token'))->toBeNull()
        ->and(strlen(session()->getOldInput('name')))->toBe(80);
    $html = $this->get($this->previewUrl.'?section=scope')->assertOk()->getContent();
    expect($html)->toMatch('/data-proposal-section="scope"[^>]+checked/s');
});

test('exact retry compares typed string values without treating numeric-looking department labels as equal', function (): void {
    $this->actingAs($this->owner);
    $state = financialPrepare($this, $this->previewUrl, $this->cartUrl);
    $input = $state['draft']['input'];
    $input['departments'][0]['label'] = '0';
    $save = ['action' => 'save', 'planning_hash' => $state['binding'], 'scenario_id' => $state['draft']['id'], 'scenario' => $input];
    $this->post($this->cartUrl, $save)->assertRedirect();
    $save['scenario']['departments'][0]['label'] = '00';
    $this->post($this->cartUrl, $save)->assertConflict();
});

test('a saved twelve-month offer keeps its exact term and charges while legacy unknown terms remain unknown', function (): void {
    $payload = $this->draft->payload;
    $payload['items'][0]['terms_months'] = [12];
    $before = $payload;
    $input = $this->calc->prepare($payload, ['name' => 'Saved term comparison', 'service_id' => $payload['items'][0]['id'], 'department_count' => 1, 'profile' => 'light']);
    $result = $this->calc->calculate($payload, $input);
    expect($input['term_months'])->toBe(12)->and($result['service']['services'][0]['term_months'])->toBe(12)
        ->and($result['service']['totals_cents'])->toBe(['month' => 100000, 'one_time' => 300000])->and($payload)->toBe($before)
        ->and(financialInput($this)['term_months'])->toBeNull();
    $input['term_months'] = 24;
    expect(fn () => $this->calc->calculate($payload, $input))->toThrow(ValidationException::class);
});
