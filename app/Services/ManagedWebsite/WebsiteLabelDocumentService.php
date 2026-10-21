<?php

namespace App\Services\ManagedWebsite;

use App\Models\WebsiteOrder;
use App\Models\WebsiteShipment;
use App\Models\WebsiteShipmentEvent;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebsiteLabelDocumentService
{
    public const FILE_HOSTS = ['easypost-files.s3.us-west-2.amazonaws.com', 'easypost-files.s3-us-west-2.amazonaws.com'];

    public function assertPrintable(WebsiteShipment $shipment): void
    {
        $shipment->refresh();
        WebsiteOrder::query()->forTenantId($shipment->tenant_id)->findOrFail($shipment->website_order_id)->assertNotPosSimulation();
        abort_unless($shipment->provider === 'easypost' && data_get($shipment->parcel, '_native.version') === 1
            && data_get($shipment->parcel, '_native.buy_state') === 'purchased' && ! data_get($shipment->parcel, '_native.retired', false), 422, 'Only a purchased, active native label can be printed. Refresh the carrier status or review its replacement/refund first.');
    }

    public function prepare(WebsiteShipment $shipment, string $orientation = 'portrait'): array
    {
        abort_unless(in_array($orientation, ['portrait', 'landscape'], true), 422);
        $this->assertPrintable($shipment);

        try {
            return Cache::lock('website-label-document-'.$shipment->tenant_id.'-'.$shipment->id.'-'.$orientation, 60)->block(3, function () use ($shipment, $orientation): array {
                if ($manifest = $this->manifest($shipment, $orientation)) {
                    if ($this->complete($shipment, $manifest)) {
                        return $manifest;
                    }
                }
                // Lifecycle evidence is accepted against the original account and
                // shipment. Document preparation has no buy/refund API dependency.
                $evidence = app(WebsiteShipmentLifecycleService::class)->providerEvidence($shipment);
                $label = (array) ($evidence['postage_label'] ?? []);
                abort_unless(preg_match('/^pl_[A-Za-z0-9_]+$/', (string) ($label['id'] ?? ''))
                    && ($label['label_file_type'] ?? '') === 'image/png' && ($label['label_size'] ?? '') === '4x6', 422, 'A verified original 4 x 6 PNG label is required. Refresh carrier status; do not purchase postage again to fix a document.');
                $url = (string) ($label['label_url'] ?? '');
                $parts = parse_url($url);
                abort_unless(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && in_array($parts['host'] ?? '', self::FILE_HOSTS, true)
                    && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']) && ! isset($parts['fragment'])
                    && str_starts_with($parts['path'] ?? '', '/files/postage_label/'), 422, 'The carrier label file location needs review.');
                try {
                    // Never forward an EasyPost Authorization header to file storage.
                    $response = Http::accept('image/png')->withoutRedirecting()->connectTimeout(5)->timeout(15)
                        ->withOptions(['progress' => static function ($total, $downloaded): void {
                            if ($total > WebsiteLabelPdf::MAX_BYTES || $downloaded > WebsiteLabelPdf::MAX_BYTES) {
                                throw new \RuntimeException('Label file exceeds size limit.');
                            }
                        }])->get($url);
                    abort_unless($response->successful() && in_array(strtolower(trim(explode(';', $response->header('Content-Type'))[0])), ['image/png', 'application/octet-stream'], true), 422);
                    $png = $response->body();
                    $size = app(WebsiteLabelPdf::class)->inspect($png);
                } catch (\Throwable $error) {
                    throw ValidationException::withMessages(['label' => 'The purchased label file could not be verified. Retry document preparation; this does not buy postage.']);
                }
                $resolution = (int) ($label['label_resolution'] ?? 0);
                abort_unless($resolution >= 200 && $resolution <= 600 && abs($size['width'] / $resolution - 4) < 0.05
                    && abs($size['height'] / $resolution - 6) < 0.05, 422, 'The carrier label dimensions or resolution do not match 4 x 6 inches. Review the original before printing.');
                $hash = hash('sha256', $png);
                $base = $this->base($shipment, $hash);
                $this->store($base.'/original.png', $png);
                $files = [];
                foreach (WebsiteLabelPdf::LAYOUTS as $layout => $paper) {
                    $pdf = app(WebsiteLabelPdf::class)->render($png, $layout, $orientation);
                    $path = $base.'/'.$orientation.'/'.$layout.'.pdf';
                    $this->store($path, $pdf);
                    $files[$layout] = ['path' => $path, 'sha256' => hash('sha256', $pdf), 'paper_points' => $orientation === 'portrait' ? $paper : array_reverse($paper)];
                }
                $manifest = ['version' => 1, 'label_id' => $label['id'], 'source_sha256' => $hash, 'source_path' => $base.'/original.png',
                    'width_px' => $size['width'], 'height_px' => $size['height'], 'resolution' => $resolution, 'orientation' => $orientation, 'files' => $files];
                DB::transaction(function () use ($shipment, $manifest, $hash, $orientation): void {
                    $locked = WebsiteShipment::query()->forTenantId($shipment->tenant_id)->lockForUpdate()->findOrFail($shipment->id);
                    $this->assertPrintable($locked);
                    WebsiteShipmentEvent::query()->firstOrCreate(['website_shipment_id' => $locked->id, 'provider_event_id' => 'document:'.$hash.':'.$orientation.':v1'], [
                        'tenant_id' => $locked->tenant_id, 'event_type' => 'label_document_prepared', 'message' => 'Private actual-size label documents prepared without buying postage.',
                        'payload' => ['document' => $manifest], 'occurred_at' => now(),
                    ]);
                });

                return $manifest;
            });
        } catch (LockTimeoutException $exception) {
            throw ValidationException::withMessages(['label' => 'This label document is already being prepared. Retry shortly; postage is unchanged.']);
        }
    }

    public function manifest(WebsiteShipment $shipment, string $orientation = 'portrait'): ?array
    {
        $events = WebsiteShipmentEvent::query()->forTenantId($shipment->tenant_id)->where('website_shipment_id', $shipment->id)
            ->where('event_type', 'label_document_prepared')->latest('id')->get();
        foreach ($events as $event) {
            $manifest = data_get($event->payload, 'document');
            if (is_array($manifest) && ($manifest['version'] ?? null) === 1 && ($manifest['orientation'] ?? '') === $orientation) {
                return $manifest;
            }
        }

        return null;
    }

    /** Read-only: a missing/corrupt file requires explicit preparation, never another purchase. */
    public function bytes(WebsiteShipment $shipment, string $layout, string $orientation = 'portrait'): string
    {
        $this->assertPrintable($shipment);
        abort_unless(isset(WebsiteLabelPdf::LAYOUTS[$layout]) && in_array($orientation, ['portrait', 'landscape'], true), 422);
        $manifest = $this->manifest($shipment, $orientation);
        abort_unless($manifest && $this->complete($shipment, $manifest), 409, 'Prepare the purchased label document again. No additional postage is needed.');

        return Storage::disk('local')->get($manifest['files'][$layout]['path']);
    }

    private function complete(WebsiteShipment $shipment, array $manifest): bool
    {
        $hash = (string) ($manifest['source_sha256'] ?? '');
        if (! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return false;
        }
        $base = $this->base($shipment, $hash);
        $source = $base.'/original.png';
        $disk = Storage::disk('local');
        if (($manifest['source_path'] ?? '') !== $source || ! $disk->exists($source) || ! hash_equals($hash, hash('sha256', $disk->get($source)))) {
            return false;
        }
        foreach (WebsiteLabelPdf::LAYOUTS as $layout => $paper) {
            $path = $base.'/'.($manifest['orientation'] ?? '').'/'.$layout.'.pdf';
            if (data_get($manifest, 'files.'.$layout.'.path') !== $path || ! $disk->exists($path)
                || ! hash_equals((string) data_get($manifest, 'files.'.$layout.'.sha256'), hash('sha256', $disk->get($path)))) {
                return false;
            }
        }

        return true;
    }

    private function base(WebsiteShipment $shipment, string $hash): string
    {
        return 'website-labels/'.$shipment->tenant_id.'/'.$shipment->id.'/'.$hash;
    }

    private function store(string $path, string $bytes): void
    {
        $disk = Storage::disk('local');
        if ($disk->exists($path) && hash_equals(hash('sha256', $bytes), hash('sha256', $disk->get($path)))) {
            return;
        }
        $temporary = $path.'.'.Str::uuid().'.tmp';
        try {
            abort_unless($disk->put($temporary, $bytes, ['visibility' => 'private']) && $disk->move($temporary, $path), 503, 'The purchased label could not be stored. Retry document preparation.');
        } finally {
            $disk->delete($temporary);
        }
    }
}
