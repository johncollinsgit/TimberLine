<?php

use App\Models\LandlordProspect;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.theeverbranch.com';

    config()->set('tenancy.landlord.primary_host', $landlordHost);
    config()->set('tenancy.landlord.hosts', [$landlordHost]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
});

test('landlord onboarding sheet starts with the ten researched trade prospects', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST) ?: 'app.theeverbranch.com';
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    expect(LandlordProspect::query()->count())->toBe(10);

    $response = $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/onboarding");

    $response
        ->assertOk()
        ->assertSeeText('Turn local conversations into long-term customers.')
        ->assertSeeText('8/10')
        ->assertSeeText('R&R Lawn LLC')
        ->assertSeeText('SC Wired')
        ->assertSeeText('Warmer Water & Plumbing')
        ->assertSeeText('sales@turnkeyroofing.net')
        ->assertSeeText('Convert to customer');
});

test('landlord can log an inbound email response and move the prospect to replied', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST) ?: 'app.theeverbranch.com';
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $prospect = LandlordProspect::query()->where('email', 'ryan@scwired.com')->firstOrFail();

    $this->actingAs($user)
        ->post("http://{$landlordHost}/landlord/onboarding/{$prospect->id}/communications", [
            'direction' => 'inbound',
            'channel' => 'email',
            'communication_status' => 'received',
            'subject' => 'Re: local software fit',
            'body' => 'Interested. Can we talk next Tuesday?',
            'from_address' => $prospect->email,
            'to_address' => 'john@evergrovesoftware.com',
        ])
        ->assertRedirect();

    $prospect->refresh();

    expect($prospect->status)->toBe('replied')
        ->and($prospect->responded_at)->not->toBeNull()
        ->and($prospect->communications()->count())->toBe(2)
        ->and($prospect->communications()->where('direction', 'inbound')->exists())->toBeTrue();

    $this->assertDatabaseHas('landlord_operator_actions', [
        'action_type' => 'landlord_prospect_communication_logged',
        'target_type' => 'landlord_prospect_communication',
    ]);
});

test('landlord can convert a researched prospect into a production tenant', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST) ?: 'app.theeverbranch.com';
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $prospect = LandlordProspect::query()->where('email', 'rrlawneasley@gmail.com')->firstOrFail();

    $response = $this->actingAs($user)
        ->post("http://{$landlordHost}/landlord/tenants", [
            'prospect_id' => $prospect->id,
            'name' => $prospect->business_name,
            'primary_contact_email' => $prospect->email,
            'tenant_type' => 'direct',
            'operating_mode' => 'direct',
            'account_mode' => 'production',
            'data_source_preference' => 'undecided',
            'business_template' => 'landscaping',
            'role' => 'manager',
            'status' => 'active',
        ]);

    $tenant = Tenant::query()->where('name', 'R&R Lawn LLC')->firstOrFail();

    $response->assertRedirect(route('landlord.tenants.show', [
        'tenant' => $tenant->id,
        'tab' => 'overview',
    ]));

    $prospect->refresh();

    expect($prospect->status)->toBe('converted')
        ->and($prospect->converted_tenant_id)->toBe($tenant->id)
        ->and($prospect->converted_at)->not->toBeNull()
        ->and($prospect->communications()->where('subject', 'Converted to Everbranch customer')->exists())->toBeTrue();
});

test('landlord prospect sheet exports the current filtered rows as csv', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST) ?: 'app.theeverbranch.com';
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/onboarding-export.csv?trade=HVAC")
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)
        ->toContain('Agee HVAC LLC')
        ->toContain('Rhino HVAC')
        ->not->toContain('R&R Lawn LLC');
});
