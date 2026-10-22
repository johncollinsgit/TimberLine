<?php

use App\Models\Tenant;
use App\Models\TenantSupportTicket;
use App\Models\TenantSupportTicketMessage;
use App\Models\User;
use App\Services\Mobile\TenantMobileSupportService;
use App\SoftwareSupport\NotificationSimulation;
use App\SoftwareSupport\Record;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    Storage::fake('local');
    $this->operator = User::factory()->create(['is_active' => true, 'role' => 'user']);
    $this->requester = User::factory()->create(['is_active' => true, 'role' => 'user']);
    $this->otherDepartment = User::factory()->create(['is_active' => true, 'role' => 'user']);
    $this->foreignUser = User::factory()->create(['is_active' => true, 'role' => 'admin']);
    $this->ownerTenant = Tenant::create(['name' => 'Evergrove Enterprise test', 'slug' => 'support-owner-test']);
    $this->clientTenant = Tenant::create(['name' => 'Fictional college', 'slug' => 'support-client-test']);
    $this->foreignTenant = Tenant::create(['name' => 'Other fictional client', 'slug' => 'support-other-test']);
    $this->operator->tenants()->attach($this->ownerTenant->id, ['role' => 'owner', 'membership_active' => true]);
    foreach ([$this->requester, $this->otherDepartment] as $user) {
        $user->tenants()->attach($this->clientTenant->id, ['role' => 'member', 'membership_active' => true]);
    }
    $this->foreignUser->tenants()->attach($this->foreignTenant->id, ['role' => 'owner', 'membership_active' => true]);
    config(['software_support.enabled' => true, 'software_support.owner_tenant_id' => $this->ownerTenant->id,
        'software_support.owner_user_ids' => [$this->operator->id],
        'software_support.clients' => [$this->clientTenant->id => ['departments' => [
            'it' => ['name' => 'Technology', 'user_ids' => [$this->requester->id]],
            'finance' => ['name' => 'Finance', 'user_ids' => [$this->otherDepartment->id]],
        ]], $this->foreignTenant->id => ['departments' => ['it' => ['name' => 'Technology', 'user_ids' => [$this->foreignUser->id]]]]],
        'software_support.owner_sms' => 'FAKE_PRIVATE_DESTINATION', 'software_support.owner_email' => 'fake-owner@example.test']);
    $this->clientPath = '/workspaces/'.$this->clientTenant->slug.'/software-support';
    $this->ownerPath = '/workspaces/'.$this->ownerTenant->slug.'/software-support';
    $this->data = ['request_key' => (string) Str::uuid(), 'department' => 'it', 'subject' => 'Software export stops', 'category' => 'bug', 'priority' => 'high', 'body' => 'Confidential fictional steps.'];
});

function submitSoftwareTicket($test, array $extra = []): Record
{
    $test->actingAs($test->requester)->post($test->clientPath, [...$test->data, ...$extra])->assertStatus(303);

    return Record::where('kind', 'ticket')->firstOrFail();
}

it('persists a private submission and deduplicates interrupted or repeated submits without sending', function (): void {
    $record = submitSoftwareTicket($this, ['attachments' => [UploadedFile::fake()->createWithContent('steps.txt', 'Fictional file content')]]);
    $this->post($this->clientPath, [...$this->data, 'attachments' => [UploadedFile::fake()->createWithContent('steps.txt', 'Fictional file content')]])->assertStatus(303);
    expect(Record::where('kind', 'ticket')->count())->toBe(1)
        ->and(TenantSupportTicket::withoutGlobalScopes()->count())->toBe(1)
        ->and(TenantSupportTicketMessage::withoutGlobalScopes()->count())->toBe(1)
        ->and(Record::where('kind', 'notification')->count())->toBe(2)
        ->and(Record::where('kind', 'attachment')->count())->toBe(1);
    $this->post($this->clientPath, [...$this->data, 'body' => 'Changed replay'])->assertConflict();
    $response = $this->get($this->clientPath.'/'.$record->id)->assertOk()->assertSee('Confidential fictional steps.')->assertDontSee('FAKE_PRIVATE_DESTINATION')->assertDontSee('fake-owner@example.test');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    foreach (Record::where('kind', 'notification')->get() as $notice) {
        expect($notice->payload['message'])->toContain($record->id, 'HIGH')->not->toContain('Confidential', 'Software export', 'Fictional college', 'fake-owner');
    }
    expect(DB::table('software_support_records')->where('kind', 'notification')->value('payload'))->not->toContain('Evergrove support');
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

it('rejects foreign tenants departments inactive memberships and client triage', function (): void {
    $record = submitSoftwareTicket($this);
    $this->actingAs($this->otherDepartment)->get($this->clientPath.'/'.$record->id)->assertNotFound();
    $this->get($this->clientPath)->assertOk()->assertDontSee('Software export stops');
    $this->post($this->clientPath, [...$this->data, 'request_key' => (string) Str::uuid()])->assertSessionHasErrors('department');
    $this->actingAs($this->foreignUser)->get($this->clientPath.'/'.$record->id)->assertForbidden();
    $this->get('/workspaces/'.$this->foreignTenant->slug.'/software-support/'.$record->id)->assertNotFound();
    $this->actingAs($this->requester)->post($this->clientPath.'/'.$record->id.'/triage', ['status' => 'closed', 'priority' => 'low', 'revision' => 1])->assertForbidden();
    $this->requester->tenants()->updateExistingPivot($this->clientTenant->id, ['membership_active' => false]);
    $this->get($this->clientPath.'/'.$record->id)->assertForbidden();
});

it('requires scoped owner access rather than a global admin role or an email match', function (): void {
    $record = submitSoftwareTicket($this);
    $this->actingAs($this->foreignUser)->get($this->ownerPath)->assertForbidden();
    $this->foreignUser->tenants()->attach($this->ownerTenant->id, ['role' => 'owner', 'membership_active' => true]);
    $this->get($this->ownerPath)->assertForbidden();
    $this->actingAs($this->operator)->get($this->ownerPath)->assertOk()->assertSee('Software export stops');
    config(['software_support.clients.'.$this->clientTenant->id => null]);
    $this->get($this->ownerPath.'/'.$record->id)->assertNotFound();
});

it('supports owner replies status history stale updates and searchable tracking', function (): void {
    $record = submitSoftwareTicket($this);
    $this->actingAs($this->operator)->get($this->ownerPath.'?q=no-match')->assertOk()->assertDontSee('Software export stops');
    $this->get($this->ownerPath.'?q='.$record->id)->assertOk()->assertSee('Software export stops');
    $reply = ['request_key' => (string) Str::uuid(), 'body' => 'We are reviewing this software issue.'];
    $this->post($this->ownerPath.'/'.$record->id.'/reply', $reply)->assertStatus(303);
    $this->post($this->ownerPath.'/'.$record->id.'/reply', $reply)->assertStatus(303);
    expect(TenantSupportTicketMessage::withoutGlobalScopes()->count())->toBe(2);
    $this->post($this->ownerPath.'/'.$record->id.'/reply', [...$reply, 'body' => 'Changed'])->assertConflict();
    $triage = ['status' => 'in_progress', 'priority' => 'urgent', 'revision' => 1];
    $this->post($this->ownerPath.'/'.$record->id.'/triage', $triage)->assertStatus(303);
    $this->post($this->ownerPath.'/'.$record->id.'/triage', $triage)->assertStatus(303);
    $this->post($this->ownerPath.'/'.$record->id.'/triage', [...$triage, 'status' => 'closed'])->assertConflict();
    $this->actingAs($this->requester)->get($this->clientPath.'/'.$record->id)->assertOk()->assertSee('We are reviewing')->assertSee('Triaged')->assertSee('In Progress');
});

it('keeps attachments private rejects unsafe inputs and validates integrity', function (): void {
    $this->actingAs($this->requester)->post($this->clientPath, [...$this->data, 'attachments' => ['not a file']])->assertSessionHasErrors('attachments.0');
    $this->actingAs($this->requester)->post($this->clientPath, [...$this->data, 'attachments' => [UploadedFile::fake()->createWithContent('attack.html', '<script>alert(1)</script>')]])->assertSessionHasErrors('attachments.0');
    $this->post($this->clientPath, [...$this->data, 'attachments' => [UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')]])->assertSessionHasErrors('attachments.0');
    expect(Record::count())->toBe(0);
    $record = submitSoftwareTicket($this, ['attachments' => [UploadedFile::fake()->createWithContent('steps.txt', 'Fictional file content')]]);
    $file = Record::where('kind', 'attachment')->firstOrFail();
    $url = $this->clientPath.'/'.$record->id.'/files/'.$file->id;
    $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertDownload('steps.txt');
    $this->actingAs($this->otherDepartment)->get($url)->assertNotFound();
    Storage::disk('local')->put('private/software-support/'.$file->id, 'tampered');
    $this->actingAs($this->requester)->get($url)->assertStatus(503);
});

it('rolls back a failed attachment write and safely retries the same submission', function (): void {
    Storage::shouldReceive('disk')->with('local')->andReturn($disk = Mockery::mock());
    $disk->shouldReceive('put')->twice()->andReturn(false, true);
    $disk->shouldReceive('delete')->once()->andReturn(true);
    $this->actingAs($this->requester)->post($this->clientPath, [...$this->data, 'attachments' => [UploadedFile::fake()->createWithContent('steps.txt', 'file')]])->assertStatus(503);
    expect(Record::count())->toBe(0)->and(TenantSupportTicket::withoutGlobalScopes()->count())->toBe(0);
    $this->post($this->clientPath, [...$this->data, 'attachments' => [UploadedFile::fake()->createWithContent('steps.txt', 'file')]])->assertStatus(303);
    expect(Record::where('kind', 'ticket')->count())->toBe(1);
});

it('simulates owner notification failures retries dedupe and rate limits without provider IO', function (): void {
    submitSoftwareTicket($this);
    $simulation = app(NotificationSimulation::class);
    expect($simulation->run(['sms']))->toBe(2);
    expect(Record::where('kind', 'notification')->get()->pluck('payload.state')->all())->toContain('failed_simulation', 'simulated');
    expect($simulation->run())->toBe(1)->and($simulation->run())->toBe(0);
    submitSoftwareTicket($this, ['request_key' => (string) Str::uuid(), 'subject' => 'Second issue']);
    config(['software_support.simulation_hourly_limit' => 1]);
    expect($simulation->run())->toBe(0);
    $this->travel(61)->minutes();
    expect($simulation->run())->toBe(2);
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

it('does not expose department tickets through legacy account help or platform support', function (): void {
    $record = submitSoftwareTicket($this);
    $legacy = app(TenantMobileSupportService::class);
    expect($legacy->index($this->clientTenant->id)['tickets'])->toHaveCount(0)
        ->and($legacy->landlordIndex('all')['tickets'])->toHaveCount(0);
    expect(fn () => $legacy->show($this->clientTenant->id, $record->ticket_id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(fn () => $legacy->reply($this->clientTenant->id, $record->ticket_id, $this->otherDepartment, 'Forbidden', 'tenant'))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('caps failed simulated attempts and refuses simulation in production', function (): void {
    submitSoftwareTicket($this);
    $simulation = app(NotificationSimulation::class);
    expect($simulation->run(['sms']))->toBe(2)
        ->and($simulation->run(['sms']))->toBe(1)
        ->and($simulation->run(['sms']))->toBe(1)
        ->and($simulation->run())->toBe(0);
    $sms = Record::where('kind', 'notification')->get()->first(fn ($row) => $row->payload['channel'] === 'sms');
    expect($sms->payload['attempts'])->toBe(3)->and($sms->payload['state'])->toBe('failed_simulation');
    app()->detectEnvironment(fn () => 'production');
    expect(fn () => $simulation->run())->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('fails closed by default and renders a reviewable mobile friendly interface', function (): void {
    config(['software_support.enabled' => false]);
    $this->actingAs($this->requester)->get($this->clientPath)->assertNotFound();
    config(['software_support.enabled' => true]);
    $record = submitSoftwareTicket($this);
    $this->get($this->clientPath)->assertOk()->assertDontSee('one 30-minute');
    config(['software_support.clients.'.$this->clientTenant->id.'.approved_support_allowance' => true]);
    $this->get($this->clientPath)->assertOk()->assertSee('one 30-minute');
    if (getenv('SUPPORT_CAPTURE_HTML')) {
        file_put_contents(base_path('.tmp/support-client.html'), $this->get($this->clientPath)->assertOk()->getContent());
        file_put_contents(base_path('.tmp/support-thread.html'), $this->get($this->clientPath.'/'.$record->id)->assertOk()->getContent());
        file_put_contents(base_path('.tmp/support-owner.html'), $this->actingAs($this->operator)->get($this->ownerPath)->assertOk()->getContent());
    }
});
