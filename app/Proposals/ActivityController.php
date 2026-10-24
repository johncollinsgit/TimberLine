<?php

namespace App\Proposals;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivityController
{
    public function capture(Request $request, string $token, Access $access, Activity $activity)
    {
        $version = $access->recipient($request, $token);
        abort_unless(config('service_proposals.writes_enabled') === true && $activity->enabled($version) && $access->unlocked($request, $version), 403);
        abort_if(array_diff(array_keys($request->except('_token')), ['event', 'page', 'scene', 'event_id', 'version_hash']) !== [], 422);
        $data = $request->validate(['event' => ['required', Rule::in(['proposal_section', 'campus_page', 'tour_scene', 'tour_completed'])],
            'page' => ['nullable', 'string', 'max:32'], 'scene' => ['nullable', 'integer', 'min:1', 'max:7'],
            'event_id' => ['required', 'uuid'], 'version_hash' => ['required', 'string', 'size:64']]);
        abort_unless(hash_equals($version->content_hash, $data['version_hash']), 409);
        $page = $data['page'] ?? null;
        $scene = $data['scene'] ?? null;
        if ($data['event'] === 'proposal_section') {
            abort_unless(in_array($page, Activity::SECTIONS, true) && $scene === null, 422);
        } else {
            app(CampusReviewManifest::class)->resolveReviewEdition($version, $data['event'] === 'campus_page' ? (string) $page : 'home');
            abort_unless($data['event'] === 'campus_page' ? $scene === null : ($page === null && $scene !== null), 422);
            abort_if($data['event'] === 'tour_completed' && $scene !== 7, 422);
        }
        $recorded = $activity->record($request, $version, $data['event'], $page, $scene, $data['event_id']);

        return response()->json(['recorded' => $recorded], $recorded ? 200 : 503);
    }

    public function owner(Request $request, string $workspace, string $versionId, Access $access, Activity $activity)
    {
        $tenant = $access->owner($request);
        $version = Version::where('tenant_id', $tenant->id)->findOrFail($versionId);
        abort_unless($activity->enabled($version), 404);
        $journal = $activity->read($version);
        $allVisits = collect($journal['events'])->groupBy('visit')->map(function ($events) {
            $names = $events->pluck('name')->filter()->unique()->values();

            return ['name' => $names->isEmpty() ? 'Name not selected' : $names->join(' → '),
                'first' => $events->first()['at'], 'last' => $events->last()['at'],
                'unlocked' => $events->contains('event', 'unlocked'),
                'owner_test' => $events->contains('owner_test', true),
                'pages' => $events->whereIn('event', ['proposal_view', 'proposal_section', 'campus_page'])->pluck('page')->filter()->unique()->values(),
                'scene' => $events->max('scene'), 'completed' => $events->contains('event', 'tour_completed'),
                'events' => $events->reverse()->values()];
        })->sortByDesc('last');
        $includeTests = $request->boolean('include_tests');
        $testCount = $allVisits->where('owner_test', true)->count();
        $visits = $includeTests ? $allVisits : $allVisits->where('owner_test', false);

        return response()->view('service-proposals.activity', compact('tenant', 'version', 'journal', 'visits', 'testCount', 'includeTests'));
    }
}
