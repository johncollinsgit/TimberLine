<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingMessageMediaAsset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class MarketingCampaignMmsMediaService
{
    public const MAX_BYTES = 5_000_000;

    public function snapshotForCampaign(MarketingCampaign $campaign, ?int $tenantId, ?string $storeKey = null): ?array
    {
        $selection = data_get($campaign->target_snapshot, 'mms_media');
        if ($selection === null && ! array_key_exists('mms_media', (array) $campaign->target_snapshot)) {
            return null;
        }

        $snapshot = $this->selection($selection);
        $store = trim((string) ($campaign->store_key ?: $storeKey));
        $this->require($campaign->channel === 'sms' && $tenantId !== null && $tenantId > 0
            && (int) $campaign->tenant_id === $tenantId && $store !== ''
            && ($storeKey === null || $storeKey === '' || $store === $storeKey), 'The photo requires a matching campaign tenant and store.');

        $asset = MarketingMessageMediaAsset::query()->forTenantId($tenantId)
            ->where('store_key', $store)->where('channel', 'sms')->find($snapshot['asset_id']);
        $this->require($asset !== null && $asset->disk === 'public', 'The selected photo is not an owned public SMS asset.');
        $prefix = 'messaging-media/tenant-'.$tenantId.'/'.$store.'/sms/';
        $path = (string) $asset->path;
        $filename = substr($path, strlen($prefix));
        $this->require(str_starts_with($path, $prefix) && $filename !== ''
            && basename($filename) === $filename && ! str_contains($filename, '..')
            && ! str_contains($filename, '\\'), 'The photo storage path is invalid.');

        try {
            $disk = Storage::disk('public');
            $this->require($disk->exists($path), 'The selected photo file is missing.');
            $size = $disk->size($path);
            $this->require($size > 0 && $size <= self::MAX_BYTES && $size === $asset->size_bytes, 'The photo must be at most 5 MB and match its saved size.');
            $bytes = $disk->get($path);
            $this->require(strlen($bytes) === $size, 'The photo changed while it was being checked.');
            $image = @getimagesizefromstring($bytes);
            $mime = $image['mime'] ?? null;
            $this->require(in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)
                && $mime === $asset->mime_type, 'MMS requires a matching JPEG, PNG or GIF image.');
            $this->require(hash_equals($snapshot['sha256'], hash('sha256', $bytes)), 'The photo changed after it was prepared.');
            $url = $disk->url($path);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['mms_media' => 'The selected photo could not be read.']);
        }

        $parts = parse_url($url);
        $this->require(filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && filled($parts['host'] ?? null) && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['fragment']) && $asset->public_url === $url, 'The photo requires its canonical public HTTPS URL.');

        return [...$snapshot, 'url' => $url, 'mime_type' => $mime, 'size_bytes' => $size];
    }

    public function forApproval(MarketingCampaignRecipient $recipient): ?array
    {
        $campaign = $recipient->campaign()->first();
        $this->require($campaign !== null, 'The photo campaign is missing.');
        $media = $this->snapshotForCampaign($campaign, $campaign->tenant_id, $campaign->store_key);
        $selection = data_get($recipient->recommendation_snapshot, 'mms_media');
        if ($media === null && $selection === null) {
            return null;
        }
        $this->require($media !== null && $this->sameSelection($media, $selection), 'Prepare the current photo for this recipient before approving.');
        $this->require($recipient->profile()->forTenantId($campaign->tenant_id)->exists(), 'The photo recipient belongs to another workspace.');

        return $media;
    }

    public function forRecipient(MarketingCampaign $campaign, MarketingCampaignRecipient $recipient, ?int $tenantId, ?string $storeKey, string $body): ?array
    {
        // Read current selections/approval rather than a caller's cached relations.
        $currentCampaign = MarketingCampaign::query()->forTenantId($tenantId)->find($campaign->id);
        $currentRecipient = MarketingCampaignRecipient::query()->where('campaign_id', $campaign->id)->find($recipient->id);
        $this->require($currentCampaign !== null && $currentRecipient !== null, 'The photo campaign or recipient changed.');
        $media = $this->snapshotForCampaign($currentCampaign, $tenantId, $storeKey);
        $recipientSelection = data_get($currentRecipient->recommendation_snapshot, 'mms_media');
        $approvedSelection = data_get($currentRecipient->recommendation_snapshot, 'mms_media_approval');
        if ($media === null && $recipientSelection === null && $approvedSelection === null) {
            return null;
        }

        $this->require($media !== null && $this->sameSelection($media, $recipientSelection)
            && $this->sameSelection($media, $approvedSelection), 'Prepare and approve the current photo for this recipient again.');
        $this->require($currentRecipient->profile()->forTenantId($tenantId)->exists(), 'The photo recipient belongs to another workspace.');
        // The executor separately permits failed/undelivered only for an explicit retry.
        $this->require(in_array($currentRecipient->status, ['approved', 'scheduled', 'failed', 'undelivered'], true)
            && $currentRecipient->approved_by !== null && $currentRecipient->approved_at !== null
            && $currentRecipient->approved_at->greaterThanOrEqualTo(CarbonImmutable::parse($media['prepared_at'])->startOfSecond()), 'The current photo needs a new send approval.');
        $this->assertMessageFits($media, $body);

        return $media;
    }

    public function assertMessageFits(array $media, string $body): void
    {
        $this->require(mb_strlen($body) <= 1600 && strlen($body) + $media['size_bytes'] <= self::MAX_BYTES, 'The MMS text and photo exceed the message size limit.');
    }

    public function sameSelection(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        try {
            return $this->selection($left) === $this->selection($right);
        } catch (ValidationException) {
            return false;
        }
    }

    protected function selection(mixed $value): array
    {
        $this->require(is_array($value), 'The photo selection is invalid.');
        $id = filter_var($value['asset_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sha256 = strtolower(trim((string) ($value['sha256'] ?? '')));
        $preparedAt = (string) ($value['prepared_at'] ?? '');
        $this->require($id !== false && ($value['required'] ?? null) === true
            && preg_match('/\A[a-f0-9]{64}\z/D', $sha256) === 1
            && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D', $preparedAt) === 1, 'The photo needs an asset, checksum and dated review snapshot.');
        try {
            $date = CarbonImmutable::parse($preparedAt);
        } catch (Throwable) {
            throw ValidationException::withMessages(['mms_media' => 'The photo review date is invalid.']);
        }
        $this->require($date->lessThanOrEqualTo(CarbonImmutable::now()->addSeconds(5)), 'The photo review date is in the future.');

        return ['asset_id' => $id, 'sha256' => $sha256, 'required' => true, 'prepared_at' => $date->toISOString()];
    }

    protected function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['mms_media' => $message]);
        }
    }
}
