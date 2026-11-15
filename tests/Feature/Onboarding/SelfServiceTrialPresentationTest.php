<?php

use App\Models\FieldServiceJob;
use App\Models\MarketingProfile;
use App\Models\User;
use App\Models\WorkspaceAsset;
use App\Services\Onboarding\SelfServiceTrialService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->withoutVite();
    Notification::fake();
    Http::preventStrayRequests();
    config()->set(['self_service.registration_enabled' => true, 'self_service.trial_enabled' => true]);
    $this->owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now(), 'role' => 'self_service_pending',
        'requested_via' => 'self_service_registration', 'onboarding_guide_answers' => ['business_template' => 'generic']]);
    $this->actingAs($this->owner)->post(route('workspace.first-login.store'), [
        'workspace_name' => 'Fictional Trial Presentation', 'template_key' => 'generic', 'start_path' => 'self',
    ])->assertRedirect();
    $this->tenant = $this->owner->tenants()->firstOrFail();
    $this->actingAs($this->owner->refresh());
    $this->customer = MarketingProfile::query()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional', 'last_name' => 'Customer', 'email' => 'fictional@example.test']);
    $this->job = FieldServiceJob::query()->create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->customer->id,
        'title' => 'Fictional retained work', 'status' => 'new', 'description' => 'Private trial job']);
    $this->asset = WorkspaceAsset::query()->create(['tenant_id' => $this->tenant->id, 'uploaded_by_user_id' => $this->owner->id,
        'source' => 'manual', 'visibility' => 'team', 'storage_disk' => 'local', 'storage_path' => 'fictional/private.txt',
        'file_name' => 'fictional-private.txt', 'mime_type' => 'text/plain', 'file_size' => 12]);
});

test('trial customer work and file screens retain useful controls without advertising paid actions', function (bool $expired): void {
    if ($expired) {
        $this->travelTo(\Carbon\CarbonImmutable::parse(app(SelfServiceTrialService::class)->status($this->tenant)['ends_at']));
    }
    $customers = $this->get(route('marketing.customers', ['tenant' => $this->tenant->slug]))->assertOk()
        ->assertSee('data-message-customer-url=""', false)->assertSee('data-bulk-action-url=""', false);
    $work = $this->get(route('field-service.index', ['tenant' => $this->tenant->slug]))->assertOk()
        ->assertSee('data-can-manage="0"', false)->assertSee('data-can-post-updates="0"', false)
        ->assertDontSee('Job update text alerts')->assertDontSee('name="assigned_user_id"', false)
        ->assertDontSee('href="'.route('field-service.payroll-hours').'"', false);
    $files = $this->get(route('documents.index', $this->tenant))->assertOk()
        ->assertSee('fictional-private.txt')->assertSee(route('documents.download', [$this->tenant, $this->asset]), false);
    $customer = $this->get(route('marketing.customers.show', [$this->customer, 'tenant' => $this->tenant->slug]))->assertOk()
        ->assertSee('Fictional Customer')->assertSee('Job history')->assertDontSee('Directory controls');
    $job = $this->get(route('field-service.jobs.show', [$this->job, 'tenant' => $this->tenant->slug]))->assertOk()
        ->assertDontSee('Save update')->assertDontSee('Delete job')->assertDontSee('Add task');
    if ($expired) {
        $customers->assertSee('data-add-customer-url=""', false)->assertDontSee('Add Customer');
        $work->assertDontSee('id="new-job"', false);
        $files->assertDontSee('name="files[]"', false)->assertDontSee('Update links')->assertDontSee('>Delete<', false);
        $customer->assertDontSee('Save customer');
        $job->assertDontSee('Save job details');
    } else {
        $customers->assertSee('Add Customer');
        $work->assertSee('id="new-job"', false);
        $files->assertSee('name="files[]"', false);
        $customer->assertSee('Save customer');
        $job->assertSee('Save job details');
    }
    $this->get(route('field-service.calendar', ['tenant' => $this->tenant->slug]))->assertOk()->assertDontSee('Payroll hours');
    Http::assertNothingSent();
})->with([false, true]);

test('the trial guide uses admitted private destinations and never sends the owner to forbidden setup', function (): void {
    $this->get(route('workspace.guide', ['tenant' => $this->tenant->slug]))->assertOk()
        ->assertDontSee('href="'.route('app.start').'"', false)
        ->assertSee(route('managed-website.index', ['tenant' => $this->tenant->slug]), false)
        ->assertSee(route('self-service.purchase', ['tenant' => $this->tenant->slug]), false)
        ->assertSee('After activation');
    Http::assertNothingSent();
});
