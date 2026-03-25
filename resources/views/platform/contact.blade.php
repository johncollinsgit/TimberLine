@php
    $content = is_array($contact ?? null) ? $contact : [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $content['headline'] ?? 'Contact Fire Forge Tech' }}</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Manrope", "Avenir Next", "Segoe UI", sans-serif;
            background: linear-gradient(170deg, #09120f, #122720);
            color: #edf8f4;
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .contact-shell {
            width: min(760px, 100%);
            border-radius: 18px;
            border: 1px solid rgba(131, 241, 199, 0.24);
            background: rgba(13, 33, 27, 0.84);
            box-shadow: 0 28px 62px -40px rgba(0, 0, 0, 0.7);
            padding: 22px;
            display: grid;
            gap: 12px;
        }

        .contact-shell h1 {
            margin: 0;
            font-size: clamp(1.4rem, 2.6vw, 2rem);
            font-family: "Fraunces", "Iowan Old Style", "Times New Roman", serif;
        }

        .contact-shell p {
            margin: 0;
            color: rgba(223, 244, 235, 0.8);
            line-height: 1.6;
            font-size: 14px;
        }

        .contact-list {
            display: grid;
            gap: 8px;
            margin-top: 4px;
        }

        .contact-row {
            border-radius: 12px;
            border: 1px solid rgba(161, 241, 209, 0.2);
            background: rgba(21, 45, 37, 0.84);
            padding: 10px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .contact-row strong {
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(196, 247, 226, 0.88);
        }

        .contact-row a {
            color: #aef0d3;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
        }

        .contact-row a:hover {
            text-decoration: underline;
        }

        .contact-back {
            display: inline-flex;
            width: fit-content;
            margin-top: 8px;
            color: #d8f7ea;
            font-size: 12px;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <main class="contact-shell">
        <h1>{{ $content['headline'] ?? 'Contact Fire Forge Tech' }}</h1>
        <p>{{ $content['summary'] ?? '' }}</p>

        <section class="contact-list" aria-label="Contact channels">
            @foreach((array) ($content['channels'] ?? []) as $channel)
                <article class="contact-row">
                    <strong>{{ $channel['label'] ?? 'Contact' }}</strong>
                    @if(filled($channel['href'] ?? null))
                        <a href="{{ $channel['href'] }}">{{ $channel['value'] ?? 'Open' }}</a>
                    @else
                        <span>{{ $channel['value'] ?? '' }}</span>
                    @endif
                </article>
            @endforeach
        </section>

        <a class="contact-back" href="{{ route('platform.promo') }}">Back to Product Overview</a>
    </main>
</body>
</html>
