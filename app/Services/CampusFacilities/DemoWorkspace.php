<?php

namespace App\Services\CampusFacilities;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Shared, bounded demo workflows. No native tenant records or provider calls. */
class DemoWorkspace
{
    public const ACTIONS = [
        'settings.save', 'crew.save', 'crew.start', 'crew.pause', 'crew.resume', 'crew.finish',
        'booking.save', 'booking.confirm', 'booking.cancel',
        'contractor.assign', 'contractor.remove', 'project.save', 'vendor.save', 'certificate.propose', 'certificate.review', 'certificate.remind',
        'fund.save', 'invoice.save', 'invoice.submit', 'invoice.review', 'invoice.reopen', 'invoice.record',
        'report.review', 'training.complete', 'notification.read',
    ];

    public function snapshot(array $state): array
    {
        $workspace = $state['operations'];
        unset($workspace['receipts']);
        $directory = app(DemoDirectory::class);
        $workspace['directory'] = $directory->people($state, true);
        $workspace['permissionCatalog'] = DemoDirectory::PERMISSIONS;
        $workspace['permissionDependencies'] = DemoDirectory::DEPENDENCIES;
        $workspace['shortcutCatalog'] = DemoDirectory::SHORTCUTS;
        $workspace['reportColumnCatalog'] = [
            'work_orders' => 'Recorded work orders', 'completed' => 'Completed work',
            'labor_minutes' => 'Recorded labor minutes', 'materials' => 'Recorded materials',
            'teams' => 'Service-team breakdown', 'finance' => 'Finance packet summary',
            'certificates' => 'Contractor certificate attention',
        ];
        $workspace['fundBalances'] = app(DemoFinance::class)->balances($state);
        $workspace['invoiceChecks'] = [];
        foreach ($workspace['invoices'] as $invoice) {
            $workspace['invoiceChecks'][$invoice['id']] = app(DemoFinance::class)->checks($state, $invoice);
        }
        $workspace['categoryCatalog'] = DemoFinance::CATEGORIES;
        $workspace['conflicts'] = app(DemoSchedule::class)->conflicts($state);
        $workspace['certificateAttention'] = app(DemoVendors::class)->attention($state);
        $workspace['defaultReportPeriod'] = app(DemoReports::class)->defaultPeriod($workspace['settings']);
        $workspace['asOf'] = now()->toIso8601String();
        $workspace['demo'] = true;

        return $workspace;
    }

    public function command(array &$state, array $input): array
    {
        $input = Validator::make($input, [
            'id' => 'required|uuid', 'generation' => 'required|uuid',
            'actor' => 'required|string|max:50', 'role' => 'required|in:staff,head,technician,admin',
            'action' => ['required', Rule::in(self::ACTIONS)], 'recordId' => 'required|string|max:70',
            'expectedRevision' => 'required|integer|min:0', 'data' => 'present|array',
        ])->validate();
        $directory = app(DemoDirectory::class);
        $actor = $directory->actor($input, $state);
        $ops = &$state['operations'];
        abort_unless(hash_equals($ops['generation'], $input['generation']), 409, 'The sample workspace was reset. Refresh before continuing.');
        $permission = match (explode('.', $input['action'])[0]) {
            'settings' => 'settings.manage', 'booking' => 'schedule.manage',
            'contractor' => 'work.assign', 'project', 'crew' => 'crew.manage', 'vendor' => 'vendors.manage',
            'certificate', 'notification' => 'certificates.review',
            'fund' => 'finance.review', 'invoice' => match ($input['action']) {
                'invoice.review', 'invoice.reopen' => 'finance.review', 'invoice.record' => 'finance.record',
                default => 'finance.submit',
            },
            'report' => 'reports.review', 'training' => 'training.manage',
        };
        $crewMember = str_starts_with($input['action'], 'crew.') && $input['action'] !== 'crew.save' && $directory->may($actor, 'work.execute');
        abort_unless($directory->may($actor, $permission) || $crewMember, 403, 'Switch to a demo role responsible for this workflow.');
        $digest = hash('sha256', json_encode([$input, $actor['id']], JSON_THROW_ON_ERROR));
        if (isset($ops['receipts'][$input['id']])) {
            $receipt = $ops['receipts'][$input['id']];
            abort_unless(hash_equals($receipt['digest'], $digest), 409, 'This saved action belongs to a different review.');

            return [...$receipt['result'], 'replayed' => true];
        }
        abort_if(count($ops['receipts']) >= 1000 || count($ops['history']) >= 2000, 422, 'This demo history is full. Ask the demo administrator to start a new sample walkthrough.');
        $id = $input['recordId'];
        $revision = $input['expectedRevision'];
        $data = $input['data'];
        $action = $input['action'];
        $result = match (explode('.', $action)[0]) {
            'settings' => $this->settings($state, $actor, $revision, $data),
            'crew' => app(DemoWorkPlanner::class)->handle($state, $actor, $action, $id, $revision, $data),
            'booking' => app(DemoSchedule::class)->handle($state, $actor, $action, $id, $revision, $data),
            'contractor' => app(DemoContractors::class)->handle($state, $actor, $action, $id, $revision, $data),
            'vendor', 'certificate' => app(DemoVendors::class)->handle($state, $actor, $action, $id, $revision, $data),
            'project', 'fund', 'invoice' => app(DemoFinance::class)->handle($state, $actor, $action, $id, $revision, $data),
            'report' => app(DemoReports::class)->review($state, $actor, $id, $revision, $data),
            'training' => $this->training($state, $actor, $id, $revision),
            'notification' => $this->notification($state, $actor, $id, $revision),
        };
        $ops['revision']++;
        $ops['history'][] = ['id' => $input['id'], 'at' => now()->toIso8601String(), 'actor' => $actor['name'], 'action' => $action, 'recordId' => $id, 'message' => $result['message']];
        $result = [...$result, 'receiptId' => $input['id'], 'replayed' => false];
        $ops['receipts'][$input['id']] = ['digest' => $digest, 'result' => $result];

        return $result;
    }

    private function settings(array &$state, array $actor, int $revision, array $data): array
    {
        abort_unless($revision === $state['operations']['settings']['revision'], 409, 'The settings changed on another device. Your draft is retained; refresh and review the newer settings.');
        $settings = app(DemoWorkspaceSettings::class)->validate($data, $state);
        $settings['revision'] = $revision + 1;
        $state['operations']['settings'] = $settings;

        return ['message' => 'Demo settings saved. New submissions use the updated rules.'];
    }

    private function training(array &$state, array $actor, string $id, int $revision): array
    {
        $session = collect($state['operations']['settings']['training'])->firstWhere('id', $id);
        abort_unless($session, 404, 'Training example not found.');
        app(DemoRecords::class)->requireReviewer($actor, $session['ownerRole']);
        abort_unless($revision === $state['operations']['settings']['revision'], 409, 'Refresh the current training plan.');
        $state['operations']['trainingProgress'][$id] = ['at' => now()->toIso8601String(), 'actor' => $actor['name'], 'title' => $session['title']];

        return ['message' => 'Example training session marked complete.'];
    }

    private function notification(array &$state, array $actor, string $id, int $revision): array
    {
        $records = app(DemoRecords::class);
        $row = $records->existing($state, 'notifications', $id, $revision);
        $records->requireReviewer($actor, $row['audienceRole']);
        $records->save($state, 'notifications', $id, $revision, ['readAt' => now()->toIso8601String(), 'readBy' => $actor['name']]);

        return ['message' => 'Demo reminder acknowledged.'];
    }
}
