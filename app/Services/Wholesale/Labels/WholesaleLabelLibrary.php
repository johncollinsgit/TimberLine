<?php

namespace App\Services\Wholesale\Labels;

use App\Jobs\Wholesale\ProcessWholesaleLabel;
use App\Models\WholesaleLabelAssignment;
use App\Models\WholesaleLabelDesign;
use App\Models\WholesaleLabelEvent;
use App\Models\WholesaleLabelRequest;
use App\Models\WholesaleLabelRevision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WholesaleLabelLibrary
{
    public function owned(string $model, array $owner): Builder
    {
        return $model::query()->forAllTenants()->where('tenant_id', $owner['tenant_id'])->where('shop_domain', $owner['shop_domain'])->where('customer_id', $owner['customer_id']);
    }

    public function event(array $owner, string $action, array $refs = [], ?int $actor = null, array $details = []): void
    {
        WholesaleLabelEvent::query()->create(array_merge($owner, $refs, ['action' => $action, 'actor_id' => $actor, 'details' => $details]));
    }

    public function upload(array $owner, UploadedFile $file, string $name, ?string $designId = null, ?int $actor = null): WholesaleLabelRevision
    {
        abort_if($file->getSize() > config('wholesale_labels.max_bytes'), 422, 'Choose a file of 20 MB or less.');
        $bytes = file_get_contents($file->getRealPath());
        $mime = app(WholesaleLabelProcessor::class)->validateOriginal($bytes);
        $sha = hash('sha256', $bytes);

        return Cache::lock('wl-upload:'.hash('sha256', implode('|', $owner).'|'.$sha), 30)->block(5, function () use ($owner, $file, $name, $designId, $actor, $bytes, $mime, $sha): WholesaleLabelRevision {
            $duplicate = $this->owned(WholesaleLabelRevision::class, $owner)->where('sha256', $sha)->where('created_at', '>', now()->subMinutes(10))->first();
            if ($duplicate && (! $designId || $duplicate->design_id === $designId)) {
                return $duplicate;
            }
            $design = $designId ? $this->owned(WholesaleLabelDesign::class, $owner)->whereNull('archived_at')->findOrFail($designId) : null;
            $id = (string) Str::uuid();
            $path = 'wholesale-labels/'.$owner['tenant_id'].'/'.$id.'/original';
            abort_unless(Storage::disk(config('wholesale_labels.disk'))->put($path, $bytes, ['visibility' => 'private']), 503, 'Your original file could not be saved. Please try again.');
            try {
                $revision = DB::transaction(function () use ($owner, $file, $name, $actor, $bytes, $mime, $sha, $design, $id, $path): WholesaleLabelRevision {
                    $design ??= WholesaleLabelDesign::query()->create($owner + ['name' => $name]);
                    $revision = WholesaleLabelRevision::query()->create($owner + [
                        'id' => $id, 'design_id' => $design->id, 'source' => $actor ? 'staff_import' : 'buyer_upload',
                        'original_name' => Str::limit(basename($file->getClientOriginalName()), 190, ''),
                        'mime' => $mime, 'original_path' => $path, 'sha256' => $sha, 'byte_size' => strlen($bytes),
                        'created_by' => $actor, 'status' => 'queued', 'settings' => ['threshold' => 160],
                    ]);
                    $this->event($owner, 'uploaded', ['design_id' => $design->id, 'revision_id' => $id], $actor, ['sha256' => $sha]);
                    ProcessWholesaleLabel::dispatch($id)->afterCommit();

                    return $revision;
                });
            } catch (\Throwable $e) {
                Storage::disk(config('wholesale_labels.disk'))->delete($path);
                throw $e;
            }

            return $revision;
        });
    }

    public function prepare(array $owner, array $data): WholesaleLabelRequest
    {
        $payload = [
            'mode' => $data['mode'], 'revision_id' => $data['revision_id'] ?? null,
            'groups' => $data['groups'], 'instructions' => $data['instructions'] ?? '',
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return Cache::lock('wl-request:'.hash('sha256', implode('|', $owner).'|'.$data['idempotency_key']), 20)->block(5, function () use ($owner, $data, $hash): WholesaleLabelRequest {
            $existing = $this->owned(WholesaleLabelRequest::class, $owner)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_sha256, $hash), 409, 'This request has changed. Please review it again.');
                abort_if($existing->bound_at !== null, 409, 'This request already belongs to an order. Start a new label request.');

                return $existing;
            }
            $revision = null;
            if ($data['mode'] !== 'legacy') {
                $revision = $this->owned(WholesaleLabelRevision::class, $owner)->findOrFail($data['revision_id']);
                $design = $this->owned(WholesaleLabelDesign::class, $owner)->whereNull('archived_at')->findOrFail($revision->design_id);
                abort_if(in_array($revision->status, ['failed', 'rejected'], true), 422, 'Please choose a valid logo file.');
                if ($data['mode'] === 'reuse') {
                    abort_unless($revision->status === 'approved' && $design->approved_revision_id === $revision->id, 422, 'Choose an approved label.');
                }
            }
            $request = WholesaleLabelRequest::query()->create($owner + [
                'idempotency_key' => $data['idempotency_key'], 'payload_sha256' => $hash,
                'mode' => $data['mode'], 'revision_id' => $revision?->id, 'design_id' => $revision?->design_id,
                'groups' => $data['groups'], 'instructions' => $data['instructions'] ?? null,
                'fee_cents' => $data['mode'] === 'new' ? config('wholesale_labels.setup_cents') : config('wholesale_labels.reuse_cents'),
            ]);
            $this->event($owner, 'request_prepared', ['request_id' => $request->id, 'revision_id' => $revision?->id, 'design_id' => $revision?->design_id]);

            return $request;
        });
    }

    public function approve(array $owner, string $revisionId, int $actor): WholesaleLabelRevision
    {
        return DB::transaction(function () use ($owner, $revisionId, $actor): WholesaleLabelRevision {
            $revision = $this->owned(WholesaleLabelRevision::class, $owner)->lockForUpdate()->findOrFail($revisionId);
            abort_unless(in_array($revision->status, ['draft', 'approved'], true) && $revision->vector_path && $revision->preview_path, 422, 'A completed vector draft and original preview are required.');
            $disk = Storage::disk(config('wholesale_labels.disk'));
            abort_unless($disk->exists($revision->vector_path) && hash_equals($revision->vector_sha256, hash('sha256', $disk->get($revision->vector_path))), 422, 'The vector file is missing or changed. Regenerate it first.');
            $revision->update(['status' => 'approved', 'approved_at' => $revision->approved_at ?? now(), 'approved_by' => $revision->approved_by ?? $actor]);
            $this->owned(WholesaleLabelDesign::class, $owner)->whereNull('archived_at')->findOrFail($revision->design_id)->update(['approved_revision_id' => $revision->id]);
            $requests = $this->owned(WholesaleLabelRequest::class, $owner)->where('design_id', $revision->design_id)->pluck('id');
            $this->owned(WholesaleLabelAssignment::class, $owner)->whereIn('request_id', $requests)->where('status', 'needs_review')->whereNull('production_revision_id')->update(['status' => 'ready', 'production_revision_id' => $revision->id]);
            $orders = $this->owned(WholesaleLabelAssignment::class, $owner)->where('production_revision_id', $revision->id)->pluck('order_id')->unique();
            foreach ($orders as $orderId) {
                $hold = $this->owned(WholesaleLabelAssignment::class, $owner)->where('order_id', $orderId)->where('status', '!=', 'ready')->exists();
                $order = \App\Models\Order::query()->where('tenant_id', $owner['tenant_id'])->find($orderId);
                if ($order) {
                    $meta = $order->attribution_meta ?? [];
                    $meta['custom_labels']['hold'] = $hold;
                    $meta['custom_labels']['status'] = $hold ? 'Artwork review required' : 'Artwork approved';
                    $order->forceFill(['attribution_meta' => $meta])->saveQuietly();
                }
            }
            $this->event($owner, 'approved', ['revision_id' => $revision->id, 'design_id' => $revision->design_id], $actor);

            return $revision;
        });
    }

    public function revisionPayload(WholesaleLabelRevision $revision, string $base): array
    {
        $design = WholesaleLabelDesign::query()->forAllTenants()->where('tenant_id', $revision->tenant_id)->find($revision->design_id);

        return ['id' => $revision->id, 'design_id' => $revision->design_id, 'name' => $design?->name ?? 'Custom label', 'status' => $revision->status,
            'preview' => $revision->preview_path ? $base.'/'.$revision->id.'/preview' : null,
            'vector_preview' => $revision->vector_preview_path ? $base.'/'.$revision->id.'/vector-preview' : null,
            'failure' => $revision->failure, 'created_at' => $revision->created_at?->toIso8601String()];
    }
}
