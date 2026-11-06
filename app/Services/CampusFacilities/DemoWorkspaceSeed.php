<?php

namespace App\Services\CampusFacilities;

use Illuminate\Support\Str;

/** Coherent examples only. Adds samples once without replacing earlier records. */
class DemoWorkspaceSeed
{
    public static function settings(): array
    {
        $all = array_keys(DemoDirectory::PERMISSIONS);
        $role = fn ($id, $name, $base, $permissions) => ['id' => $id, 'name' => $name, 'baseRole' => $base, 'permissions' => $permissions, 'enabled' => true];
        $people = json_decode(file_get_contents(resource_path('data/campus-facilities/people.json')), true, 32, JSON_THROW_ON_ERROR);
        foreach ($people as &$person) {
            $person['profile'] = $person['role'];
            $person['enabled'] = true;
            unset($person['role']);
        }
        unset($person);
        $person = fn ($id, $name, $profile, $department, $team) => ['id' => $id, 'name' => $name, 'initials' => collect(explode(' ', $name))->map(fn ($s) => mb_substr($s, 0, 1))->implode(''), 'profile' => $profile, 'department' => $department, 'team' => $team, 'enabled' => true];

        return [
            'revision' => 1,
            'roles' => [
                $role('staff', 'Faculty & staff', 'staff', ['work.view', 'work.create', 'work.comment', 'schedule.view']),
                $role('technician', 'Technician', 'technician', ['work.view', 'work.create', 'work.comment', 'work.execute', 'schedule.view']),
                $role('head', 'Facilities head', 'head', ['work.view', 'work.create', 'work.comment', 'work.approve', 'work.assign', 'crew.manage', 'schedule.view', 'schedule.manage', 'vendors.view', 'finance.view', 'finance.submit', 'finance.review', 'reports.view', 'training.manage']),
                $role('admin', 'Administrator', 'admin', $all),
                $role('scheduler', 'Campus scheduling coordinator', 'admin', ['work.view', 'work.comment', 'crew.manage', 'schedule.view', 'schedule.manage', 'reports.view', 'training.manage']),
                $role('finance', 'Finance reviewer', 'admin', ['work.view', 'vendors.view', 'finance.view', 'finance.submit', 'finance.review', 'finance.record', 'reports.view', 'training.manage']),
                $role('director', 'Operations director', 'admin', ['work.view', 'schedule.view', 'vendors.view', 'finance.view', 'finance.review', 'reports.view', 'reports.review']),
                $role('vendor', 'Vendor coordinator', 'admin', ['work.view', 'vendors.view', 'vendors.manage', 'certificates.review', 'finance.view', 'reports.view']),
                $role('it', 'IT service lead', 'head', ['work.view', 'work.create', 'work.comment', 'work.approve', 'work.assign', 'crew.manage', 'schedule.view', 'schedule.manage', 'vendors.view', 'finance.view', 'finance.submit', 'reports.view']),
                $role('reporting', 'Reporting coordinator', 'admin', ['work.view', 'reports.view', 'reports.review', 'schedule.view', 'finance.view', 'vendors.view']),
            ],
            'people' => [...$people,
                $person('devon', 'Devon Lee', 'technician', 'Facilities', 'Maintenance'),
                $person('ellis', 'Ellis Park', 'scheduler', 'Campus Operations', 'Events'),
                $person('jamie', 'Jamie Finley', 'finance', 'Finance', 'Maintenance'),
                $person('quinn', 'Quinn Avery', 'director', 'Campus Operations', 'Maintenance'),
                $person('mika', 'Mika Lane', 'vendor', 'Procurement', 'Maintenance'),
                $person('drew', 'Drew Carter', 'it', 'Information Technology', 'IT'),
                $person('rory', 'Rory Brooks', 'technician', 'Information Technology', 'IT'),
                $person('sage', 'Sage Bennett', 'reporting', 'Campus Operations', 'Maintenance'),
            ],
            'teams' => array_map(fn ($name) => ['id' => $name, 'name' => $name], ['Maintenance', 'Grounds', 'Housekeeping', 'IT', 'Events']),
            'workApproval' => [['id' => 'facilities', 'name' => 'Service-head review', 'role' => 'head']],
            'financeApproval' => [['id' => 'scope', 'name' => 'Facilities scope review', 'role' => 'head', 'minimumCents' => 0], ['id' => 'funds', 'name' => 'Finance and fund review', 'role' => 'finance', 'minimumCents' => 0], ['id' => 'director', 'name' => 'Operations director review', 'role' => 'director', 'minimumCents' => 250000]],
            'timezone' => 'America/New_York', 'bufferMinutes' => 15, 'conflictRole' => 'scheduler',
            'fiscalStartMonth' => 7, 'reportColumns' => ['work_orders', 'completed', 'labor_minutes', 'materials', 'teams', 'finance', 'certificates'], 'reportReviewerRole' => 'reporting',
            'certificateWarningDays' => 30, 'certificateReviewerRole' => 'vendor', 'certificateTypes' => ['General liability', 'Workers compensation'], 'requireValidCertificate' => true, 'requireContractorInsurance' => true,
            'training' => [
                ['id' => 'technicians', 'title' => 'Technician quick start: daily work, photos and interruptions', 'ownerRole' => 'head', 'minutes' => 30],
                ['id' => 'coordinators', 'title' => 'Coordinator walkthrough: conflicts, crews and handoffs', 'ownerRole' => 'scheduler', 'minutes' => 45],
                ['id' => 'finance', 'title' => 'Finance walkthrough: invoice reviews and fund restrictions', 'ownerRole' => 'finance', 'minutes' => 30],
            ],
            'pilotGoals' => ['Every active job has an owner and next step', 'Review new requests within one working day', 'Resolve scheduling conflicts before work starts', 'Prepare a reviewed quarterly report from recorded work'],
            'feeModel' => 'Example scope: a predictable departmental flat fee, with included users, support and usage agreed before a real pilot.',
            'sources' => [['id' => 'maintenance', 'name' => 'MaintenanceCare example', 'reference' => 'MC-DEMO'], ['id' => 'it', 'name' => 'IT help-desk example', 'reference' => 'IT-DEMO'], ['id' => 'spreadsheet', 'name' => 'Building/project/vendor spreadsheet example', 'reference' => 'SHEET-DEMO']],
            'shortcuts' => [
                'staff' => ['new_request', 'buildings', 'schedule', 'workload'],
                'technician' => ['daily_work', 'new_request', 'buildings', 'workload'],
                'head' => ['daily_work', 'schedule', 'finance', 'reports'],
                'admin' => ['daily_work', 'schedule', 'finance', 'reports'],
                'scheduler' => ['schedule', 'daily_work', 'workload', 'reports'],
                'finance' => ['finance', 'reports', 'vendors', 'workload'],
                'director' => ['finance', 'reports', 'schedule', 'workload'],
                'vendor' => ['vendors', 'reports', 'workload'],
                'it' => ['daily_work', 'schedule', 'finance', 'workload'],
                'reporting' => ['reports', 'workload', 'schedule'],
            ],
        ];
    }

    public static function upgrade(array &$state): bool
    {
        if (isset($state['operations'])) {
            return self::upgradeContractors($state);
        }
        $today = now('America/New_York')->startOfDay();
        $at = fn ($days, $time = '09:00') => $today->copy()->addDays($days)->setTimeFromTimeString($time)->toIso8601String();
        $day = fn ($days) => $today->copy()->addDays($days)->toDateString();
        $ticket = function ($id, $title, $team, $assignee, $room, $priority = 'Normal') use ($at): array {
            return ['id' => $id, 'title' => $title, 'status' => 'Assigned', 'priority' => $priority, 'assignee' => $assignee, 'revision' => 1, 'createdAt' => $at(-3), 'updatedAt' => $at(-1), 'photos' => [], 'workLogs' => [], 'events' => [['id' => 'seed-'.$id, 'at' => $at(-3), 'actor' => 'Demo coordinator', 'action' => 'Request approved and assigned', 'message' => 'Fictional campus-operations walkthrough. Keep linked schedules and approvals together.', 'visibility' => 'requester']], 'fields' => ['requester' => 'Jordan Ellis', 'requesterId' => 'jordan', 'department' => 'Student Affairs', 'contact' => 'jordan@example.invalid', 'location' => 'Bryan Gym', 'room' => $room, 'issue' => $title, 'onset' => 'Sample event preparation', 'impact' => 'Example campus event', 'hazards' => 'Assess the area before beginning work', 'access' => 'Confirm the event window with the scheduling coordinator', 'category' => $team]];
        };
        foreach ([
            $ticket('FM-DEMO-GYM', 'Check gym audio before the sample athletics event', 'Maintenance', 'devon', 'Event audio position'),
            $ticket('FM-DEMO-IT', 'Coordinate the event network and scoreboard connection', 'IT', 'rory', 'Scoreboard control desk'),
            $ticket('FM-DEMO-ACCESS', 'Check the accessible event-entry route', 'Maintenance', 'devon', 'Accessible entrance', 'High'),
        ] as $sample) {
            if (! isset($state['tickets'][$sample['id']]) && count($state['tickets']) < 200) {
                $state['tickets'][$sample['id']] = $sample;
                $state['request_keys']['seed-'.$sample['id']] = ['id' => $sample['id'], 'digest' => null];
            }
        }
        $state['operations'] = [
            'schema' => 1, 'generation' => (string) Str::uuid(), 'revision' => 1, 'settings' => self::settings(),
            'projects' => [
                ['id' => 'project-comfort', 'name' => 'Bowman teaching-space comfort', 'category' => 'Building maintenance', 'serviceTeam' => 'Maintenance', 'owner' => 'morgan', 'ticketIds' => ['FM-1045', 'FM-1048'], 'source' => 'MC-DEMO-204', 'nextAction' => 'Finish the leak response, then resume the HVAC check', 'revision' => 1],
                ['id' => 'project-event', 'name' => 'Bryan Gym event readiness', 'category' => 'Event support', 'serviceTeam' => 'Maintenance', 'owner' => 'ellis', 'ticketIds' => ['FM-DEMO-GYM', 'FM-DEMO-IT'], 'source' => 'IT-DEMO-117', 'nextAction' => 'Agree a maintenance window outside athletics practice', 'revision' => 1],
                ['id' => 'project-access', 'name' => 'Accessible event arrival', 'category' => 'Accessibility', 'serviceTeam' => 'Maintenance', 'owner' => 'morgan', 'ticketIds' => ['FM-DEMO-ACCESS'], 'source' => 'SHEET-DEMO-12', 'nextAction' => 'Review the entrance repair invoice against its dedicated fund', 'revision' => 1],
            ],
            'vendors' => [
                ['id' => 'vendor-mechanical', 'name' => 'Blue Ridge Mechanical — fictional', 'trade' => 'HVAC and plumbing', 'rating' => 4, 'notes' => 'Sample preferred vendor for the Bowman comfort project.', 'contact' => 'service@blue-ridge.example.invalid', 'revision' => 1],
                ['id' => 'vendor-av', 'name' => 'High Country Event Systems — fictional', 'trade' => 'Event audio and network support', 'rating' => 5, 'notes' => 'Example cross-department event partner. Renewal needs review.', 'contact' => 'events@high-country.example.invalid', 'revision' => 1],
                ['id' => 'vendor-access', 'name' => 'Summit Access Works — fictional', 'trade' => 'Accessible routes and entry hardware', 'rating' => 4, 'notes' => 'Sample vendor for the dedicated accessibility project.', 'contact' => 'office@summit-access.example.invalid', 'revision' => 1],
            ],
            'certificates' => [
                ['id' => 'cert-mechanical', 'vendorId' => 'vendor-mechanical', 'type' => 'General liability', 'expiresOn' => $day(14), 'reference' => 'Example GL certificate BRM-2026', 'pending' => null, 'reviewedBy' => 'mika', 'revision' => 1],
                ['id' => 'cert-av', 'vendorId' => 'vendor-av', 'type' => 'General liability', 'expiresOn' => $day(-3), 'reference' => 'Example expired event-vendor certificate', 'pending' => null, 'reviewedBy' => 'mika', 'revision' => 1],
                ['id' => 'cert-access', 'vendorId' => 'vendor-access', 'type' => 'General liability', 'expiresOn' => $day(120), 'reference' => 'Example accessibility-contractor certificate', 'pending' => null, 'reviewedBy' => 'mika', 'revision' => 1],
                ['id' => 'cert-mechanical-wc', 'vendorId' => 'vendor-mechanical', 'type' => 'Workers compensation', 'expiresOn' => $day(180), 'reference' => 'Example mechanical contractor WC certificate', 'pending' => null, 'reviewedBy' => 'mika', 'revision' => 1],
                ['id' => 'cert-av-wc', 'vendorId' => 'vendor-av', 'type' => 'Workers compensation', 'expiresOn' => $day(180), 'reference' => 'Example event contractor WC certificate', 'pending' => null, 'reviewedBy' => 'mika', 'revision' => 1],
                ['id' => 'cert-access-wc', 'vendorId' => 'vendor-access', 'type' => 'Workers compensation', 'expiresOn' => $day(180), 'reference' => 'Example access contractor WC certificate', 'pending' => null, 'reviewedBy' => 'mika', 'revision' => 1],
            ],
            'funds' => [
                ['id' => 'fund-operations', 'code' => 'DEMO-OPS-100', 'name' => 'Facilities operating allocation', 'budgetCents' => 2500000, 'categories' => ['Building maintenance', 'Event support', 'Accessibility'], 'restriction' => 'Fictional operating allocation for this walkthrough.', 'revision' => 1],
                ['id' => 'fund-access', 'code' => 'DEMO-GIFT-210', 'name' => 'Accessibility donor-purpose example', 'budgetCents' => 1000000, 'categories' => ['Accessibility'], 'restriction' => 'Example purpose restriction: accessibility projects only.', 'revision' => 1],
            ],
            'bookings' => [
                ['id' => 'booking-athletics', 'title' => 'Sample athletics practice', 'building' => 'Bryan Gym', 'startsAt' => $at(1, '14:00'), 'endsAt' => $at(1, '16:00'), 'kind' => 'Athletics', 'owner' => 'ellis', 'ticketId' => null, 'status' => 'Confirmed', 'resolution' => '', 'revision' => 1],
                ['id' => 'booking-work', 'title' => 'Gym audio maintenance window', 'building' => 'Bryan Gym', 'startsAt' => $at(1, '13:30'), 'endsAt' => $at(1, '15:00'), 'kind' => 'Maintenance', 'owner' => 'morgan', 'ticketId' => 'FM-DEMO-GYM', 'status' => 'Proposed', 'resolution' => '', 'revision' => 1],
                ['id' => 'booking-class', 'title' => 'Sample classroom teaching block', 'building' => 'Bowman Building', 'startsAt' => $at(0, '09:00'), 'endsAt' => $at(0, '12:00'), 'kind' => 'Class', 'owner' => 'ellis', 'ticketId' => null, 'status' => 'Confirmed', 'resolution' => '', 'revision' => 1],
            ],
            'invoices' => [
                ['id' => 'invoice-hvac', 'number' => 'DEMO-INV-204', 'projectId' => 'project-comfort', 'vendorId' => 'vendor-mechanical', 'fundId' => 'fund-operations', 'amountCents' => 240000, 'description' => 'Example HVAC diagnosis, replacement part and leak-call support', 'reference' => 'Example invoice; no payment is requested', 'status' => 'Draft', 'route' => [], 'step' => 0, 'decisions' => [], 'createdAt' => $at(-2), 'updatedAt' => $at(-2), 'revision' => 1],
                ['id' => 'invoice-access', 'number' => 'DEMO-INV-211', 'projectId' => 'project-access', 'vendorId' => 'vendor-access', 'fundId' => 'fund-access', 'amountCents' => 315000, 'description' => 'Example accessible-entry hardware and route repair', 'reference' => 'Example purpose-matched invoice', 'status' => 'Draft', 'route' => [], 'step' => 0, 'decisions' => [], 'createdAt' => $at(-1), 'updatedAt' => $at(-1), 'revision' => 1],
            ],
            'crewPlans' => [
                ['id' => 'plan-hvac', 'ticketId' => 'FM-1045', 'date' => $day(0), 'lead' => 'sam', 'helpers' => ['devon'], 'nextAction' => 'Finish the HVAC check after the leak response', 'checkpoint' => '', 'status' => 'Planned', 'emergencyTicketId' => null, 'revision' => 1],
                ['id' => 'plan-leak', 'ticketId' => 'FM-1048', 'date' => $day(0), 'lead' => 'sam', 'helpers' => ['devon'], 'nextAction' => 'Assess and isolate the sink leak', 'checkpoint' => '', 'status' => 'Planned', 'emergencyTicketId' => null, 'revision' => 1],
            ],
            'notifications' => [], 'reports' => [], 'trainingProgress' => [], 'history' => [], 'receipts' => [],
        ];

        self::upgradeContractors($state, true);

        return true;
    }

    private static function upgradeContractors(array &$state, bool $fresh = false): bool
    {
        if (($state['operations']['schema'] ?? 1) >= 2) {
            return false;
        }
        $samples = [
            'vendor-mechanical' => ['contactName' => 'Pat Mercer', 'phone' => '828-555-0101', 'coordinatorId' => 'morgan', 'projectIds' => ['project-comfort']],
            'vendor-av' => ['contactName' => 'Chris Rowan', 'phone' => '828-555-0102', 'coordinatorId' => 'ellis', 'projectIds' => ['project-event']],
            'vendor-access' => ['contactName' => 'Dana Wells', 'phone' => '828-555-0103', 'coordinatorId' => 'morgan', 'projectIds' => ['project-access']],
        ];
        $projects = array_column($state['operations']['projects'], 'id');
        $people = array_column($state['operations']['settings']['people'], 'id');
        foreach ($state['operations']['vendors'] as &$vendor) {
            $extra = $samples[$vendor['id']] ?? ['contactName' => '', 'phone' => '', 'coordinatorId' => null, 'projectIds' => []];
            $extra['projectIds'] = array_values(array_intersect($extra['projectIds'], $projects));
            if (! in_array($extra['coordinatorId'], $people, true)) {
                $extra['coordinatorId'] = null;
            }
            $vendor += [...$extra, 'status' => 'Active'];
            if (! $fresh) {
                $vendor['revision']++;
            }
        }
        unset($vendor);
        $state['operations']['settings']['requireContractorInsurance'] ??= true;
        if (! $fresh) {
            $state['operations']['settings']['revision']++;
            $state['operations']['revision']++;
        }
        $state['operations']['schema'] = 2;

        return true;
    }
}
