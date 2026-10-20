<?php

use App\Models\User;
use Livewire\Livewire;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function () {
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
});

test('both Teams surfaces contain settings without a platform profile navigation link', function () {
    [$tenant, $owner] = teamWorkspace();
    foreach (['team.app', 'team.index'] as $route) {
        $this->actingAs($owner)->get(route($route, ['tenant' => $tenant->slug]))->assertOk()
            ->assertSee('id="tw-settings-dialog"', false)
            ->assertSee('data-team-settings', false)
            ->assertSee('Save profile')
            ->assertSee('Connection settings')
            ->assertDontSee('href="'.route('profile.edit', ['tenant' => $tenant->slug]).'"', false);
    }
});

test('contained profile uses canonical validation and preserves verification for unchanged email', function () {
    [$tenant, $owner] = teamWorkspace();
    $this->actingAs($owner);
    Livewire::test('pages::settings.profile', ['teamTenantId' => $tenant->id])
        ->set('name', 'Fictional coordinator')->call('updateProfileInformation')
        ->assertHasNoErrors()->assertSee('Profile saved.');
    expect($owner->fresh()->name)->toBe('Fictional coordinator')
        ->and($owner->fresh()->email_verified_at)->not->toBeNull();
    $other = User::factory()->create();
    Livewire::test('pages::settings.profile', ['teamTenantId' => $tenant->id])
        ->set('email', $other->email)->call('updateProfileInformation')->assertHasErrors('email');
});

test('contained profile refuses foreign workspace and revoked membership on later requests', function () {
    [$tenant, $owner] = teamWorkspace();
    [$foreign] = teamWorkspace();
    $this->actingAs($owner);
    Livewire::test('pages::settings.profile', ['teamTenantId' => $foreign->id])->assertForbidden();
    $component = Livewire::test('pages::settings.profile', ['teamTenantId' => $tenant->id]);
    $tenant->users()->updateExistingPivot($owner->id, ['membership_active' => false]);
    $component->set('name', 'Must not save')->assertForbidden();
    expect($owner->fresh()->name)->not->toBe('Must not save');
});

test('contained profile fences a different account on subsequent requests', function () {
    [$tenant, $owner] = teamWorkspace();
    $other = teamMember($tenant, 'admin');
    $this->actingAs($owner);
    $component = Livewire::test('pages::settings.profile', ['teamTenantId' => $tenant->id]);
    $this->actingAs($other);
    $component->set('name', 'Wrong account')->assertForbidden();
    expect($other->fresh()->name)->not->toBe('Wrong account')->and($owner->fresh()->name)->not->toBe('Wrong account');
});

test('contained email change uses canonical verification reset and refuses further Teams requests', function () {
    [$tenant, $owner] = teamWorkspace();
    $this->actingAs($owner);
    $component = Livewire::test('pages::settings.profile', ['teamTenantId' => $tenant->id])
        ->set('email', 'updated-profile@fictional.invalid')->call('updateProfileInformation')
        ->assertHasNoErrors()->assertSee('Verify your updated email');
    expect($owner->fresh()->email_verified_at)->toBeNull();
    $component->set('name', 'After revocation')->assertForbidden();
});
