<?php

namespace App\Services\Shopify;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageGroup;
use App\Models\MarketingMessageGroupMember;
use App\Models\MarketingMessageJob;
use App\Models\MarketingProfile;
use App\Models\MarketingTemplateDefinition;
use App\Models\Tenant;
use App\Services\Marketing\EmbeddedMessagingCampaignDispatchService;
use App\Services\Marketing\MarketingDirectMessagingService;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ShopifyEmbeddedMessagingWorkspaceService
{
    public const AUTO_GROUP_ALL_SUBSCRIBED = 'all_subscribed';
    public const AUTO_GROUP_LEGACY_SMS_SUBSCRIBED = 'legacy_sms_subscribed';
    public const AUTO_GROUP_LEGACY_EMAIL_SUBSCRIBED = 'legacy_email_subscribed';

    protected const MODERN_FORESTRY_SLUG = 'modern-forestry';
    protected const AUDIENCE_SCOPE_EFFECTIVE = 'effective';
    protected const AUDIENCE_SCOPE_LEGACY_IMPORTED = 'legacy_imported';
    protected const AUDIENCE_SUMMARY_CACHE_MINUTES = 10;

    /**
     * @var array<int,array<string,mixed>>
     */
    protected const DEFAULT_EMAIL_TEMPLATES = [
        [
            'key' => 'announcement',
            'name' => 'Announcement',
            'description' => 'Clean launch/update announcement layout.',
            'default_subject' => 'A quick update from Forestry Backstage',
            'default_sections' => [
                ['id' => 'heading_1', 'type' => 'heading', 'text' => 'Big news', 'align' => 'left'],
                ['id' => 'body_1', 'type' => 'text', 'html' => 'Share your update in two or three concise paragraphs.'],
                ['id' => 'button_1', 'type' => 'button', 'label' => 'Read more', 'href' => '', 'align' => 'left'],
            ],
        ],
        [
            'key' => 'product_spotlight',
            'name' => 'Product spotlight',
            'description' => 'Single product feature with focused CTA.',
            'default_subject' => 'Product spotlight',
            'default_sections' => [
                ['id' => 'heading_1', 'type' => 'heading', 'text' => 'Featured right now', 'align' => 'left'],
                ['id' => 'product_1', 'type' => 'product', 'productId' => '', 'title' => '', 'imageUrl' => '', 'price' => '', 'href' => '', 'buttonLabel' => 'Shop now'],
                ['id' => 'body_1', 'type' => 'text', 'html' => 'Add one supporting paragraph with key details.'],
            ],
        ],
        [
            'key' => 'event_update',
            'name' => 'Event/update',
            'description' => 'Simple event or operations update.',
            'default_subject' => 'Upcoming event update',
            'default_sections' => [
                ['id' => 'heading_1', 'type' => 'heading', 'text' => 'Upcoming event', 'align' => 'left'],
                ['id' => 'body_1', 'type' => 'text', 'html' => 'Share the key details: when, where, and what to expect.'],
                ['id' => 'button_1', 'type' => 'button', 'label' => 'View details', 'href' => '', 'align' => 'left'],
            ],
        ],
        [
            'key' => 'photo_cta',
            'name' => 'Photo + CTA',
            'description' => 'Image-first message with a focused next step.',
            'default_subject' => 'See what is new',
            'default_sections' => [
                ['id' => 'image_1', 'type' => 'image', 'imageUrl' => '', 'alt' => 'Feature image', 'href' => '', 'padding' => '0 0 12px 0'],
                ['id' => 'heading_1', 'type' => 'heading', 'text' => 'A quick look', 'align' => 'left'],
                ['id' => 'button_1', 'type' => 'button', 'label' => 'Open', 'href' => '', 'align' => 'left'],
            ],
        ],
        [
            'key' => 'merch_grid_4',
            'name' => '4-product merch grid',
            'description' => 'Premium merchandising layout with a 4-up product block.',
            'default_subject' => 'Shop what is new',
            'default_sections' => [
                ['id' => 'heading_1', 'type' => 'heading', 'text' => 'Fresh picks for you', 'align' => 'left'],
                ['id' => 'divider_1', 'type' => 'fading_divider', 'spacingTop' => 8, 'spacingBottom' => 16],
                ['id' => 'grid_1', 'type' => 'product_grid_4', 'heading' => 'Featured now', 'products' => []],
                ['id' => 'button_1', 'type' => 'button', 'label' => 'Shop all', 'href' => '', 'align' => 'left'],
            ],
        ],
        [
            'key' => 'minimal_plain',
            'name' => 'Minimal / plain',
            'description' => 'Low-friction plain style note.',
            'default_subject' => 'Quick note',
            'default_sections' => [
                ['id' => 'body_1', 'type' => 'text', 'html' => 'Keep this short and conversational.'],
            ],
        ],
    ];

    /**
     * @var array<int,string>
     */
    protected const LEGACY_CONSENT_SOURCE_TYPES = [
        'yotpo_contacts_import',
        'square_marketing_import',
        'square_customer_sync',
        'legacy_import_reconciliation',
        'growave_marketing_reconciliation_sync',
    ];

    /**
     * @var array<string,array<string,mixed>>
     */
    protected array $channelAudienceCache = [];

    /**
     * @var array<int,bool>
     */
    protected array $modernForestryTenantCache = [];

    public function __construct(
        protected ShopifyEmbeddedCustomersGridService $customersGridService,
        protected MarketingDirectMessagingService $directMessagingService,
        protected MarketingIdentityNormalizer $identityNormalizer,
        protected ShopifyEmbeddedEmailComposerService $emailComposerService,
        protected EmbeddedMessagingCampaignDispatchService $campaignDispatchService
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
     * @param  array<string,mixed>  $storeContext
     * @return array<int,array{
     *   id:string,
     *   gid:string,
     *   title:string,
     *   image_url:?string,
     *   price:?string,
     *   url:?string
     * }>
     */
    public function searchProducts(string $query, array $storeContext, int $limit = 12): array
    {
        $search = trim($query);
        if ($search === '') {
            return [];
        }

        $shopDomain = trim((string) ($storeContext['shop'] ?? ''));
        $token = trim((string) ($storeContext['token'] ?? ''));
        $apiVersion = trim((string) ($storeContext['api_version'] ?? '2026-01'));

        if ($shopDomain === '' || $token === '') {
            throw ValidationException::withMessages([
                'q' => 'Shopify product lookup is unavailable for this store session.',
            ]);
        }

        $client = new ShopifyGraphqlClient($shopDomain, $token, $apiVersion !== '' ? $apiVersion : '2026-01');
        $graphql = <<<'GRAPHQL'
query EmbeddedMessagingProducts($query: String!, $first: Int!) {
  products(first: $first, query: $query, sortKey: UPDATED_AT, reverse: true) {
    edges {
      node {
        id
        title
        handle
        onlineStoreUrl
        featuredImage {
          url
          altText
        }
        priceRangeV2 {
          minVariantPrice {
            amount
            currencyCode
          }
        }
      }
    }
  }
}
GRAPHQL;

        try {
            $data = $client->query($graphql, [
                'query' => $search,
                'first' => max(1, min($limit, 20)),
            ]);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'q' => 'Shopify product lookup failed. Confirm read_products scope is enabled.',
            ]);
        }

        $edges = (array) data_get($data, 'products.edges', []);

        return collect($edges)
            ->map(function (mixed $edge) use ($shopDomain): ?array {
                $node = is_array($edge) ? (array) ($edge['node'] ?? []) : [];
                $gid = trim((string) ($node['id'] ?? ''));
                if ($gid === '') {
                    return null;
                }

                $title = trim((string) ($node['title'] ?? ''));
                $handle = trim((string) ($node['handle'] ?? ''));
                $onlineStoreUrl = $this->nullableString($node['onlineStoreUrl'] ?? null);
                $fallbackUrl = $handle !== '' ? ('https://' . rtrim($shopDomain, '/') . '/products/' . ltrim($handle, '/')) : null;
                $imageUrl = $this->nullableString(data_get($node, 'featuredImage.url'));
                $priceAmount = $this->nullableString(data_get($node, 'priceRangeV2.minVariantPrice.amount'));
                $priceCurrency = $this->nullableString(data_get($node, 'priceRangeV2.minVariantPrice.currencyCode'));

                return [
                    'id' => $this->numericShopifyIdFromGid($gid) ?? $gid,
                    'gid' => $gid,
                    'title' => $title !== '' ? $title : 'Shopify product',
                    'image_url' => $imageUrl,
                    'price' => $this->formatMoneyAmount($priceAmount, $priceCurrency),
                    'url' => $onlineStoreUrl ?? $fallbackUrl,
                ];
            })
            ->filter(fn (?array $row): bool => is_array($row))
            ->values()
            ->all();
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

        $groupSummaries = $includeAutoCounts ? $this->autoGroupSummaries($tenantId) : [];
        $autoGroups = $this->autoGroupDefinitions($tenantId)
            ->map(function (array $definition) use ($includeAutoCounts, $groupSummaries): array {
                $key = (string) ($definition['key'] ?? '');
                $channels = array_values(array_filter(
                    array_map(
                        static fn ($channel): string => strtolower(trim((string) $channel)),
                        (array) ($definition['channels'] ?? [])
                    ),
                    static fn (string $channel): bool => in_array($channel, ['sms', 'email'], true)
                ));

                return [
                    'type' => 'auto',
                    'key' => $key,
                    'name' => (string) ($definition['name'] ?? 'Automatic Audience'),
                    'description' => (string) ($definition['description'] ?? ''),
                    'channels' => $channels,
                    'counts' => $includeAutoCounts
                        ? (array) ($groupSummaries[$key] ?? ['sms' => 0, 'email' => 0, 'overlap' => 0, 'unique' => 0])
                        : null,
                ];
            })
            ->values()
            ->all();

        return [
            'saved' => $savedGroups,
            'auto' => $autoGroups,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function emailTemplateDefinitions(): array
    {
        $definitions = MarketingTemplateDefinition::query()
            ->where('channel', 'email')
            ->where('is_active', true)
            ->orderBy('id')
            ->get([
                'id',
                'template_key',
                'name',
                'description',
                'default_subject',
                'default_sections',
                'thumbnail_svg',
            ])
            ->map(function (MarketingTemplateDefinition $template): array {
                return [
                    'id' => (int) $template->id,
                    'key' => (string) $template->template_key,
                    'name' => (string) $template->name,
                    'description' => $this->nullableString($template->description),
                    'default_subject' => $this->nullableString($template->default_subject),
                    'default_sections' => is_array($template->default_sections) ? $template->default_sections : [],
                    'thumbnail_svg' => $this->nullableString($template->thumbnail_svg),
                ];
            })
            ->values()
            ->all();

        if ($definitions !== []) {
            $fallbackByKey = collect(self::DEFAULT_EMAIL_TEMPLATES)
                ->keyBy(fn (array $template): string => (string) ($template['key'] ?? ''));
            $existingKeys = collect($definitions)->pluck('key')->filter()->all();

            $missingDefaults = $fallbackByKey
                ->reject(fn (array $template, string $key): bool => in_array($key, $existingKeys, true))
                ->values()
                ->map(function (array $template, int $index): array {
                    return [
                        'id' => 10000 + $index,
                        'key' => (string) ($template['key'] ?? ('template_' . ($index + 1))),
                        'name' => (string) ($template['name'] ?? 'Email template'),
                        'description' => $this->nullableString($template['description'] ?? null),
                        'default_subject' => $this->nullableString($template['default_subject'] ?? null),
                        'default_sections' => is_array($template['default_sections'] ?? null) ? $template['default_sections'] : [],
                        'thumbnail_svg' => null,
                    ];
                })
                ->all();

            return [...$definitions, ...$missingDefaults];
        }

        return collect(self::DEFAULT_EMAIL_TEMPLATES)
            ->map(function (array $template, int $index): array {
                return [
                    'id' => $index + 1,
                    'key' => (string) ($template['key'] ?? ('template_' . ($index + 1))),
                    'name' => (string) ($template['name'] ?? 'Email template'),
                    'description' => $this->nullableString($template['description'] ?? null),
                    'default_subject' => $this->nullableString($template['default_subject'] ?? null),
                    'default_sections' => is_array($template['default_sections'] ?? null) ? $template['default_sections'] : [],
                    'thumbnail_svg' => null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   summary:array{sms:int,email:int,overlap:int,unique:int},
     *   group_summaries:array<string,array{sms:int,email:int,overlap:int,unique:int}>,
     *   diagnostics:array<string,mixed>
     * }
     */
    public function audienceSummary(?int $tenantId): array
    {
        $cacheKey = 'shopify_embedded_messaging:audience_summary:v1:' . (string) ($tenantId ?? 'none');

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(self::AUDIENCE_SUMMARY_CACHE_MINUTES),
            fn (): array => $this->computeAudienceSummary($tenantId)
        );
    }

    /**
     * @return array{
     *   summary:array{sms:int,email:int,overlap:int,unique:int},
     *   group_summaries:array<string,array{sms:int,email:int,overlap:int,unique:int}>,
     *   diagnostics:array<string,mixed>
     * }
     */
    protected function computeAudienceSummary(?int $tenantId): array
    {
        $sms = $this->resolvedChannelAudience(
            $tenantId,
            'sms',
            includeRecipients: false,
            scope: self::AUDIENCE_SCOPE_EFFECTIVE
        );
        $email = $this->resolvedChannelAudience(
            $tenantId,
            'email',
            includeRecipients: false,
            scope: self::AUDIENCE_SCOPE_EFFECTIVE
        );

        $smsIds = $this->normalizedAudienceProfileIds($sms);
        $emailIds = $this->normalizedAudienceProfileIds($email);
        $summary = $this->audienceSummaryFromIds($smsIds, $emailIds);
        $groupSummaries = [
            self::AUTO_GROUP_ALL_SUBSCRIBED => $summary,
        ];
        $diagnostics = [
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
        ];

        if ($this->isModernForestryTenant($tenantId)) {
            $legacySms = $this->resolvedChannelAudience(
                $tenantId,
                'sms',
                includeRecipients: false,
                scope: self::AUDIENCE_SCOPE_LEGACY_IMPORTED
            );
            $legacyEmail = $this->resolvedChannelAudience(
                $tenantId,
                'email',
                includeRecipients: false,
                scope: self::AUDIENCE_SCOPE_LEGACY_IMPORTED
            );

            $legacySmsIds = $this->normalizedAudienceProfileIds($legacySms);
            $legacyEmailIds = $this->normalizedAudienceProfileIds($legacyEmail);

            $groupSummaries[self::AUTO_GROUP_LEGACY_SMS_SUBSCRIBED] = [
                'sms' => $legacySmsIds->count(),
                'email' => 0,
                'overlap' => 0,
                'unique' => $legacySmsIds->count(),
            ];
            $groupSummaries[self::AUTO_GROUP_LEGACY_EMAIL_SUBSCRIBED] = [
                'sms' => 0,
                'email' => $legacyEmailIds->count(),
                'overlap' => 0,
                'unique' => $legacyEmailIds->count(),
            ];

            $diagnostics[self::AUTO_GROUP_LEGACY_SMS_SUBSCRIBED] = [
                'displayed_audience_count' => $legacySmsIds->count(),
                'query_candidate_count' => (int) ($legacySms['candidate_count'] ?? 0),
                'effective_consent_count' => (int) ($legacySms['effective_consent_count'] ?? 0),
                'resolved_sendable_count' => (int) ($legacySms['resolved_sendable_count'] ?? 0),
            ];
            $diagnostics[self::AUTO_GROUP_LEGACY_EMAIL_SUBSCRIBED] = [
                'displayed_audience_count' => $legacyEmailIds->count(),
                'query_candidate_count' => (int) ($legacyEmail['candidate_count'] ?? 0),
                'effective_consent_count' => (int) ($legacyEmail['effective_consent_count'] ?? 0),
                'resolved_sendable_count' => (int) ($legacyEmail['resolved_sendable_count'] ?? 0),
            ];
        }

        return [
            'summary' => $summary,
            'group_summaries' => $groupSummaries,
            'diagnostics' => $diagnostics,
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
        ?int $actorId,
        ?string $storeKey = null,
        ?string $emailTemplateMode = null,
        mixed $emailSections = null,
        ?string $emailAdvancedHtml = null
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

        $emailTemplate = null;
        $htmlBody = null;
        if ($channel === 'email') {
            $composed = $this->emailComposerService->compose(
                subject: (string) ($subject ?? ''),
                body: trim($body),
                mode: $emailTemplateMode,
                sections: $emailSections,
                legacyHtml: $emailAdvancedHtml
            );
            $emailTemplate = [
                'mode' => (string) ($composed['mode'] ?? ShopifyEmbeddedEmailComposerService::MODE_SECTIONS),
                'sections' => is_array($composed['sections'] ?? null) ? $composed['sections'] : [],
                'legacy_html' => $this->nullableString($composed['legacy_html'] ?? null),
            ];
            $htmlBody = $this->nullableString($composed['html'] ?? null);
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
                'store_key' => $this->nullableString($storeKey),
                'source_label' => 'shopify_embedded_messaging_individual',
                'force_send_profile_ids' => $forceSendProfileIds,
                'html_body' => $htmlBody,
                'email_template' => $emailTemplate,
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
        ?int $actorId,
        ?string $storeKey = null,
        ?string $emailTemplateMode = null,
        ?string $emailTemplateKey = null,
        mixed $emailSections = null,
        ?string $emailAdvancedHtml = null,
        mixed $scheduleFor = null,
        bool $shortenLinks = false
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

        $emailTemplate = null;
        $htmlBody = null;
        if ($channel === 'email') {
            $composed = $this->emailComposerService->compose(
                subject: (string) ($subject ?? ''),
                body: trim($body),
                mode: $emailTemplateMode,
                sections: $emailSections,
                legacyHtml: $emailAdvancedHtml
            );
            $emailTemplate = [
                'mode' => (string) ($composed['mode'] ?? ShopifyEmbeddedEmailComposerService::MODE_SECTIONS),
                'template_key' => $this->nullableString($emailTemplateKey),
                'sections' => is_array($composed['sections'] ?? null) ? $composed['sections'] : [],
                'legacy_html' => $this->nullableString($composed['legacy_html'] ?? null),
            ];
            $htmlBody = $this->nullableString($composed['html'] ?? null);
        }

        $queued = $this->campaignDispatchService->queueCampaign(
            tenantId: $tenantId,
            storeKey: $this->nullableString($storeKey),
            channel: $channel,
            target: (array) ($resolvedTarget['target'] ?? []),
            recipients: $recipients,
            body: trim($body),
            subject: $subject,
            senderKey: $senderKey,
            actorId: $actorId,
            sourceLabel: (string) ($resolvedTarget['source_label'] ?? 'shopify_embedded_messaging_group'),
            forceSendProfileIds: array_values(array_unique(array_map(
                'intval',
                (array) ($resolvedTarget['force_send_profile_ids'] ?? [])
            ))),
            emailTemplate: $emailTemplate,
            htmlBody: $htmlBody,
            scheduleFor: $scheduleFor,
            shortenLinks: $shortenLinks
        );

        $summary = (array) ($queued['summary'] ?? []);
        $summary = [
            'processed' => (int) ($summary['processed'] ?? count($recipients)),
            'sent' => 0,
            'failed' => 0,
            'skipped' => (int) ($summary['skipped'] ?? 0),
            'queued' => (int) ($summary['scheduled'] ?? 0),
            'dry_run' => 0,
            'campaign_id' => (int) ($summary['campaign_id'] ?? 0),
            'schedule_for' => $summary['schedule_for'] ?? null,
        ];

        return [
            'summary' => $summary,
            'target' => (array) ($resolvedTarget['target'] ?? []),
            'campaign' => (array) ($queued['campaign'] ?? []),
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
        ?string $subject,
        ?string $emailTemplateMode = null,
        ?string $emailTemplateKey = null,
        mixed $emailSections = null,
        ?string $emailAdvancedHtml = null
    ): array {
        $channel = $this->normalizedChannel($channel);
        $resolvedTarget = $this->resolveGroupTarget($tenantId, $targetType, $groupId, $groupKey, $channel);
        $recipients = (array) ($resolvedTarget['recipients'] ?? []);
        $emailTemplate = null;
        $emailHtml = null;

        if ($channel === 'email') {
            $composed = $this->emailComposerService->compose(
                subject: (string) ($subject ?? ''),
                body: trim($body),
                mode: $emailTemplateMode,
                sections: $emailSections,
                legacyHtml: $emailAdvancedHtml
            );
            $emailTemplate = [
                'mode' => (string) ($composed['mode'] ?? ShopifyEmbeddedEmailComposerService::MODE_SECTIONS),
                'template_key' => $this->nullableString($emailTemplateKey),
                'sections' => is_array($composed['sections'] ?? null) ? $composed['sections'] : [],
                'legacy_html' => $this->nullableString($composed['legacy_html'] ?? null),
            ];
            $emailHtml = $this->nullableString($composed['html'] ?? null);
        }

        return [
            'target' => (array) ($resolvedTarget['target'] ?? []),
            'channel' => $channel,
            'subject' => $channel === 'email' ? $this->nullableString($subject) : null,
            'body' => trim($body),
            'message_preview' => Str::limit(trim($body), 280),
            'email_template' => $emailTemplate,
            'email_html' => $emailHtml,
            'estimated_recipients' => count($recipients),
            'query_candidate_count' => (int) ($resolvedTarget['query_candidate_count'] ?? 0),
            'resolved_sendable_count' => (int) ($resolvedTarget['resolved_sendable_count'] ?? count($recipients)),
            'force_send_profile_ids_count' => count((array) ($resolvedTarget['force_send_profile_ids'] ?? [])),
        ];
    }

    /**
     * @param  array<int,string>  $testNumbers
     * @return array{
     *   summary:array<string,mixed>,
     *   deliveries:array<int,array<string,mixed>>,
     *   invalid_inputs:array<int,string>
     * }
     */
    public function sendSmsSmokeTest(
        ?int $tenantId,
        array $testNumbers,
        string $message,
        ?string $senderKey,
        ?int $actorId,
        ?string $storeKey = null
    ): array {
        $rawNumbers = collect($testNumbers)
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values();

        $normalizedNumbers = $rawNumbers
            ->map(fn (string $value): ?string => $this->identityNormalizer->toE164($value))
            ->filter(fn (?string $value): bool => $value !== null)
            ->unique()
            ->values();

        if ($normalizedNumbers->isEmpty()) {
            throw ValidationException::withMessages([
                'test_numbers' => 'Add at least one valid phone number for SMS smoke test.',
            ]);
        }

        $invalidInputs = $rawNumbers
            ->filter(function (string $value) use ($normalizedNumbers): bool {
                $e164 = $this->identityNormalizer->toE164($value);

                return $e164 === null || ! $normalizedNumbers->contains($e164);
            })
            ->values()
            ->all();

        $recipients = $normalizedNumbers
            ->map(fn (string $phone): array => [
                'profile_id' => null,
                'name' => 'Smoke Test',
                'email' => null,
                'phone' => $phone,
                'normalized_phone' => $phone,
                'source_type' => 'smoke_test',
            ])
            ->all();

        $summary = $this->directMessagingService->send(
            channel: 'sms',
            recipients: $recipients,
            message: trim($message),
            options: [
                'sender_key' => $senderKey,
                'actor_id' => $actorId,
                'tenant_id' => $tenantId,
                'store_key' => $this->nullableString($storeKey),
                'source_label' => 'shopify_embedded_messaging_smoke_test',
            ]
        );

        $batchId = trim((string) ($summary['batch_id'] ?? ''));
        $deliveries = $batchId !== ''
            ? MarketingMessageDelivery::query()
                ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
                ->where('batch_id', $batchId)
                ->where('channel', 'sms')
                ->where('source_label', 'shopify_embedded_messaging_smoke_test')
                ->latest('id')
                ->get([
                    'id',
                    'to_phone',
                    'send_status',
                    'error_code',
                    'error_message',
                    'provider_message_id',
                    'sent_at',
                ])
                ->map(fn (MarketingMessageDelivery $delivery): array => [
                    'id' => (int) $delivery->id,
                    'recipient' => $this->nullableString($delivery->to_phone),
                    'status' => strtolower(trim((string) ($delivery->send_status ?? 'sent'))),
                    'error_code' => $this->nullableString($delivery->error_code),
                    'error_message' => $this->nullableString($delivery->error_message),
                    'provider_message_id' => $this->nullableString($delivery->provider_message_id),
                    'sent_at' => optional($delivery->sent_at)->toIso8601String(),
                ])
                ->values()
                ->all()
            : [];

        return [
            'summary' => $summary,
            'deliveries' => $deliveries,
            'invalid_inputs' => $invalidInputs,
        ];
    }

    /**
     * @param  array<int,string>  $testEmails
     * @return array{
     *   summary:array<string,mixed>,
     *   deliveries:array<int,array<string,mixed>>,
     *   integrity:array<string,mixed>,
     *   invalid_inputs:array<int,string>
     * }
     */
    public function sendEmailSmokeTest(
        ?int $tenantId,
        array $testEmails,
        string $subject,
        string $body,
        ?int $actorId,
        ?string $storeKey = null,
        ?string $emailTemplateMode = null,
        ?string $emailTemplateKey = null,
        mixed $emailSections = null,
        ?string $emailAdvancedHtml = null
    ): array {
        $rawEmails = collect($testEmails)
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values();

        $normalizedEmails = $rawEmails
            ->map(fn (string $value): ?string => $this->identityNormalizer->normalizeEmail($value))
            ->filter(fn (?string $value): bool => $value !== null)
            ->unique()
            ->values();

        if ($normalizedEmails->isEmpty()) {
            throw ValidationException::withMessages([
                'test_emails' => 'Add at least one valid email for email smoke test.',
            ]);
        }

        $invalidInputs = $rawEmails
            ->filter(function (string $value) use ($normalizedEmails): bool {
                $normalized = $this->identityNormalizer->normalizeEmail($value);

                return $normalized === null || ! $normalizedEmails->contains($normalized);
            })
            ->values()
            ->all();

        $composed = $this->emailComposerService->compose(
            subject: trim($subject),
            body: trim($body),
            mode: $emailTemplateMode,
            sections: $emailSections,
            legacyHtml: $emailAdvancedHtml
        );

        $integrity = $this->validateEmailContentIntegrity((string) ($composed['html'] ?? ''));
        if (! (bool) ($integrity['valid'] ?? false)) {
            throw ValidationException::withMessages([
                'email_sections' => [
                    'Email content contains invalid links or image URLs.',
                ],
            ]);
        }

        $recipients = $normalizedEmails
            ->map(fn (string $email): array => [
                'profile_id' => null,
                'name' => 'Smoke Test',
                'email' => $email,
                'phone' => null,
                'normalized_phone' => null,
                'source_type' => 'smoke_test',
            ])
            ->all();

        $summary = $this->directMessagingService->send(
            channel: 'email',
            recipients: $recipients,
            message: trim($body),
            options: [
                'subject' => trim($subject),
                'actor_id' => $actorId,
                'tenant_id' => $tenantId,
                'store_key' => $this->nullableString($storeKey),
                'source_label' => 'shopify_embedded_messaging_smoke_test',
                'html_body' => $this->nullableString($composed['html'] ?? null),
                'email_template' => [
                    'mode' => (string) ($composed['mode'] ?? ShopifyEmbeddedEmailComposerService::MODE_SECTIONS),
                    'template_key' => $this->nullableString($emailTemplateKey),
                    'sections' => is_array($composed['sections'] ?? null) ? $composed['sections'] : [],
                    'legacy_html' => $this->nullableString($composed['legacy_html'] ?? null),
                ],
            ]
        );

        $batchId = trim((string) ($summary['batch_id'] ?? ''));
        $deliveries = $batchId !== ''
            ? MarketingEmailDelivery::query()
                ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId))
                ->where('batch_id', $batchId)
                ->where('source_label', 'shopify_embedded_messaging_smoke_test')
                ->latest('id')
                ->get([
                    'id',
                    'email',
                    'status',
                    'provider_message_id',
                    'failed_at',
                    'sent_at',
                ])
                ->map(fn (MarketingEmailDelivery $delivery): array => [
                    'id' => (int) $delivery->id,
                    'recipient' => $this->nullableString($delivery->email),
                    'status' => strtolower(trim((string) ($delivery->status ?? 'sent'))),
                    'provider_message_id' => $this->nullableString($delivery->provider_message_id),
                    'sent_at' => optional($delivery->sent_at)->toIso8601String(),
                    'failed_at' => optional($delivery->failed_at)->toIso8601String(),
                ])
                ->values()
                ->all()
            : [];

        return [
            'summary' => $summary,
            'deliveries' => $deliveries,
            'integrity' => $integrity,
            'invalid_inputs' => $invalidInputs,
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
     * @return array<string,array{sms:int,email:int,overlap:int,unique:int}>
     */
    protected function autoGroupSummaries(?int $tenantId): array
    {
        $summary = $this->audienceSummary($tenantId);
        $groupSummaries = (array) ($summary['group_summaries'] ?? []);
        $resolved = [];

        foreach ($groupSummaries as $groupKey => $groupSummary) {
            $resolved[(string) $groupKey] = [
                'sms' => (int) data_get($groupSummary, 'sms', 0),
                'email' => (int) data_get($groupSummary, 'email', 0),
                'overlap' => (int) data_get($groupSummary, 'overlap', 0),
                'unique' => (int) data_get($groupSummary, 'unique', 0),
            ];
        }

        return $resolved;
    }

    /**
     * @return Collection<int,array{
     *   key:string,
     *   name:string,
     *   description:string,
     *   channels:array<int,string>,
     *   channel:?string,
     *   audience_scope:string
     * }>
     */
    protected function autoGroupDefinitions(?int $tenantId): Collection
    {
        $definitions = collect([
            [
                'key' => self::AUTO_GROUP_ALL_SUBSCRIBED,
                'name' => 'All Subscribed',
                'description' => 'Customers with active consent and reachable contact info for SMS and/or email.',
                'channels' => ['sms', 'email'],
                'channel' => null,
                'audience_scope' => self::AUDIENCE_SCOPE_EFFECTIVE,
            ],
        ]);

        if ($this->isModernForestryTenant($tenantId)) {
            $definitions->push([
                'key' => self::AUTO_GROUP_LEGACY_SMS_SUBSCRIBED,
                'name' => 'Legacy SMS Subscribed',
                'description' => 'Modern Forestry imported legacy records with SMS consent history and sendable SMS contact info.',
                'channels' => ['sms'],
                'channel' => 'sms',
                'audience_scope' => self::AUDIENCE_SCOPE_LEGACY_IMPORTED,
            ]);
            $definitions->push([
                'key' => self::AUTO_GROUP_LEGACY_EMAIL_SUBSCRIBED,
                'name' => 'Legacy Email Subscribed',
                'description' => 'Modern Forestry imported legacy records with email consent history and sendable email contact info.',
                'channels' => ['email'],
                'channel' => 'email',
                'audience_scope' => self::AUDIENCE_SCOPE_LEGACY_IMPORTED,
            ]);
        }

        return $definitions;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function autoGroupDefinitionByKey(?int $tenantId, string $groupKey): ?array
    {
        $normalizedKey = strtolower(trim($groupKey));

        return $this->autoGroupDefinitions($tenantId)
            ->first(fn (array $definition): bool => strtolower(trim((string) ($definition['key'] ?? ''))) === $normalizedKey);
    }

    /**
     * @return array{
     *   entries:array<int,array{
     *     channel:string,
     *     status:string,
     *     recipient:string,
     *     profile_name:string,
     *     message_preview:string,
     *     sent_at:?string
     *   }>,
     *   campaigns:array<int,array<string,mixed>>
     * }
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

        $entries = $smsRows
            ->concat($emailRows)
            ->sortByDesc(fn (array $row): string => (string) ($row['sent_at'] ?? ''))
            ->take($limit)
            ->values()
            ->all();

        $campaigns = MarketingCampaign::query()
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId), fn (Builder $query) => $query->whereNull('tenant_id'))
            ->whereNotNull('source_label')
            ->where('source_label', 'like', 'shopify_embedded_messaging%')
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id',
                'name',
                'status',
                'channel',
                'source_label',
                'message_subject',
                'target_snapshot',
                'status_counts',
                'scheduled_for',
                'queued_at',
                'launched_at',
                'completed_at',
                'created_at',
            ])
            ->map(function (MarketingCampaign $campaign): array {
                $statusCounts = is_array($campaign->status_counts) ? $campaign->status_counts : [];
                if ($statusCounts === []) {
                    $statusCounts = MarketingCampaignRecipient::query()
                        ->where('campaign_id', (int) $campaign->id)
                        ->selectRaw('status, count(*) as aggregate_count')
                        ->groupBy('status')
                        ->pluck('aggregate_count', 'status')
                        ->map(fn ($count): int => (int) $count)
                        ->all();
                }

                $jobStatusCounts = MarketingMessageJob::query()
                    ->where('campaign_id', (int) $campaign->id)
                    ->selectRaw('status, count(*) as aggregate_count')
                    ->groupBy('status')
                    ->pluck('aggregate_count', 'status')
                    ->map(fn ($count): int => (int) $count)
                    ->all();

                $failureCodes = MarketingMessageDelivery::query()
                    ->where('campaign_id', (int) $campaign->id)
                    ->whereNotNull('error_code')
                    ->selectRaw('error_code, count(*) as aggregate_count')
                    ->groupBy('error_code')
                    ->orderByDesc('aggregate_count')
                    ->orderBy('error_code')
                    ->limit(8)
                    ->get()
                    ->map(fn (MarketingMessageDelivery $delivery): array => [
                        'code' => (string) ($delivery->error_code ?? ''),
                        'count' => (int) ($delivery->aggregate_count ?? 0),
                    ])
                    ->filter(fn (array $row): bool => $row['code'] !== '')
                    ->values()
                    ->all();

                return [
                    'id' => (int) $campaign->id,
                    'name' => (string) $campaign->name,
                    'status' => strtolower(trim((string) $campaign->status)),
                    'channel' => strtolower(trim((string) $campaign->channel)),
                    'source_label' => (string) ($campaign->source_label ?? 'shopify_embedded_messaging_group'),
                    'subject' => $this->nullableString($campaign->message_subject),
                    'target' => is_array($campaign->target_snapshot) ? $campaign->target_snapshot : [],
                    'status_counts' => $statusCounts,
                    'job_status_counts' => $jobStatusCounts,
                    'failure_codes' => $failureCodes,
                    'scheduled_for' => optional($campaign->scheduled_for)->toIso8601String(),
                    'queued_at' => optional($campaign->queued_at)->toIso8601String(),
                    'launched_at' => optional($campaign->launched_at)->toIso8601String(),
                    'completed_at' => optional($campaign->completed_at)->toIso8601String(),
                    'created_at' => optional($campaign->created_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return [
            'entries' => $entries,
            'campaigns' => $campaigns,
        ];
    }

    /**
     * @return array{
     *   valid:bool,
     *   link_count:int,
     *   image_count:int,
     *   invalid_urls:array<int,string>
     * }
     */
    protected function validateEmailContentIntegrity(string $html): array
    {
        $normalizedHtml = trim($html);
        if ($normalizedHtml === '') {
            return [
                'valid' => true,
                'link_count' => 0,
                'image_count' => 0,
                'invalid_urls' => [],
            ];
        }

        preg_match_all('/<a\b[^>]*\bhref\s*=\s*[\'"]([^\'"]+)[\'"]/i', $normalizedHtml, $linkMatches);
        preg_match_all('/<img\b[^>]*\bsrc\s*=\s*[\'"]([^\'"]+)[\'"]/i', $normalizedHtml, $imageMatches);

        $links = collect((array) ($linkMatches[1] ?? []))
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values();
        $images = collect((array) ($imageMatches[1] ?? []))
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values();

        $invalidUrls = $links
            ->concat($images)
            ->filter(fn (string $url): bool => ! $this->isSafeEmailUrl($url))
            ->unique()
            ->values()
            ->all();

        return [
            'valid' => $invalidUrls === [],
            'link_count' => $links->count(),
            'image_count' => $images->count(),
            'invalid_urls' => $invalidUrls,
        ];
    }

    protected function isSafeEmailUrl(string $value): bool
    {
        $url = trim($value);
        if ($url === '') {
            return false;
        }

        if (str_starts_with($url, '#')) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower(trim((string) ($parts['scheme'] ?? '')));
        if ($scheme === '') {
            return false;
        }

        if (in_array($scheme, ['http', 'https'], true)) {
            return filter_var($url, FILTER_VALIDATE_URL) !== false;
        }

        if ($scheme === 'mailto') {
            return str_contains($url, '@');
        }

        return in_array($scheme, ['tel', 'cid', 'data'], true);
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
        $groupDefinition = $this->autoGroupDefinitionByKey($tenantId, $normalizedGroupKey);
        if (! is_array($groupDefinition)) {
            throw ValidationException::withMessages([
                'group_key' => 'Unsupported automatic group selection.',
            ]);
        }

        $lockedChannel = $this->nullableString($groupDefinition['channel'] ?? null);
        if ($lockedChannel !== null && $lockedChannel !== $channel) {
            throw ValidationException::withMessages([
                'channel' => sprintf(
                    '%s can only be sent through %s.',
                    (string) ($groupDefinition['name'] ?? 'This automatic audience'),
                    strtoupper($lockedChannel)
                ),
            ]);
        }

        $audienceScope = $this->normalizedAudienceScope((string) ($groupDefinition['audience_scope'] ?? self::AUDIENCE_SCOPE_EFFECTIVE));
        $audienceChannel = $lockedChannel ?? $channel;
        $audience = $this->resolvedChannelAudience(
            $tenantId,
            $audienceChannel,
            includeRecipients: true,
            scope: $audienceScope
        );

        return [
            'source_label' => 'shopify_embedded_messaging_auto_group',
            'target' => [
                'type' => 'auto',
                'key' => (string) ($groupDefinition['key'] ?? $normalizedGroupKey),
                'name' => (string) ($groupDefinition['name'] ?? 'Automatic audience'),
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
    protected function resolvedChannelAudience(
        ?int $tenantId,
        string $channel,
        bool $includeRecipients,
        string $scope = self::AUDIENCE_SCOPE_EFFECTIVE
    ): array
    {
        $channel = $this->normalizedChannel($channel);
        $scope = $this->normalizedAudienceScope($scope);
        $cacheKey = implode(':', [
            (string) ($tenantId ?? 'null'),
            $channel,
            $scope,
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
            ->where(function (Builder $builder) use ($consentColumn, $channel, $tenantId, $scope): void {
                if ($scope === self::AUDIENCE_SCOPE_LEGACY_IMPORTED) {
                    $builder->whereExists(function ($exists) use ($channel, $tenantId): void {
                        $this->applyLegacyConsentExistsConstraint($exists, $channel, $tenantId);
                    });

                    return;
                }

                $builder->where($consentColumn, true)
                    ->orWhereExists(function ($exists) use ($channel, $tenantId): void {
                        $this->applyLegacyConsentExistsConstraint($exists, $channel, $tenantId);
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
            ->chunkById(1200, function (Collection $profiles) use (
                $channel,
                $tenantId,
                $includeRecipients,
                $scope,
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

                    $effectiveConsent = $scope === self::AUDIENCE_SCOPE_LEGACY_IMPORTED
                        ? ($hasLegacyImport && ! in_array($latestEventType, ['opted_out', 'revoked'], true))
                        : ($canonicalConsent || ($hasLegacyImport && ! in_array($latestEventType, ['opted_out', 'revoked'], true)));
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

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    protected function applyLegacyConsentExistsConstraint($query, string $channel, ?int $tenantId): void
    {
        $query->selectRaw('1')
            ->from('marketing_consent_events as legacy_events')
            ->whereColumn('legacy_events.marketing_profile_id', 'marketing_profiles.id')
            ->where('legacy_events.channel', $channel)
            ->where('legacy_events.event_type', 'imported')
            ->whereIn('legacy_events.source_type', self::LEGACY_CONSENT_SOURCE_TYPES);

        if (! Schema::hasColumn('marketing_consent_events', 'tenant_id')) {
            return;
        }

        if ($tenantId === null) {
            $query->whereNull('legacy_events.tenant_id');

            return;
        }

        $query->where('legacy_events.tenant_id', $tenantId);
    }

    protected function normalizedAudienceScope(string $scope): string
    {
        $normalized = strtolower(trim($scope));

        return in_array($normalized, [self::AUDIENCE_SCOPE_EFFECTIVE, self::AUDIENCE_SCOPE_LEGACY_IMPORTED], true)
            ? $normalized
            : self::AUDIENCE_SCOPE_EFFECTIVE;
    }

    /**
     * @param  array<string,mixed>  $audience
     * @return Collection<int,int>
     */
    protected function normalizedAudienceProfileIds(array $audience): Collection
    {
        return collect((array) ($audience['profile_ids'] ?? []))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int,int>  $smsIds
     * @param  Collection<int,int>  $emailIds
     * @return array{sms:int,email:int,overlap:int,unique:int}
     */
    protected function audienceSummaryFromIds(Collection $smsIds, Collection $emailIds): array
    {
        $overlap = $smsIds->intersect($emailIds)->count();

        return [
            'sms' => $smsIds->count(),
            'email' => $emailIds->count(),
            'overlap' => $overlap,
            'unique' => $smsIds->merge($emailIds)->unique()->count(),
        ];
    }

    protected function isModernForestryTenant(?int $tenantId): bool
    {
        if ($tenantId === null || $tenantId <= 0) {
            return false;
        }

        if (array_key_exists($tenantId, $this->modernForestryTenantCache)) {
            return (bool) $this->modernForestryTenantCache[$tenantId];
        }

        $slug = strtolower(trim((string) Tenant::query()->whereKey($tenantId)->value('slug')));
        $this->modernForestryTenantCache[$tenantId] = $slug === self::MODERN_FORESTRY_SLUG;

        return (bool) $this->modernForestryTenantCache[$tenantId];
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

    protected function numericShopifyIdFromGid(?string $gid): ?string
    {
        $resolved = trim((string) $gid);
        if ($resolved === '') {
            return null;
        }

        if (preg_match('/\/Product\/(\d+)$/', $resolved, $matches) === 1) {
            return (string) ($matches[1] ?? null);
        }

        return null;
    }

    protected function formatMoneyAmount(?string $amount, ?string $currency): ?string
    {
        $resolvedAmount = $this->nullableString($amount);
        if ($resolvedAmount === null || ! is_numeric($resolvedAmount)) {
            return null;
        }

        $resolvedCurrency = strtoupper(trim((string) $currency));
        $formatted = number_format((float) $resolvedAmount, 2);

        if ($resolvedCurrency !== '') {
            return $resolvedCurrency . ' ' . $formatted;
        }

        return '$' . $formatted;
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
