<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageGroup;
use App\Models\MarketingMessageTemplate;
use App\Models\MarketingProfile;
use App\Models\MarketingSegment;
use App\Services\Marketing\MarketingDirectMessagingService;
use App\Services\Marketing\MarketingSegmentEvaluator;
use App\Support\Marketing\MarketingIdentityNormalizer;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MarketingMessagesController extends Controller
{
    public function __construct(
        protected MarketingIdentityNormalizer $identityNormalizer,
        protected MarketingSegmentEvaluator $segmentEvaluator
    ) {
    }

    public function send(Request $request): View
    {
        $state = $this->wizardState();
        $search = trim((string) $request->query('customer_search', $state['customer_search'] ?? ''));
        $searchResults = $this->customerSearchResults($search);
        $selectedProfileIds = collect((array) ($state['selected_profile_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $selectedProfiles = $selectedProfileIds === []
            ? collect()
            : MarketingProfile::query()
                ->whereIn('id', $selectedProfileIds)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get();

        $message = (string) ($state['message_text'] ?? '');
        $segmentsPerMessage = $this->smsSegmentCount($message);
        $recipientCount = count((array) ($state['recipients'] ?? []));
        $estimatedSegments = $segmentsPerMessage > 0 ? $segmentsPerMessage * max(1, $recipientCount) : 0;

        return view('marketing/messages/send', [
            'section' => MarketingSectionRegistry::section('messages'),
            'sections' => $this->navigationItems(),
            'state' => $state,
            'search' => $search,
            'searchResults' => $searchResults,
            'selectedProfiles' => $selectedProfiles,
            'segments' => MarketingSegment::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'channel_scope']),
            'groups' => MarketingMessageGroup::query()
                ->where('channel', 'sms')
                ->orderByDesc('last_used_at')
                ->orderBy('name')
                ->withCount('members')
                ->get(['id', 'name', 'is_reusable', 'last_used_at']),
            'templates' => MarketingMessageTemplate::query()
                ->where('is_active', true)
                ->where('channel', 'sms')
                ->orderBy('name')
                ->get(['id', 'name', 'template_text']),
            'segmentsPerMessage' => $segmentsPerMessage,
            'estimatedSegments' => $estimatedSegments,
            'recipientCount' => $recipientCount,
        ]);
    }

    public function saveAudience(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'audience_type' => ['required', 'in:single_customer,segment,existing_group,custom_group,manual'],
            'selected_profile_id' => ['nullable', 'integer', 'exists:marketing_profiles,id'],
            'selected_profile_ids' => ['nullable', 'array'],
            'selected_profile_ids.*' => ['integer', 'exists:marketing_profiles,id'],
            'segment_id' => ['nullable', 'integer', 'exists:marketing_segments,id'],
            'group_id' => ['nullable', 'integer', 'exists:marketing_message_groups,id'],
            'manual_phones' => ['nullable', 'string', 'max:5000'],
            'group_name' => ['nullable', 'string', 'max:120'],
            'group_description' => ['nullable', 'string', 'max:500'],
            'save_reusable_group' => ['nullable', 'boolean'],
            'customer_search' => ['nullable', 'string', 'max:160'],
        ]);

        $audienceType = (string) $data['audience_type'];
        $selectedProfileIds = collect((array) ($data['selected_profile_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $search = trim((string) ($data['customer_search'] ?? ''));
        $manualPhonesText = trim((string) ($data['manual_phones'] ?? ''));

        $groupId = null;
        $members = match ($audienceType) {
            'single_customer' => $this->singleCustomerRecipients((int) ($data['selected_profile_id'] ?? 0)),
            'segment' => $this->segmentRecipients((int) ($data['segment_id'] ?? 0)),
            'existing_group' => (function () use (&$groupId, $data): array {
                $groupId = (int) ($data['group_id'] ?? 0);
                return $this->groupRecipients($groupId);
            })(),
            'custom_group' => $this->customRecipients($selectedProfileIds, $manualPhonesText),
            'manual' => $this->manualRecipients($manualPhonesText),
            default => [],
        };

        if ($members === []) {
            return redirect()
                ->route('marketing.messages.send', ['customer_search' => $search])
                ->with('toast', [
                    'style' => 'warning',
                    'message' => 'No sendable recipients found for the selected audience.',
                ]);
        }

        $saveReusable = (bool) ($data['save_reusable_group'] ?? false);
        $groupName = trim((string) ($data['group_name'] ?? ''));
        $groupDescription = trim((string) ($data['group_description'] ?? '')) ?: null;
        $adHocGroupPayload = null;

        if ($audienceType === 'custom_group') {
            if ($saveReusable && $groupName !== '') {
                $group = app(MarketingDirectMessagingService::class)->saveGroup(
                    name: $groupName,
                    channel: 'sms',
                    members: $members,
                    isReusable: true,
                    createdBy: (int) auth()->id(),
                    description: $groupDescription
                );
                $groupId = (int) $group->id;
            } else {
                $adHocGroupPayload = [
                    'name' => $groupName !== '' ? $groupName : 'Ad-hoc Group',
                    'description' => $groupDescription,
                ];
            }
        }

        $this->storeWizardState([
            ...$this->wizardState(),
            'step' => 2,
            'audience_type' => $audienceType,
            'customer_search' => $search,
            'segment_id' => (int) ($data['segment_id'] ?? 0),
            'group_id' => $groupId,
            'selected_profile_ids' => $selectedProfileIds,
            'selected_profile_id' => (int) ($data['selected_profile_id'] ?? 0),
            'manual_phones' => $manualPhonesText,
            'recipients' => $members,
            'group_name' => $groupName,
            'group_description' => $groupDescription,
            'save_reusable_group' => $saveReusable,
            'ad_hoc_group_payload' => $adHocGroupPayload,
        ]);

        return redirect()
            ->route('marketing.messages.send')
            ->with('toast', [
                'style' => 'success',
                'message' => 'Audience saved. Continue to message composition.',
            ]);
    }

    public function saveMessage(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'template_id' => ['nullable', 'integer', 'exists:marketing_message_templates,id'],
            'message_text' => ['required', 'string', 'max:1600'],
            'send_at' => ['nullable', 'date'],
        ]);

        $state = $this->wizardState();
        if (((int) ($state['step'] ?? 1)) < 2 || empty($state['recipients'])) {
            return redirect()
                ->route('marketing.messages.send')
                ->with('toast', ['style' => 'warning', 'message' => 'Select an audience before composing a message.']);
        }

        $message = trim((string) $data['message_text']);
        if ($message === '') {
            return redirect()
                ->route('marketing.messages.send')
                ->with('toast', ['style' => 'warning', 'message' => 'Message text is required.']);
        }

        $this->storeWizardState([
            ...$state,
            'step' => 3,
            'template_id' => (int) ($data['template_id'] ?? 0),
            'message_text' => $message,
            'send_at' => isset($data['send_at']) ? (string) $data['send_at'] : null,
        ]);

        return redirect()
            ->route('marketing.messages.send')
            ->with('toast', [
                'style' => 'success',
                'message' => 'Message saved. Review and send when ready.',
            ]);
    }

    public function sendTest(Request $request, MarketingDirectMessagingService $service): RedirectResponse
    {
        $data = $request->validate([
            'test_phone' => ['required', 'string', 'max:60'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $state = $this->wizardState();
        $message = trim((string) ($state['message_text'] ?? ''));
        if ($message === '') {
            return redirect()
                ->route('marketing.messages.send')
                ->with('toast', ['style' => 'warning', 'message' => 'Compose a message before sending a test SMS.']);
        }

        try {
            $summary = $service->send(
                channel: 'sms',
                recipients: [[
                    'profile_id' => null,
                    'name' => 'Test Recipient',
                    'email' => null,
                    'phone' => (string) $data['test_phone'],
                    'normalized_phone' => null,
                    'source_type' => 'test',
                ]],
                message: $message,
                options: [
                    'dry_run' => (bool) ($data['dry_run'] ?? false),
                    'actor_id' => (int) auth()->id(),
                    'source_label' => 'direct_message_wizard_test',
                ]
            );
        } catch (\Throwable $e) {
            return redirect()
                ->route('marketing.messages.send')
                ->with('toast', [
                    'style' => 'warning',
                    'message' => 'Test SMS failed: ' . $e->getMessage(),
                ]);
        }

        return redirect()
            ->route('marketing.messages.send')
            ->with('toast', [
                'style' => 'success',
                'message' => sprintf(
                    'Test send complete. processed=%d sent=%d failed=%d skipped=%d',
                    (int) $summary['processed'],
                    (int) $summary['sent'],
                    (int) $summary['failed'],
                    (int) $summary['skipped']
                ),
            ]);
    }

    public function executeSend(Request $request, MarketingDirectMessagingService $service): RedirectResponse
    {
        $data = $request->validate([
            'confirm_send' => ['required', 'accepted'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $state = $this->wizardState();
        $message = trim((string) ($state['message_text'] ?? ''));
        $recipients = (array) ($state['recipients'] ?? []);

        if ($message === '' || $recipients === []) {
            return redirect()
                ->route('marketing.messages.send')
                ->with('toast', ['style' => 'warning', 'message' => 'Audience and message are required before sending.']);
        }

        $groupId = isset($state['group_id']) ? (int) $state['group_id'] : null;
        $audienceType = (string) ($state['audience_type'] ?? '');
        if ($audienceType === 'custom_group' && ! $groupId && isset($state['ad_hoc_group_payload']) && is_array($state['ad_hoc_group_payload'])) {
            $payload = (array) $state['ad_hoc_group_payload'];
            $adHocName = trim((string) ($payload['name'] ?? '')) ?: 'Ad-hoc Group';
            $adHocDescription = trim((string) ($payload['description'] ?? '')) ?: null;
            $adHocGroup = $service->saveGroup(
                name: $adHocName . ' (' . now()->format('Y-m-d H:i') . ')',
                channel: 'sms',
                members: $recipients,
                isReusable: false,
                createdBy: (int) auth()->id(),
                description: $adHocDescription
            );
            $groupId = (int) $adHocGroup->id;
        }

        try {
            $summary = $service->send(
                channel: 'sms',
                recipients: $recipients,
                message: $message,
                options: [
                    'dry_run' => (bool) ($data['dry_run'] ?? false),
                    'actor_id' => (int) auth()->id(),
                    'group_id' => $groupId,
                    'source_label' => 'direct_message_wizard',
                ]
            );
        } catch (\Throwable $e) {
            return redirect()
                ->route('marketing.messages.send')
                ->with('toast', [
                    'style' => 'warning',
                    'message' => 'Send failed: ' . $e->getMessage(),
                ]);
        }

        $this->clearWizardState();

        return redirect()
            ->route('marketing.messages.deliveries', ['batch' => $summary['batch_id']])
            ->with('toast', [
                'style' => 'success',
                'message' => sprintf(
                    'Send complete. processed=%d sent=%d failed=%d skipped=%d',
                    (int) $summary['processed'],
                    (int) $summary['sent'],
                    (int) $summary['failed'],
                    (int) $summary['skipped']
                ),
            ]);
    }

    public function resetWizard(): RedirectResponse
    {
        $this->clearWizardState();

        return redirect()
            ->route('marketing.messages.send')
            ->with('toast', ['style' => 'success', 'message' => 'Wizard cleared.']);
    }

    public function deliveries(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $batch = trim((string) $request->query('batch', ''));

        $deliveries = MarketingMessageDelivery::query()
            ->whereNull('campaign_id')
            ->with(['profile:id,first_name,last_name,email,phone', 'creator:id,name'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('to_phone', 'like', '%' . $search . '%')
                        ->orWhere('provider_message_id', 'like', '%' . $search . '%')
                        ->orWhere('rendered_message', 'like', '%' . $search . '%');
                });
            })
            ->when($status !== '', fn ($query) => $query->where('send_status', $status))
            ->when($batch !== '', function ($query) use ($batch): void {
                $query->whereRaw("json_extract(provider_payload, '$.batch_id') = ?", [$batch]);
            })
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('marketing/messages/deliveries', [
            'section' => MarketingSectionRegistry::section('messages'),
            'sections' => $this->navigationItems(),
            'deliveries' => $deliveries,
            'search' => $search,
            'status' => $status,
            'batch' => $batch,
        ]);
    }

    protected function singleCustomerRecipients(int $profileId): array
    {
        $profile = $profileId > 0
            ? MarketingProfile::query()->find($profileId)
            : null;

        if (!$profile) {
            return [];
        }

        return [$this->profileRecipient($profile)];
    }

    protected function segmentRecipients(int $segmentId): array
    {
        if ($segmentId <= 0) {
            return [];
        }

        $segment = MarketingSegment::query()->find($segmentId);
        if (!$segment) {
            return [];
        }

        $recipients = [];
        foreach (MarketingProfile::query()->orderBy('id')->get() as $profile) {
            $result = $this->segmentEvaluator->evaluateProfile($segment, $profile);
            if (!$result['matched']) {
                continue;
            }
            $recipients[] = $this->profileRecipient($profile);
        }

        return $this->dedupeRecipients($recipients);
    }

    protected function groupRecipients(int $groupId): array
    {
        if ($groupId <= 0) {
            return [];
        }

        $group = MarketingMessageGroup::query()
            ->with(['members.profile'])
            ->find($groupId);
        if (!$group) {
            return [];
        }

        $rows = [];
        foreach ($group->members as $member) {
            if ($member->profile) {
                $rows[] = $this->profileRecipient($member->profile);
                continue;
            }

            $rows[] = [
                'profile_id' => null,
                'name' => $this->nullableString($member->full_name),
                'email' => $this->nullableString($member->email),
                'phone' => $this->nullableString($member->phone),
                'normalized_phone' => $this->nullableString($member->normalized_phone),
                'source_type' => 'group_manual',
            ];
        }

        return $this->dedupeRecipients($rows);
    }

    /**
     * @param array<int,int> $profileIds
     */
    protected function customRecipients(array $profileIds, string $manualPhones): array
    {
        $rows = [];
        if ($profileIds !== []) {
            $profiles = MarketingProfile::query()
                ->whereIn('id', $profileIds)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get();
            foreach ($profiles as $profile) {
                $rows[] = $this->profileRecipient($profile);
            }
        }

        foreach ($this->manualRecipients($manualPhones) as $manual) {
            $rows[] = $manual;
        }

        return $this->dedupeRecipients($rows);
    }

    protected function manualRecipients(string $manualPhones): array
    {
        $tokens = preg_split('/[\r\n,;]+/', $manualPhones) ?: [];
        $rows = [];
        foreach ($tokens as $token) {
            $value = trim((string) $token);
            if ($value === '') {
                continue;
            }

            $normalized = $this->identityNormalizer->normalizePhone($value);
            if ($normalized === null) {
                continue;
            }

            $rows[] = [
                'profile_id' => null,
                'name' => null,
                'email' => null,
                'phone' => $value,
                'normalized_phone' => $normalized,
                'source_type' => 'manual',
            ];
        }

        return $this->dedupeRecipients($rows);
    }

    protected function profileRecipient(MarketingProfile $profile): array
    {
        $name = trim((string) ($profile->first_name . ' ' . $profile->last_name));

        return [
            'profile_id' => (int) $profile->id,
            'name' => $name !== '' ? $name : null,
            'email' => $this->nullableString($profile->email),
            'phone' => $this->nullableString($profile->phone ?: $profile->normalized_phone),
            'normalized_phone' => $this->nullableString($profile->normalized_phone),
            'source_type' => 'profile',
        ];
    }

    /**
     * @param array<int,array{profile_id:?int,name:?string,email:?string,phone:?string,normalized_phone:?string,source_type:string}> $recipients
     * @return array<int,array{profile_id:?int,name:?string,email:?string,phone:?string,normalized_phone:string,source_type:string}>
     */
    protected function dedupeRecipients(array $recipients): array
    {
        $rows = [];
        foreach ($recipients as $recipient) {
            $normalized = $this->identityNormalizer->normalizePhone((string) ($recipient['normalized_phone'] ?? $recipient['phone'] ?? ''));
            if ($normalized === null) {
                continue;
            }

            $rows[$normalized] = [
                'profile_id' => isset($recipient['profile_id']) ? (int) $recipient['profile_id'] : null,
                'name' => $this->nullableString($recipient['name'] ?? null),
                'email' => $this->nullableString($recipient['email'] ?? null),
                'phone' => $this->nullableString($recipient['phone'] ?? null) ?: $normalized,
                'normalized_phone' => $normalized,
                'source_type' => trim((string) ($recipient['source_type'] ?? 'profile')) ?: 'profile',
            ];
        }

        return array_values($rows);
    }

    protected function customerSearchResults(string $search): Collection
    {
        if ($search === '') {
            return collect();
        }

        return MarketingProfile::query()
            ->where(function ($query) use ($search): void {
                $query->where('first_name', 'like', '%' . $search . '%')
                    ->orWhere('last_name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%');
            })
            ->orderByDesc('updated_at')
            ->limit(40)
            ->get();
    }

    protected function smsSegmentCount(string $message): int
    {
        $message = trim($message);
        if ($message === '') {
            return 0;
        }

        $isGsm = (bool) preg_match('/^[\r\n !\"#$%&\'()*+,\-.\/0-9:;<=>?@A-Z\\[\\\\\\]_a-z{|}~\^€£¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉÄÖÑÜ§¿äöñüà]+$/u', $message);

        if ($isGsm) {
            $length = mb_strlen($message, 'UTF-8');
            if ($length <= 160) {
                return 1;
            }

            return (int) ceil($length / 153);
        }

        $length = mb_strlen($message, 'UTF-8');
        if ($length <= 70) {
            return 1;
        }

        return (int) ceil($length / 67);
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

    /**
     * @return array<string,mixed>
     */
    protected function wizardState(): array
    {
        $default = [
            'step' => 1,
            'audience_type' => 'single_customer',
            'selected_profile_id' => 0,
            'selected_profile_ids' => [],
            'segment_id' => 0,
            'group_id' => null,
            'manual_phones' => '',
            'group_name' => '',
            'group_description' => '',
            'save_reusable_group' => false,
            'customer_search' => '',
            'recipients' => [],
            'template_id' => 0,
            'message_text' => '',
            'send_at' => null,
            'ad_hoc_group_payload' => null,
        ];

        $state = session('marketing.messages.wizard', []);
        if (!is_array($state)) {
            $state = [];
        }

        return array_replace($default, $state);
    }

    /**
     * @param array<string,mixed> $state
     */
    protected function storeWizardState(array $state): void
    {
        session(['marketing.messages.wizard' => $state]);
    }

    protected function clearWizardState(): void
    {
        session()->forget('marketing.messages.wizard');
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
