<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Save this tab’s edits</title><style>{!! $style !!}</style></head>
<body><main><h1>Save this tab’s edits</h1><p>This saves only this tab’s fictional edits. It may include earlier demos. Nothing is imported.</p><p>The file keeps the original text for review. Its version, author and completeness cannot be verified. Keep it private.</p><button id="capture-download" type="button">Download this tab’s edits</button><p id="capture-status" role="status" aria-live="polite">Your tab has not been read.</p></main>
<script id="capture-context" type="application/json">{!! json_encode($boot, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<script type="module">{!! $script !!}</script></body></html>
