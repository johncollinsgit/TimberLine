<?php

use App\Console\Commands\EverbranchPreparePestControlDemo;
use App\Models\FormSubmission;
use App\Models\MarketingProfile;
use App\Models\Tenant;

test('the public pest-control fleet demonstration identifies its fictional data and video', function (): void {
    $this->get(route('platform.pest-control-fleet-demo'))
        ->assertOk()
        ->assertSee('Green Shield Pest Control')
        ->assertSee(EverbranchPreparePestControlDemo::OWNER_EMAIL)
        ->assertSee(EverbranchPreparePestControlDemo::DEFAULT_PASSWORD)
        ->assertSee(route('platform.pest-control-fleet-demo.login'), false)
        ->assertSee('Enter the demo workspace')
        ->assertSee('green-shield-fleet-demo.mp4?v=20260813-map-title')
        ->assertSee('green-shield-fleet-demo-poster.jpg?v=20260813-map-title')
        ->assertSee('Demonstration data is fictional');
});

test('the demo login handoff returns an old link to the public demo page', function (): void {
    $this->get(route('platform.pest-control-fleet-demo.login.redirect'))
        ->assertRedirect(route('platform.pest-control-fleet-demo'));
});

test('the fictional public pest-control website makes reminders and Bouncie-aware dispatch visible', function (): void {
    $this->get(route('platform.pest-control-website'))
        ->assertOk()
        ->assertSee('Pest prevention reminder sign-up')
        ->assertSee('Bouncie linked')
        ->assertSee('Save fictional preferences')
        ->assertSee('does not create a customer, consent record, automation, email, or text message');
});

test('the fictional reminder sign-up is a tenant-scoped non-delivery submission', function (): void {
    $this->artisan('everbranch:prepare-pest-control-demo')->assertSuccessful();
    $tenant = Tenant::query()->where('slug', 'green-shield-pest-control')->firstOrFail();
    $profileCount = MarketingProfile::query()->forTenantId((int) $tenant->id)->count();

    $this->post(route('platform.pest-control-website.reminders'), [
        'name' => 'Taylor Morgan',
        'email' => 'taylor@example.test',
        'phone' => '(704) 555-0182',
        'property_type' => 'home',
        'reminders' => ['service', 'mosquito'],
        'message' => 'Send the fictional mosquito-season reminder.',
    ])->assertRedirect(route('platform.pest-control-website').'#reminders')
        ->assertSessionHas('pest_reminder_status');

    $submission = FormSubmission::query()->forTenantId((int) $tenant->id)->sole();

    expect($submission->source)->toBe('fictional_pest_control_website')
        ->and($submission->submitter_email)->toBe('taylor@example.test')
        ->and($submission->payload['reminders'])->toBe(['service', 'mosquito'])
        ->and($submission->metadata['delivery_enabled'])->toBeFalse()
        ->and(MarketingProfile::query()->forTenantId((int) $tenant->id)->count())->toBe($profileCount);
});

test('the demo handoff signs into the isolated fictional account and opens its workspace', function (): void {
    $this->artisan('everbranch:prepare-pest-control-demo')->assertSuccessful();

    $owner = \App\Models\User::query()->where('email', EverbranchPreparePestControlDemo::OWNER_EMAIL)->firstOrFail();

    $this->post(route('platform.pest-control-fleet-demo.login'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('field-service.index', ['tenant' => 'green-shield-pest-control'], absolute: false));

    $this->assertAuthenticatedAs($owner);
});

test('the populated fictional workspace includes a home calendar and a per-job route map', function (): void {
    $this->artisan('everbranch:prepare-pest-control-demo')->assertSuccessful();

    $owner = \App\Models\User::query()->where('email', EverbranchPreparePestControlDemo::OWNER_EMAIL)->firstOrFail();
    $job = \App\Models\FieldServiceJob::query()->where('external_id', 'termite-inspection-412-hawthorne')->firstOrFail();

    $this->actingAs($owner)
        ->get(route('field-service.index', ['tenant' => 'green-shield-pest-control']))
        ->assertOk()
        ->assertSee('This week')
        ->assertSee('Money In');

    $this->actingAs($owner)
        ->get(route('field-service.jobs.show', ['job' => $job, 'tenant' => 'green-shield-pest-control']))
        ->assertOk()
        ->assertSee('Fictional van route')
        ->assertSee('Fictional job financials');
});
