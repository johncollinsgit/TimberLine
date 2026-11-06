<?php

namespace App\Services\CampusFacilities;

/** Explicit contractor support on an existing staff-owned work order. */
class DemoContractors
{
    public function handle(array &$state, array $actor, string $action, string $id, int $revision, array $data): array
    {
        $records = app(DemoRecords::class);
        $tickets = app(Tickets::class);
        $ticket = $records->ticket($state, $id);
        abort_unless($tickets->canSee($ticket, $actor), 403, 'Use the responsible service-team head.');
        abort_unless($ticket['revision'] === $revision, 409, 'This work order changed. Refresh and review before saving.');
        abort_unless(in_array($ticket['status'], ['Assigned', 'In progress', 'Awaiting parts'], true) && $ticket['assignee'], 422, 'Choose approved open work with an internal staff lead.');
        abort_if(count($ticket['events']) >= 200, 422, 'This work order has reached its demo history limit.');
        if ($action === 'contractor.assign') {
            $data = $records->data($data, ['vendorId' => 'required|string|max:70', 'scope' => 'required|string|min:5|max:1000', 'targetDate' => 'nullable|date_format:Y-m-d']);
            $vendor = $records->row($state, 'vendors', $data['vendorId']);
            abort_unless($vendor['status'] === 'Active', 422, 'Only active companies can receive new assignments.');
            abort_if($state['operations']['settings']['requireContractorInsurance'] && ! app(DemoVendors::class)->validFor($state, $vendor['id']), 422, 'Accept current insurance for every required certificate type before assigning this contractor.');
            abort_if(isset($ticket['contractor']), 422, 'Remove the current contractor before assigning another.');
            $ticket['contractor'] = [...$data, 'targetDate' => $data['targetDate'] ?? null, 'assignedBy' => $actor['name'], 'assignedAt' => now()->toIso8601String()];
            $label = 'Contractor assigned';
            $message = $vendor['name'].' · '.$data['scope'].($ticket['contractor']['targetDate'] ? ' · Target '.$ticket['contractor']['targetDate'] : '');
        } else {
            $data = $records->data($data, ['reason' => 'required|string|min:5|max:1000']);
            abort_unless(isset($ticket['contractor']), 422, 'This work order has no contractor assignment.');
            $vendor = $records->row($state, 'vendors', $ticket['contractor']['vendorId']);
            $label = 'Contractor removed';
            $message = $vendor['name'].' · '.$ticket['contractor']['scope'].' · '.$data['reason'];
            unset($ticket['contractor']);
        }
        $ticket['events'][] = $tickets->event($actor['name'], $label, $message, 'internal');
        $ticket['revision']++;
        $ticket['updatedAt'] = now()->toIso8601String();
        $state['tickets'][$id] = $ticket;

        return ['message' => $label.'. The internal lead and work status are retained.', 'resourceId' => $id];
    }
}
