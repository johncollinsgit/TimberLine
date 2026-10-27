<?php

namespace App\Proposals;

/** Bounded genuine decode and generated thumbnail. Decode success is NEVER a malware verdict. */
final class ReviewerWorkspaceImageRenderer
{
    private function decode(string $bytes, string $mime, string $sha, int $size): \GdImage
    {
        abort_unless(strlen($bytes) === $size && $size >= 1 && $size <= ReviewerWorkspaceAttachmentsStore::MAX_BYTES
            && hash_equals($sha, hash('sha256', $bytes)) && in_array($mime, ['image/jpeg', 'image/png'], true)
            && (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) === $mime && function_exists('imagecreatefromstring'), 503);
        $dimensions = @getimagesizefromstring($bytes);
        abort_unless(is_array($dimensions) && ($dimensions['mime'] ?? null) === $mime && $dimensions[0] >= 1 && $dimensions[1] >= 1
            && $dimensions[0] <= 16000000 && $dimensions[1] <= 16000000 && $dimensions[0] * $dimensions[1] <= 16000000, 503);
        $image = @imagecreatefromstring($bytes);
        abort_unless($image !== false && imagesx($image) === $dimensions[0] && imagesy($image) === $dimensions[1], 503);

        return $image;
    }

    public function verify(string $bytes, string $mime, string $sha, int $size): void
    {
        $image = $this->decode($bytes, $mime, $sha, $size);
        unset($image);
    }

    public function thumbnail(string $bytes, string $mime, string $sha, int $size): array
    {
        $source = $this->decode($bytes, $mime, $sha, $size);
        $scale = min(1, 320 / imagesx($source), 240 / imagesy($source));
        $width = max(1, (int) floor(imagesx($source) * $scale));
        $height = max(1, (int) floor(imagesy($source) * $scale));
        $thumb = imagecreatetruecolor($width, $height);
        abort_unless($thumb !== false, 503);
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        try {
            abort_unless(imagecopyresampled($thumb, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source)), 503);
            ob_start();
            try {
                $ok = $mime === 'image/png' ? imagepng($thumb, null, 6) : imagejpeg($thumb, null, 80);
                $output = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            abort_unless($ok && is_string($output) && strlen($output) >= 1 && strlen($output) <= 262144
                && (new \finfo(FILEINFO_MIME_TYPE))->buffer($output) === $mime, 503);

            return ['bytes' => $output, 'metadata' => ['sha256' => hash('sha256', $output), 'size' => strlen($output), 'mime' => $mime,
                'width' => $width, 'height' => $height, 'renderer_sha256' => hash_file('sha256', __FILE__)]];
        } finally {
            unset($source, $thumb);
        }
    }
}
