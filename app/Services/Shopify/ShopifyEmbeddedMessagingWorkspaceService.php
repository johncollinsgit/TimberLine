<?php

namespace App\Services\Shopify;

use App\Models\MarketingEmailDelivery;
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
    public function groups(?int $tenantId): array
    {
        $savedGroups = $this->tenantScopedGroupQuery($tenantId)
            ->where('is_system', false)
            ->withCount('members')
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

        $summary = $this->allSubscribedSummary($tenantId);
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
        $this->assertContactMethodAvailable($profile, $channel);

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
            if ($recipients === []) {
                throw ValidationException::withMessages([
                    'group_id' => 'This group has no members that can be evaluated for messaging.',
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
                    'group_id' => (int) $group->id,
                    'source_label' => 'shopify_embedded_messaging_group',
                ]
            );

            return [
                'summary' => $summary,
                'target' => [
                    'type' => 'saved',
                    'id' => (int) $group->id,
                    'name' => (string) $group->name,
                ],
            ];
        }

        $normalizedGroupKey = strtolower(trim((string) $groupKey));
        if ($normalizedGroupKey !== self::AUTO_GROUP_ALL_SUBSCRIBED) {
            throw ValidationException::withMessages([
                'group_key' => 'Unsupported automatic group selection.',
            ]);
        }

        $recipients = $this->allSubscribedRecipients($tenantId, $channel);
        if ($recipients === []) {
            throw ValidationException::withMessages([
                'group_key' => 'No subscribed recipients are currently eligible for this channel.',
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
                'source_label' => 'shopify_embedded_messaging_auto_group',
            ]
        );

        return [
            'summary' => $summary,
            'target' => [
                'type' => 'auto',
                'key' => self::AUTO_GROUP_ALL_SUBSCRIBED,
                'name' => 'All Subscribed',
            ],
        ];
    }

    /**
     * @return array{sms:int,email:int,overlap:int,unique:int}
     */
    public function allSubscribedSummary(?int $tenantId): array
    {
        $summary = [
            'sms' => 0,
            'email' => 0,
            'overlap' => 0,
            'unique' => 0,
        ];

        MarketingProfile::query()
            ->when($tenantId !== null, fn (Builder $query) => $query->forTenantId($tenantId))
            ->select([
                'id',
                'email',
                'normalized_email',
                'phone',
                'normalized_phone',
                'accepts_sms_marketing',
                'accepts_email_marketing',
            ])
            ->orderBy('id')
            ->chunkById(500, function (Collection $profiles) use (&$summary): void {
                foreach ($profiles as $profile) {
                    $smsEligible = $this->sendableSmsPhone($profile) !== null;
                    $emailEligible = $this->sendableEmailAddress($profile) !== null;

                    if ($smsEligible) {
                        $summary['sms']++;
                    }
                    if ($emailEligible) {
                        $summary['email']++;
                    }
                    if ($smsEligible || $emailEligible) {
                        $summary['unique']++;
                    }
                    if ($smsEligible && $emailEligible) {
                        $summary['overlap']++;
                    }
                }
            });

        return $summary;
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
        $profiles = MarketingProfile::query()
            ->when($tenantId !== null, fn (Builder $query) => $query->forTenantId($tenantId))
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
            ->orderBy('id')
            ->get();

        return $profiles
            ->filter(function (MarketingProfile $profile) use ($channel): bool {
                return $channel === 'sms'
                    ? $this->sendableSmsPhone($profile) !== null
                    : $this->sendableEmailAddress($profile) !== null;
            })
            ->map(fn (MarketingProfile $profile): array => $this->profileRecipient($profile))
            ->values()
            ->all();
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

    protected function assertContactMethodAvailable(MarketingProfile $profile, string $channel): void
    {
        if ($channel === 'sms' && $this->sendableSmsPhone($profile) === null) {
            throw ValidationException::withMessages([
                'profile_id' => 'The selected customer does not have an SMS-contactable profile (consent + phone).',
            ]);
        }

        if ($channel === 'email' && $this->sendableEmailAddress($profile) === null) {
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
        return $this->sendableEmailFromValues(
            $profile->email,
            $profile->normalized_email,
            (bool) ($profile->accepts_email_marketing ?? false)
        );
    }

    protected function sendableSmsPhoneFromValues(mixed $phone, mixed $normalizedPhone, bool $consented): ?string
    {
        if (! $consented) {
            return null;
        }

        return $this->identityNormalizer->toE164((string) ($normalizedPhone ?: $phone));
    }

    protected function sendableEmailFromValues(mixed $email, mixed $normalizedEmail, bool $consented): ?string
    {
        if (! $consented) {
            return null;
        }

        return $this->identityNormalizer->normalizeEmail((string) ($normalizedEmail ?: $email));
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
