<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;

class MarketingStorefrontIdentityService
{
    public function __construct(
        protected MarketingProfileSyncService $profileSyncService
    ) {
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $context
     * @return array{status:string,profile:?MarketingProfile,sync:array<string,mixed>}
     */
    public function resolve(array $identity, array $context = []): array
    {
        $sourceType = trim((string) ($context['source_type'] ?? 'storefront'));
        $sourceId = trim((string) ($context['source_id'] ?? ''));
        if ($sourceId === '') {
            $seed = strtolower(trim((string) ($identity['email'] ?? ''))) . '|' . trim((string) ($identity['phone'] ?? ''));
            $sourceId = $sourceType . ':' . sha1($seed);
        }

        $sourceChannels = array_values(array_filter((array) ($context['source_channels'] ?? ['storefront'])));

        $sync = $this->profileSyncService->syncExternalIdentity([
            'first_name' => trim((string) ($identity['first_name'] ?? '')) ?: null,
            'last_name' => trim((string) ($identity['last_name'] ?? '')) ?: null,
            'raw_email' => trim((string) ($identity['email'] ?? '')) ?: null,
            'raw_phone' => trim((string) ($identity['phone'] ?? '')) ?: null,
            'source_channels' => $sourceChannels,
            'source_links' => [[
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_meta' => is_array($context['source_meta'] ?? null) ? $context['source_meta'] : [],
            ]],
            'primary_source' => [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ],
        ], [
            'review_context' => [
                'source_label' => (string) ($context['source_label'] ?? $sourceType),
                'source_id' => $sourceId,
                ...((array) ($context['review_context'] ?? [])),
            ],
            'allow_create' => (bool) ($context['allow_create'] ?? false),
        ]);

        $profile = null;
        if ((int) ($sync['profile_id'] ?? 0) > 0) {
            $profile = MarketingProfile::query()->find((int) $sync['profile_id']);
        }

        if (! $profile) {
            if ((string) ($sync['status'] ?? '') === 'review') {
                return ['status' => 'review_required', 'profile' => null, 'sync' => $sync];
            }

            return ['status' => 'not_found', 'profile' => null, 'sync' => $sync];
        }

        return ['status' => 'resolved', 'profile' => $profile, 'sync' => $sync];
    }

    public function deterministicSourceId(string $prefix, ?string $email = null, ?string $phone = null, array $extra = []): string
    {
        $seed = [
            strtolower(trim((string) $email)),
            trim((string) $phone),
            ...collect($extra)->map(fn ($value) => trim((string) $value))->all(),
        ];

        return trim($prefix) . ':' . sha1(implode('|', $seed));
    }
}
