<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Jobs\SendMarketingDirectMessageBatch;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageGroup;
use App\Models\MarketingMessageTemplate;
use App\Models\MarketingProfile;
use App\Models\MarketingSegment;
use App\Services\Marketing\MarketingDirectMessagingService;
use App\Services\Marketing\MarketingLinkShortenerService;
use App\Services\Marketing\MarketingSegmentEvaluator;
use App\Support\Marketing\MarketingIdentityNormalizer;
use App\Support\Marketing\MarketingSectionRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MarketingMessagesController extends Controller
{
    public function __construct(
        protected MarketingIdentityNormalizer $identityNormalizer,
        protected MarketingSegmentEvaluator $segmentEvaluator,
        protected MarketingLinkShortenerService $linkShortener
    ) {
    }

    public function send(Request $request): View
    {
        $state = $this->wizardState();
        $step = $this->normalizeStep((int) ($state['step'] ?? 1));

        $selectedPerson = ((int) ($state['selected_profile_id'] ?? 0)) > 0
            ? MarketingProfile::query()->find((int) $state['selected_profile_id'])
            : null;

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
                ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'normalized_phone']);

        $rawMessage = (string) ($state['raw_message_text'] ?? '');
        $finalMessage = (string) ($state['message_text'] ?? '');
        $segmentsPerMessage = $this->smsSegmentCount($finalMessage !== '' ? $finalMessage : $rawMessage);
        $recipientCount = count((array) ($state['recipients'] ?? []));
        $estimatedSegments = $segmentsPerMessage > 0 ? $segmentsPerMessage * max(1, $recipientCount) : 0;

        $profileCount = (int) MarketingProfile::query()->count();

        return view('marketing/messages/send', [
            'state' => $state,
            'step' => $step,
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
            'selectedPerson' => $selectedPerson ? $this->profileSearchPayload($selectedPerson) : null,
            'selectedProfiles' => $selectedProfiles->map(fn (MarketingProfile $profile): array => $this->profileSearchPayload($profile))->values()->all(),
            'segmentsPerMessage' => $segmentsPerMessage,
            'estimatedSegments' => $estimatedSegments,
            'recipientCount' => $recipientCount,
            'recipientWarnings' => $this->recipientWarnings((array) ($state['recipients'] ?? [])),
            'profileCount' => $profileCount,
            'shortenedLinks' => (array) ($state['shortened_links'] ?? []),
            'searchEndpoint' => route('marketing.messages.search-customers'),
            'deliveryLogUrl' => route('marketing.messages.deliveries'),
            'segmentRecipientEstimate' => (string) ($state['audience_kind'] ?? '') === 'segment' ? $recipientCount : null,
            'shortLinkPathPrefix' => trim((string) config('marketing.links.path_prefix', 'go'), '/'),
        ]);
    }

    public function searchCustomers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $queryText = trim((string) ($data['q'] ?? ''));
        $profileCount = (int) MarketingProfile::query()->count();

        if ($queryText === '') {
            $recent = MarketingProfile::query()
                ->where(function ($query): void {
                    $query->where(function ($named): void {
                        $named->whereNotNull('first_name')
                            ->where('first_name', '!=', '');
                    })->orWhere(function ($email): void {
                        $email->whereNotNull('email')
                            ->where('email', '!=', '');
                    })->orWhere(function ($phone): void {
                        $phone->whereNotNull('phone')
                            ->where('phone', '!=', '');
                    });
                })
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit(12)
                ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'normalized_phone']);

            return response()->json([
                'data' => $recent->map(fn (MarketingProfile $profile): array => $this->profileSearchPayload($profile))->values()->all(),
                'meta' => [
                    'query' => $queryText,
                    'profile_count' => $profileCount,
                    'has_profiles' => $profileCount > 0,
                    'mode' => 'recent',
                    'empty_reason' => $profileCount === 0
                        ? 'no_profiles'
                        : ($recent->isEmpty() ? 'no_searchable_profiles' : null),
                ],
            ]);
        }

        $results = MarketingProfile::query()
            ->where(function ($query) use ($queryText): void {
                $query->where('first_name', 'like', '%' . $queryText . '%')
                    ->orWhere('last_name', 'like', '%' . $queryText . '%')
                    ->orWhere('email', 'like', '%' . $queryText . '%')
                    ->orWhere('phone', 'like', '%' . $queryText . '%')
                    ->orWhere('normalized_phone', 'like', '%' . $queryText . '%')
                    ->orWhere('normalized_email', 'like', '%' . $queryText . '%');
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(12)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'normalized_phone']);

        return response()->json([
            'data' => $results->map(fn (MarketingProfile $profile): array => $this->profileSearchPayload($profile))->values()->all(),
            'meta' => [
                'query' => $queryText,
                'profile_count' => $profileCount,
                'has_profiles' => $profileCount > 0,
                'empty_reason' => $profileCount === 0
                    ? 'no_profiles'
                    : ($results->isEmpty() ? 'no_match' : null),
            ],
        ]);
    }

    public function saveAudience(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'audience_kind' => ['required', 'in:person,group,segment,manual'],
            'group_mode' => ['nullable', 'in:saved,custom'],
            'selected_profile_id' => ['nullable', 'integer', 'exists:marketing_profiles,id'],
            'selected_profile_ids' => ['nullable', 'array'],
            'selected_profile_ids.*' => ['integer', 'exists:marketing_profiles,id'],
            'segment_id' => ['nullable', 'integer', 'exists:marketing_segments,id'],
            'group_id' => ['nullable', 'integer', 'exists:marketing_message_groups,id'],
            'manual_phones' => ['nullable', 'string', 'max:5000'],
            'group_name' => ['nullable', 'string', 'max:120'],
            'group_description' => ['nullable', 'string', 'max:500'],
            'save_reusable_group' => ['nullable', 'boolean'],
        ]);

        $audienceKind = (string) $data['audience_kind'];
        $groupMode = (string) ($data['group_mode'] ?? 'saved');

        $selectedProfileIds = collect((array) ($data['selected_profile_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $manualPhonesText = trim((string) ($data['manual_phones'] ?? ''));

        $groupId = null;
        $members = match ($audienceKind) {
            'person' => $this->singleCustomerRecipients((int) ($data['selected_profile_id'] ?? 0)),
            'segment' => $this->segmentRecipients((int) ($data['segment_id'] ?? 0)),
            'group' => $groupMode === 'saved'
                ? (function () use (&$groupId, $data): array {
                    $groupId = (int) ($data['group_id'] ?? 0);
                    return $this->groupRecipients($groupId);
                })()
                : $this->customRecipients($selectedProfileIds, $manualPhonesText),
            'manual' => $this->manualRecipients($manualPhonesText),
            default => [],
        };

        if ($members === []) {
            $warning = match ($audienceKind) {
                'manual' => 'Couldn’t parse any phone numbers. Try +15551234567 or 5551234567.',
                'person' => 'Pick a person, or switch to Manual and paste a phone number.',
                default => 'No sendable recipients yet. Pick someone, a group, a segment, or paste valid numbers.',
            };

            return redirect()
                ->route('marketing.messages.send')
                ->with('toast', [
                    'style' => 'warning',
                    'message' => $warning,
                ]);
        }

        $saveReusable = (bool) ($data['save_reusable_group'] ?? false);
        $groupName = trim((string) ($data['group_name'] ?? ''));
        $groupDescription = trim((string) ($data['group_description'] ?? '')) ?: null;
        $adHocGroupPayload = null;

        if ($audienceKind === 'group' && $groupMode === 'custom') {
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
            'audience_kind' => $audienceKind,
            'group_mode' => $groupMode,
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
            'audience_summary' => $this->audienceSummary($audienceKind, $groupMode, $data, $members),
        ]);

        return redirect()
            ->route('marketing.messages.send')
            ->with('toast', [
                'style' => 'success',
                'message' => 'Audience locked in. Next up: write the text.',
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
        if (empty($state['recipients'])) {
            return redirect()
                ->route('marketing.messages.send')
                ->with('toast', [
                    'style' => 'warning',
                    'message' => 'Pick an audience first so we know who this text is for.',
                ]);
        }

        $rawMessage = trim((string) $data['message_text']);
        if ($rawMessage === '') {
            return redirect()
                ->route('marketing.messages.send')
                ->with('toast', [
                    'style' => 'warning',
                    'message' => 'Message text is required.',
                ]);
        }

        $shortened = $this->linkShortener->shortenMessage($rawMessage, (int) auth()->id());
        $finalMessage = trim((string) ($shortened['message'] ?? $rawMessage));
        if ($finalMessage === '') {
            $finalMessage = $rawMessage;
        }

        $this->storeWizardState([
            ...$state,
            'step' => 3,
            'template_id' => (int) ($data['template_id'] ?? 0),
            'raw_message_text' => $rawMessage,
            'message_text' => $finalMessage,
            'shortened_links' => (array) ($shortened['links'] ?? []),
            'send_at' => isset($data['send_at']) ? (string) $data['send_at'] : null,
        ]);

        $shortenedCount = count((array) ($shortened['links'] ?? []));

        return redirect()
            ->route('marketing.messages.send')
            ->with('toast', [
                'style' => 'success',
                'message' => $shortenedCount > 0
                    ? "Message saved. {$shortenedCount} link(s) shortened and ready to review."
                    : 'Message saved. Want to send yourself a test text next?',
            ]);
    }

    public function setStep(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'step' => ['required', 'integer', 'between:1,4'],
        ]);

        $target = $this->normalizeStep((int) $data['step']);
        $state = $this->wizardState();

        if ($target >= 2 && empty($state['recipients'])) {
            return redirect()
                ->route('marketing.messages.send')
                ->with('toast', [
                    'style' => 'warning',
                    'message' => 'Choose an audience first.',
                ]);
        }

        if ($target >= 3 && trim((string) ($state['message_text'] ?? '')) === '') {
            return redirect()
                ->route('marketing.messages.send')
                ->with('toast', [
                    'style' => 'warning',
                    'message' => 'Write your message before jumping ahead.',
                ]);
        }

        $state['step'] = $target;
        $this->storeWizardState($state);

        return redirect()->route('marketing.messages.send');
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
                ->with('toast', [
                    'style' => 'warning',
                    'message' => 'Write a message before sending a test text.',
                ]);
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
                    'message' => 'Test send failed: ' . $e->getMessage(),
                ]);
        }

        $this->storeWizardState([
            ...$state,
            'step' => 3,
            'last_test_phone' => (string) $data['test_phone'],
        ]);

        return redirect()
            ->route('marketing.messages.send')
            ->with('toast', [
                'style' => $this->sendToastStyle($summary),
                'message' => $this->sendToastMessage(
                    summary: $summary,
                    successPrefix: 'Test sent.',
                    failurePrefix: 'Test failed.'
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
                ->with('toast', [
                    'style' => 'warning',
                    'message' => 'Audience and message are required before sending.',
                ]);
        }

        $groupId = isset($state['group_id']) ? (int) $state['group_id'] : null;
        $audienceKind = (string) ($state['audience_kind'] ?? '');
        $groupMode = (string) ($state['group_mode'] ?? 'saved');

        if ($audienceKind === 'group' && $groupMode === 'custom' && !$groupId && isset($state['ad_hoc_group_payload']) && is_array($state['ad_hoc_group_payload'])) {
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

        $sendAt = $this->parseScheduledSendAt($state['send_at'] ?? null);
        $batchId = (string) Str::uuid();
        $sendOptions = [
            'dry_run' => (bool) ($data['dry_run'] ?? false),
            'actor_id' => (int) auth()->id(),
            'group_id' => $groupId,
            'source_label' => 'direct_message_wizard',
            'batch_id' => $batchId,
            'scheduled_for' => $sendAt?->toIso8601String(),
        ];

        if ($sendAt && $sendAt->isFuture()) {
            SendMarketingDirectMessageBatch::dispatch(
                channel: 'sms',
                recipients: $recipients,
                message: $message,
                options: $sendOptions,
            )->delay($sendAt);

            $this->clearWizardState();

            return redirect()
                ->route('marketing.messages.deliveries', ['batch' => $batchId])
                ->with('toast', [
                    'style' => 'success',
                    'message' => sprintf(
                        'Message scheduled for %s %s.',
                        $sendAt->format('Y-m-d H:i'),
                        (string) config('app.timezone', 'UTC')
                    ),
                ]);
        }

        try {
            $summary = $service->send(
                channel: 'sms',
                recipients: $recipients,
                message: $message,
                options: $sendOptions,
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
                'style' => $this->sendToastStyle($summary),
                'message' => $this->sendToastMessage(
                    summary: $summary,
                    successPrefix: 'Message sent.',
                    failurePrefix: 'Message send had failures.'
                ),
            ]);
    }

    public function resetWizard(): RedirectResponse
    {
        $this->clearWizardState();

        return redirect()
            ->route('marketing.messages.send')
            ->with('toast', ['style' => 'success', 'message' => 'Wizard reset. Fresh start.']);
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
                        ->orWhere('rendered_message', 'like', '%' . $search . '%')
                        ->orWhere('error_code', 'like', '%' . $search . '%')
                        ->orWhere('error_message', 'like', '%' . $search . '%');
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
     * @param array<int,array{profile_id:?int,name:?string,email:?string,phone:?string,normalized_phone:?string,source_type:string}> $recipients
     * @return array<int,string>
     */
    protected function recipientWarnings(array $recipients): array
    {
        $warnings = [];
        if ($recipients === []) {
            return $warnings;
        }

        $profileIds = collect($recipients)
            ->pluck('profile_id')
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($profileIds->isNotEmpty()) {
            $nonConsented = MarketingProfile::query()
                ->whereIn('id', $profileIds->all())
                ->where('accepts_sms_marketing', false)
                ->count();

            if ($nonConsented > 0) {
                $warnings[] = "{$nonConsented} profile(s) currently do not have SMS consent and will be skipped.";
            }
        }

        $manualCount = collect($recipients)
            ->filter(fn (array $row): bool => in_array((string) ($row['source_type'] ?? ''), ['manual', 'group_manual'], true))
            ->count();

        if ($manualCount > 0) {
            $warnings[] = "{$manualCount} manual number(s) are included; double-check for typos before sending.";
        }

        return $warnings;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,array{profile_id:?int,name:?string,email:?string,phone:?string,normalized_phone:string,source_type:string}> $members
     * @return array{label:string,detail:string}
     */
    protected function audienceSummary(string $audienceKind, string $groupMode, array $data, array $members): array
    {
        $count = count($members);

        return match ($audienceKind) {
            'person' => [
                'label' => 'Person',
                'detail' => $count . ' recipient selected.',
            ],
            'segment' => [
                'label' => 'Segment',
                'detail' => $count . ' recipients matched this segment right now.',
            ],
            'group' => $groupMode === 'saved'
                ? [
                    'label' => 'Saved Group',
                    'detail' => $count . ' recipients loaded from your selected group.',
                ]
                : [
                    'label' => 'Custom Group',
                    'detail' => $count . ' recipients from your custom list.',
                ],
            default => [
                'label' => 'Manual Numbers',
                'detail' => $count . ' manual number(s) are ready.',
            ],
        };
    }

    /**
     * @return array{id:int,name:string,email:?string,phone:?string}
     */
    protected function profileSearchPayload(MarketingProfile $profile): array
    {
        $name = trim((string) ($profile->first_name . ' ' . $profile->last_name));

        return [
            'id' => (int) $profile->id,
            'name' => $name !== '' ? $name : 'Unnamed profile #' . $profile->id,
            'email' => $this->nullableString($profile->email),
            'phone' => $this->nullableString($profile->phone ?: $profile->normalized_phone),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function wizardState(): array
    {
        $default = [
            'step' => 1,
            'audience_kind' => 'manual',
            'group_mode' => 'saved',
            'selected_profile_id' => 0,
            'selected_profile_ids' => [],
            'segment_id' => 0,
            'group_id' => null,
            'manual_phones' => '',
            'group_name' => '',
            'group_description' => '',
            'save_reusable_group' => false,
            'recipients' => [],
            'template_id' => 0,
            'raw_message_text' => '',
            'message_text' => '',
            'shortened_links' => [],
            'send_at' => null,
            'ad_hoc_group_payload' => null,
            'audience_summary' => ['label' => 'Audience', 'detail' => 'No audience selected yet.'],
            'last_test_phone' => '',
        ];

        $state = session('marketing.messages.wizard', []);
        if (!is_array($state)) {
            $state = [];
        }

        $merged = array_replace($default, $state);
        $merged['step'] = $this->normalizeStep((int) ($merged['step'] ?? 1));

        return $merged;
    }

    /**
     * @param array<string,mixed> $state
     */
    protected function storeWizardState(array $state): void
    {
        $state['step'] = $this->normalizeStep((int) ($state['step'] ?? 1));
        session(['marketing.messages.wizard' => $state]);
    }

    protected function clearWizardState(): void
    {
        session()->forget('marketing.messages.wizard');
    }

    protected function normalizeStep(int $step): int
    {
        return max(1, min(4, $step));
    }

    protected function parseScheduledSendAt(mixed $value): ?Carbon
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw, (string) config('app.timezone', 'UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $summary
     */
    protected function sendToastStyle(array $summary): string
    {
        $failed = (int) ($summary['failed'] ?? 0);

        return $failed > 0 ? 'warning' : 'success';
    }

    /**
     * @param array<string,mixed> $summary
     */
    protected function sendToastMessage(array $summary, string $successPrefix, string $failurePrefix): string
    {
        $processed = (int) ($summary['processed'] ?? 0);
        $sent = (int) ($summary['sent'] ?? 0);
        $failed = (int) ($summary['failed'] ?? 0);
        $skipped = (int) ($summary['skipped'] ?? 0);

        $prefix = $failed > 0 ? $failurePrefix : $successPrefix;
        $message = sprintf(
            '%s processed=%d sent=%d failed=%d skipped=%d',
            $prefix,
            $processed,
            $sent,
            $failed,
            $skipped
        );

        $errorCode = trim((string) ($summary['first_error_code'] ?? ''));
        $errorMessage = trim((string) ($summary['first_error_message'] ?? ''));
        if ($failed > 0 && ($errorCode !== '' || $errorMessage !== '')) {
            $reason = trim($errorCode . ($errorMessage !== '' ? ': ' . $errorMessage : ''));
            $message .= ' · Reason: ' . $reason;
        }

        return $message;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
