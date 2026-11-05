<?php

namespace App\Services\CampusFacilities;

use Illuminate\Support\Facades\Validator;

/** Helpers operate only on the caller's already locked fictional state. */
class DemoRecords
{
    public function index(array $rows, string $id): int
    {
        foreach ($rows as $index => $row) {
            if ($row['id'] === $id) {
                return $index;
            }
        }
        abort(404, 'This sample record no longer exists.');
    }

    public function row(array $state, string $group, string $id): array
    {
        $rows = $state['operations'][$group];

        return $rows[$this->index($rows, $id)];
    }

    public function existing(array $state, string $group, string $id, int $revision): array
    {
        $row = $this->row($state, $group, $id);
        abort_unless($row['revision'] === $revision, 409, 'This record changed. Refresh and review before saving.');

        return $row;
    }

    public function save(array &$state, string $group, string $id, int $revision, array $data): array
    {
        Validator::make(['id' => $id], ['id' => 'required|string|max:70|regex:/^[a-z][a-z0-9-]*$/D'])->validate();
        $rows = &$state['operations'][$group];
        $index = null;
        foreach ($rows as $key => $row) {
            if ($row['id'] === $id) {
                $index = $key;
                break;
            }
        }
        if ($index === null) {
            abort_unless($revision === 0, 409, 'Refresh before adding this sample.');
            abort_if(count($rows) >= 100, 422, 'This demo collection has reached its 100-record limit.');
            $data = [...$data, 'id' => $id, 'revision' => 1, 'createdAt' => now()->toIso8601String(), 'updatedAt' => now()->toIso8601String()];
            $rows[] = $data;
        } else {
            abort_unless($rows[$index]['revision'] === $revision, 409, 'This record changed. Refresh and review before saving.');
            $data = [...$rows[$index], ...$data, 'id' => $id, 'revision' => $revision + 1, 'updatedAt' => now()->toIso8601String()];
            $rows[$index] = $data;
        }

        return $data;
    }

    public function person(array $state, string $id): array
    {
        $person = collect(app(DemoDirectory::class)->people($state))->firstWhere('id', $id);
        abort_unless($person, 422, 'Choose an enabled demo person.');

        return $person;
    }

    public function ticket(array $state, string $id): array
    {
        abort_unless(isset($state['tickets'][$id]), 422, 'Choose an existing work order.');

        return $state['tickets'][$id];
    }

    public function requirePermission(array $actor, string $permission): void
    {
        abort_unless(app(DemoDirectory::class)->may($actor, $permission), 403, 'Switch to a demo role with permission for this action.');
    }

    public function requireReviewer(array $actor, string $role): void
    {
        abort_unless(app(DemoDirectory::class)->reviewsAs($actor, $role), 403, 'Switch to the role responsible for this review step.');
    }

    public function data(array $data, array $rules): array
    {
        abort_if(array_diff(array_keys($data), array_keys($rules)), 422, 'This form contains unsupported fields.');

        return Validator::make($data, $rules)->validate();
    }
}
