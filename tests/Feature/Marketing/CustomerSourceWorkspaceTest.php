<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingSegment;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Services\Marketing\CustomerSourceOwnershipReconciliation;
use App\Services\Marketing\LegacySystemAudienceTemplates;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    [$this->tenant, $this->owner] = teamWorkspace();
});

it('reviews only its original links and rejects stale batches or a different workspace owner', function (): void {
    [$other, $otherOwner] = teamWorkspace();
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Original buyer', 'accepts_email_marketing' => false]);
    SquareCustomer::create(['tenant_id' => $this->tenant->id, 'square_customer_id' => 'workspace-original']);
    $link = MarketingProfileLink::create(['marketing_profile_id' => $profile->id, 'source_type' => 'square_customer', 'source_id' => 'workspace-original', 'source_meta' => ['original' => 'retained']]);
    $foreign = MarketingProfile::create(['tenant_id' => $other->id, 'first_name' => 'Private foreign customer']);
    MarketingProfileLink::create(['marketing_profile_id' => $foreign->id, 'source_type' => 'manual_customer', 'source_id' => 'foreign-private-source']);
    $url = '/workspace/customers/reconciliation?tenant='.$this->tenant->slug;
    $preview = app(CustomerSourceOwnershipReconciliation::class)->batch($this->tenant->id, $this->owner);
    $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('Restore 1 proven links')->assertDontSee('foreign-private-source');
    $link->update(['source_meta' => ['original' => 'retained', 'changed' => true]]);
    $restore = '/workspace/customers/reconciliation/restore?tenant='.$this->tenant->slug;
    $this->post($restore, ['after_id' => 0, 'fingerprint' => $preview['fingerprint']])->assertConflict();
    expect($link->fresh()->tenant_id)->toBeNull();
    $preview = app(CustomerSourceOwnershipReconciliation::class)->batch($this->tenant->id, $this->owner);
    $this->post($restore, ['after_id' => 0, 'fingerprint' => $preview['fingerprint']])->assertRedirect();
    expect($link->fresh()->tenant_id)->toBe($this->tenant->id)->and($link->fresh()->source_meta['original'])->toBe('retained')
        ->and($profile->fresh()->accepts_email_marketing)->toBeFalse()->and(MarketingImportRun::forTenantId($this->tenant->id)->where('type', 'customer_source_ownership')->count())->toBe(1);
    $this->actingAs($otherOwner)->get($url)->assertForbidden();
    $this->post($restore, ['after_id' => 0, 'fingerprint' => $preview['fingerprint']])->assertForbidden();
    Http::assertNothingSent();
});

it('copies only an unchanged published starter as an audited tenant draft and preserves original definitions on replay', function (): void {
    $source = MarketingSegment::whereNull('tenant_id')->where('slug', 'repeat-buyers')->firstOrFail();
    $source->update(['description' => 'Unowned legacy private note sentinel']);
    $service = app(LegacySystemAudienceTemplates::class);
    $template = collect($service->available())->firstWhere('id', $source->id);
    expect($template)->not->toBeNull();
    $before = MarketingCampaign::count();
    $url = '/workspace/customers/reconciliation/template?tenant='.$this->tenant->slug;
    $this->actingAs($this->owner)->post($url, ['source_id' => $source->id, 'fingerprint' => $template['fingerprint']])->assertRedirect();
    $copy = MarketingSegment::forTenantId($this->tenant->id)->where('name', $source->name)->sole();
    expect($copy->status)->toBe('draft')->and($copy->is_system)->toBeFalse()->and($copy->rules_json['_legacy_system_audience']['source_id'])->toBe($source->id)
        ->and($source->fresh()->tenant_id)->toBeNull()->and($source->fresh()->is_system)->toBeTrue();
    expect($copy->description)->not->toContain('private note sentinel')->and($source->fresh()->description)->toBe('Unowned legacy private note sentinel');
    $this->post($url, ['source_id' => $source->id, 'fingerprint' => $template['fingerprint']])->assertRedirect();
    expect(MarketingSegment::forTenantId($this->tenant->id)->count())->toBe(1)->and(MarketingImportRun::where('type', 'legacy_audience_template')->count())->toBe(1)->and(MarketingCampaign::count())->toBe($before);
    $source->update(['rules_json' => ['logic' => 'and', 'conditions' => [['field' => 'total_orders', 'operator' => 'gt', 'value' => 20]], 'groups' => []]]);
    $this->post($url, ['source_id' => $source->id, 'fingerprint' => $template['fingerprint']])->assertConflict();
    expect(collect($service->available())->contains('id', $source->id))->toBeFalse();
    Http::assertNothingSent();
});

it('paginates older canonical purchases without exposing private profile or tenant settings in the shared JSON contract', function (): void {
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'History customer', 'email' => 'history@example.invalid', 'notes' => 'Private staff note sentinel']);
    for ($i = 0; $i < 55; $i++) {
        $id = 'workspace-history-'.$i;
        SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => $id, 'state' => 'COMPLETED', 'closed_at' => now()->subDays($i), 'location_id' => 'original-location', 'total_money_amount' => 1080, 'total_money_currency' => 'USD']);
        SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'payment-'.$id, 'square_order_id' => $id, 'status' => 'COMPLETED', 'location_id' => 'original-location', 'amount_money' => 1080, 'currency' => 'USD', 'raw_payload' => ['refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
        MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'square_order', 'source_id' => $id]);
    }
    $url = '/workspace/customers/'.$profile->id.'?tenant='.$this->tenant->slug;
    $first = $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonCount(50, 'commerce.purchases')->assertJsonPath('purchase_has_more', true);
    $first->assertJsonMissingPath('profile')->assertJsonMissingPath('tenant.users')->assertDontSee('Private staff note sentinel');
    expect($first->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $second = $this->getJson($url.'&purchase_page=2')->assertOk()->assertJsonCount(5, 'commerce.purchases')->assertJsonPath('purchase_has_more', false);
    expect(array_intersect(array_column($first->json('commerce.purchases'), 'source_id'), array_column($second->json('commerce.purchases'), 'source_id')))->toBe([]);
    $this->get($url.'&purchase_page=2')->assertOk()->assertSee('Newer purchases');
    [$other, $otherOwner] = teamWorkspace();
    $this->actingAs($otherOwner)->getJson('/workspace/customers/'.$profile->id.'?tenant='.$other->slug)->assertNotFound();
    Http::assertNothingSent();
});
