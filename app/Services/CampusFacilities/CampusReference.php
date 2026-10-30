<?php

namespace App\Services\CampusFacilities;

use Illuminate\Support\Facades\Http;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

class CampusReference
{
    public function read(bool $refresh = false): array
    {
        $path = app(DemoStore::class)->directory().'/campus.json';
        $cached = json_decode(file_get_contents(is_file($path) ? $path : resource_path('data/campus-facilities/campus.json')), true, 64, JSON_THROW_ON_ERROR);
        if (! $refresh && is_file($path) && strtotime($cached['fetchedAt']) > time() - 3600) {
            return [...$cached, 'stale' => false];
        }
        // A single refresh across phones; preserve the last successful reference on failure.
        $lock = fopen(app(DemoStore::class)->directory().'/campus.lock', 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return [...$cached, 'stale' => true];
        }
        try {
            $sources = ['map' => 'https://www.lmc.edu/admissions/visit/files/lmc-campus-map.pdf', 'directory' => 'https://www.lmc.edu/directory/index.htm', 'facilities' => 'https://www.lmc.edu/directory/facilities.htm'];
            $responses = [];
            foreach ($sources as $key => $url) {
                $response = Http::timeout(12)->withOptions(['allow_redirects' => false])->get($url)->throw();
                abort_if(strlen($response->body()) > 8_000_000, 503);
                $responses[$key] = $response->body();
            }
            $config = new Config;
            $config->setDecodeMemoryLimit(32 * 1024 * 1024);
            $document = (new Parser([], $config))->parseContent($responses['map']);
            $pages = $document->getPages();
            $text = isset($pages[1]) ? $pages[1]->getText() : $document->getText();
            preg_match_all('/(?:^|\s)(\d{1,2})\.\s+([^\d]+?)(?=\s+\d{1,2}\.\s|$)/u', $text, $matches, PREG_SET_ORDER);
            $buildings = [];
            foreach ($matches as $match) {
                $name = trim(preg_replace('/\s+/', ' ', str_replace('*', '', $match[2])));
                if (mb_strlen($name) < 200) {
                    $buildings[] = ['number' => (int) $match[1], 'name' => $name];
                }
            }
            abort_if(count($buildings) < 20, 503);
            preg_match_all('/href=["\'][^"\']*directory\/([^\/"\']+)\.htm["\'][^>]*>([^<]+)</', $responses['directory'], $departments);
            $names = array_values(array_unique(array_filter(array_map('trim', $departments[2]), fn ($name) => $name && ! preg_match('/Directory|Board of Trustees/i', $name))));
            $payload = ['buildings' => $buildings, 'departments' => $names ?: $cached['departments'], 'sources' => $sources, 'fetchedAt' => now()->toIso8601String(), 'facilitiesAvailable' => str_contains($responses['facilities'], 'Facilities')];
            $temporary = tempnam(dirname($path), '.campus-');
            try {
                file_put_contents($temporary, json_encode($payload, JSON_THROW_ON_ERROR));
                chmod($temporary, 0600);
                rename($temporary, $path);
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }

            return [...$payload, 'stale' => false];
        } catch (Throwable) {
            return [...$cached, 'stale' => true];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
