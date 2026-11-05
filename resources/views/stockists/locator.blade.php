<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Find a retailer · {{ $storeName }}</title>
    @foreach($assetEntry['css'] ?? [] as $css)<link rel="stylesheet" href="{{ $assetBase.basename($css) }}">@endforeach
    @if(isset($assetEntry['file']))<script type="module" src="{{ $assetBase.basename($assetEntry['file']) }}"></script>@endif
</head>
<body>
<main class="locator" data-locator>
    @if($preview)<p class="locator-preview">Private draft preview · Changes are not live.</p>@endif
    <header class="locator-intro"><h1>Find us near you</h1><p>Explore our retail partners. Selection varies by store; check with the retailer before visiting.</p></header>
    <a class="locator-skip" href="#retailer-list">Skip map and browse retailers</a>
    <form class="locator-search" data-search role="search">
        <div><label for="retailer-query">City, ZIP code, address or store name</label><input id="retailer-query" type="search" name="q" autocomplete="off" maxlength="200" placeholder="Try Easley, SC" aria-describedby="locator-status"></div>
        <button type="submit">Search</button><button type="button" data-nearby>Use my location</button><button type="button" data-reset>Show all</button>
    </form>
    <p id="locator-status" class="locator-status" role="status" aria-live="polite">{{ count($locations) }} retailers</p>
    <div class="locator-layout">
        <section class="locator-results" aria-label="Retailer results"><ul id="retailer-list" tabindex="-1">
            @foreach($locations as $location)
                <li data-retailer="{{ $location['id'] }}">
                    <h2><button type="button" data-select="{{ $location['id'] }}">{{ $location['name'] }}</button></h2>
                    @if($location['featured'])<p class="locator-featured">Featured retailer</p>@endif
                    <p>{{ $location['address'] }}</p><p class="locator-distance" data-distance></p>
                    <a href="https://www.google.com/maps/dir/?api=1&amp;destination={{ $location['latitude'] }},{{ $location['longitude'] }}" target="_blank" rel="noopener" aria-label="Directions to {{ $location['name'] }} at {{ $location['address'] }} (opens a new tab)">Get directions <span aria-hidden="true">↗</span></a>
                </li>
            @endforeach
        </ul><p data-empty hidden>No matching retailers. Try a nearby city, ZIP code or store name.</p></section>
        <section class="locator-map-wrap" aria-label="Retailer map"><div id="retailer-map" class="locator-map" role="region" aria-label="Interactive retailer map"></div><p data-map-status class="locator-map-status">Loading map. All retailers and directions are available in the list.</p></section>
    </div>
    <noscript><p>Search and the interactive map need JavaScript. All retailer addresses and directions are listed above.</p></noscript>
    <p class="locator-footer">{{ $storeName }} · Shop locally</p>
    <script type="application/json" id="locator-data">{!! json_encode(['locations' => $locations, 'mapsKey' => $mapsKey], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
</main>
</body>
</html>
