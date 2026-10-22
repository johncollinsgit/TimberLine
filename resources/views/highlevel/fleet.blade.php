<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Everbranch Fleet</title>
    <meta name="referrer" content="origin">
    @vite('resources/js/highlevel/fleet.js')
</head>
<body>
    <div id="fleet-app"><div class="launch-card"><div class="brand">Everbranch <span>Fleet</span></div><h1>Signing in to Fleet</h1><p role="status">Using your CRM sign-in to verify access to this client account. No separate Fleet password is needed.</p></div></div>
    <script id="fleet-config" type="application/json">{!! json_encode(['challenge' => $challenge ?? null, 'parentOrigins' => $parentOrigins, 'demo' => $demo ?? false, 'demoMapKey' => ($demo ?? false) ? config('services.google_maps.fleet_demo_api_key') : null], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
</body>
</html>
