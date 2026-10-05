<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Everbranch Fleet</title>
    @vite('resources/js/highlevel/fleet.js')
</head>
<body>
    <div id="fleet-app" aria-live="polite"><div class="launch-card"><div class="brand">Everbranch <span>Fleet</span></div><h1>Opening your fleet</h1><p>Verifying your CRM account and administrator access…</p></div></div>
    <script id="fleet-config" type="application/json">{!! json_encode(['challenge' => $challenge, 'parentOrigins' => $parentOrigins], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
</body>
</html>
