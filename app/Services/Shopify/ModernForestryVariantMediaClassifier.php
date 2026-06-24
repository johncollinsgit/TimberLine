<?php

namespace App\Services\Shopify;

use Illuminate\Support\Str;

class ModernForestryVariantMediaClassifier
{
    public const CANONICAL_4OZ = '4oz';
    public const CANONICAL_8OZ = '8oz';
    public const CANONICAL_16OZ = '16oz';
    public const CANONICAL_WOOD_WICK_8OZ = 'wood_wick_8oz';
    public const CANONICAL_WOOD_WICK_16OZ = 'wood_wick_16oz';
    public const CANONICAL_WAX_MELT = 'wax_melt';

    public const DEFAULT_IMAGE_DIR = '/Users/johncollins/Downloads';

    /**
     * @return array<string,string>
     */
    public function imageFiles(string $imageDir = self::DEFAULT_IMAGE_DIR): array
    {
        $dir = rtrim($imageDir, '/');

        return [
            self::CANONICAL_4OZ => $dir.'/4oz.png',
            self::CANONICAL_8OZ => $dir.'/8oz.png',
            self::CANONICAL_16OZ => $dir.'/16oz.png',
            self::CANONICAL_WOOD_WICK_8OZ => $dir.'/Wood Wick.png',
            self::CANONICAL_WOOD_WICK_16OZ => $dir.'/Wood Wick.png',
            self::CANONICAL_WAX_MELT => $dir.'/Wax Melt.png',
        ];
    }

    public function classify(string $title): ?string
    {
        $matches = $this->matches($title);

        if (in_array(self::CANONICAL_WAX_MELT, $matches, true)) {
            return self::CANONICAL_WAX_MELT;
        }

        $sizeMatches = array_values(array_intersect($matches, $this->sizeCanonicals()));

        return count($sizeMatches) === 1 ? $sizeMatches[0] : null;
    }

    public function isAmbiguous(string $title): bool
    {
        $matches = $this->matches($title);
        $sizeMatches = array_values(array_intersect($matches, $this->sizeCanonicals()));

        return count($sizeMatches) > 1;
    }

    /**
     * @return array<int,string>
     */
    public function matches(string $title): array
    {
        $normalized = $this->normalize($title);
        $matches = [];
        $isWoodWick = preg_match('/\b(?:wood|wooden|cedar)\s*wick\b/', $normalized) === 1
            || preg_match('/\b(?:woodwick|woodenwick|cedarwick)\b/', str_replace(' ', '', $normalized)) === 1;

        if (preg_match('/\b(?:wax\s*)?(?:melt|melts|tart|tarts)\b/', $normalized) === 1
            || preg_match('/\bsoy\s*tarts?\b/', $normalized) === 1) {
            $matches[] = self::CANONICAL_WAX_MELT;
        }

        foreach ([4, 8, 16] as $size) {
            if (preg_match('/\b'.$size.'\s*(?:oz|ounce|ounces)\b/', $normalized) === 1
                || preg_match('/\b'.$size.'oz\b/', str_replace(' ', '', $normalized)) === 1) {
                $matches[] = match (true) {
                    $isWoodWick && $size === 8 => self::CANONICAL_WOOD_WICK_8OZ,
                    $isWoodWick && $size === 16 => self::CANONICAL_WOOD_WICK_16OZ,
                    default => $size.'oz',
                };
            }
        }

        return array_values(array_unique($matches));
    }

    public function normalize(string $title): string
    {
        $normalized = Str::lower($title);
        $normalized = str_replace(['-', '_', '/', '+'], ' ', $normalized);
        $normalized = preg_replace('/[^\pL\pN\s]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\bounces?\b/u', 'ounce', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    public function alt(string $canonical): string
    {
        return match ($canonical) {
            self::CANONICAL_4OZ => 'mf-app-variant-size:4oz Modern Forestry 4 oz size reference',
            self::CANONICAL_8OZ => 'mf-app-variant-size:8oz Modern Forestry 8 oz size reference',
            self::CANONICAL_16OZ => 'mf-app-variant-size:16oz Modern Forestry 16 oz size reference',
            self::CANONICAL_WOOD_WICK_8OZ => 'mf-app-variant-size:wood_wick_8oz Modern Forestry 8 oz wood wick reference',
            self::CANONICAL_WOOD_WICK_16OZ => 'mf-app-variant-size:wood_wick_16oz Modern Forestry 16 oz wood wick reference',
            self::CANONICAL_WAX_MELT => 'mf-app-variant-size:wax_melt Modern Forestry wax melt size reference',
            default => 'mf-app-variant-size:unknown Modern Forestry size reference',
        };
    }

    public function altMarker(string $canonical): string
    {
        return match ($canonical) {
            self::CANONICAL_4OZ => 'mf-app-variant-size:4oz',
            self::CANONICAL_8OZ => 'mf-app-variant-size:8oz',
            self::CANONICAL_16OZ => 'mf-app-variant-size:16oz',
            self::CANONICAL_WOOD_WICK_8OZ => 'mf-app-variant-size:wood_wick_8oz',
            self::CANONICAL_WOOD_WICK_16OZ => 'mf-app-variant-size:wood_wick_16oz',
            self::CANONICAL_WAX_MELT => 'mf-app-variant-size:wax_melt',
            default => 'mf-app-variant-size:unknown',
        };
    }

    /**
     * @return array<int,string>
     */
    protected function sizeCanonicals(): array
    {
        return [
            self::CANONICAL_4OZ,
            self::CANONICAL_8OZ,
            self::CANONICAL_16OZ,
            self::CANONICAL_WOOD_WICK_8OZ,
            self::CANONICAL_WOOD_WICK_16OZ,
        ];
    }
}
