<?php

use App\Models\ClientProject;
use App\Models\ClientProjectLink;
use App\Models\ClientProjectMilestone;
use App\Models\ClientProjectPhase;
use App\Models\ClientProjectUpdate;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\HomeRedirect;

beforeEach(function (): void {
    $this->withoutVite();
});

test('tenant user can view client project progress dashboard', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Acme Services',
        'slug' => 'acme-services',
    ]);

    $user = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id => ['role' => 'manager']]);

    $project = ClientProject::query()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Acme Website Relaunch',
        'summary' => 'A client-facing website and project visibility portal.',
        'status' => 'in_progress',
        'health' => 'on_track',
        'starts_on' => now()->subDays(5)->toDateString(),
        'due_on' => now()->addDays(25)->toDateString(),
    ]);

    ClientProjectMilestone::query()->create([
        'tenant_id' => $tenant->id,
        'client_project_id' => $project->id,
        'title' => 'Homepage prototype',
        'status' => 'upcoming',
        'due_on' => now()->addDays(5)->toDateString(),
    ]);

    ClientProjectUpdate::query()->create([
        'tenant_id' => $tenant->id,
        'client_project_id' => $project->id,
        'title' => 'Prototype ready',
        'body' => 'First screens are ready for review.',
        'visibility' => 'client',
        'published_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('client.projects.index'))
        ->assertOk()
        ->assertSeeText('Acme Services Projects')
        ->assertSeeText('Acme Website Relaunch')
        ->assertSeeText('Homepage prototype')
        ->assertSeeText('Prototype ready');
});

test('client project detail shows phases timeline updates and links', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Acme Services',
        'slug' => 'acme-services',
    ]);

    $user = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id => ['role' => 'manager']]);

    $project = ClientProject::query()->create([
        'tenant_id' => $tenant->id,
        'title' => 'AI Intake Workflow',
        'summary' => 'A guided intake and automation planning project.',
        'status' => 'in_progress',
        'health' => 'watch',
        'starts_on' => '2026-06-01',
        'due_on' => '2026-06-30',
    ]);

    $phase = ClientProjectPhase::query()->create([
        'tenant_id' => $tenant->id,
        'client_project_id' => $project->id,
        'name' => 'Discovery and workflow map',
        'summary' => 'Capture the real intake path.',
        'status' => 'complete',
        'starts_on' => '2026-06-01',
        'due_on' => '2026-06-07',
        'percent_complete' => 100,
    ]);

    ClientProjectMilestone::query()->create([
        'tenant_id' => $tenant->id,
        'client_project_id' => $project->id,
        'client_project_phase_id' => $phase->id,
        'title' => 'Workflow map approved',
        'status' => 'complete',
        'due_on' => '2026-06-07',
    ]);

    ClientProjectUpdate::query()->create([
        'tenant_id' => $tenant->id,
        'client_project_id' => $project->id,
        'title' => 'Discovery finished',
        'body' => 'The first pass of workflow mapping is complete.',
        'visibility' => 'client',
        'published_at' => now(),
    ]);

    ClientProjectLink::query()->create([
        'tenant_id' => $tenant->id,
        'client_project_id' => $project->id,
        'label' => 'Shared brief',
        'url' => 'https://example.com/brief',
        'description' => 'Current working brief.',
    ]);

    $this->actingAs($user)
        ->get(route('client.projects.show', ['project' => $project]))
        ->assertOk()
        ->assertSeeText('AI Intake Workflow')
        ->assertSeeText('Discovery and workflow map')
        ->assertSeeText('Workflow map approved')
        ->assertSee('data-gantt-scroll', false)
        ->assertSeeText('Discovery finished')
        ->assertSeeText('Shared brief');
});

test('tenant user cannot view another tenants client project', function (): void {
    $tenantA = Tenant::query()->create([
        'name' => 'Tenant A',
        'slug' => 'tenant-a',
    ]);
    $tenantB = Tenant::query()->create([
        'name' => 'Tenant B',
        'slug' => 'tenant-b',
    ]);

    $user = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenantA->id => ['role' => 'manager']]);

    $otherProject = ClientProject::query()->create([
        'tenant_id' => $tenantB->id,
        'title' => 'Private Tenant B Project',
        'status' => 'planning',
        'health' => 'on_track',
    ]);

    $this->actingAs($user)
        ->get(route('client.projects.show', ['project' => $otherProject]))
        ->assertNotFound();
});

test('project dashboard has a useful empty state', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Empty Client',
        'slug' => 'empty-client',
    ]);

    $user = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id => ['role' => 'manager']]);

    $this->actingAs($user)
        ->get(route('client.projects.index'))
        ->assertOk()
        ->assertSeeText('No client projects yet')
        ->assertSeeText('Once Evergrove adds a project, progress and updates will appear here.');
});

test('customer users with active projects land on the client project portal', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Portal Client',
        'slug' => 'portal-client',
    ]);

    $user = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'requested_via' => 'customer_production',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->syncWithoutDetaching([$tenant->id => ['role' => 'manager']]);

    ClientProject::query()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Client Portal Build',
        'status' => 'in_progress',
        'health' => 'on_track',
    ]);

    expect(HomeRedirect::pathFor($user, $tenant))->toBe(route('client.projects.index', absolute: false));
});
