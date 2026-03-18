<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingGroup;
use App\Models\MarketingGroupImportRun;
use App\Models\MarketingGroupMember;
use App\Models\MarketingProfile;
use App\Services\Marketing\MarketingGroupDirectSendService;
use App\Services\Marketing\MarketingGroupImportService;
use App\Services\Marketing\TwilioSenderConfigService;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MarketingGroupsController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $internal = trim((string) $request->query('internal', ''));

        $groups = MarketingGroup::query()
            ->withCount('members')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', '%' . $search . '%')
                        ->orWhere('description', 'like', '%' . $search . '%');
                });
            })
            ->when($internal !== '', function ($query) use ($internal): void {
                if ($internal === 'yes') {
                    $query->where('is_internal', true);
                } elseif ($internal === 'no') {
                    $query->where('is_internal', false);
                }
            })
            ->orderByDesc('updated_at')
            ->paginate(30)
            ->withQueryString();

        return view('marketing/groups/index', [
            'section' => MarketingSectionRegistry::section('groups'),
            'sections' => $this->navigationItems(),
            'groups' => $groups,
            'search' => $search,
            'internal' => $internal,
        ]);
    }

    public function create(Request $request): View
    {
        return view('marketing/groups/form', [
            'section' => MarketingSectionRegistry::section('groups'),
            'sections' => $this->navigationItems(),
            'group' => new MarketingGroup(['is_internal' => false]),
            'mode' => 'create',
            'initialProfileId' => max(0, (int) $request->query('marketing_profile_id', 0)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_internal' => ['nullable', 'boolean'],
            'initial_profile_id' => ['nullable', 'integer', 'exists:marketing_profiles,id'],
        ]);

        $group = MarketingGroup::query()->create([
            'name' => trim((string) $data['name']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'is_internal' => (bool) ($data['is_internal'] ?? false),
            'created_by' => auth()->id(),
        ]);

        $profileId = (int) ($data['initial_profile_id'] ?? 0);
        if ($profileId > 0) {
            MarketingGroupMember::query()->updateOrCreate(
                [
                    'marketing_group_id' => $group->id,
                    'marketing_profile_id' => $profileId,
                ],
                [
                    'added_by' => auth()->id(),
                ]
            );
        }

        return redirect()
            ->route('marketing.groups.show', $group)
            ->with('toast', ['style' => 'success', 'message' => 'Group created.']);
    }

    public function show(MarketingGroup $group, Request $request): View
    {
        $memberSearch = trim((string) $request->query('member_search', ''));
        $memberIds = MarketingGroupMember::query()
            ->where('marketing_group_id', $group->id)
            ->pluck('marketing_profile_id');

        $members = MarketingProfile::query()
            ->whereIn('id', $memberIds)
            ->when($memberSearch !== '', function ($query) use ($memberSearch): void {
                $query->where(function ($nested) use ($memberSearch): void {
                    $nested->where('first_name', 'like', '%' . $memberSearch . '%')
                        ->orWhere('last_name', 'like', '%' . $memberSearch . '%')
                        ->orWhere('email', 'like', '%' . $memberSearch . '%')
                        ->orWhere('phone', 'like', '%' . $memberSearch . '%');
                });
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->paginate(40, ['*'], 'members_page')
            ->withQueryString();

        $candidateSearch = trim((string) $request->query('candidate_search', ''));
        $candidates = MarketingProfile::query()
            ->whereNotIn('id', $memberIds)
            ->when($candidateSearch !== '', function ($query) use ($candidateSearch): void {
                $query->where(function ($nested) use ($candidateSearch): void {
                    $nested->where('first_name', 'like', '%' . $candidateSearch . '%')
                        ->orWhere('last_name', 'like', '%' . $candidateSearch . '%')
                        ->orWhere('email', 'like', '%' . $candidateSearch . '%')
                        ->orWhere('phone', 'like', '%' . $candidateSearch . '%');
                });
            })
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone']);

        $recentRuns = MarketingGroupImportRun::query()
            ->where('marketing_group_id', $group->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('marketing/groups/show', [
            'section' => MarketingSectionRegistry::section('groups'),
            'sections' => $this->navigationItems(),
            'group' => $group,
            'members' => $members,
            'candidates' => $candidates,
            'recentRuns' => $recentRuns,
            'memberSearch' => $memberSearch,
            'candidateSearch' => $candidateSearch,
        ]);
    }

    public function edit(MarketingGroup $group): View
    {
        return view('marketing/groups/form', [
            'section' => MarketingSectionRegistry::section('groups'),
            'sections' => $this->navigationItems(),
            'group' => $group,
            'mode' => 'edit',
            'initialProfileId' => 0,
        ]);
    }

    public function update(Request $request, MarketingGroup $group): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_internal' => ['nullable', 'boolean'],
        ]);

        $group->forceFill([
            'name' => trim((string) $data['name']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'is_internal' => (bool) ($data['is_internal'] ?? false),
        ])->save();

        return redirect()
            ->route('marketing.groups.show', $group)
            ->with('toast', ['style' => 'success', 'message' => 'Group updated.']);
    }

    public function addMember(Request $request, MarketingGroup $group): RedirectResponse
    {
        $data = $request->validate([
            'marketing_profile_id' => ['required', 'integer', 'exists:marketing_profiles,id'],
            'return_to' => ['nullable', 'string', 'max:255'],
        ]);

        MarketingGroupMember::query()->updateOrCreate(
            [
                'marketing_group_id' => $group->id,
                'marketing_profile_id' => (int) $data['marketing_profile_id'],
            ],
            [
                'added_by' => auth()->id(),
            ]
        );

        $redirect = $this->redirectFromReturnTo($data['return_to'] ?? null);
        if ($redirect) {
            return $redirect->with('toast', ['style' => 'success', 'message' => 'Profile added to group.']);
        }

        return redirect()
            ->route('marketing.groups.show', $group)
            ->with('toast', ['style' => 'success', 'message' => 'Profile added to group.']);
    }

    public function removeMember(MarketingGroup $group, MarketingProfile $marketingProfile): RedirectResponse
    {
        MarketingGroupMember::query()
            ->where('marketing_group_id', $group->id)
            ->where('marketing_profile_id', $marketingProfile->id)
            ->delete();

        return redirect()
            ->route('marketing.groups.show', $group)
            ->with('toast', ['style' => 'success', 'message' => 'Profile removed from group.']);
    }

    public function importCsv(
        Request $request,
        MarketingGroup $group,
        MarketingGroupImportService $importService
    ): RedirectResponse {
        $data = $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('csv_file');
        $storedPath = $file->storeAs(
            'marketing/group-imports',
            now()->format('Ymd_His') . '_' . Str::slug(pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME)) . '.csv'
        );

        $absolutePath = Storage::disk('local')->path($storedPath);
        $dryRun = (bool) ($data['dry_run'] ?? false);

        try {
            $result = $importService->importFromCsv(
                group: $group,
                filePath: $absolutePath,
                createdBy: auth()->id(),
                dryRun: $dryRun
            );
        } finally {
            Storage::disk('local')->delete($storedPath);
        }

        $summary = (array) ($result['summary'] ?? []);

        return redirect()
            ->route('marketing.groups.show', $group)
            ->with('toast', [
                'style' => 'success',
                'message' => sprintf(
                    'Import complete. rows=%d members_added=%d members_existing=%d skipped=%d',
                    (int) ($summary['rows'] ?? 0),
                    (int) ($summary['members_added'] ?? 0),
                    (int) ($summary['members_existing'] ?? 0),
                    (int) ($summary['records_skipped'] ?? 0)
                ),
            ]);
    }

    public function sendForm(MarketingGroup $group, TwilioSenderConfigService $senderConfigService): View
    {
        abort_unless($group->is_internal, 403);

        $memberCount = (int) $group->members()->count();

        return view('marketing/groups/send', [
            'section' => MarketingSectionRegistry::section('groups'),
            'sections' => $this->navigationItems(),
            'group' => $group,
            'memberCount' => $memberCount,
            'smsSenders' => $senderConfigService->all(),
            'defaultSmsSenderKey' => (string) ($senderConfigService->defaultSender()['key'] ?? ''),
        ]);
    }

    public function send(
        Request $request,
        MarketingGroup $group,
        MarketingGroupDirectSendService $directSendService
    ): RedirectResponse {
        abort_unless($group->is_internal, 403);

        $data = $request->validate([
            'channel' => ['required', 'in:sms,email'],
            'subject' => ['nullable', 'string', 'max:255', 'required_if:channel,email'],
            'message' => ['required', 'string', 'max:2000'],
            'dry_run' => ['nullable', 'boolean'],
            'sender_key' => ['nullable', 'string', 'max:80'],
        ]);

        $summary = $directSendService->sendToGroup(
            group: $group,
            channel: (string) $data['channel'],
            message: (string) $data['message'],
            subject: $data['subject'] ?? null,
            options: [
                'dry_run' => (bool) ($data['dry_run'] ?? false),
                'actor_id' => auth()->id(),
                'sender_key' => trim((string) ($data['sender_key'] ?? '')) ?: null,
            ]
        );

        return redirect()
            ->route('marketing.groups.send', $group)
            ->with('toast', [
                'style' => 'success',
                'message' => sprintf(
                    'Direct send complete. processed=%d sent=%d failed=%d skipped=%d',
                    (int) $summary['processed'],
                    (int) $summary['sent'],
                    (int) $summary['failed'],
                    (int) $summary['skipped']
                ),
            ]);
    }

    /**
     * @return array<int,array{key:string,label:string,href:string,current:bool}>
     */
    protected function navigationItems(): array
    {
        $items = [];
        foreach (MarketingSectionRegistry::sections() as $key => $section) {
            $items[] = [
                'key' => $key,
                'label' => $section['label'],
                'href' => route($section['route']),
                'current' => request()->routeIs($section['route']) || request()->routeIs($section['route'] . '.*'),
            ];
        }

        return $items;
    }

    protected function redirectFromReturnTo(?string $returnTo): ?RedirectResponse
    {
        $value = trim((string) $returnTo);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^customer:(\d+)$/', $value, $matches) === 1) {
            return redirect()->route('marketing.customers.show', (int) $matches[1]);
        }

        return null;
    }
}
