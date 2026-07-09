<?php

use App\Models\ClientProject;
use App\Models\ClientProjectMilestone;
use App\Models\ClientProjectPhase;
use App\Models\ClientProjectTicket;
use App\Models\ClientProjectTicketReference;
use App\Models\ClientProjectTicketTask;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Database\Seeders\ModernForestryAppFeedbackSeeder;

beforeEach(function (): void {
    $this->withoutVite();

    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.public_host', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.landlord_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
});

function clientTicketTenant(string $slug = 'client-ticket-tenant'): Tenant
{
    return Tenant::query()->create([
        'name' => str($slug)->replace('-', ' ')->headline()->toString(),
        'slug' => $slug,
    ]);
}

function clientTicketUser(Tenant $tenant, string $role = 'manager'): User
{
    $user = User::factory()->create([
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id => ['role' => $role]]);

    return $user;
}

test('tenant customer can submit a project-aware feature request with tasks and references', function (): void {
    $tenant = clientTicketTenant('acme');
    $user = clientTicketUser($tenant);
    $project = ClientProject::query()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Acme Portal Build',
        'status' => 'in_progress',
        'health' => 'on_track',
    ]);
    $phase = ClientProjectPhase::query()->create([
        'tenant_id' => $tenant->id,
        'client_project_id' => $project->id,
        'name' => 'Client dashboard',
        'status' => 'in_progress',
    ]);
    $milestone = ClientProjectMilestone::query()->create([
        'tenant_id' => $tenant->id,
        'client_project_id' => $project->id,
        'client_project_phase_id' => $phase->id,
        'title' => 'Dashboard review',
        'status' => 'upcoming',
    ]);

    $this->actingAs($user)
        ->post(route('client.projects.requests.store', ['project' => $project]), [
            'type' => 'feature',
            'title' => 'Add approval notifications',
            'problem_summary' => 'Customers need to know when quotes are ready to approve.',
            'desired_outcome' => 'Send a clear email and show a portal task.',
            'scope_notes' => 'Keep the first version lightweight.',
            'urgency' => 'normal',
            'priority' => 'high',
            'client_project_phase_id' => $phase->id,
            'client_project_milestone_id' => $milestone->id,
            'task_titles' => "Draft notification copy\nAdd portal status chip",
            'reference_label' => 'Current quote screen',
            'reference_url' => 'https://example.com/quote',
            'reference_notes' => 'Use this as the starting point.',
        ])
        ->assertRedirect();

    $ticket = ClientProjectTicket::query()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($ticket->title)->toBe('Add approval notifications')
        ->and($ticket->client_project_phase_id)->toBe($phase->id)
        ->and(ClientProjectTicketTask::query()->where('client_project_ticket_id', $ticket->id)->count())->toBe(2)
        ->and(ClientProjectTicketReference::query()->where('client_project_ticket_id', $ticket->id)->count())->toBe(1)
        ->and(TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->get(route('client.projects.requests.show', ['ticket' => $ticket]))
        ->assertOk()
        ->assertSeeText('Add approval notifications')
        ->assertSeeText('Client dashboard')
        ->assertSeeText('Draft notification copy')
        ->assertSeeText('Current quote screen')
        ->assertDontSeeText('landlord_notes');
});

test('tenant customer cannot attach a project request to another tenants phase', function (): void {
    $tenant = clientTicketTenant('acme');
    $otherTenant = clientTicketTenant('other');
    $user = clientTicketUser($tenant);

    $project = ClientProject::query()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Acme Build',
        'status' => 'in_progress',
        'health' => 'on_track',
    ]);
    $otherProject = ClientProject::query()->create([
        'tenant_id' => $otherTenant->id,
        'title' => 'Other Build',
        'status' => 'in_progress',
        'health' => 'on_track',
    ]);
    $otherPhase = ClientProjectPhase::query()->create([
        'tenant_id' => $otherTenant->id,
        'client_project_id' => $otherProject->id,
        'name' => 'Private phase',
    ]);

    $this->actingAs($user)
        ->post(route('client.projects.requests.store', ['project' => $project]), [
            'type' => 'feature',
            'title' => 'Cross tenant request',
            'problem_summary' => 'This should not be accepted.',
            'urgency' => 'normal',
            'priority' => 'normal',
            'client_project_phase_id' => $otherPhase->id,
        ])
        ->assertSessionHasErrors('client_project_phase_id');

    expect(ClientProjectTicket::query()->count())->toBe(0);
});

test('landlord operator can triage client project tickets without exposing internal notes to tenant', function (): void {
    $tenant = clientTicketTenant('acme');
    $project = ClientProject::query()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Acme Build',
        'status' => 'in_progress',
        'health' => 'on_track',
    ]);
    $ticket = ClientProjectTicket::query()->create([
        'tenant_id' => $tenant->id,
        'client_project_id' => $project->id,
        'type' => 'app_request',
        'title' => 'Customer app request',
        'problem_summary' => 'Need a client-facing app workflow.',
        'status' => 'new',
        'priority' => 'normal',
        'urgency' => 'normal',
    ]);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get('http://app.theeverbranch.com/landlord/client-project-tickets')
        ->assertOk()
        ->assertSeeText('Customer app request')
        ->assertSeeText('Client request triage');

    $this->actingAs($admin)
        ->post("http://app.theeverbranch.com/landlord/client-project-tickets/{$ticket->id}", [
            'status' => 'scoped',
            'priority' => 'high',
            'scope_notes' => 'Client-visible scope summary.',
            'landlord_notes' => 'Internal pricing thought.',
        ])
        ->assertRedirect(route('landlord.client-project-tickets.index', ['filter' => 'scoped']));

    $ticket->refresh();

    expect($ticket->status)->toBe('scoped')
        ->and($ticket->priority)->toBe('high')
        ->and($ticket->landlord_notes)->toBe('Internal pricing thought.')
        ->and(TenantModuleEntitlement::query()->where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('modern forestry app feedback seed localizes tickets to the client project request board', function (): void {
    $this->seed(ModernForestryAppFeedbackSeeder::class);

    $tenant = Tenant::query()->where('slug', 'modern-forestry')->firstOrFail();
    $user = clientTicketUser($tenant);

    $project = ClientProject::query()
        ->where('tenant_id', $tenant->id)
        ->where('title', 'Modern Forestry App Request Board')
        ->firstOrFail();

    expect(ClientProjectTicket::query()->where('client_project_id', $project->id)->count())->toBe(15)
        ->and(ClientProjectTicket::query()
            ->where('client_project_id', $project->id)
            ->where('title', 'QA: confirm Google appears beside Facebook on the live sign-in sheet')
            ->value('status'))->toBe('done')
        ->and(ClientProjectTicket::query()
            ->where('client_project_id', $project->id)
            ->where('title', 'Confirm App Store Guideline 4.8 compliance now that Google/Facebook login is enabled')
            ->value('type'))->toBe('app_request');

    $this->actingAs($user)
        ->get(route('client.projects.requests.index'))
        ->assertOk()
        ->assertSeeText('Modern Forestry App Request Board')
        ->assertSeeText('Confirm App Store Guideline 4.8 compliance now that Google/Facebook login is enabled');
});
