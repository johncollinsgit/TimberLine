<?php

namespace App\Services\Marketing;

use App\Jobs\SendTeamShowTextRecipient;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageMediaAsset;
use App\Models\MessagingConversationMessage;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeamShowTextCampaigns
{
    public function __construct(private MarketingCampaignMmsMediaService $media, private MarketingApprovalService $approvals) {}

    public static function enabledFor(int $tenantId): bool
    {
        $tenants = (array) config('team_operations.show_texts_tenants', []);

        return config('team_operations.show_texts_enabled') && (! $tenants || in_array((string) $tenantId, $tenants, true));
    }

    public function upload(Tenant $tenant, User $actor, TeamOperationRecord $event, string $store, UploadedFile $file): MarketingMessageMediaAsset
    {
        $bytes = $file->get();
        $size = is_string($bytes) ? strlen($bytes) : 0;
        $image = $size > 0 && $size <= MarketingCampaignMmsMediaService::MAX_BYTES ? @getimagesizefromstring($bytes) : false;
        $extension = match ($image['mime'] ?? '') {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            default => null,
        };
        if (! $extension) {
            throw ValidationException::withMessages(['photo' => 'Choose a JPEG, PNG, or GIF (up to 5 MB).']);
        }
        $disk = Storage::disk('public');
        $path = 'messaging-media/tenant-'.$tenant->id.'/'.$store.'/sms/'.Str::uuid().'.'.$extension;
        $url = $disk->url($path);
        if (! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw ValidationException::withMessages(['photo' => 'Photo hosting is unavailable. Try again when secure media hosting is enabled.']);
        }
        if (! $disk->put($path, $bytes, 'public')) {
            throw ValidationException::withMessages(['photo' => 'The photo could not be uploaded. Try again.']);
        }
        try {
            return MarketingMessageMediaAsset::create([
                'tenant_id' => $tenant->id, 'store_key' => $store, 'channel' => 'sms', 'disk' => 'public',
                'path' => $path, 'public_url' => $url, 'original_name' => Str::limit($file->getClientOriginalName(), 220),
                'mime_type' => $image['mime'], 'size_bytes' => $size, 'width' => $image[0], 'height' => $image[1],
                'uploaded_by' => $actor->id, 'metadata' => [
                    'source' => 'team_show_text', 'event_id' => $event->id, 'content_sha256' => hash('sha256', $bytes),
                    'prepared_at' => \Carbon\CarbonImmutable::now('UTC')->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }
    }

    public function selection(Tenant $tenant, User $actor, TeamOperationRecord $event, string $store, ?int $assetId): ?array
    {
        if (! $assetId) {
            return null;
        }
        $asset = MarketingMessageMediaAsset::forTenantId($tenant->id)->where('store_key', $store)
            ->where('channel', 'sms')->where('uploaded_by', $actor->id)->find($assetId);
        if (! $asset || data_get($asset->metadata, 'event_id') !== $event->id) {
            throw ValidationException::withMessages(['photo' => 'Choose a photo uploaded for this show.']);
        }
        $snapshot = ['asset_id' => (int) $asset->id, 'sha256' => data_get($asset->metadata, 'content_sha256'),
            'required' => true, 'prepared_at' => data_get($asset->metadata, 'prepared_at')];
        $campaign = new MarketingCampaign(['tenant_id' => $tenant->id, 'store_key' => $store,
            'channel' => 'sms', 'target_snapshot' => ['mms_media' => $snapshot]]);

        return $this->media->snapshotForCampaign($campaign, $tenant->id, $store);
    }

    public function validateMessage(string $body, ?array $photo): void
    {
        if (! preg_match('/\b(?:reply|text)\s+STOP\b/i', $body)) {
            throw ValidationException::withMessages(['body' => 'Include “Reply STOP to opt out.” in the message.']);
        }
        if ($photo) {
            $this->media->assertMessageFits($photo, $body);
        }
    }

    public function slug(TeamOperationRecord $event, User $actor, string $reviewId, bool $preview): string
    {
        return 'team-show-text-'.hash('sha256', implode(':', [$event->tenant_id, $event->id, $actor->id, $reviewId, (int) $preview]));
    }

    public function queue(Tenant $tenant, User $actor, TeamOperationRecord $event, string $store, string $reviewId, string $body, ?array $photo, array $rows, bool $preview): MarketingCampaign
    {
        $this->validateMessage($body, $photo);
        $slug = $this->slug($event, $actor, $reviewId, $preview);

        return DB::transaction(function () use ($tenant, $actor, $event, $store, $slug, $body, $photo, $rows, $preview): MarketingCampaign {
            $locked = TeamOperationRecord::query()->where('tenant_id', $tenant->id)->where('area_id', $event->area_id)
                ->where('kind', 'event')->lockForUpdate()->findOrFail($event->id);
            $existing = MarketingCampaign::forTenantId($tenant->id)->where('slug', $slug)->first();
            if ($existing) {
                return $existing;
            }
            abort_if($locked->version !== $event->version || in_array($locked->status, ['withdrawn', 'cancelled'], true), 409, 'The show changed. Review the message again.');
            $target = ['team_show_text' => ['event_id' => $event->id, 'area_id' => $event->area_id, 'preview' => $preview]];
            if ($photo) {
                $target['mms_media'] = array_intersect_key($photo, array_flip(['asset_id', 'sha256', 'required', 'prepared_at']));
            }
            $campaign = MarketingCampaign::create([
                'tenant_id' => $tenant->id, 'store_key' => $store, 'slug' => $slug,
                'name' => Str::limit($event->title.($preview ? ' · Text preview' : ' · Show text'), 120),
                'status' => 'queued', 'channel' => 'sms', 'objective' => 'event_followup',
                'message_body' => $body, 'target_snapshot' => $target, 'source_label' => 'team_show_text',
                'attribution_window_days' => 7, 'queued_at' => now(), 'launched_at' => now(),
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $variant = $campaign->variants()->create(['name' => 'Show text', 'message_text' => $body, 'status' => 'active', 'weight' => 100]);
            foreach ($rows as $row) {
                $recipient = $campaign->recipients()->create([
                    'marketing_profile_id' => $row['id'], 'channel' => 'sms', 'status' => 'queued_for_approval', 'variant_id' => $variant->id,
                    'recommendation_snapshot' => [
                        'team_show_text_phone' => $row['phone'], 'team_show_text_body_sha256' => hash('sha256', $body),
                        'team_show_text_photo_sha256' => $photo['sha256'] ?? '',
                        'team_show_text_store' => $store,
                        ...($photo ? ['mms_media' => $photo] : []),
                    ],
                    'reason_codes' => $row['reasons'],
                ]);
                $this->approvals->approveRecipient($recipient, $actor->id, $preview ? 'Show text preview' : 'Reviewed show text and recipients');
                // On the production database queue this inserts the job in the same
                // transaction as its approval. Other queue backends dispatch after commit.
                $job = new SendTeamShowTextRecipient($tenant->id, $campaign->id, $recipient->id);
                $connection = config('queue.default');
                $atomic = config("queue.connections.{$connection}.driver") === 'database'
                    && (config("queue.connections.{$connection}.connection") ?: config('database.default')) === DB::connection()->getName();
                app(Dispatcher::class)->dispatch($atomic ? $job->beforeCommit() : $job->afterCommit());
            }

            return $campaign;
        }, 3);
    }

    public function results(MarketingCampaign $campaign, string $filter): array
    {
        $recipients = $campaign->recipients()->whereHas('profile', fn ($q) => $q->forTenantId($campaign->tenant_id))->get();
        $deliveries = $campaign->deliveries()->forTenantId($campaign->tenant_id)->whereIn('campaign_recipient_id', $recipients->modelKeys())->get()->keyBy('id');
        $latest = $deliveries->sortByDesc('id')->unique('campaign_recipient_id')->keyBy('campaign_recipient_id');
        $messages = MessagingConversationMessage::forTenantId($campaign->tenant_id)->where('channel', 'sms')->where('direction', 'inbound')
            ->whereIn('marketing_message_delivery_id', $deliveries->modelKeys())->get()->groupBy('marketing_message_delivery_id');
        $replyByRecipient = [];
        $stopped = [];
        foreach ($messages as $id => $inbound) {
            $recipientId = $deliveries->get($id)->campaign_recipient_id;
            foreach ($inbound as $message) {
                if (in_array($message->message_type, ['opt_out', 'stop', 'unsubscribe'], true)) {
                    $stopped[$recipientId] = true;
                } elseif ($message->message_type === 'normal' && data_get($message->metadata, 'keyword') !== 'start') {
                    $replyByRecipient[$recipientId] = $message->conversation_id;
                }
            }
        }
        $counts = array_fill_keys(['Queued', 'Sent', 'Delivered', 'Failed'], 0);
        $statuses = [];
        foreach ($recipients as $recipient) {
            $delivery = $latest->get($recipient->id);
            if ((string) $delivery?->error_code === '21610') {
                $stopped[$recipient->id] = true;
            }
            $status = match ($delivery?->delivered_at ? 'delivered' : ($delivery?->send_status ?? $recipient->status)) {
                'delivered' => 'Delivered',
                'accepted', 'queued', 'sent', 'sending', 'undelivered', 'failed', 'canceled' => $this->deliveryLabel($delivery, $recipient),
                'skipped', 'blocked', 'rejected' => 'Failed',
                default => 'Queued',
            };
            $counts[$status]++;
            $statuses[$recipient->id] = $status;
        }
        $counts['Replied'] = count($replyByRecipient);
        $counts['Opted out'] = count($stopped);
        $ids = $recipients->filter(fn ($recipient) => match ($filter) {
            'Replied' => isset($replyByRecipient[$recipient->id]),
            'Opted out' => isset($stopped[$recipient->id]),
            '' => true,
            default => $statuses[$recipient->id] === $filter,
        })->modelKeys();
        $page = $campaign->recipients()->whereIn('id', $ids)->with('profile')->orderBy('id')->paginate(35)->withQueryString();

        return compact('page', 'counts', 'statuses', 'replyByRecipient', 'stopped') + [
            'purchases' => $campaign->conversions()->whereHas('profile', fn ($q) => $q->forTenantId($campaign->tenant_id))->count(),
        ];
    }

    private function deliveryLabel(?MarketingMessageDelivery $delivery, MarketingCampaignRecipient $recipient): string
    {
        if (in_array($delivery?->send_status ?? $recipient->status, ['failed', 'undelivered', 'canceled'], true)) {
            return 'Failed';
        }

        return $delivery?->provider_message_id ? 'Sent' : 'Queued';
    }
}
