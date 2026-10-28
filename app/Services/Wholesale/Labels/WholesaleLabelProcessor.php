<?php

namespace App\Services\Wholesale\Labels;

use App\Models\WholesaleLabelRevision;
use DOMDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class WholesaleLabelProcessor
{
    public function validateOriginal(string $bytes): string
    {
        abort_if(strlen($bytes) > config('wholesale_labels.max_bytes') || $bytes === '', 422, 'Choose a logo file of 20 MB or less.');
        if (str_starts_with($bytes, '%PDF-')) {
            abort_unless(str_contains(substr($bytes, -2048), '%%EOF'), 422, 'This PDF is incomplete.');

            return 'application/pdf';
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            $size = @getimagesizefromstring($bytes);
            abort_unless($size && $size[0] * $size[1] <= 40000000, 422, 'Choose a logo smaller than 40 megapixels.');

            return $mime;
        }
        $this->safeSvg($bytes);

        return 'image/svg+xml';
    }

    public function safeSvg(string $bytes, bool $vector = false): string
    {
        abort_if(preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes), 422, 'SVG entities are not supported.');
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $dom->loadXML($bytes, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        abort_unless($ok && $dom->documentElement?->localName === 'svg', 422, 'Choose PNG, JPEG, WebP, SVG, or a single-page PDF.');
        $allowed = $vector ? ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon'] : ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'defs', 'clipPath', 'title', 'desc'];
        $geometry = 0;
        foreach ($dom->getElementsByTagName('*') as $element) {
            abort_unless(in_array($element->localName, $allowed, true), 422, 'Export a plain SVG without embedded images or scripts.');
            if ($element->localName === 'path') {
                $geometry++;
            }
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->localName);
                $value = $attribute->value;
                abort_if(str_starts_with($name, 'on') || in_array($name, ['href', 'src'], true)
                    || preg_match('/(?:https?:|data:|file:|javascript:|@import|url\s*\(\s*[^#])/i', $value) && ! in_array($name, ['xmlns'], true), 422, 'SVG external resources are not supported.');
                if ($vector && in_array($name, ['fill', 'stroke'], true)) {
                    abort_unless(in_array(strtolower(trim($value)), ['#000000', '#000', 'black', 'none'], true), 422, 'The replacement vector must contain black paths on a transparent background.');
                }
                abort_if($vector && in_array($name, ['style', 'class'], true), 422, 'Use explicit black fills in the vector.');
            }
        }
        abort_if($vector && $geometry === 0, 422, 'A vector with real paths is required.');

        return $dom->saveXML($dom->documentElement);
    }

    public function process(string $id): void
    {
        Cache::lock('wl-process:'.$id, 150)->block(5, function () use ($id): void {
            $revision = WholesaleLabelRevision::query()->forAllTenants()->findOrFail($id);
            if (! in_array($revision->status, ['queued', 'processing'], true)) {
                return;
            }
            $disk = Storage::disk(config('wholesale_labels.disk'));
            $directory = storage_path('app/label-processing/'.Str::uuid());
            mkdir($directory, 0700, true);
            $revision->update(['status' => 'processing', 'failure' => null]);
            try {
                $bytes = $disk->get($revision->original_path);
                if (! hash_equals($revision->sha256, hash('sha256', $bytes))) {
                    throw new \RuntimeException('Original checksum mismatch.');
                }
                $input = $directory.'/input';
                file_put_contents($input, $revision->mime === 'image/svg+xml' ? $this->safeSvg($bytes) : $bytes);
                if ($revision->mime === 'application/pdf') {
                    $this->run([config('wholesale_labels.ghostscript'), '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dPDFSTOPONERROR', '-sDEVICE=pngalpha', '-r150', '-dFirstPage=1', '-dLastPage=2', '-sOutputFile='.$directory.'/page-%d.png', $input]);
                    if (! is_file($directory.'/page-1.png') || is_file($directory.'/page-2.png')) {
                        throw new \RuntimeException('Use a single-page PDF.');
                    }
                    $input = $directory.'/page-1.png';
                }
                $this->run([config('wholesale_labels.node'), base_path('scripts/labels/render.mjs'), $input, $directory.'/preview.png', $directory.'/bitmap.png', json_encode($revision->settings ?? [])]);
                if (! empty($revision->settings['replacement_path'])) {
                    $svg = $this->safeSvg($disk->get($revision->settings['replacement_path']), true);
                } else {
                    $version = new Process([config('wholesale_labels.vtracer'), '--version']);
                    $version->mustRun();
                    if (! str_contains($version->getOutput(), '0.6.5')) {
                        throw new \RuntimeException('VTracer 0.6.5 is required.');
                    }
                    $this->run([config('wholesale_labels.vtracer'), '--input', $directory.'/bitmap.png', '--output', $directory.'/vector.svg', '--colormode', 'bw', '--filter_speckle', '0']);
                    $svg = $this->safeSvg(file_get_contents($directory.'/vector.svg'), true);
                }
                file_put_contents($directory.'/vector.svg', $svg);
                $this->run([config('wholesale_labels.node'), base_path('scripts/labels/render.mjs'), $directory.'/vector.svg', $directory.'/vector-preview.png', '-']);
                $prefix = 'wholesale-labels/'.$revision->tenant_id.'/'.$revision->id;
                foreach (['preview.png', 'vector.svg', 'vector-preview.png'] as $file) {
                    $disk->put($prefix.'/'.$file, file_get_contents($directory.'/'.$file), ['visibility' => 'private']);
                }
                $revision->update(['status' => 'draft', 'preview_path' => $prefix.'/preview.png', 'vector_path' => $prefix.'/vector.svg', 'vector_preview_path' => $prefix.'/vector-preview.png', 'vector_sha256' => hash('sha256', $svg), 'failure' => null]);
            } catch (Throwable $e) {
                $revision->update(['status' => 'failed', 'failure' => 'The artwork could not be converted. Staff can retry, adjust the crop or threshold, or provide a plain black SVG.']);
                report($e);
            } finally {
                foreach (glob($directory.'/*') as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        });
    }

    protected function run(array $command): void
    {
        (new Process($command, base_path(), null, null, 60))->mustRun();
    }
}
