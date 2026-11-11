<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $tenant->name }} · Property workspace</title>
    @vite('resources/js/rentals/app.tsx')
</head>
<body>
    <div id="rental-workspace" data-bootstrap="{{ json_encode($bootstrap) }}"></div>
</body>
</html>
