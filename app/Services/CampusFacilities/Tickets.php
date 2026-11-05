<?php

namespace App\Services\CampusFacilities;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class Tickets
{
    public const TEAMS = ['Maintenance', 'Grounds', 'Housekeeping'];

    public function people(?array $state = null, bool $includeDisabled = false): array
    {
        return app(DemoDirectory::class)->people($state, $includeDisabled);
    }

    public function actor(array $input, ?array $state = null): array
    {
        return app(DemoDirectory::class)->actor($input, $state);
    }

    public function fields(array $fields, bool $partial = false, ?array $state = null): array
    {
        $minimums = ['requester' => 2, 'requesterId' => 1, 'department' => 2, 'contact' => 3, 'location' => 2, 'room' => 1, 'issue' => 5, 'onset' => 1, 'impact' => 2, 'hazards' => 2, 'access' => 2];
        $rules = [];
        foreach ($minimums as $field => $minimum) {
            $rules[$field] = [$partial ? 'sometimes' : 'required', 'string', 'min:'.$minimum, 'max:3000'];
        }
        $rules['category'] = [$partial ? 'sometimes' : 'required', Rule::in(app(DemoDirectory::class)->teams($state))];

        return Validator::make($fields, $rules)->validate();
    }

    public function canSee(array $ticket, array $person): bool
    {
        if (! app(DemoDirectory::class)->may($person, 'work.view')) {
            return false;
        }

        return match ($person['role']) {
            'staff' => $ticket['fields']['requesterId'] === $person['id'],
            'technician' => $ticket['assignee'] === $person['id'] || in_array($person['id'], $ticket['crewIds'] ?? [], true),
            'head' => $ticket['fields']['category'] === $person['team'],
            'admin' => true,
            default => false,
        };
    }

    public function event(string $actor, string $action, string $message = '', string $visibility = 'requester'): array
    {
        return ['id' => (string) Str::uuid(), 'at' => now()->toIso8601String(), 'actor' => $actor, 'action' => $action, 'message' => $message, 'visibility' => $visibility];
    }

    public function save(array &$state, array $input): array
    {
        $person = $this->actor($input, $state);
        $directory = app(DemoDirectory::class);
        if (($input['action'] ?? null) === 'create') {
            abort_unless($directory->may($person, 'work.create'), 403, 'This demo role cannot submit work requests.');
            $fields = $this->fields($input['fields'] ?? [], false, $state);
            abort_unless($fields['requesterId'] === $person['id'], 422, 'Choose the requester demo person.');
            Validator::make($input, ['requestKey' => ['required', 'string', 'min:8', 'max:100']])->validate();
            $photos = $this->photos($state, $input['photos'] ?? [], 5);
            $digest = hash('sha256', json_encode([$fields, array_column($photos, 'id'), $person['id']], JSON_THROW_ON_ERROR));
            $key = $input['requestKey'];
            if (isset($state['request_keys'][$key])) {
                $receipt = $state['request_keys'][$key];
                abort_if($receipt['digest'] !== null && $receipt['digest'] !== $digest, 409, 'This request identifier belongs to a different draft.');

                return $state['tickets'][$receipt['id']];
            }
            abort_if(count($state['tickets']) >= 200, 422, 'The shared demo has reached its 200-ticket limit.');
            $id = 'FM-'.strtoupper(substr((string) Str::uuid(), 0, 8));
            $at = now()->toIso8601String();
            $ticket = ['id' => $id, 'fields' => $fields, 'title' => Str::limit(preg_replace('/\s+/', ' ', $fields['issue']), 85, ''), 'status' => 'Pending approval', 'priority' => 'Normal', 'assignee' => null, 'createdAt' => $at, 'updatedAt' => $at, 'revision' => 1, 'photos' => $photos, 'events' => [$this->event($person['name'], 'Request submitted', 'Awaiting Facilities-head approval.')], 'workLogs' => []];
            $ticket['approvalRoute'] = $state['operations']['settings']['workApproval'];
            $ticket['approvalStep'] = 0;
            $ticket['approvalDecisions'] = [];
            $state['tickets'][$id] = $ticket;
            $state['request_keys'][$key] = ['id' => $id, 'digest' => $digest];

            return $ticket;
        }
        $id = $input['id'] ?? '';
        abort_unless(is_string($id) && isset($state['tickets'][$id]), 404, 'Ticket not found.');
        $ticket = $state['tickets'][$id];
        abort_unless($this->canSee($ticket, $person), 403, 'Choose the demo person responsible for this ticket.');
        abort_unless(($input['revision'] ?? null) === $ticket['revision'], 409, 'This ticket changed on another device. Refresh and review before saving.');
        abort_if(count($ticket['events']) >= 200, 422, 'This demo ticket has reached its history limit.');
        $action = $input['action'] ?? '';
        $data = $input['data'] ?? [];
        abort_unless(is_array($data), 422, 'Review the workflow fields.');
        $message = $data['message'] ?? '';
        abort_unless(is_string($message) && mb_strlen($message) <= 3000, 422, 'Use a message under 3000 characters.');
        $message = trim($message);
        $head = $directory->may($person, 'work.approve');
        $tech = $directory->may($person, 'work.execute');
        if (in_array($action, ['approve', 'details', 'reject'], true)) {
            $ticket['approvalRoute'] ??= DemoWorkspaceSeed::settings()['workApproval'];
            $ticket['approvalStep'] ??= 0;
            $step = $ticket['approvalRoute'][$ticket['approvalStep']] ?? null;
            abort_unless($step && $directory->reviewsAs($person, $step['role']), 403, 'Switch to the role responsible for this approval step.');
        }
        if (in_array($action, ['start', 'await', 'work', 'complete'], true)) {
            abort_if(($ticket['operations']['status'] ?? null) === 'Paused', 422, 'Resume the paused daily plan before recording further work.');
        }
        $visibility = 'requester';
        $requireState = function (array $states) use ($ticket): void {
            abort_unless(in_array($ticket['status'], $states, true), 422, 'This action is unavailable while the request is '.strtolower($ticket['status']).'.');
        };
        switch ($action) {
            case 'approve':
                abort_unless($head, 403);
                $requireState(['Pending approval']);
                $ticket['approvalDecisions'][] = ['step' => $step['name'], 'by' => $person['name'], 'at' => now()->toIso8601String()];
                $ticket['approvalStep']++;
                $ticket['status'] = $ticket['approvalStep'] === count($ticket['approvalRoute']) ? 'Approved' : 'Pending approval';
                $label = $ticket['status'] === 'Approved' ? 'Request approved' : 'Approval step recorded';
                $message = $step['name'];
                break;
            case 'details':
            case 'reject':
                abort_unless($head, 403);
                $requireState($action === 'details' ? ['Pending approval'] : ['Pending approval', 'Needs details']);
                abort_if(mb_strlen($message) < 5, 422, 'Explain the requested details or decision.');
                $ticket['status'] = $action === 'details' ? 'Needs details' : 'Rejected';
                $label = $action === 'details' ? 'More details requested' : 'Request rejected';
                break;
            case 'assign':
                abort_unless($directory->may($person, 'work.assign'), 403);
                $requireState(['Approved', 'Assigned']);
                $assignee = collect($this->people($state))->first(fn ($p) => $p['id'] === ($data['assignee'] ?? null) && $p['role'] === 'technician' && $directory->may($p, 'work.execute') && $p['team'] === $ticket['fields']['category']);
                abort_unless($assignee, 422, 'Choose a technician from this service team.');
                $ticket['assignee'] = $assignee['id'];
                $ticket['status'] = 'Assigned';
                $label = 'Technician assigned';
                $message = $assignee['name'];
                break;
            case 'priority':
                abort_unless($directory->may($person, 'work.assign'), 403);
                abort_unless(in_array($data['priority'] ?? null, ['Low', 'Normal', 'High', 'Urgent'], true), 422, 'Choose a valid priority.');
                $ticket['priority'] = $data['priority'];
                $label = 'Priority changed';
                $message = $data['priority'];
                break;
            case 'reply':
                abort_unless($directory->may($person, 'work.comment') && ($person['role'] === 'staff' || $head), 403);
                $requireState(['Needs details']);
                abort_if(mb_strlen($message) < 5, 422, 'Add the requested information.');
                $ticket['status'] = 'Pending approval';
                $label = 'Requester added details';
                break;
            case 'photos':
                abort_unless($directory->may($person, 'work.comment') || $tech, 403);
                $photos = $this->photos($state, $data['photos'] ?? [], 10 - count($ticket['photos']));
                abort_unless(count($photos), 422, 'Choose a photo before saving.');
                $ticket['photos'] = array_merge($ticket['photos'], $photos);
                $label = 'Photos added';
                $message = count($photos).' photo(s) attached.';
                break;
            default:
                abort_unless($tech, 403);
                $requireState(['Assigned', 'In progress', 'Awaiting parts']);
                if ($action === 'start') {
                    $requireState(['Assigned', 'Awaiting parts']);
                    $ticket['status'] = 'In progress';
                    $label = 'Work started';
                } elseif ($action === 'await' || $action === 'note') {
                    abort_if(mb_strlen($message) < 3, 422, 'Add a note before saving.');
                    if ($action === 'await') {
                        $ticket['status'] = 'Awaiting parts';
                        $label = 'Awaiting parts';
                    } else {
                        $label = 'Technician note';
                        $visibility = ($data['visibility'] ?? '') === 'requester' ? 'requester' : 'internal';
                    }
                } elseif (in_array($action, ['work', 'complete'], true)) {
                    $work = Validator::make($data['work'] ?? [], ['summary' => 'required|string|min:5|max:3000', 'minutes' => 'required|integer|min:0|max:1440', 'materials' => 'required|string|max:3000', 'outcome' => 'required|string|min:2|max:3000', 'remaining' => 'required|string|max:3000'])->validate();
                    $ticket['workLogs'][] = [...$work, 'at' => now()->toIso8601String(), 'actor' => $person['name']];
                    $ticket['status'] = $action === 'complete' ? 'Completed' : 'In progress';
                    $label = $action === 'complete' ? 'Job completed' : 'Work logged';
                    $message = $work['summary']."\nTime: ".$work['minutes'].' minutes · Materials: '.$work['materials']."\nOutcome: ".$work['outcome']."\nRemaining: ".$work['remaining'];
                    if ($action === 'complete') {
                        foreach ($state['operations']['crewPlans'] as &$plan) {
                            if ($plan['ticketId'] === $id && $plan['status'] === 'Active') {
                                $plan['status'] = 'Done';
                                $plan['revision']++;
                                $plan['updatedAt'] = now()->toIso8601String();
                                $state['operations']['revision']++;
                                if (($ticket['operations']['planId'] ?? null) === $plan['id']) {
                                    $ticket['operations']['status'] = 'Done';
                                }
                            }
                        }
                        unset($plan);
                    }
                } else {
                    abort(422, 'Unknown workflow action.');
                }
        }
        $ticket['events'][] = $this->event($person['name'], $label, $message, $visibility);
        $ticket['revision']++;
        $ticket['updatedAt'] = now()->toIso8601String();
        $state['tickets'][$id] = $ticket;

        return $ticket;
    }

    private function photos(array $state, mixed $photos, int $maximum): array
    {
        abort_unless(is_array($photos) && count($photos) <= $maximum, 422, 'Too many photos for this ticket.');
        $verified = [];
        foreach ($photos as $photo) {
            $id = is_array($photo) ? ($photo['id'] ?? '') : '';
            abort_unless(is_string($id) && isset($state['attachments'][$id]), 422, 'Upload the photo before saving.');
            $verified[$id] = $state['attachments'][$id];
        }

        return array_values($verified);
    }
}
