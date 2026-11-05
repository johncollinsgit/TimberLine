<?php

namespace App\Services\CampusFacilities;

class DemoWorkPlanner
{
    public function handle(array &$state, array $actor, string $action, string $id, int $revision, array $data): array
    {
        $records = app(DemoRecords::class);
        $directory = app(DemoDirectory::class);
        if ($action === 'crew.save') {
            $data = $records->data($data, ['ticketId' => 'required|string|max:50', 'date' => 'required|date_format:Y-m-d', 'lead' => 'required|string|max:50', 'helpers' => 'present|array|max:3', 'helpers.*' => 'string|max:50|distinct', 'nextAction' => 'required|string|min:5|max:500']);
            $ticket = $records->ticket($state, $data['ticketId']);
            $lead = $records->person($state, $data['lead']);
            $crew = array_values(array_unique([$data['lead'], ...$data['helpers']]));
            abort_unless(count($crew) === 1 + count($data['helpers']), 422, 'Choose different people for the lead and helpers.');
            foreach ($crew as $personId) {
                $person = $records->person($state, $personId);
                abort_unless($person['role'] === 'technician' && $directory->may($person, 'work.execute') && $person['team'] === $ticket['fields']['category'], 422, 'Choose technicians from this work order’s service team.');
            }
            if ($actor['role'] === 'head') {
                abort_unless($actor['team'] === $lead['team'], 403, 'Plan crews within this service team.');
            }
            $old = $revision ? $records->existing($state, 'crewPlans', $id, $revision) : null;
            foreach ($state['operations']['crewPlans'] as $other) {
                abort_if($other['id'] !== $id && $other['ticketId'] === $ticket['id'] && $other['status'] !== 'Done', 422, 'Edit the existing unfinished plan for this work order before creating another.');
            }
            abort_if($old && $old['ticketId'] !== $ticket['id'], 422, 'Create a separate plan for a different work order.');
            abort_if($old && $old['status'] === 'Done', 422, 'Create a new daily plan after a completed shift.');
            $plan = [...$data, 'status' => $old['status'] ?? 'Planned', 'checkpoint' => $old['checkpoint'] ?? '', 'emergencyTicketId' => $old['emergencyTicketId'] ?? null];
            if ($plan['status'] === 'Active') {
                $this->available($state, $id, $crew);
            }
            $records->save($state, 'crewPlans', $id, $revision, $plan);
            $this->ticket($state, $actor, $ticket['id'], $id, $plan, 'Crew plan updated');

            return ['message' => 'Daily crew plan saved.', 'resourceId' => $id];
        }
        $plan = $records->existing($state, 'crewPlans', $id, $revision);
        $ticket = $records->ticket($state, $plan['ticketId']);
        $crew = [$plan['lead'], ...$plan['helpers']];
        abort_unless($directory->may($actor, 'crew.manage') || in_array($actor['id'], $crew, true), 403, 'Use this crew’s demo person or its coordinator.');
        if ($actor['role'] === 'head') {
            abort_unless($actor['team'] === $ticket['fields']['category'], 403, 'Use the responsible service-team head.');
        }
        if ($action === 'crew.pause') {
            $data = $records->data($data, ['checkpoint' => 'required|string|min:8|max:1200', 'nextAction' => 'required|string|min:5|max:500', 'emergencyTicketId' => 'nullable|string|max:50']);
            abort_unless($plan['status'] === 'Active', 422, 'Start this crew before recording an interruption.');
            if ($data['emergencyTicketId'] ?? null) {
                $emergency = $records->ticket($state, $data['emergencyTicketId']);
                abort_unless($emergency['id'] !== $ticket['id'] && ! in_array($emergency['status'], ['Completed', 'Rejected'], true), 422, 'Choose a different open emergency request.');
            }
            $plan = [...$plan, ...$data, 'status' => 'Paused'];
            $label = 'Work paused with a checkpoint';
        } elseif (in_array($action, ['crew.start', 'crew.resume'], true)) {
            $records->data($data, []);
            abort_unless($plan['status'] === ($action === 'crew.start' ? 'Planned' : 'Paused'), 422, 'Refresh the current crew state before starting or resuming.');
            abort_unless(in_array($ticket['status'], ['Approved', 'Assigned', 'In progress', 'Awaiting parts'], true), 422, 'The service head must approve this request before dispatch.');
            foreach ($crew as $personId) {
                $person = $records->person($state, $personId);
                abort_unless($person['role'] === 'technician' && $directory->may($person, 'work.execute') && $person['team'] === $ticket['fields']['category'], 422, 'Refresh the enabled crew before dispatch.');
            }
            $this->available($state, $id, $crew);
            $plan['status'] = 'Active';
            $label = $action === 'crew.resume' ? 'Original work explicitly resumed' : 'Daily crew started work';
            $state['tickets'][$ticket['id']]['status'] = 'In progress';
        } elseif ($action === 'crew.finish') {
            $records->data($data, []);
            abort_unless(in_array($ticket['status'], ['Completed', 'Rejected'], true), 422, 'Record the work order’s completion or closure before finishing its daily plan.');
            $plan['status'] = 'Done';
            $label = 'Daily plan closed';
        } else {
            abort(422, 'Unsupported crew action.');
        }
        $records->save($state, 'crewPlans', $id, $revision, $plan);
        $this->ticket($state, $actor, $ticket['id'], $id, $plan, $label);

        return ['message' => $label.'.', 'resourceId' => $id];
    }

    private function available(array $state, string $id, array $crew): void
    {
        foreach ($state['operations']['crewPlans'] as $other) {
            if ($other['id'] !== $id && $other['status'] === 'Active') {
                abort_if(array_intersect($crew, [$other['lead'], ...$other['helpers']]), 422, 'A crew member is working on another active plan. Record a checkpoint and pause that work first.');
            }
        }
    }

    private function ticket(array &$state, array $actor, string $ticketId, string $planId, array $plan, string $label): void
    {
        $ticket = &$state['tickets'][$ticketId];
        abort_if(count($ticket['events']) >= 200, 422, 'This work order has reached its demo history limit.');
        $ticket['assignee'] = $plan['lead'];
        $ticket['crewIds'] = array_values(array_unique([$plan['lead'], ...$plan['helpers']]));
        $ticket['operations'] = ['planId' => $planId, 'status' => $plan['status'], 'checkpoint' => $plan['checkpoint'], 'nextAction' => $plan['nextAction'], 'emergencyTicketId' => $plan['emergencyTicketId'] ?? null];
        $ticket['events'][] = app(Tickets::class)->event($actor['name'], $label, $plan['checkpoint'] ?: $plan['nextAction'], 'internal');
        $ticket['updatedAt'] = now()->toIso8601String();
        $ticket['revision']++;
    }
}
