<?php

namespace App\Services\CampusFacilities;

/** Named fictional perspectives. Never authenticates a native user or grants access. */
class DemoDirectory
{
    private ?array $settings = null;

    public const PERMISSIONS = [
        'work.view' => 'View work orders', 'work.create' => 'Submit requests',
        'work.comment' => 'Comment on work', 'work.approve' => 'Review work requests',
        'work.assign' => 'Assign technicians', 'work.execute' => 'Record and complete work',
        'crew.manage' => 'Plan crews and interruptions', 'schedule.view' => 'View the schedule',
        'schedule.manage' => 'Schedule work and resolve conflicts', 'vendors.view' => 'View vendors',
        'vendors.manage' => 'Edit vendors and ratings', 'certificates.review' => 'Review certificate renewals',
        'finance.view' => 'View finance packets', 'finance.submit' => 'Prepare finance packets',
        'finance.review' => 'Review finance packets', 'finance.record' => 'Record a demo payment',
        'reports.view' => 'Preview and export reports', 'reports.review' => 'Review quarterly reports',
        'training.manage' => 'Record sample training',
        'settings.manage' => 'Customize demo settings',
    ];

    public const DEPENDENCIES = [
        'work.create' => 'work.view', 'work.comment' => 'work.view', 'work.approve' => 'work.view',
        'work.assign' => 'work.view', 'work.execute' => 'work.view', 'crew.manage' => 'work.view',
        'schedule.manage' => 'schedule.view', 'vendors.manage' => 'vendors.view', 'certificates.review' => 'vendors.view',
        'finance.submit' => 'finance.view', 'finance.review' => 'finance.view', 'finance.record' => 'finance.view',
        'reports.review' => 'reports.view',
    ];

    public const SHORTCUTS = [
        'new_request' => ['name' => 'Report an issue', 'permission' => 'work.create', 'page' => 'request'],
        'workload' => ['name' => 'Review workload', 'permission' => 'work.view', 'page' => 'overview'],
        'daily_work' => ['name' => 'Daily crew plan', 'permission' => 'work.view', 'page' => 'operations', 'tab' => 'daily'],
        'schedule' => ['name' => 'Review the schedule', 'permission' => 'schedule.view', 'page' => 'operations', 'tab' => 'calendar'],
        'vendors' => ['name' => 'Vendors and renewals', 'permission' => 'vendors.view', 'page' => 'operations', 'tab' => 'vendors'],
        'finance' => ['name' => 'Review finance packets', 'permission' => 'finance.view', 'page' => 'operations', 'tab' => 'finance'],
        'reports' => ['name' => 'Quarterly reports', 'permission' => 'reports.view', 'page' => 'operations', 'tab' => 'reports'],
        'buildings' => ['name' => 'Buildings and jobs', 'permission' => 'work.view', 'page' => 'campus'],
        'settings' => ['name' => 'Customize the demo', 'permission' => 'settings.manage', 'page' => 'settings'],
    ];

    public function load(array $settings): void
    {
        $this->settings = $settings;
    }

    public function settings(?array $state = null): array
    {
        return $state['operations']['settings'] ?? $this->settings ?? DemoWorkspaceSeed::settings();
    }

    public function people(?array $state = null, bool $includeDisabled = false): array
    {
        $settings = $this->settings($state);
        $roles = array_column($settings['roles'], null, 'id');
        $people = [];
        foreach ($settings['people'] as $person) {
            $role = $roles[$person['profile']] ?? null;
            if (! $role || (! $includeDisabled && (! ($person['enabled'] ?? true) || ! $role['enabled']))) {
                continue;
            }
            $people[] = [...$person, 'enabled' => ($person['enabled'] ?? true) && $role['enabled'], 'role' => $role['baseRole'], 'roleName' => $role['name'], 'permissions' => $role['permissions']];
        }

        return $people;
    }

    public function actor(array $input, ?array $state = null): array
    {
        foreach ($this->people($state) as $person) {
            if ($person['id'] === ($input['actor'] ?? null) && $person['role'] === ($input['role'] ?? null)) {
                return $person;
            }
        }
        abort(422, 'Choose an enabled demo role and person.');
    }

    public function teams(?array $state = null): array
    {
        return array_column($this->settings($state)['teams'], 'id');
    }

    public function may(array $person, string $permission): bool
    {
        return in_array($permission, $person['permissions'] ?? [], true);
    }

    public function reviewsAs(array $person, string $profile): bool
    {
        return $person['profile'] === $profile || ($profile === 'head' && $person['role'] === 'head') || $this->may($person, 'settings.manage');
    }
}
