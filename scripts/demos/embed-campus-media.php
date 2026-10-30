<?php

declare(strict_types=1);

/** Embed reviewed local photographs; the hosted demo never fetches image providers. */
function embedCampusMedia(string $html): string
{
    $root = dirname(__DIR__, 2);
    $catalog = json_decode(file_get_contents($root.'/resources/data/campus-media.json'), true, flags: JSON_THROW_ON_ERROR);
    $total = 0;
    foreach ($catalog['assets'] as &$asset) {
        if (isset($asset['legacy_id'])) {
            continue;
        }
        if (basename($asset['file']) !== $asset['file'] || $asset['mime'] !== 'image/jpeg') {
            throw new RuntimeException('Invalid campus photo asset.');
        }
        $bytes = file_get_contents($root.'/resources/demos/campus-media/'.$asset['file']);
        if ($bytes === false || hash('sha256', $bytes) !== $asset['sha256'] || strlen($bytes) !== $asset['bytes']) {
            throw new RuntimeException('Campus photo checksum mismatch: '.$asset['file']);
        }
        $total += strlen($bytes);
        $asset['src'] = 'data:image/jpeg;base64,'.base64_encode($bytes);
    }
    unset($asset);
    if ($total > 1600000) {
        throw new RuntimeException('Campus photograph byte budget exceeded.');
    }
    while (($start = strpos($html, '<!-- CAMPUS_MEDIA_BEGIN -->')) !== false) {
        $end = strpos($html, '<!-- CAMPUS_MEDIA_END -->', $start);
        if ($end === false) {
            throw new RuntimeException('Incomplete campus media block.');
        }
        $html = substr($html, 0, $start).ltrim(substr($html, $end + strlen('<!-- CAMPUS_MEDIA_END -->')), "\r\n");
    }
    $script = 'globalThis.CAMPUS_MEDIA_CATALOG='.json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';'."\n".file_get_contents($root.'/resources/js/campus/media.js');
    $block = "<!-- CAMPUS_MEDIA_BEGIN -->\n<style>".file_get_contents($root.'/resources/css/campus-media.css')."</style>\n<script>".str_ireplace('</script', '<\\/script', $script)."</script>\n<!-- CAMPUS_MEDIA_END -->\n";
    $html = str_replace('</head>', $block.'</head>', $html);
    if (! str_contains($html, 'function bind(){globalThis.CampusMedia?.mount({data,view});')) {
        if (substr_count($html, 'function bind(){') !== 1) {
            throw new RuntimeException('Expected one persistent campus binding entry point.');
        }
        $html = str_replace('function bind(){', 'function bind(){globalThis.CampusMedia?.mount({data,view});', $html);
    }
    $html = str_replace('<form id="task-edit">', '<form id="task-edit" data-campus-task="${esc(t.id)}">', $html);
    $html = str_replace('showModal(`<div class="eyebrow">${esc(job.id)}', 'showModal(`<div class="eyebrow" data-campus-job="${esc(job.id)}">${esc(job.id)}', $html);

    return str_replace("\r\n", "\n", $html);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $path = $argv[1] ?? dirname(__DIR__, 2).'/resources/demos/lees-mcrae-campus.html';
    file_put_contents($path, embedCampusMedia(file_get_contents($path)));
    echo "Embedded reviewed campus photographs and galleries.\n";
}
