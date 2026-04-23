<?php

use App\Models\DevelopmentChangeLog;
use App\Models\DevelopmentNote;
use App\Models\User;

test('development notes page is admin only', function () {
    $this->get(route('admin.development-notes.index'))
        ->assertRedirect(route('login'));

    $manager = User::factory()->create([
        'role' => 'manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('admin.development-notes.index'))
        ->assertForbidden();

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.development-notes.index'))
        ->assertOk()
        ->assertSeeText('Development Notes')
        ->assertSeeText('Project Notes')
        ->assertSeeText('Change Log');
});

test('admin can create update and delete project notes', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.development-notes.notes.store'), [
            'title' => 'Initial Internal Scope',
            'body' => 'Kickoff decisions and internal implementation notes.',
        ])
        ->assertRedirect(route('admin.development-notes.index'));

    $note = DevelopmentNote::query()->firstOrFail();

    expect($note->title)->toBe('Initial Internal Scope')
        ->and($note->body)->toBe('Kickoff decisions and internal implementation notes.')
        ->and((int) $note->created_by)->toBe((int) $admin->id)
        ->and((int) $note->updated_by)->toBe((int) $admin->id);

    $this->actingAs($admin)
        ->put(route('admin.development-notes.notes.update', $note), [
            'title' => 'Updated Internal Scope',
            'body' => 'Updated details after implementation pass.',
        ])
        ->assertRedirect(route('admin.development-notes.index'));

    $note->refresh();

    expect($note->title)->toBe('Updated Internal Scope')
        ->and($note->body)->toBe('Updated details after implementation pass.')
        ->and((int) $note->updated_by)->toBe((int) $admin->id);

    $this->actingAs($admin)
        ->delete(route('admin.development-notes.notes.destroy', $note))
        ->assertRedirect(route('admin.development-notes.index'));

    expect(DevelopmentNote::query()->count())->toBe(0);
});

test('admin can add change log entries and newest entry renders first', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post(route('admin.development-notes.change-logs.store'), [
            'title' => 'First entry',
            'summary' => 'Initial integration baseline.',
            'area' => 'Admin',
        ])
        ->assertRedirect(route('admin.development-notes.index'));

    $this->actingAs($admin)
        ->post(route('admin.development-notes.change-logs.store'), [
            'title' => 'Second entry',
            'summary' => 'Follow-up migration updates.',
            'area' => 'Navigation',
        ])
        ->assertRedirect(route('admin.development-notes.index'));

    expect(DevelopmentChangeLog::query()->count())->toBe(2);
    expect((int) DevelopmentChangeLog::query()->latest('id')->value('created_by'))->toBe((int) $admin->id);

    $this->actingAs($admin)
        ->get(route('admin.development-notes.index'))
        ->assertOk()
        ->assertSeeInOrder(['Second entry', 'First entry']);
});

test('development notes admin link appears for admins but not managers', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $manager = User::factory()->create([
        'role' => 'manager',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Development Notes');

    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSeeText('Development Notes');
});
