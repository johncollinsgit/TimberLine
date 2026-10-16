<?php

namespace App\Services\Marketing\Desk;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MarketingMediaService
{
    public function store(int $tenantId, UploadedFile $file): string
    {
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov', default => abort(422, 'Use JPEG photos or MP4/MOV videos.')
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
