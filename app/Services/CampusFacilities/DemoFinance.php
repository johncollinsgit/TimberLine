<?php

namespace App\Services\CampusFacilities;

/** Fictional routing and allocations only. Never executes or verifies a payment. */
class DemoFinance
{
    public const CATEGORIES = ['Building maintenance', 'Event support', 'Accessibility', 'IT', 'Grounds', 'Housekeeping'];

    public function balances(array $state, ?string $exclude = null): array
    {
        $rows = [];
        foreach ($state['operations']['funds'] as $fund) {
            $committed = 0;
            foreach ($state['operations']['invoices'] as $invoice) {
                if ($invoice['id'] !== $exclude && $invoice['fundId'] === $fund['id'] && in_array($invoice['status'], ['Approved', 'Demo paid'], true)) {
                    $committed += $invoice['amountCents'];
                }
            }
            $rows[$fund['id']] = ['budgetCents' => $fund['budgetCents'], 'committedCents' => $committed, 'availableCents' => $fund['budgetCents'] - $committed];
        }

        return $rows;
    }

    public function checks(array $state, array $invoice): array
    {
        $ops = $state['operations'];
        $project = collect($ops['projects'])->firstWhere('id', $invoice['projectId']);
        $fund = collect($ops['funds'])->firstWhere('id', $invoice['fundId']);
        $errors = [];
        if (! $project || ! $fund || ! in_array($project['category'], $fund['categories'], true)) {
            $errors[] = 'This fund does not cover the project’s purpose.';
        }
        $balance = $this->balances($state, $invoice['id'])[$invoice['fundId']] ?? null;
        if (! $balance || $balance['availableCents'] < $invoice['amountCents']) {
            $errors[] = 'The sample allocation does not have enough uncommitted budget.';
        }
        if ($ops['settings']['requireValidCertificate'] && ! app(DemoVendors::class)->validFor($state, $invoice['vendorId'])) {
            $errors[] = 'The vendor needs accepted, current certificates of the configured types.';
        }

        return $errors;
    }

    public function handle(array &$state, array $actor, string $action, string $id, int $revision, array $data): array
    {
        $records = app(DemoRecords::class);
        if ($action === 'project.save') {
            $data = $records->data($data, ['name' => 'required|string|min:5|max:150', 'category' => 'required|string|min:2|max:70', 'serviceTeam' => ['required', \Illuminate\Validation\Rule::in(app(DemoDirectory::class)->teams($state))], 'owner' => 'required|string|max:50', 'ticketIds' => 'present|array|max:10', 'ticketIds.*' => 'string|max:50|distinct', 'source' => 'required|string|min:3|max:120', 'nextAction' => 'required|string|min:5|max:500']);
            $records->person($state, $data['owner']);
            foreach ($data['ticketIds'] as $ticketId) {
                abort_unless(app(Tickets::class)->canSee($records->ticket($state, $ticketId), $actor), 403, 'Use work orders in this demo view.');
            }
            if ($revision) {
                $old = $records->existing($state, 'projects', $id, $revision);
                if ($old['category'] !== $data['category'] || $old['ticketIds'] !== $data['ticketIds'] || ($old['serviceTeam'] ?? 'Maintenance') !== $data['serviceTeam']) {
                    abort_if(collect($state['operations']['invoices'])->contains(fn ($invoice) => $invoice['projectId'] === $id && in_array($invoice['status'], ['In review', 'Approved', 'Demo paid'], true)), 422, 'Reopen the linked finance packets before changing their project purpose or evidence. Retain paid examples with their original purpose.');
                }
            }
            $records->save($state, 'projects', $id, $revision, $data);

            return ['message' => 'Project and source reference saved.', 'resourceId' => $id];
        }
        if ($action === 'fund.save') {
            $data = $records->data($data, ['code' => 'required|string|min:3|max:50', 'name' => 'required|string|min:4|max:120', 'budgetCents' => 'required|integer|min:0|max:100000000', 'categories' => 'required|array|min:1|max:12', 'categories.*' => 'required|string|min:2|max:70|distinct', 'restriction' => 'required|string|min:5|max:1000']);
            $committed = $this->balances($state)[$id]['committedCents'] ?? 0;
            abort_if($data['budgetCents'] < $committed, 422, 'Keep the allocation at least as large as its accepted commitments.');
            abort_if(collect($state['operations']['funds'])->contains(fn ($fund) => $fund['id'] !== $id && mb_strtolower($fund['code']) === mb_strtolower($data['code'])), 422, 'Choose a different sample fund code.');
            $records->save($state, 'funds', $id, $revision, $data);

            return ['message' => 'Sample fund rules saved. Future reviews use these restrictions.', 'resourceId' => $id];
        }
        $old = $revision ? $records->existing($state, 'invoices', $id, $revision) : null;
        if ($action === 'invoice.save') {
            $data = $records->data($data, ['number' => 'required|string|min:3|max:70', 'projectId' => 'required|string|max:70', 'vendorId' => 'required|string|max:70', 'fundId' => 'required|string|max:70', 'amountCents' => 'required|integer|min:1|max:100000000', 'description' => 'required|string|min:5|max:1200', 'reference' => 'required|string|min:5|max:500']);
            $project = $records->row($state, 'projects', $data['projectId']);
            $this->scope($state, $actor, $project);
            $records->row($state, 'vendors', $data['vendorId']);
            $records->row($state, 'funds', $data['fundId']);
            abort_if($old && ! in_array($old['status'], ['Draft', 'Changes requested'], true), 422, 'Reopen a reviewed packet before editing its original terms.');
            if ($old) {
                abort_unless($old['projectId'] === $data['projectId'], 422, 'Create a separate packet for a different project.');
            }
            $previous = $old['previousReviews'] ?? [];
            if ($old && $old['route']) {
                abort_if(count($previous) >= 20, 422, 'This packet has reached its demo review limit.');
                $previous[] = $this->reviewSnapshot($old);
            }
            $records->save($state, 'invoices', $id, $revision, [...$data, 'status' => 'Draft', 'route' => [], 'step' => 0, 'decisions' => [], 'previousReviews' => $previous, 'preparedBy' => $actor['id'], 'terms' => null]);

            return ['message' => 'Demo invoice draft saved.', 'resourceId' => $id];
        }
        abort_unless($old, 404, 'Choose an existing invoice packet.');
        $project = $records->row($state, 'projects', $old['projectId']);
        $this->scope($state, $actor, $project);
        if ($action === 'invoice.submit') {
            $records->data($data, []);
            abort_unless($old['status'] === 'Draft', 422, 'Save a corrected draft before submitting a new route.');
            $this->requireChecks($state, $old);
            $route = array_values(array_filter($state['operations']['settings']['financeApproval'], fn ($step) => $old['amountCents'] >= $step['minimumCents']));
            abort_unless(count($route), 422, 'Configure at least one applicable finance review step.');
            $vendor = $records->row($state, 'vendors', $old['vendorId']);
            $fund = $records->row($state, 'funds', $old['fundId']);
            $old = [...$old, 'status' => 'In review', 'route' => $route, 'step' => 0, 'decisions' => [], 'submittedAt' => now()->toIso8601String(), 'terms' => ['projectName' => $project['name'], 'category' => $project['category'], 'vendorName' => $vendor['name'], 'fundCode' => $fund['code'], 'restriction' => $fund['restriction'], 'amountCents' => $old['amountCents']]];
            $message = 'Packet submitted through its reviewed demo approval route.';
        } elseif ($action === 'invoice.review') {
            $data = $records->data($data, ['decision' => 'required|in:approve,changes,reject', 'reason' => 'required|string|min:5|max:1000']);
            abort_unless($old['status'] === 'In review' && isset($old['route'][$old['step']]), 422, 'This packet is not waiting for a review.');
            $step = $old['route'][$old['step']];
            $records->requireReviewer($actor, $step['role']);
            if ($data['decision'] === 'approve') {
                $this->requireChecks($state, $old);
                $old['step']++;
                $old['status'] = $old['step'] === count($old['route']) ? 'Approved' : 'In review';
            } else {
                $old['status'] = $data['decision'] === 'changes' ? 'Changes requested' : 'Rejected';
            }
            $old['decisions'][] = [...$data, 'step' => $step['name'], 'by' => $actor['name'], 'profile' => $actor['profile'], 'at' => now()->toIso8601String()];
            $message = $old['status'] === 'Approved' ? 'Demo review complete. The allocation is now committed once.' : 'Review decision recorded; the next owner is visible.';
        } elseif ($action === 'invoice.reopen') {
            $data = $records->data($data, ['reason' => 'required|string|min:5|max:1000']);
            abort_unless(in_array($old['status'], ['In review', 'Approved', 'Rejected', 'Changes requested'], true), 422, 'Only a review packet can be reopened; retain recorded payment examples with their original terms.');
            if ($old['status'] === 'Approved') {
                $records->requireReviewer($actor, $old['route'][count($old['route']) - 1]['role']);
            }
            $previous = $old['previousReviews'] ?? [];
            abort_if(count($previous) >= 20, 422, 'This packet has reached its demo review limit.');
            $previous[] = [...$this->reviewSnapshot($old), 'reopenedBy' => $actor['name'], 'reason' => $data['reason']];
            $old = [...$old, 'status' => 'Draft', 'route' => [], 'step' => 0, 'decisions' => [], 'previousReviews' => $previous];
            $old['terms'] = null;
            $message = 'Packet reopened. Earlier reviews are retained and a new route must be submitted.';
        } elseif ($action === 'invoice.record') {
            $data = $records->data($data, ['reference' => 'required|string|min:5|max:500']);
            abort_unless($old['status'] === 'Approved', 422, 'Complete the demo approval route first.');
            $old = [...$old, 'status' => 'Demo paid', 'paymentReference' => $data['reference'], 'recordedBy' => $actor['name'], 'recordedAt' => now()->toIso8601String()];
            $message = 'Fictional payment outcome recorded. No money was moved.';
        } else {
            abort(422, 'Unsupported finance action.');
        }
        $records->save($state, 'invoices', $id, $revision, $old);

        return ['message' => $message, 'resourceId' => $id];
    }

    private function requireChecks(array $state, array $invoice): void
    {
        $errors = $this->checks($state, $invoice);
        abort_if(count($errors), 422, implode(' ', $errors));
    }

    private function scope(array $state, array $actor, array $project): void
    {
        if ($actor['role'] === 'head') {
            abort_unless(($project['serviceTeam'] ?? 'Maintenance') === $actor['team'], 403, 'Review packets in this project’s lead service team.');
        }
    }

    private function reviewSnapshot(array $invoice): array
    {
        return array_intersect_key($invoice, array_flip(['status', 'route', 'step', 'decisions', 'terms', 'amountCents', 'fundId', 'vendorId', 'revision']));
    }
}
