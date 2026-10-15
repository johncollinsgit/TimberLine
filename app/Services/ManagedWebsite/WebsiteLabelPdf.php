<?php

namespace App\Services\ManagedWebsite;

/** A single opaque, lossless label image; no HTML, arbitrary PDF imports or remote resources. */
class WebsiteLabelPdf
{
    public const LAYOUTS = ['thermal_4x6' => [288.0, 432.0], 'letter' => [612.0, 792.0], 'a4' => [595.275591, 841.889764]];

    public const MAX_BYTES = 8388608;

    public function inspect(string $png): array
    {
        abort_unless(strlen($png) <= self::MAX_BYTES && str_starts_with($png, "\x89PNG\r\n\x1a\n"), 422, 'The carrier label must be a valid PNG.');
        $size = @getimagesizefromstring($png);
        abort_unless(is_array($size) && ($size[2] ?? null) === IMAGETYPE_PNG
            && $size[0] > 0 && $size[1] > 0 && $size[0] * $size[1] <= 5000000, 422, 'The carrier label image is invalid or too large.');
        $decoded = @imagecreatefromstring($png);
        abort_unless($decoded !== false, 422, 'The carrier label image is corrupt.');
        unset($decoded);

        return ['width' => $size[0], 'height' => $size[1]];
    }

    public function render(string $png, string $layout, string $orientation = 'portrait'): string
    {
        abort_unless(isset(self::LAYOUTS[$layout]) && in_array($orientation, ['portrait', 'landscape'], true), 422, 'Choose a supported label layout and orientation.');
        $size = $this->inspect($png);
        abort_unless(abs($size['width'] / $size['height'] - 2 / 3) < 0.002, 422, 'The original label is not a 4 x 6 image. Review it before printing.');
        $source = @imagecreatefromstring($png);
        abort_unless($source !== false, 422, 'The carrier label could not be decoded.');
        $image = imagecreatetruecolor($size['width'], $size['height']);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagealphablending($image, true);
        imagecopy($image, $source, 0, 0, 0, 0, $size['width'], $size['height']);
        ob_start();
        imagepng($image);
        $normalized = (string) ob_get_clean();
        unset($source, $image);
        // GD writes an opaque 8-bit RGB PNG. Reuse its compressed scanlines,
        // preserving every decoded pixel without JPEG compression or resampling.
        abort_unless(ord($normalized[24] ?? "\0") === 8 && ord($normalized[25] ?? "\0") === 2, 422, 'The label image could not be prepared.');
        $compressed = '';
        for ($offset = 8; $offset + 12 <= strlen($normalized);) {
            $length = unpack('N', substr($normalized, $offset, 4))[1];
            $type = substr($normalized, $offset + 4, 4);
            if ($type === 'IDAT') {
                $compressed .= substr($normalized, $offset + 8, $length);
            }
            $offset += $length + 12;
        }
        abort_if($compressed === '', 422, 'The label image could not be prepared.');
        [$width, $height] = self::LAYOUTS[$layout];
        if ($orientation === 'landscape') {
            [$width, $height] = [$height, $width];
        }
        $number = static fn (float $value): string => rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        $matrix = $layout === 'thermal_4x6'
            ? ($orientation === 'portrait' ? '288 0 0 432 0 0' : '0 288 -432 0 432 0')
            : '288 0 0 432 36 '.$number($height - 468);
        $content = "q\n{$matrix} cm\n/Label Do\nQ\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R /ViewerPreferences << /PrintScaling /None >> >>',
            '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$number($width).' '.$number($height).'] /Resources << /XObject << /Label 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($content).">>\nstream\n".$content.'endstream',
            '<< /Type /XObject /Subtype /Image /Width '.$size['width'].' /Height '.$size['height'].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Interpolate false /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 3 /BitsPerComponent 8 /Columns '.$size['width'].' >> /Length '.strlen($compressed).">>\nstream\n".$compressed."\nendstream",
        ];
        $pdf = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    public function testPng(): string
    {
        $image = imagecreatetruecolor(800, 1200);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);
        $write = static function (int $x, int $y, string $text, int $font = 4) use ($image): void {
            $width = imagefontwidth($font) * strlen($text);
            $height = imagefontheight($font);
            $line = imagecreatetruecolor($width, $height);
            imagefill($line, 0, 0, imagecolorallocate($line, 255, 255, 255));
            imagestring($line, $font, 0, 0, $text, imagecolorallocate($line, 0, 0, 0));
            imagecopyresized($image, $line, $x, $y, 0, 0, $width * 2, $height * 2, $width, $height);
        };
        imagerectangle($image, 25, 25, 774, 1174, $black);
        $write(202, 95, 'EVERBRANCH PRINTER TEST', 5);
        $write(256, 150, 'TEST - NO POSTAGE', 5);
        $write(136, 210, 'Do not use this page for shipping.');
        $write(96, 280, 'Print Actual Size / 100%, no scaling.');
        $write(96, 320, 'Match the driver paper to the PDF.');
        imagerectangle($image, 200, 400, 400, 600, $black);
        $write(136, 625, 'The square must measure 1 inch.');
        imageline($image, 200, 720, 600, 720, $black);
        for ($tick = 0; $tick <= 16; $tick++) {
            imageline($image, 200 + $tick * 25, 720, 200 + $tick * 25, $tick % 8 === 0 ? 750 : 735, $black);
        }
        $write(192, 770, '0');
        $write(352, 770, '1 inch');
        $write(536, 770, '2 inches');
        $write(120, 900, 'Check all borders for clipping.');
        $write(80, 950, 'Printing this test does not buy postage.');
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        unset($image);

        return $png;
    }
}
