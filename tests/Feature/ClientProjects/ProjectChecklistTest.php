<?php

use App\Models\ClientProject;
use App\Models\ClientProjectTicket;
use App\Models\ClientProjectTicketTask;
use App\Models\LandlordOperatorAction;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = Tenant::query()->create(['name' => 'Checklist Client', 'slug' => 'checklist-client']);
    $this->user = User::factory()->create(['role' => 'manager', 'is_active' => true]);
    $this->user->tenants()->attach($this->tenant, ['role' => 'manager', 'membership_active' => true]);
    $this->project = ClientProject::query()->create(['tenant_id' => $this->tenant->id, 'title' => 'Website launch', 'metadata' => ['checklist_enabled' => true]]);
    $this->ticket = ClientProjectTicket::query()->create(['tenant_id' => $this->tenant->id, 'client_project_id' => $this->project->id, 'title' => 'Start here', 'problem_summary' => 'Launch', 'customer_visible' => true, 'landlord_notes' => 'PRIVATE OPERATOR NOTES']);
    $this->task = ClientProjectTicketTask::query()->create(['tenant_id' => $this->tenant->id, 'client_project_ticket_id' => $this->ticket->id, 'title' => 'Choose a featured product', 'owner_type' => 'client', 'status' => 'open']);
});

test('checklist exposes only visible tasks and saves completion and reopening with audit', function (): void {
    $hidden = $this->ticket->replicate();
    $hidden->customer_visible = false;
    $hidden->save();
    $hiddenTask = $this->task->replicate();
    $hiddenTask->client_project_ticket_id = $hidden->id;
    $hiddenTask->title = 'Hidden task';
    $hiddenTask->save();
    $this->actingAs($this->user)->get(route('client.projects.checklist'))
        ->assertOk()->assertSeeText('Choose a featured product')->assertSeeText('Launch checklist')
        ->assertDontSeeText('Hidden task')->assertDontSeeText('PRIVATE OPERATOR NOTES');
    $url = route('client.projects.checklist.update', ['task' => $this->task->id]);
    $this->patchJson($url, ['completed' => true])->assertOk()->assertJson(['completed' => true]);
    expect($this->task->fresh()->status)->toBe('done')->and($this->task->fresh()->completed_at)->not->toBeNull();
    $this->patchJson($url, ['completed' => true])->assertOk();
    expect(LandlordOperatorAction::where('action_type', 'client_project.checklist_updated')->count())->toBe(1);
    $this->patchJson($url, ['completed' => false])->assertOk();
    expect($this->task->fresh()->status)->toBe('open')->and($this->task->fresh()->completed_at)->toBeNull();
    $this->patchJson(route('client.projects.checklist.update', ['task' => $hiddenTask->id]), ['completed' => true])->assertNotFound();
});

test('client cannot complete provider tasks even as tenant admin with permissive landlord gate', function (): void {
    $this->user->update(['role' => 'admin']);
    config(['tenancy.landlord.operator_roles' => ['admin'], 'tenancy.landlord.operator_emails' => []]);
    $this->task->update(['owner_type' => 'evergrove']);
    $this->actingAs($this->user)->patchJson(route('client.projects.checklist.update', ['task' => $this->task->id]), ['completed' => true])->assertForbidden();
    expect($this->task->fresh()->status)->toBe('open');
});

test('authorized operator with active membership can update provider work', function (): void {
    $this->user->update(['role' => 'admin']);
    config(['tenancy.landlord.operator_roles' => ['admin'], 'tenancy.landlord.operator_emails' => [$this->user->email]]);
    $this->task->update(['owner_type' => 'evergrove']);
    $this->actingAs($this->user)->patchJson(route('client.projects.checklist.update', ['task' => $this->task->id]), ['completed' => true])->assertOk();
});

test('checklist rejects cross tenant records inactive membership and disabled projects', function (): void {
    $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other']);
    $this->task->update(['tenant_id' => $other->id]);
    $url = route('client.projects.checklist.update', ['task' => $this->task->id]);
    $this->actingAs($this->user)->patchJson($url, ['completed' => true])->assertNotFound();
    $this->task->update(['tenant_id' => $this->tenant->id]);
    $this->project->update(['metadata' => ['checklist_enabled' => false]]);
    $this->patchJson($url, ['completed' => true])->assertNotFound();
    $this->project->update(['metadata' => ['checklist_enabled' => true]]);
    $this->user->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    $this->get(route('client.projects.checklist'))->assertForbidden();
    $this->patchJson($url, ['completed' => true])->assertForbidden();
});

test('checklist requires authentication and a boolean completion value', function (): void {
    $this->get(route('client.projects.checklist'))->assertRedirect();
    $this->actingAs($this->user)->patchJson(route('client.projects.checklist.update', ['task' => $this->task->id]), ['completed' => 'yes'])->assertUnprocessable();
});

test('checklist import previews then adds records idempotently without overwriting progress', function (): void {
    $this->user->update(['role' => 'admin']);
    config(['tenancy.landlord.operator_roles' => ['admin'], 'tenancy.landlord.operator_emails' => [$this->user->email]]);
    $path = tempnam(sys_get_temp_dir(), 'checklist');
    file_put_contents($path, json_encode(['key' => 'launch', 'title' => 'Imported launch', 'summary' => 'Reviewed source', 'client_label' => 'From client', 'provider_label' => 'Provider work', 'groups' => [['key' => 'first', 'title' => 'First steps', 'tasks' => [['key' => 'photos', 'title' => 'Supply photos', 'details' => 'Original photos', 'owner' => 'client', 'complete' => false]]]]]));
    try {
        $args = ['tenant' => $this->tenant->slug, 'file' => $path];
        $this->artisan('client-projects:import-checklist', $args)->assertSuccessful();
        expect(ClientProject::where('title', 'Imported launch')->count())->toBe(0);
        $this->artisan('client-projects:import-checklist', $args + ['--apply' => true, '--actor' => $this->user->id])->assertSuccessful();
        $task = ClientProjectTicketTask::where('title', 'Supply photos')->firstOrFail();
        $task->update(['status' => 'done', 'completed_at' => now()]);
        $this->artisan('client-projects:import-checklist', $args + ['--apply' => true, '--actor' => $this->user->id])->assertSuccessful();
        expect(ClientProject::where('title', 'Imported launch')->count())->toBe(1)
            ->and(ClientProjectTicketTask::where('title', 'Supply photos')->count())->toBe(1)
            ->and($task->fresh()->status)->toBe('done');
    } finally {
        unlink($path);
    }
});
