<?php

use App\Models\Tenant;
use App\Models\User;
use App\Proposals\FinancialScenario;
use App\Proposals\ProposalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    $this->tenant = Tenant::create(['name' => 'Fictional example presentation', 'slug' => 'usage-example-owner']);
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

function usageExamplePresentationDom(string $html): DOMXPath
{
    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

    return new DOMXPath($document);
}

function usageExamplePresentationRows(): array
{
    $rows = [];
    foreach (['service_proposal_assets', 'service_proposal_drafts', 'service_proposal_versions', 'service_proposal_reviews', 'service_proposal_comments', 'tenant_user'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }

    return $rows;
}

test('the first Financial view shows the pinned usage example without a form wall or saved choices', function (): void {
    $this->actingAs($this->owner);
    $before = usageExamplePresentationRows();
    $payload = $this->draft->payload;
    $response = $this->get($this->previewUrl.'?section=scope')->assertOk();
    $xpath = usageExamplePresentationDom($response->getContent());
    expect($xpath->query('//*[@data-financial-example]'))->toHaveCount(1)
        ->and($xpath->query('//*[@data-financial-example]//input | //*[@data-financial-example]//select | //*[@data-financial-example]//form'))->toHaveCount(0)
        ->and($xpath->query('//details[@data-financial-setup and @open]'))->toHaveCount(0)
        ->and($xpath->query('//details[@data-financial-example-breakdown and @open]'))->toHaveCount(0)
        ->and($response->viewData('financial')['draft'])->toBeNull()
        ->and($response->viewData('financial')['scenarios'])->toBe([])
        ->and(session('proposal_financial', []))->toBe([])
        ->and(usageExamplePresentationRows())->toBe($before);
    $input = $this->calc->prepare($payload, ['name' => 'Illustrative Moderate example', 'service_id' => $payload['items'][0]['id'], 'department_count' => 1, 'profile' => 'moderate']);
    $input['phone_numbers'] = 1;
    $result = $this->calc->calculate($payload, $input);
    expect(array_column($result['departments'][0]['lines'], 'low_nano'))->toBe([450000000, 6000000000, 5900000000, 620000000])
        ->and($result['usage_low_nano'])->toBe(14120000000)
        ->and($result['usage_high_nano'])->toBe(14870000000)
        ->and($result['number_rental_nano'])->toBe(1150000000)
        ->and($result['markup_nano'])->toBe(0)
        ->and($result['all_in_total'])->toBeNull()
        ->and($this->draft->fresh()->payload)->toBe($payload);
    $response->assertSee(FinancialScenario::money($result['usage_low_nano']))->assertSee(FinancialScenario::money($result['usage_high_nano']))
        ->assertSee('Software fees are separate')->assertSee('not measured campus usage')->assertSee('one assumed SMS number')
        ->assertSee('1 GB')->assertSee('Adjust this example')->assertDontSee('$1,014.12');
    foreach (['$0.45', '$6.00', '$5.90', '$6.65', '$0.62', '$1.15/month'] as $cost) {
        $response->assertSee($cost);
    }
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('saved comparisons and an explicit draft retain their exact inputs and management preferences', function (): void {
    $this->actingAs($this->owner);
    $binding = $this->get($this->previewUrl)->viewData('financial')['binding'];
    $this->post($this->cartUrl, ['planning_hash' => $binding, 'action' => 'prepare', 'name' => 'Retained university comparison',
        'service_id' => $this->draft->payload['items'][0]['id'], 'department_count' => 1, 'profile' => 'moderate', 'management' => 'university'])->assertRedirect();
    $state = $this->get($this->previewUrl)->viewData('financial');
    $input = $state['draft']['input'];
    $input['phone_numbers'] = 2;
    $input['method'] = 'actual';
    $this->post($this->cartUrl, ['planning_hash' => $binding, 'action' => 'save', 'scenario_id' => $state['draft']['id'], 'scenario' => $input])->assertRedirect();
    $this->post($this->cartUrl, ['planning_hash' => $binding, 'action' => 'prepare', 'name' => 'Retained department draft',
        'service_id' => $this->draft->payload['items'][1]['id'], 'department_count' => 3, 'profile' => 'busy', 'management' => 'department'])->assertRedirect();
    $beforeSession = session('proposal_financial');
    $beforeRows = usageExamplePresentationRows();
    $before = $this->get($this->previewUrl)->viewData('financial');
    $response = $this->get($this->previewUrl.'?section=scope')->assertOk()->assertSee('Retained university comparison')->assertSee('Retained department draft');
    $xpath = usageExamplePresentationDom($response->getContent());
    expect($response->viewData('financial'))->toBe($before)
        ->and(session('proposal_financial'))->toBe($beforeSession)
        ->and(usageExamplePresentationRows())->toBe($beforeRows)
        ->and($xpath->query('//details[@data-financial-adjustments and @open]'))->toHaveCount(1)
        ->and($xpath->query('//*[@id="scenario-management"]/option[@value="department" and @selected]'))->toHaveCount(1)
        ->and($xpath->query('//*[@data-financial-result]'))->toHaveCount(1)
        ->and($xpath->query('//*[@data-financial-department]'))->toHaveCount(3);
    expect(array_values($before['scenarios'])[0]['input'])->toBe($input);
});

test('setup errors reveal the original controls and draft errors preserve invalid inputs for correction', function (): void {
    $this->actingAs($this->owner);
    $binding = $this->get($this->previewUrl)->viewData('financial')['binding'];
    $this->post($this->cartUrl, ['planning_hash' => $binding, 'action' => 'prepare', 'name' => 'Retained setup name', 'profile' => 'invented'])->assertSessionHasErrors('financial');
    $response = $this->get($this->previewUrl.'?section=scope')->assertOk();
    $xpath = usageExamplePresentationDom($response->getContent());
    expect($xpath->query('//details[@data-financial-setup and @open]'))->toHaveCount(1)
        ->and($xpath->query('//*[@id="financial-name"]')->item(0)->getAttribute('value'))->toBe('Retained setup name');
    $this->post($this->cartUrl, ['planning_hash' => $binding, 'action' => 'prepare', 'name' => 'Explicit draft',
        'service_id' => $this->draft->payload['items'][0]['id'], 'department_count' => 1, 'profile' => 'light'])->assertRedirect();
    $state = $this->get($this->previewUrl)->viewData('financial');
    $input = $state['draft']['input'];
    $input['departments'][0]['questions'] = -1;
    $input['departments'][0]['label'] = '<script>Retained private label</script>';
    $this->post($this->cartUrl, ['planning_hash' => $binding, 'action' => 'save', 'scenario_id' => $state['draft']['id'], 'scenario' => $input])->assertSessionHasErrors('financial');
    $response = $this->get($this->previewUrl.'?section=scope')->assertOk()->assertDontSee('<script>Retained private label</script>', false);
    $xpath = usageExamplePresentationDom($response->getContent());
    expect($xpath->query('//details[@data-financial-adjustments and @open]'))->toHaveCount(1)
        ->and($xpath->query('//*[@id="dept-0-questions"]')->item(0)->getAttribute('value'))->toBe('-1')
        ->and($xpath->query('//*[@id="dept-0-label"]')->item(0)->getAttribute('value'))->toBe('<script>Retained private label</script>')
        ->and($response->viewData('financial')['scenarios'])->toBe([]);
});

test('the usage-only example never chooses a commercial offer or an unresolved contract term', function (): void {
    $this->actingAs($this->owner);
    $payload = $this->draft->payload;
    $payload['items'][0]['charges'][1]['amount_cents'] = 98765;
    $payload['items'][0]['terms_months'] = [12, 24];
    $this->draft->update(['payload' => $payload, 'revision' => 2]);
    $response = $this->get($this->previewUrl.'?section=scope')->assertOk()->assertSee('$987.65')->assertSee('$14.12')->assertSee('$14.87');
    $xpath = usageExamplePresentationDom($response->getContent());
    expect($xpath->query('//*[@id="financial-offer"]/option[@selected]'))->toHaveCount(0)
        ->and($response->viewData('financial')['draft'])->toBeNull()
        ->and(session('proposal_financial', []))->toBe([])
        ->and($this->draft->fresh()->payload)->toBe($payload);
    $binding = $response->viewData('financial')['binding'];
    $this->post($this->cartUrl, ['planning_hash' => $binding, 'action' => 'prepare', 'name' => 'Explicit unknown offer comparison',
        'service_id' => $payload['items'][0]['id'], 'department_count' => 1, 'profile' => 'moderate'])->assertRedirect();
    $response = $this->get($this->previewUrl.'?section=scope')->assertOk();
    expect($response->viewData('financial')['draft']['input']['term_months'])->toBeNull();
    $xpath = usageExamplePresentationDom($response->getContent());
    expect($xpath->query('//*[@id="scenario-term"]/option[@value="12" or @value="24"]'))->toHaveCount(2)
        ->and($xpath->query('//*[@id="scenario-term"]/option[@selected]'))->toHaveCount(0);
});

test('an existing unlocked reviewer edition keeps its immutable payload and comments when the example is displayed', function (): void {
    ['version' => $version, 'token' => $token] = $this->service->publish($this->tenant, $this->owner->id, $this->draft->id, 1,
        'Fictional example password', 7, (string) Str::uuid(), 'reviewer');
    $this->service->comment($version, 'Ross', 'overview', 'Retained fictional reviewer note', (string) Str::uuid());
    $url = 'https://evergrove.test/service-proposals/'.$token;
    $this->post($url.'/unlock', ['password' => 'Fictional example password'])->assertRedirect();
    $before = usageExamplePresentationRows();
    $raw = $version->getRawOriginal('payload');
    $hash = $version->content_hash;
    $this->get($url.'?section=scope')->assertOk()->assertSee('Monthly usage example')->assertSee('Reviewer access')->assertSee('$14.12');
    expect(usageExamplePresentationRows())->toBe($before)
        ->and($version->fresh()->getRawOriginal('payload'))->toBe($raw)
        ->and($version->fresh()->content_hash)->toBe($hash)
        ->and(session('proposal_financial', []))->toBe([]);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});
