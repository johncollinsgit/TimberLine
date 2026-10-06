<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $list['name'] }} wishlist | Modern Forestry</title>
    <style>
        body { margin: 0; background: #f7f5f1; color: #202a36; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        main { max-width: 720px; margin: 0 auto; padding: 56px 24px; }
        .eyebrow { margin: 0 0 10px; font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: #667085; }
        h1 { margin: 0; font-family: Georgia, serif; font-size: clamp(32px, 6vw, 48px); }
        .intro { margin: 12px 0 32px; color: #4b5563; }
        ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
        a { display: block; padding: 18px; border-radius: 10px; background: #fff; color: inherit; text-decoration: none; box-shadow: 0 1px 3px rgba(0, 0, 0, .08); }
        a:hover { box-shadow: 0 5px 18px rgba(0, 0, 0, .12); }
        .hint { display: block; margin-top: 5px; color: #667085; font-size: 14px; }
        footer { margin-top: 38px; font-size: 14px; }
        footer a { display: inline; padding: 0; background: none; box-shadow: none; color: #315e49; text-decoration: underline; }
    </style>
</head>
<body>
<main>
    <p class="eyebrow">Modern Forestry wishlist</p>
    <h1>{{ $list['name'] }}</h1>
    <p class="intro">{{ $list['item_count'] }} saved {{ \Illuminate\Support\Str::plural('product', $list['item_count']) }}.</p>
    <ul>
        @foreach ($items as $item)
            @php($path = $item['product_url'] ?: ($item['product_handle'] ? '/products/'.$item['product_handle'] : '/collections/all'))
            <li>
                <a href="{{ \Illuminate\Support\Str::startsWith($path, ['http://', 'https://']) ? $path : 'https://modernforestry.com'.$path }}">
                    <strong>{{ $item['product_title'] }}</strong>
                    <span class="hint">View product</span>
                </a>
            </li>
        @endforeach
    </ul>
    <footer><a href="https://modernforestry.com">Shop Modern Forestry</a></footer>
</main>
</body>
</html>
