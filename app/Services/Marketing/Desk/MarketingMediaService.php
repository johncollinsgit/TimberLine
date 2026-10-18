<?php

namespace App\Services\Marketing\Desk;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MarketingMediaService
{
    public function store(int $tenantId, UploadedFile $file): string
    {
        if (in_array($file->getMimeType(), ['image/png', 'image/webp'], true)) {
            abort_unless(function_exists('imagecreatefromstring') && $file->getSize() <= 20 * 1024 * 1024, 422, 'Use a photo smaller than 20 MB.');
            $dimensions = @getimagesize($file->getRealPath());
            abort_unless($dimensions && $dimensions[0] * $dimensions[1] <= 25000000, 422, 'Use a photo smaller than 25 megapixels.');
            $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
            abort_unless($image !== false, 422, 'This photo could not be read.');
            $canvas = imagecreatetruecolor($dimensions[0], $dimensions[1]);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopy($canvas, $image, 0, 0, 0, 0, $dimensions[0], $dimensions[1]);
            ob_start();
            imagejpeg($canvas, null, 90);
            $bytes = ob_get_clean();
            imagedestroy($canvas);
            imagedestroy($image);
            $path = 'marketing-media/'.$tenantId.'/'.Str::uuid().'.jpg';
            abort_unless(is_string($bytes) && Storage::disk('public')->put($path, $bytes), 503, 'The photo upload did not complete.');

            return Storage::disk('public')->url($path);
        }
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov', default => abort(422, 'Use JPEG, PNG or WebP photos, or MP4/MOV videos.')
        };
        $path = $file->storeAs('marketing-media/'.$tenantId, Str::uuid().'.'.$extension, 'public');
        abort_unless(is_string($path), 503, 'The media upload did not complete.');

        return Storage::disk('public')->url($path);
    }

    public function ownedUrl(int $tenantId, string $url): bool
    {
        $prefix = rtrim(Storage::disk('public')->url('marketing-media/'.$tenantId), '/').'/';

        return str_starts_with($url, $prefix) && preg_match('/^[a-f0-9-]{36}\.(jpg|mp4|mov)$/', substr($url, strlen($prefix))) === 1;
    }
}
