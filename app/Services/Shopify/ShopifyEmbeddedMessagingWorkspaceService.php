<?php

namespace App\Services\Shopify;

use App\Models\MarketingEmailDelivery;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageGroup;
use App\Models\MarketingMessageGroupMember;
use App\Models\MarketingProfile;
use App\Services\Marketing\MarketingDirectMessagingService;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShopifyEmbeddedMessagingWorkspaceService
{
    public const AUTO_GROUP_ALL_SUBSCRIBED = 'all_subscribed';
    /**
     * @var array<int,string>
     */
    protected const LEGACY_CONSENT_SOURCE_TYPES = [
        'yotpo_contacts_import',
        'square_marketing_import',
        'square_customer_sync',
    ];

    /**
     * @var array<string,array<string,mixed>>
     */
    protected array $channelAudienceCache = [];

    public function __construct(
        protected ShopifyEmbeddedCustomersGridService $customersGridService,
        protected MarketingDirectMessagingService $directMessagingService,
        protected MarketingIdentityNormalizer $identityNormalizer
    ) {
    }

    /**
     * @return array<int,array{
     *   id:int,
     *   name:string,
     *   email:?string,
     *   phone:?string,
     *   accepts_sms_marketing:bool,
     *   accepts_email_marketing:bool,
     *   sms_contactable:bool,
     *   email_contactable:bool
     * }>
     */
    public function searchCustomers(string $query, ?int $tenantId, int $limit = 12): array
    {
        $rows = $this->customersGridService->searchProfilesForMessaging($query, $tenantId, $limit);

        return array_map(function (array $row): array {
            $email = $this->nullableString($row['email'] ?? null);
            $phone = $this->nullableString($row['phone'] ?? null);
            $acceptsSms = (bool) ($row['accepts_sms_marketing'] ?? false);
            $acceptsEmail = (bool) ($row['accepts_email_marketing'] ?? false);
            $smsPhone = $this->sendableSmsPhoneFromValues($phone, $row['normalized_phone'] ?? null, $acceptsSms);
            $emailAddress = $this->sendableEmailFromValues($email, $row['normalized_email'] ?? null, $acceptsEmail);

            return [
                'id' => (int) ($row['id'] ?? 0),
                'name' => trim((string) ($row['name'] ?? '')) !== ''
                    ? trim((string) $row['name'])
                    : ('Customer #' . (int) ($row['id'] ?? 0)),
                'email' => $email,
                'phone' => $phone,
                'accepts_sms_marketing' => $acceptsSms,
                'accepts_email_marketing' => $acceptsEmail,
                'sms_contactable' => $smsPhone !== null,
                'email_contactable' => $emailAddress !== null,
            ];
        }, $rows);
    }

    /**
     * @return array{
     *   saved:array<int,array<string,mixed>>,
     *   auto:array<int,array<string,mixed>>
     * }
     */
    public function groups(?int $tenantId, bool $includeAutoCounts = false): array
    {
        $savedGroups = $this->tenantScopedGroupQuery($tenantId)
            ->where('is_system', false)
            ->withCount('members')
            ->orderByDesc('members_count')
            ->orderByDesc('last_used_at')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'description',
                'channel',
                'last_used_at',
                'updated_at',
            ])
            ->map(fn (MarketingMessageGroup $group): array => [
                'type' => 'saved',
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'description' => $this->nullableString($group->description),
                'channel' => $this->normalizedGroupChannel((string) $group->channel),
                'members_count' => (int) ($group->members_count ?? 0),
                'last_used_at' => optional($group->last_used_at)->toIso8601String(),
                'updated_at' => optional($group->updated_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        $summary = $includeAutoCounts ? $this->allSubscribedSummary($tenantId) : null;
        $autoGroups = [[
            'type' => 'auto',
            'key' => self::AUTO_GROUP_ALL_SUBSCRIBED,
            'name' => 'All Subscribed',
            'description' => 'Customers with active consent and reachable contact info for SMS and/or email.',
            'counts' => $summary,
        ]];

        return [
            'saved' => $savedGroups,
            'auto' => $autoGroups,
        ];
    }

    /**
     * @return array{
     *   summary:array{sms:int,email:int,overlap:int,unique:int},
     *   diagnostics:array<string,mixed>
     * }
     */
    public function audienceSummary(?int $tenantId): array
    {
        $sms = $this->resolvedChannelAudience($tenantId, 'sms', includeRecipients: false);
        $email = $this->resolvedChannelAudience($tenantId, 'email', includeRecipients: false);

        $smsIds = collect((array) ($sms['profile_ids'] ?? []))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $emailIds = collect((array) ($email['profile_ids'] ?? []))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $overlap = $smsIds->intersect($emailIds)->count();

        return [
            'summary' => [
                'sms' => $smsIds->count(),
                'email' => $emailIds->count(),
                'overlap' => $overlap,
                'unique' => $smsIds->merge($emailIds)->unique()->count(),
            ],
            'diagnostics' => [
                'sms' => [
                    'displayed_audience_count' => $smsIds->count(),
                    'query_candidate_count' => (int) ($sms['candidate_count'] ?? 0),
                    'effective_consent_count' => (int) ($sms['effective_consent_count'] ?? 0),
                    'resolved_sendable_count' => (int) ($sms['resolved_sendable_count'] ?? 0),
                ],
                'email' => [
                    'displayed_audience_count' => $emailIds->count(),
                    'query_candidate_count' => (int) ($email['candidate_count'] ?? 0),
                    'effective_consent_count' => (int) ($email['effective_consent_count'] ?? 0),
                    'resolved_sendable_count' => (int) ($email['resolved_sendable_count'] ?? 0),
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function groupDetailById(int $groupId, ?int $tenantId): ?array
    {
        $group = $this->tenantScopedGroupQuery($tenantId)
            ->whereKey($groupId)
            ->with([
                'members.profile:id,tenant_id,first_name,last_name,email,normalized_email,phone,normalized_phone,accepts_sms_marketing,accepts_email_marketing',
            ])
            ->first();

        if (! $group instanceof MarketingMessageGroup) {
            return null;
        }

        return $this->groupPayload($group, $tenantId);
    }

    /**
     * @param  array<int,int>  $profileIds
     * @return array<string,mixed>
     */
    public function createGroup(
        ?int $tenantId,
        string $name,
        array $profileIds,
        ?string $description,
        ?int $actorId
    ): array {
        $profiles = $this->tenantScopedProfiles($tenantId, $profileIds);

        if ($profiles->isEmpty()) {
            throw ValidationException::withMessages([
                'member_profile_ids' => 'Choose at least one customer for this group.',
            ]);
        }

        $group = $this->directMessagingService->saveGroup(
            name: trim($name),
            channel: 'multi',
            members: $profiles->map(fn (MarketingProfile $profile): array => $this->profileRecipient($profile))->values()->all(),
            isReusable: true,
            createdBy: $actorId,
            description: $description !== null ? trim($description) : null,
            tenantId: $tenantId
        );

        $payload = $this->groupDetailById((int) $group->id, $tenantId);
        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'group' => 'Group was created, but could not be reloaded.',
            ]);
        }

        return $payload;
    }

    /**
     * @param  array<int,int>  $profileIds
     * @return array<string,mixed>
     */
    public function updateGroup(
        int $groupId,
        ?int $tenantId,
        string $name,
        array $profileIds,
        ?string $description
    ): array {
        $group = $this->tenantScopedGroupQuery($tenantId)->whereKey($groupId)->first();
        if (! $group instanceof MarketingMessageGroup) {
            throw ValidationException::withMessages([
                'group_id' => 'Group not found for this tenant.',
            ]);
        }

        $profiles = $this->tenantScopedProfiles($tenantId, $profileIds);
        if ($profiles->isEmpty()) {
            throw ValidationException::withMessages([
                'member_profile_ids' => 'Choose at least one customer for this group.',
            ]);
        }

        DB::transaction(function () use ($group, $profiles, $name, $description): void {
            $group->forceFill([
                'name' => trim($name),
                'description' => $this->nullableString($description),
            ])->save();

            $group->members()->delete();

            $rows = $profiles->map(function (MarketingProfile $profile) use ($group): array {
                return [
                    'marketing_message_group_id' => (int) $group->id,
                    'marketing_profile_id' => (int) $profile->id,
                    'source_type' => 'profile',
                    'full_name' => $this->profileDisplayName($profile),
                    'email' => $this->nullableString($profile->email),
                    'phone' => $this->nullableString($profile->phone ?: $profile->normalized_phone),
                    'normalized_phone' => $this->nullableString($profile->normalized_phone),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })->values()->all();

            if ($rows !== []) {
                MarketingMessageGroupMember::query()->insert($rows);
            }
        });

        $payload = $this->groupDetailById((int) $group->id, $tenantId);
        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'group' => 'Group was updated, but could not be reloaded.',
            ]);
        }

        return $payload;
    }

    /**
     * @return array{summary:array<string,mixed>,profile:array<string,mixed>}
     */
    public function sendIndividual(
        ?int $tenantId,
        int $profileId,
        string $channel,
        string $body,
        ?string $subject,
        ?string $senderKey,
        ?int $actorId
    ): array {
        $profile = MarketingProfile::query()
            ->when($tenantId !== null, fn (Builder $query) => $query->forTenantId($tenantId))
            ->whereKey($profileId)
            ->first();

        if (! $profile instanceof MarketingProfile) {
            throw ValidationException::withMessages([
                'profile_id' => 'Customer not found for this tenant.',
            ]);
        }

        $channel = $this->normalizedChannel($channel);
        $this->assertContactMethodAvailable($profile, $channel, $tenantId);

        $forceSendProfileIds = [];
        if (! $this->hasCanonicalConsent($profile, $channel)
            && $this->profileHasEffectiveLegacyConsent($profile, $channel, $tenantId)) {
            $forceSendProfileIds[] = (int) $profile->id;
        }

        $summary = $this->directMessagingService->send(
            channel: $channel,
            recipients: [$this->profileRecipient($profile)],
            message: trim($body),
            options: [
                'subject' => $subject,
                'sender_key' => $senderKey,
                'actor_id' => $actorId,
                'tenant_id' => $tenantId ?? $this->positiveInt($profile->tenant_id),
                'source_label' => 'shopify_embedded_messaging_individual',
                'force_send_profile_ids' => $forceSendProfileIds,
            ]
        );

        return [
            'summary' => $summary,
            'profile' => [
                'id' => (int) $profile->id,
                'name' => $this->profileDisplayName($profile),
            ],
        ];
    }

    /**
     * @return array{summary:array<string,mixed>,target:array<string,mixed>}
     */
    public function sendGroup(
        ?int $tenantId,
        string $targetType,
        ?int $groupId,
        ?string $groupKey,
        string $channel,
        string $body,
        ?string $subject,
        ?string $senderKey,
        ?int $actorId
    ): array {
        $channel = $this->normalizedChannel($channel);
        $resolvedTarget = $this->resolveGroupTarget($tenantId, $targetType, $groupId, $groupKey, $channel);

        $recipients = (array) ($resolvedTarget['recipients'] ?? []);
        if ($recipients === []) {
            $errorField = ((string) ($resolvedTarget['target']['type'] ?? 'saved')) === 'auto'
                ? 'group_key'
                : 'group_id';
            throw ValidationException::withMessages([
                $errorField => 'No recipients are currently eligible for this target and channel.',
            ]);
        }

        $summary = $this->directMessagingService->send(
            channel: $channel,
            recipients: $recipients,
            message: trim($body),
            options: [
                'subject' => $subject,
                'sender_key' => $senderKey,
                'actor_id' => $actorId,
                'tenant_id' => $tenantId,
                'group_id' => $this->positiveInt(data_get($resolvedTarget, 'target.id')),
                'source_label' => (string) ($resolvedTarget['source_label'] ?? 'shopify_embedded_messaging_group'),
                'force_send_profile_ids' => array_values(array_unique(array_map(
                    'intval',
                    (array) ($resolvedTarget['force_send_profile_ids'] ?? [])
                ))),
            ]
        );

        return [
            'summary' => $summary,
            'target' => (array) ($resolvedTarget['target'] ?? []),
            'diagnostics' => [
                'query_candidate_count' => (int) ($resolvedTarget['query_candidate_count'] ?? 0),
                'resolved_sendable_count' => (int) ($resolvedTarget['resolved_sendable_count'] ?? count($recipients)),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function previewGroupSend(
        ?int $tenantId,
        string $targetType,
        ?int $groupId,
        ?string $groupKey,
        string $channel,
        string $body,
        ?string $subject
    ): array {
        $channel = $this->normalizedChannel($channel);
        $resolvedTarget = $this->resolveGroupTarget($tenantId, $targetType, $groupId, $groupKey, $channel);
        $recipients = (array) ($resolvedTarget['recipients'] ?? []);

        return [
            'target' => (array) ($resolvedTarget['target'] ?? []),
            'channel' => $channel,
            'subject' => $channel === 'email' ? $this->nullableString($subject) : null,
            'body' => trim($body),
            'message_preview' => Str::limit(trim($body), 280),
            'estimated_recipients' => count($recipients),
            'query_candidate_count' => (int) ($resolvedTarget['query_candidate_count'] ?? 0),
            'resolved_sendable_count' => (int) ($resolvedTarget['resolved_sendable_count'] ?? count($recipients)),
            'force_send_profile_ids_count' => count((array) ($resolvedTarget['force_send_profile_ids'] ?? [])),
        ];
    }

    /**
     * @return array{sms:int,email:int,overlap:int,unique:int}
     */
    public function allSubscribedSummary(?int $tenantId): array
    {
        $summary = $this->audienceSummary($tenantId);

        return (array) ($summary['summary'] ?? [
            'sms' => 0,
            'email' => 0,
            'overlap' => 0,
            'unique' => 0,
        ]);
    }

    /**
     * @return array<int,array{
     *   channel:string,
     *   status:string,
     *   recipient:string,
     *   profile_name:string,
     *   message_preview:string,
     *   sent_at:?string
     * }>
     */
    public function history(?int $tenantId, int $limit = 40): array
    {
        $limit = max(1, min($limit, 100));

        $smsRows = MarketingMessageDelivery::query()
            ->whereNull('campaign_id')
            ->where('channel', 'sms')
            ->when($tenantId !== null, function ($query) use ($tenantId): void {
                $query->whereHas('profile', fn (Builder $profileQuery) => $profileQuery->forTenantId($tenantId));
            }, function ($query): void {
                $query->whereHas('profile', fn (Builder $profileQuery) => $profileQuery->whereNull('tenant_id'));
            })
            ->with('profile:id,first_name,last_name,email,phone')
            ->latest('id')
            ->limit($limit * 2)
            ->get()
            ->filter(function (MarketingMessageDelivery $delivery): bool {
                $source = strtolower(trim((string) data_get($delivery->provider_payload, 'source_label', '')));

                return str_starts_with($source, 'shopify_embedded_messaging_');
            })
            ->map(function (MarketingMessageDelivery $delivery): array {
                /** @var MarketingProfile|null $profile */
                $profile = $delivery->profile;
                $recipient = trim((string) ($delivery->to_phone ?? ''));
                if ($recipient === '') {
                    $recipient = trim((string) ($profile?->phone ?? $profile?->email ?? ''));
                }

                return [
                    'channel' => 'sms',
                    'status' => strtolower(trim((string) ($delivery->send_status ?? 'sent'))),
                    'recipient' => $recipient,
                    'profile_name' => $profile instanceof MarketingProfile ? $this->profileDisplayName($profile) : 'Customer',
                    'message_preview' => Str::limit((string) ($delivery->rendered_message ?? ''), 120),
                    'sent_at' => optional($delivery->sent_at ?? $delivery->created_at)->toIso8601String(),
                ];
            });

        $emailRows = MarketingEmailDelivery::query()
            ->where('campaign_type', 'direct_message')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId), fn ($query) => $query->whereNull('tenant_id'))
            ->with('profile:id,first_name,last_name,email,phone')
            ->latest('id')
            ->limit($limit * 2)
            ->get()
            ->filter(function (MarketingEmailDelivery $delivery): bool {
                $source = strtolower(trim((string) data_get($delivery->metadata, 'source_label', '')));

                return str_starts_with($source, 'shopify_embedded_messaging_');
            })
            ->map(function (MarketingEmailDelivery $delivery): array {
                /** @var MarketingProfile|null $profile */
                $profile = $delivery->profile;

                return [
                    'channel' => 'email',
                    'status' => strtolower(trim((string) ($delivery->status ?? 'sent'))),
                    'recipient' => trim((string) ($delivery->email ?? $profile?->email ?? '')),
                    'profile_name' => $profile instanceof MarketingProfile ? $this->profileDisplayName($profile) : 'Customer',
                    'message_preview' => Str::limit((string) data_get($delivery->metadata, 'subject', 'Email send'), 120),
                    'sent_at' => optional($delivery->sent_at ?? $delivery->created_at)->toIso8601String(),
                ];
            });

        return $smsRows
            ->concat($emailRows)
            ->sortByDesc(fn (array $row): string => (string) ($row['sent_at'] ?? ''))
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    protected function resolveGroupTarget(
        ?int $tenantId,
        string $targetType,
        ?int $groupId,
        ?string $groupKey,
        string $channel
    ): array {
        $targetType = strtolower(trim($targetType));
        if (! in_array($targetType, ['saved', 'auto'], true)) {
            throw ValidationException::withMessages([
                'target_type' => 'Group target type is invalid.',
            ]);
        }

        if ($targetType === 'saved') {
            if ($groupId === null || $groupId <= 0) {
                throw ValidationException::withMessages([
                    'group_id' => 'Choose a saved group before sending.',
                ]);
            }

            $group = $this->tenantScopedGroupQuery($tenantId)
                ->whereKey($groupId)
                ->with('members.profile:id,tenant_id,first_name,last_name,email,normalized_email,phone,normalized_phone')
                ->first();

            if (! $group instanceof MarketingMessageGroup) {
                throw ValidationException::withMessages([
                    'group_id' => 'Group not found for this tenant.',
                ]);
            }

            $recipients = $this->savedGroupRecipients($group, $tenantId);

            return [
                'source_label' => 'shopify_embedded_messaging_group',
                'target' => [
                    'type' => 'saved',
                    'id' => (int) $group->id,
                    'name' => (string) $group->name,
                ],
                'recipients' => $recipients,
                'force_send_profile_ids' => [],
                'query_candidate_count' => count($recipients),
                'resolved_sendable_count' => count($recipients),
            ];
        }

        $normalizedGroupKey = strtolower(trim((string) $groupKey));
        if ($normalizedGroupKey !== self::AUTO_GROUP_ALL_SUBSCRIBED) {
            throw ValidationException::withMessages([
                'group_key' => 'Unsupported automatic group selection.',
            ]);
        }

        $audience = $this->resolvedChannelAudience($tenantId, $channel, includeRecipients: true);

        return [
            'source_label' => 'shopify_embedded_messaging_auto_group',
            'target' => [
                'type' => 'auto',
                'key' => self::AUTO_GROUP_ALL_SUBSCRIBED,
                'name' => 'All Subscribed',
            ],
            'recipients' => (array) ($audience['recipients'] ?? []),
            'force_send_profile_ids' => (array) ($audience['force_send_profile_ids'] ?? []),
            'query_candidate_count' => (int) ($audience['candidate_count'] ?? 0),
            'resolved_sendable_count' => (int) ($audience['resolved_sendable_count'] ?? 0),
        ];
    }

    /**
     * @return array{
     *   profile_ids:array<int,int>,
     *   recipients:array<int,array<string,mixed>>,
     *   force_send_profile_ids:array<int,int>,
     *   candidate_count:int,
     *   effective_consent_count:int,
     *   resolved_sendable_count:int
     * }
     */
    protected function resolvedChannelAudience(?int $tenantId, string $channel, bool $includeRecipients): array
    {
        $channel = $this->normalizedChannel($channel);
        $cacheKey = implode(':', [
            (string) ($tenantId ?? 'null'),
            $channel,
            $includeRecipients ? 'with_recipients' : 'summary_only',
        ]);

        if (isset($this->channelAudienceCache[$cacheKey]) && is_array($this->channelAudienceCache[$cacheKey])) {
            /** @var array<string,mixed> $cached */
            $cached = $this->channelAudienceCache[$cacheKey];

            return [
                'profile_ids' => array_values(array_map('intval', (array) ($cached['profile_ids'] ?? []))),
                'recipients' => $includeRecipients ? array_values((array) ($cached['recipients'] ?? [])) : [],
                'force_send_profile_ids' => array_values(array_map('intval', (array) ($cached['force_send_profile_ids'] ?? []))),
                'candidate_count' => (int) ($cached['candidate_count'] ?? 0),
                'effective_consent_count' => (int) ($cached['effective_consent_count'] ?? 0),
                'resolved_sendable_count' => (int) ($cached['resolved_sendable_count'] ?? 0),
            ];
        }

        $consentColumn = $channel === 'sms' ? 'accepts_sms_marketing' : 'accepts_email_marketing';

        $query = MarketingProfile::query()
            ->when($tenantId !== null, fn (Builder $builder) => $builder->forTenantId($tenantId))
            ->select([
                'id',
                'tenant_id',
                'first_name',
                'last_name',
                'email',
                'normalized_email',
                'phone',
                'normalized_phone',
                'accepts_sms_marketing',
                'accepts_email_marketing',
            ])
            ->where(function (Builder $builder) use ($consentColumn, $channel, $tenantId): void {
                $builder->where($consentColumn, true)
                    ->orWhereExists(function ($exists) use ($channel, $tenantId): void {
                        $exists->selectRaw('1')
                            ->from('marketing_consent_events as legacy_events')
                            ->whereColumn('legacy_events.marketing_profile_id', 'marketing_profiles.id')
                            ->where('legacy_events.channel', $channel)
                            ->where('legacy_events.event_type', 'imported')
                            ->whereIn('legacy_events.source_type', self::LEGACY_CONSENT_SOURCE_TYPES);

                        if (Schema::hasColumn('marketing_consent_events', 'tenant_id')) {
                            if ($tenantId === null) {
                                $exists->whereNull('legacy_events.tenant_id');
                            } else {
                                $exists->where('legacy_events.tenant_id', $tenantId);
                            }
                        }
                    });
            })
            ->where(function (Builder $builder) use ($channel): void {
                if ($channel === 'sms') {
                    $builder->whereNotNull('phone')
                        ->orWhereNotNull('normalized_phone');

                    return;
                }

                $builder->whereNotNull('email')
                    ->orWhereNotNull('normalized_email');
            });

        $candidateCount = (int) (clone $query)->count('marketing_profiles.id');
        $profileIds = [];
        $forceSendProfileIds = [];
        $recipients = [];
        $effectiveConsentCount = 0;
        $resolvedSendableCount = 0;

        $query
            ->orderBy('marketing_profiles.id')
            ->chunkById(300, function (Collection $profiles) use (
                $channel,
                $tenantId,
                $includeRecipients,
                &$profileIds,
                &$forceSendProfileIds,
                &$recipients,
                &$effectiveConsentCount,
                &$resolvedSendableCount
            ): void {
                $chunkIds = $profiles
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->values()
                    ->all();

                if ($chunkIds === []) {
                    return;
                }

                $signals = $this->consentSignalsForProfiles($chunkIds, $channel, $tenantId);

                foreach ($profiles as $profile) {
                    $profileId = (int) ($profile->id ?? 0);
                    if ($profileId <= 0) {
                        continue;
                    }

                    $canonicalConsent = $this->hasCanonicalConsent($profile, $channel);
                    $signal = (array) ($signals[$profileId] ?? []);
                    $hasLegacyImport = (bool) ($signal['has_legacy_import'] ?? false);
                    $latestEventType = strtolower(trim((string) ($signal['latest_event_type'] ?? '')));

                    $effectiveConsent = $canonicalConsent
                        || ($hasLegacyImport && ! in_array($latestEventType, ['opted_out', 'revoked'], true));
                    if (! $effectiveConsent) {
                        continue;
                    }

                    $effectiveConsentCount++;

                    $sendableContact = $channel === 'sms'
                        ? $this->sendableSmsPhoneWithEffectiveConsent($profile, null, true)
                        : $this->sendableEmailWithEffectiveConsent($profile, null, true);
                    if ($sendableContact === null) {
                        continue;
                    }

                    $resolvedSendableCount++;
                    $profileIds[] = $profileId;

                    if (! $canonicalConsent) {
                        $forceSendProfileIds[] = $profileId;
                    }

                    if ($includeRecipients) {
                        $recipients[] = $this->profileRecipient($profile);
                    }
                }
            });

        $resolved = [
            'profile_ids' => array_values(array_unique(array_map('intval', $profileIds))),
            'recipients' => $includeRecipients ? array_values($recipients) : [],
            'force_send_profile_ids' => array_values(array_unique(array_map('intval', $forceSendProfileIds))),
            'candidate_count' => $candidateCount,
            'effective_consent_count' => $effectiveConsentCount,
            'resolved_sendable_count' => $resolvedSendableCount,
        ];

        $this->channelAudienceCache[$cacheKey] = $resolved;

        return $resolved;
    }

    /**
     * @param  array<int,int>  $profileIds
     * @return Collection<int,MarketingProfile>
     */
    protected function tenantScopedProfiles(?int $tenantId, array $profileIds): Collection
    {
        $resolvedIds = collect($profileIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($resolvedIds->isEmpty()) {
            return collect();
        }

        return MarketingProfile::query()
            ->whereIn('id', $resolvedIds->all())
            ->when($tenantId !== null, fn (Builder $query) => $query->forTenantId($tenantId))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get([
                'id',
                'tenant_id',
                'first_name',
                'last_name',
                'email',
                'normalized_email',
                'phone',
                'normalized_phone',
                'accepts_sms_marketing',
                'accepts_email_marketing',
            ]);
    }

    /**
     * @return array<int,array{
     *   profile_id:?int,
     *   name:?string,
     *   email:?string,
     *   phone:?string,
     *   normalized_phone:?string,
     *   source_type:string
     * }>
     */
    protected function savedGroupRecipients(MarketingMessageGroup $group, ?int $tenantId): array
    {
        return $group->members
            ->map(function (MarketingMessageGroupMember $member) use ($tenantId): ?array {
                if ($member->profile instanceof MarketingProfile) {
                    if ($tenantId !== null && (int) ($member->profile->tenant_id ?? 0) !== $tenantId) {
                        return null;
                    }

                    return $this->profileRecipient($member->profile);
                }

                return [
                    'profile_id' => null,
                    'name' => $this->nullableString($member->full_name),
                    'email' => $this->nullableString($member->email),
                    'phone' => $this->nullableString($member->phone),
                    'normalized_phone' => $this->nullableString($member->normalized_phone),
                    'source_type' => 'group_manual',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int,array{
     *   profile_id:?int,
     *   name:?string,
     *   email:?string,
     *   phone:?string,
     *   normalized_phone:?string,
     *   source_type:string
     * }>
     */
    protected function allSubscribedRecipients(?int $tenantId, string $channel): array
    {
        $audience = $this->resolvedChannelAudience($tenantId, $channel, includeRecipients: true);

        return array_values((array) ($audience['recipients'] ?? []));
    }

    protected function groupPayload(MarketingMessageGroup $group, ?int $tenantId): array
    {
        $members = $group->members
            ->map(function (MarketingMessageGroupMember $member) use ($tenantId): ?array {
                $profile = $member->profile;
                if ($profile instanceof MarketingProfile && $tenantId !== null && (int) ($profile->tenant_id ?? 0) !== $tenantId) {
                    return null;
                }

                $name = $profile instanceof MarketingProfile
                    ? $this->profileDisplayName($profile)
                    : ($this->nullableString($member->full_name) ?? 'Customer');

                $email = $profile instanceof MarketingProfile
                    ? $this->nullableString($profile->email)
                    : $this->nullableString($member->email);

                $phone = $profile instanceof MarketingProfile
                    ? $this->nullableString($profile->phone ?: $profile->normalized_phone)
                    : $this->nullableString($member->phone ?: $member->normalized_phone);

                $acceptsSms = $profile instanceof MarketingProfile ? (bool) ($profile->accepts_sms_marketing ?? false) : false;
                $acceptsEmail = $profile instanceof MarketingProfile ? (bool) ($profile->accepts_email_marketing ?? false) : false;

                return [
                    'id' => (int) $member->id,
                    'profile_id' => $profile instanceof MarketingProfile ? (int) $profile->id : null,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'accepts_sms_marketing' => $acceptsSms,
                    'accepts_email_marketing' => $acceptsEmail,
                    'sms_contactable' => $this->sendableSmsPhoneFromValues(
                        $phone,
                        $profile?->normalized_phone,
                        $acceptsSms
                    ) !== null,
                    'email_contactable' => $this->sendableEmailFromValues(
                        $email,
                        $profile?->normalized_email,
                        $acceptsEmail
                    ) !== null,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'type' => 'saved',
            'id' => (int) $group->id,
            'name' => (string) $group->name,
            'description' => $this->nullableString($group->description),
            'channel' => $this->normalizedGroupChannel((string) $group->channel),
            'members_count' => count($members),
            'last_used_at' => optional($group->last_used_at)->toIso8601String(),
            'updated_at' => optional($group->updated_at)->toIso8601String(),
            'members' => $members,
        ];
    }

    protected function tenantScopedGroupQuery(?int $tenantId): Builder
    {
        $query = MarketingMessageGroup::query();

        if (! Schema::hasColumn('marketing_message_groups', 'tenant_id')) {
            return $query->whereRaw('1 = 0');
        }

        if ($tenantId === null) {
            return $query->whereNull('tenant_id');
        }

        return $query->where('tenant_id', $tenantId);
    }

    /**
     * @return array{
     *   profile_id:int,
     *   name:?string,
     *   email:?string,
     *   phone:?string,
     *   normalized_phone:?string,
     *   source_type:string
     * }
     */
    protected function profileRecipient(MarketingProfile $profile): array
    {
        return [
            'profile_id' => (int) $profile->id,
            'name' => $this->nullableString($this->profileDisplayName($profile)),
            'email' => $this->nullableString($profile->email),
            'phone' => $this->nullableString($profile->phone ?: $profile->normalized_phone),
            'normalized_phone' => $this->nullableString($profile->normalized_phone),
            'source_type' => 'profile',
        ];
    }

    protected function profileDisplayName(MarketingProfile $profile): string
    {
        $displayName = trim((string) ($profile->first_name . ' ' . $profile->last_name));
        if ($displayName !== '') {
            return $displayName;
        }

        return trim((string) ($profile->email ?: ($profile->phone ?: ('Customer #' . $profile->id))));
    }

    protected function normalizedChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if (! in_array($channel, ['sms', 'email'], true)) {
            throw ValidationException::withMessages([
                'channel' => 'Channel must be SMS or email.',
            ]);
        }

        return $channel;
    }

    protected function normalizedGroupChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));

        return in_array($channel, ['sms', 'email', 'multi'], true) ? $channel : 'multi';
    }

    /**
     * @param  array<int,int>  $profileIds
     * @return array<int,array{has_legacy_import:bool,latest_event_type:?string}>
     */
    protected function consentSignalsForProfiles(array $profileIds, string $channel, ?int $tenantId): array
    {
        $resolvedIds = collect($profileIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($resolvedIds === []) {
            return [];
        }

        $eventsQuery = MarketingConsentEvent::query()
            ->select([
                'id',
                'marketing_profile_id',
                'event_type',
                'source_type',
                'occurred_at',
            ])
            ->whereIn('marketing_profile_id', $resolvedIds)
            ->where('channel', $channel)
            ->orderBy('marketing_profile_id')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if (Schema::hasColumn('marketing_consent_events', 'tenant_id')) {
            if ($tenantId === null) {
                $eventsQuery->whereNull('tenant_id');
            } else {
                $eventsQuery->where('tenant_id', $tenantId);
            }
        }

        $signals = [];
        foreach ($eventsQuery->get() as $event) {
            $profileId = (int) ($event->marketing_profile_id ?? 0);
            if ($profileId <= 0) {
                continue;
            }

            if (! isset($signals[$profileId])) {
                $signals[$profileId] = [
                    'has_legacy_import' => false,
                    'latest_event_type' => strtolower(trim((string) ($event->event_type ?? ''))),
                ];
            }

            $eventType = strtolower(trim((string) ($event->event_type ?? '')));
            $sourceType = strtolower(trim((string) ($event->source_type ?? '')));
            if ($eventType === 'imported' && in_array($sourceType, self::LEGACY_CONSENT_SOURCE_TYPES, true)) {
                $signals[$profileId]['has_legacy_import'] = true;
            }
        }

        return $signals;
    }

    protected function hasCanonicalConsent(MarketingProfile $profile, string $channel): bool
    {
        return $channel === 'sms'
            ? (bool) ($profile->accepts_sms_marketing ?? false)
            : (bool) ($profile->accepts_email_marketing ?? false);
    }

    protected function profileHasEffectiveLegacyConsent(MarketingProfile $profile, string $channel, ?int $tenantId): bool
    {
        if ($this->hasCanonicalConsent($profile, $channel)) {
            return true;
        }

        $signals = $this->consentSignalsForProfiles([(int) $profile->id], $channel, $tenantId);
        $signal = (array) ($signals[(int) $profile->id] ?? []);
        $hasLegacyImport = (bool) ($signal['has_legacy_import'] ?? false);
        if (! $hasLegacyImport) {
            return false;
        }

        $latestEventType = strtolower(trim((string) ($signal['latest_event_type'] ?? '')));

        return ! in_array($latestEventType, ['opted_out', 'revoked'], true);
    }

    protected function assertContactMethodAvailable(MarketingProfile $profile, string $channel, ?int $tenantId = null): void
    {
        if ($channel === 'sms' && $this->sendableSmsPhoneWithEffectiveConsent($profile, null, $this->profileHasEffectiveLegacyConsent($profile, 'sms', $tenantId)) === null) {
            throw ValidationException::withMessages([
                'profile_id' => 'The selected customer does not have an SMS-contactable profile (consent + phone).',
            ]);
        }

        if ($channel === 'email' && $this->sendableEmailWithEffectiveConsent($profile, null, $this->profileHasEffectiveLegacyConsent($profile, 'email', $tenantId)) === null) {
            throw ValidationException::withMessages([
                'profile_id' => 'The selected customer does not have an email-contactable profile (consent + email).',
            ]);
        }
    }

    protected function sendableSmsPhone(MarketingProfile $profile): ?string
    {
        return $this->sendableSmsPhoneFromValues(
            $profile->phone,
            $profile->normalized_phone,
            (bool) ($profile->accepts_sms_marketing ?? false)
        );
    }

    protected function sendableEmailAddress(MarketingProfile $profile): ?string
    {
        return $this->sendableEmailWithEffectiveConsent(
            $profile->email,
            $profile->normalized_email,
            (bool) ($profile->accepts_email_marketing ?? false)
        );
    }

    protected function sendableSmsPhoneWithEffectiveConsent(mixed $phone, mixed $normalizedPhone = null, bool $consented = false): ?string
    {
        if (! $consented) {
            return null;
        }

        if ($phone instanceof MarketingProfile) {
            return $this->identityNormalizer->toE164((string) ($phone->normalized_phone ?: $phone->phone));
        }

        return $this->identityNormalizer->toE164((string) ($normalizedPhone ?: $phone));
    }

    protected function sendableEmailWithEffectiveConsent(mixed $email, mixed $normalizedEmail = null, bool $consented = false): ?string
    {
        if (! $consented) {
            return null;
        }

        if ($email instanceof MarketingProfile) {
            return $this->identityNormalizer->normalizeEmail((string) ($email->normalized_email ?: $email->email));
        }

        return $this->identityNormalizer->normalizeEmail((string) ($normalizedEmail ?: $email));
    }

    protected function sendableSmsPhoneFromValues(mixed $phone, mixed $normalizedPhone, bool $consented): ?string
    {
        return $this->sendableSmsPhoneWithEffectiveConsent($phone, $normalizedPhone, $consented);
    }

    protected function sendableEmailFromValues(mixed $email, mixed $normalizedEmail, bool $consented): ?string
    {
        return $this->sendableEmailWithEffectiveConsent($email, $normalizedEmail, $consented);
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }
}
