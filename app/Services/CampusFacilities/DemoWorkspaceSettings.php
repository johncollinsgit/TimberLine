<?php

namespace App\Services\CampusFacilities;

use DateTimeZone;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DemoWorkspaceSettings
{
    public const REPORT_COLUMNS = ['work_orders', 'completed', 'labor_minutes', 'materials', 'teams', 'finance', 'certificates'];

    public function validate(array $input, array $state): array
    {
        $slug = 'regex:/^[a-z][a-z0-9-]{0,39}$/D';
        $settings = Validator::make(['settings' => $input], [
            'settings' => 'required|array:revision,roles,people,teams,workApproval,financeApproval,timezone,bufferMinutes,conflictRole,fiscalStartMonth,reportColumns,reportReviewerRole,certificateWarningDays,certificateReviewerRole,certificateTypes,requireValidCertificate,training,pilotGoals,feeModel,sources,shortcuts',
            'settings.revision' => 'required|integer|min:1',
            'settings.roles' => 'required|array|min:4|max:20',
            'settings.roles.*' => 'required|array:id,name,baseRole,permissions,enabled',
            'settings.roles.*.id' => ['required', 'string', $slug, 'distinct'],
            'settings.roles.*.name' => 'required|string|min:2|max:90',
            'settings.roles.*.baseRole' => ['required', Rule::in(['staff', 'technician', 'head', 'admin'])],
            'settings.roles.*.enabled' => 'required|boolean',
            'settings.roles.*.permissions' => 'present|array|max:25',
            'settings.roles.*.permissions.*' => ['string', Rule::in(array_keys(DemoDirectory::PERMISSIONS))],
            'settings.people' => 'required|array|min:9|max:50',
            'settings.people.*' => 'required|array:id,name,initials,profile,department,team,enabled',
            'settings.people.*.id' => ['required', 'string', $slug, 'distinct'],
            'settings.people.*.name' => 'required|string|min:2|max:90',
            'settings.people.*.initials' => 'required|string|max:6',
            'settings.people.*.profile' => ['required', 'string', $slug],
            'settings.people.*.department' => 'required|string|min:2|max:90',
            'settings.people.*.team' => 'required|string|min:2|max:40',
            'settings.people.*.enabled' => 'required|boolean',
            'settings.teams' => 'required|array|min:3|max:12',
            'settings.teams.*' => 'required|array:id,name',
            'settings.teams.*.id' => 'required|string|min:2|max:40|distinct|regex:/^[A-Za-z][A-Za-z0-9 -]*$/D',
            'settings.teams.*.name' => 'required|string|min:2|max:70',
            'settings.workApproval' => 'required|array|min:1|max:6',
            'settings.workApproval.*' => 'required|array:id,name,role',
            'settings.workApproval.*.id' => ['required', 'string', $slug, 'distinct'],
            'settings.workApproval.*.name' => 'required|string|min:2|max:90',
            'settings.workApproval.*.role' => ['required', 'string', $slug],
            'settings.financeApproval' => 'required|array|min:1|max:6',
            'settings.financeApproval.*' => 'required|array:id,name,role,minimumCents',
            'settings.financeApproval.*.id' => ['required', 'string', $slug, 'distinct'],
            'settings.financeApproval.*.name' => 'required|string|min:2|max:90',
            'settings.financeApproval.*.role' => ['required', 'string', $slug],
            'settings.financeApproval.*.minimumCents' => 'required|integer|min:0|max:100000000',
            'settings.timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'settings.bufferMinutes' => 'required|integer|min:0|max:120',
            'settings.conflictRole' => ['required', 'string', $slug],
            'settings.fiscalStartMonth' => 'required|integer|min:1|max:12',
            'settings.reportColumns' => 'required|array|min:1|max:7',
            'settings.reportColumns.*' => ['string', 'distinct', Rule::in(self::REPORT_COLUMNS)],
            'settings.reportReviewerRole' => ['required', 'string', $slug],
            'settings.certificateWarningDays' => 'required|integer|min:1|max:180',
            'settings.certificateReviewerRole' => ['required', 'string', $slug],
            'settings.certificateTypes' => 'required|array|min:1|max:8',
            'settings.certificateTypes.*' => 'required|string|min:2|max:90|distinct',
            'settings.requireValidCertificate' => 'required|boolean',
            'settings.training' => 'present|array|max:12',
            'settings.training.*' => 'required|array:id,title,ownerRole,minutes',
            'settings.training.*.id' => ['required', 'string', $slug, 'distinct'],
            'settings.training.*.title' => 'required|string|min:5|max:200',
            'settings.training.*.ownerRole' => ['required', 'string', $slug],
            'settings.training.*.minutes' => 'required|integer|min:5|max:480',
            'settings.pilotGoals' => 'present|array|max:12',
            'settings.pilotGoals.*' => 'required|string|min:5|max:250',
            'settings.feeModel' => 'required|string|min:10|max:1200',
            'settings.sources' => 'present|array|max:8',
            'settings.sources.*' => 'required|array:id,name,reference',
            'settings.sources.*.id' => ['required', 'string', $slug, 'distinct'],
            'settings.sources.*.name' => 'required|string|min:2|max:100',
            'settings.sources.*.reference' => 'required|string|min:2|max:100',
            'settings.shortcuts' => 'present|array|max:20',
        ])->validate()['settings'];
        $roles = array_column($settings['roles'], null, 'id');
        $people = array_column($settings['people'], null, 'id');
        $teams = array_column($settings['teams'], null, 'id');
        foreach ($roles as $role) {
            foreach (DemoDirectory::DEPENDENCIES as $action => $view) {
                abort_if(in_array($action, $role['permissions'], true) && ! in_array($view, $role['permissions'], true), 422, 'Grant the matching view permission before enabling workflow actions.');
            }
        }
        foreach ($settings['shortcuts'] as $profile => $shortcuts) {
            abort_unless(isset($roles[$profile]) && is_array($shortcuts) && count($shortcuts) <= 6 && count($shortcuts) === count(array_unique($shortcuts, SORT_REGULAR)), 422, 'Choose up to six different shortcuts for each listed role.');
            foreach ($shortcuts as $shortcut) {
                abort_unless(is_string($shortcut) && isset(DemoDirectory::SHORTCUTS[$shortcut]) && in_array(DemoDirectory::SHORTCUTS[$shortcut]['permission'], $roles[$profile]['permissions'], true), 422, 'Choose shortcuts permitted for the selected role.');
            }
        }
        foreach (['staff', 'technician', 'head', 'admin'] as $base) {
            abort_unless(isset($roles[$base]) && $roles[$base]['baseRole'] === $base, 422, 'Keep the four original perspective IDs and their role types. Their names and permissions can be customized.');
        }
        abort_unless($roles['admin']['enabled'] && in_array('settings.manage', $roles['admin']['permissions'], true) && ($people['admin']['enabled'] ?? false) && $people['admin']['profile'] === 'admin', 422, 'Keep an enabled administrator so the demo settings remain reachable.');
        foreach (Tickets::TEAMS as $team) {
            abort_unless(isset($teams[$team]), 422, 'Keep existing service-team IDs; edit their display names instead.');
        }
        foreach ($settings['people'] as $person) {
            abort_unless(isset($roles[$person['profile']], $teams[$person['team']]), 422, 'Each person needs a listed role and service team.');
            if ($person['enabled']) {
                abort_unless($roles[$person['profile']]['enabled'], 422, 'Enabled people need an enabled role.');
            }
        }
        $requireRole = function (string $id, string $permission) use ($settings, $roles): void {
            abort_unless(isset($roles[$id]) && $roles[$id]['enabled'] && in_array($permission, $roles[$id]['permissions'], true), 422, 'Choose an enabled role with the permission needed by each workflow step.');
            abort_unless(collect($settings['people'])->contains(fn ($p) => $p['profile'] === $id && $p['enabled']), 422, 'Every workflow role needs at least one enabled demo person.');
        };
        foreach ($settings['workApproval'] as $step) {
            $requireRole($step['role'], 'work.approve');
        }
        foreach ($settings['financeApproval'] as $step) {
            $requireRole($step['role'], 'finance.review');
        }
        abort_unless(min(array_column($settings['financeApproval'], 'minimumCents')) === 0, 422, 'Include a finance review step that applies to every amount.');
        $requireRole($settings['conflictRole'], 'schedule.manage');
        $requireRole($settings['reportReviewerRole'], 'reports.review');
        if (in_array('finance', $settings['reportColumns'], true)) {
            $requireRole($settings['reportReviewerRole'], 'finance.view');
        }
        if (in_array('certificates', $settings['reportColumns'], true)) {
            $requireRole($settings['reportReviewerRole'], 'vendors.view');
        }
        $requireRole($settings['certificateReviewerRole'], 'certificates.review');
        foreach ($settings['training'] as $session) {
            $requireRole($session['ownerRole'], 'training.manage');
        }
        // Retire referenced people/roles only after their open work has been reassigned.
        foreach ($state['tickets'] as $ticket) {
            abort_unless(isset($people[$ticket['fields']['requesterId']]), 422, 'Keep people referenced by existing requests. Disable them when appropriate.');
            if (! in_array($ticket['status'], ['Completed', 'Rejected'], true) && $ticket['assignee']) {
                abort_unless(($people[$ticket['assignee']]['enabled'] ?? false) && ($roles[$people[$ticket['assignee']]['profile']]['enabled'] ?? false), 422, 'Reassign open work before disabling its technician.');
                $assigned = $people[$ticket['assignee']];
                $assignedRole = $roles[$assigned['profile']];
                abort_unless($assignedRole['baseRole'] === 'technician' && $assigned['team'] === $ticket['fields']['category'] && in_array('work.execute', $assignedRole['permissions'], true), 422, 'Reassign open work before changing its technician’s team, work view or work permission.');
            }
            foreach ($ticket['approvalRoute'] ?? [] as $step) {
                if ($ticket['status'] === 'Pending approval') {
                    $requireRole($step['role'], 'work.approve');
                }
            }
        }
        foreach ($state['operations']['invoices'] as $invoice) {
            if ($invoice['status'] === 'In review') {
                foreach ($invoice['route'] as $step) {
                    $requireRole($step['role'], 'finance.review');
                }
            }
        }

        return $settings;
    }
}
